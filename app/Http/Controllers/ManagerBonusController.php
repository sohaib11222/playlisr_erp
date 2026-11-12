<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Store manager bonus (Sarah 2026-11-12, starts 12/1/2026).
// Sales = CLOVER net sales (after tax, tips and refunds), which Sarah trusts:
// 2025 Clover ties to the filed return (HW 611,676 vs 612,099; Pico 203,202
// vs 203,432). ERP imported 2025 history has holes (Oct 2025 about half).
// Each month Sarah/Jon set a realistic sales PLAN per store (the page suggests
// last year's month + half the recent growth). Flat bonus by % of plan
// (TIERS), only if the store stayed within its monthly labor-hour budget
// (Sabina's daily caps vs ERP time-clock hours). No plan set = no bonus.
// Paid once a month at the payroll closest to month end. Payouts are ledgered
// in storage/app/manager-bonus-payouts.json.
class ManagerBonusController extends Controller
{
    const START_MONTH = '2026-12';
    const RATE = 2.0; // no longer used for pay; kept for the old view text
    // Flat bonus by % of the month's sales plan (Clover net). Hollywood pays
    // more because its plan is ~3x Pico's.
    const TIERS = [
        2 => [100 => 150, 110 => 300, 120 => 500],
        1 => [100 => 75, 110 => 150, 120 => 250],
    ];
    // Monthly sales plans set by Sarah/Jon (Clover net). Saved edits on the
    // page (storage/app/manager-bonus-plans.json) override these.
    const PLANS = [
        // Sarah 2026-11-12: HW runs $78-94k/month since April, Dec is ~flat for
        // HW; Pico's Dec is ~1.7x its Aug-Oct average (Clover 2024 + 2025).
        '2026-12' => [2 => 84000, 1 => 25000],
    ];
    const PLANS_FILE = 'manager-bonus-plans.json';
    // Clover net sales per month, pulled from the Clover API 2026-11-12 across
    // every merchant account (HW F6J6PKNMC9VT1; Pico 5YAN6F4N3NCT1 +
    // EQWV171MNZP31 + M0RFJQGMSMY91). Months after this come from the ERP's
    // own Clover sync (clover_payments), checked against this list for Oct 2026.
    const CLOVER_NET = [
        1 => ['2024-01' => 19408.97, '2024-02' => 16606.26, '2024-03' => 20968.97, '2024-04' => 31163.92, '2024-05' => 15989.47, '2024-06' => 13993.69, '2024-07' => 10728.98, '2024-08' => 13080.91, '2024-09' => 13455.48, '2024-10' => 15002.80, '2024-11' => 16942.82, '2024-12' => 24899.78,
              '2025-01' => 18025.49, '2025-02' => 18596.33, '2025-03' => 18997.01, '2025-04' => 22327.62, '2025-05' => 15316.41, '2025-06' => 13908.00, '2025-07' => 16364.93, '2025-08' => 16488.68, '2025-09' => 11950.06, '2025-10' => 12326.41, '2025-11' => 16998.81, '2025-12' => 21901.95, '2026-01' => 15938.98, '2026-02' => 14390.32, '2026-03' => 13950.55, '2026-04' => 22237.09, '2026-05' => 17615.88, '2026-06' => 15933.94, '2026-07' => 19496.60, '2026-08' => 19274.78, '2026-09' => 16447.15, '2026-10' => 18583.51],
        2 => ['2024-05' => 1985.49, '2024-06' => 22976.24, '2024-07' => 45955.63, '2024-08' => 57409.04, '2024-09' => 48891.14, '2024-10' => 54110.04, '2024-11' => 49421.85, '2024-12' => 55522.43,
              '2025-01' => 44831.44, '2025-02' => 42205.84, '2025-03' => 54910.52, '2025-04' => 56715.56, '2025-05' => 49713.80, '2025-06' => 44903.33, '2025-07' => 54663.60, '2025-08' => 60041.42, '2025-09' => 53749.07, '2025-10' => 49316.91, '2025-11' => 48527.56, '2025-12' => 52097.26, '2026-01' => 52620.96, '2026-02' => 55156.69, '2026-03' => 61275.07, '2026-04' => 78376.51, '2026-05' => 76873.75, '2026-06' => 73506.44, '2026-07' => 91371.19, '2026-08' => 93956.44, '2026-09' => 78142.86, '2026-10' => 83982.75],
    ];
    const PAYOUTS_FILE = 'manager-bonus-payouts.json';

    // location_id => manager + daily labor caps [Mon-Thu, Fri-Sun]
    // (Hollywood = Store 1: 26/30, Pico = Store 2: 8/10, Sabina 2026-08-03).
    const STORES = [
        2 => ['store' => 'Hollywood', 'manager_first' => 'Luis', 'regular' => 26, 'peak' => 30],
        1 => ['store' => 'Pico',      'manager_first' => 'Zakary', 'regular' => 8, 'peak' => 10],
    ];
    // Shipping has its own 2-4h/day budget and does not count toward the
    // Hollywood store total (Sarah 2026-09-22). Nick = shipping.
    const SHIPPING_FIRST_NAMES = ['Nick'];

    // Only Sarah and Jon see this page (Sarah 2026-11-12).
    const FULL_ACCESS = [1, 8]; // Sarah 2026-11-12: only Sarah + Jon see both stores
    const MANAGER_IDS = [26 => 2, 18 => 1]; // user_id => location_id

    private function ensureAllowed()
    {
        $u = auth()->user();
        if (!$u || !in_array((int) $u->id, self::FULL_ACCESS, true)) { abort(403); }
    }

    private function visibleStores()
    {
        $u = auth()->user();
        if (!$u) { abort(403); }
        // Managers don't see this page; once paid, the bonus shows on their
        // own My Earnings statement (Sarah 2026-11-12).
        if (in_array((int) $u->id, self::FULL_ACCESS, true)) { return array_keys(self::STORES); }
        abort(403);
    }

    public function index(Request $request)
    {
        $visible = $this->visibleStores();
        $full = in_array((int) auth()->id(), self::FULL_ACCESS, true);
        $businessId = $request->session()->get('user.business_id');

        $month = (string) $request->input('month', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            // Default: last month during the first week (month-end payroll), else this month.
            $now = \Carbon::now();
            $month = $now->day <= 7 ? $now->copy()->subMonthNoOverflow()->format('Y-m') : $now->format('Y-m');
        }

        $rows = [];
        foreach (self::STORES as $locId => $cfg) {
            if (!in_array($locId, $visible, true)) { continue; }
            $rows[] = $this->compute($businessId, $locId, $cfg, $month);
        }

        $payouts = $full ? $this->loadPayouts() : [];
        $months = [];
        $m = \Carbon::parse(self::START_MONTH . '-01');
        $last = \Carbon::now()->startOfMonth();
        if ($m->gt($last)) { $last = $m->copy(); }
        while ($m->lte($last)) { $months[] = $m->format('Y-m'); $m->addMonth(); }

        return view('admin.manager_bonus', [
            'month' => $month,
            'months' => $months,
            'rows' => $rows,
            'rate' => self::RATE,
            'before_start' => $month < self::START_MONTH,
            'payouts' => array_reverse($payouts),
            'full' => $full,
            // ?check=YYYY-MM compares the ERP's Clover sync to the pulled list.
            'check' => preg_match('/^\d{4}-\d{2}$/', (string) $request->input('check')) ? array_map(function ($locId) use ($businessId, $request) {
                $m = (string) $request->input('check');
                $s = \Carbon::parse($m . '-01');
                return ['store' => self::STORES[$locId]['store'], 'month' => $m, 'pulled' => self::CLOVER_NET[$locId][$m] ?? null, 'synced' => $this->syncedNet($businessId, $locId, $s, $s->copy()->endOfMonth())];
            }, array_keys(self::STORES)) : [],
        ]);
    }

    // Used by the Commissions page to show what's owed this payroll.
    public static function owedSummary($businessId)
    {
        $self = new self();
        $now = \Carbon::now();
        $month = $now->day <= 7 ? $now->copy()->subMonthNoOverflow()->format('Y-m') : $now->format('Y-m');
        if ($month < self::START_MONTH) { return []; }
        $out = [];
        foreach (self::STORES as $locId => $cfg) {
            $r = $self->compute($businessId, $locId, $cfg, $month);
            if ($r['owed'] > 0) { $out[] = $r; }
        }
        return $out;
    }

    public function compute($businessId, $locId, array $cfg, $month)
    {
        $start = \Carbon::parse($month . '-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $today = \Carbon::today();
        $complete = $end->lt($today);
        // In-progress month: sales through yesterday vs a target prorated to
        // the same number of days.
        $through = $complete ? $end->copy() : $today->copy()->subDay()->endOfDay();
        $started = $through->gte($start);
        $daysIn = $start->daysInMonth;
        $daysDone = $started ? ($start->diffInDays($through->copy()->startOfDay()) + 1) : 0;

        $sales = $started ? $this->cloverNet($businessId, $locId, $start, $through, $complete) : 0.0;

        $lyMonth = $start->copy()->subYear()->format('Y-m');
        $lyFull = $this->monthNet($businessId, $locId, $lyMonth);
        $ly2Month = $start->copy()->subYears(2)->format('Y-m');
        $ly2Full = $this->monthNet($businessId, $locId, $ly2Month);
        // Recent pace: last 3 COMPLETED months vs the same 3 months a year
        // earlier. Only used to SUGGEST a plan; the owner sets the real one.
        $prev = []; $prevLy = [];
        for ($i = 1; count($prev) < 3 && $i <= 12; $i++) {
            $pmStart = $start->copy()->subMonthsNoOverflow($i);
            if ($pmStart->copy()->endOfMonth()->gte($today)) { continue; }
            $pm = $pmStart->format('Y-m');
            $prev[$pm] = $this->monthNet($businessId, $locId, $pm);
            $prevLy[$pm] = $this->monthNet($businessId, $locId, $pmStart->copy()->subYear()->format('Y-m'));
        }
        $pace = array_sum($prevLy) > 0 ? (array_sum($prev) / array_sum($prevLy) - 1) : 0.0;
        // Suggested plan = last year's month + HALF the recent growth, to $500.
        $suggested = round($lyFull * (1 + max(0, $pace) / 2) / 500) * 500;
        $plan = $this->planFor($locId, $month);
        $planSet = $plan !== null;
        $planFull = $planSet ? $plan : $suggested;
        // In-progress month: compare sales so far to the plan for the same days.
        $planToDate = $complete ? $planFull : round($planFull * $daysDone / $daysIn, 2);
        $pctOfPlan = $planToDate > 0 ? round($sales / $planToDate * 100, 1) : 0.0;
        // Next tier up, for "how much more to reach it".
        $nextTier = null;
        foreach (self::TIERS[$locId] as $pct => $amt) {
            if ($pctOfPlan < $pct) { $nextTier = ['pct' => $pct, 'amount' => $amt, 'sales' => round($planFull * $pct / 100, 2)]; break; }
        }

        // Labor budget over the same days.
        $budget = 0;
        for ($d = $start->copy(); $d->lte($through); $d->addDay()) {
            $budget += in_array($d->dayOfWeekIso, [5, 6, 7], true) ? $cfg['peak'] : $cfg['regular'];
        }
        $hours = $started ? $this->laborHours($businessId, $locId, $start, $through) : 0.0;
        $withinBudget = $hours <= $budget;

        // Flat tiered bonus on % of plan (Sarah 2026-11-12: realistic, real
        // incentive, no overpaying). Labor budget must also be met.
        $tierHit = 0; $earned = 0.0;
        if ($withinBudget && $planSet) {
            foreach (self::TIERS[$locId] as $pct => $amt) {
                if ($pctOfPlan >= $pct) { $tierHit = $pct; $earned = (float) $amt; }
            }
        }

        $manager = DB::table('users')->where('business_id', $businessId)
            ->where('first_name', $cfg['manager_first'])->orderBy('id')->first(['id', 'first_name', 'last_name']);
        $paid = 0.0;
        foreach ($this->loadPayouts() as $p) {
            if (($p['month'] ?? '') === $month && (int) ($p['location_id'] ?? 0) === (int) $locId) { $paid += (float) $p['amount']; }
        }

        return [
            'location_id' => $locId,
            'store' => $cfg['store'],
            'manager_id' => $manager->id ?? null,
            'manager' => $manager ? trim($manager->first_name . ' ' . $manager->last_name) : $cfg['manager_first'],
            'month' => $month,
            'complete' => $complete,
            'through' => $started ? $through->format('m/d/y') : null,
            'sales' => round($sales, 2),
            'ly_month' => $lyMonth,
            'ly_full' => round($lyFull, 2),
            'ly2_month' => $ly2Month,
            'ly2_full' => round($ly2Full, 2),
            'prev' => $prev,
            'prev_ly' => $prevLy,
            'pace' => round($pace * 100, 1),
            'suggested' => $suggested,
            'plan' => $planFull,
            'plan_set' => $planSet,
            'plan_to_date' => $planToDate,
            'pct_of_plan' => $pctOfPlan,
            'tiers' => self::TIERS[$locId],
            'tier_hit' => $tierHit,
            'next_tier' => $nextTier,
            'hours' => round($hours, 2),
            'budget' => $budget,
            'within_budget' => $withinBudget,
            'earned' => $earned,
            'paid' => round($paid, 2),
            'owed' => ($month >= self::START_MONTH && $complete) ? max(0, round($earned - $paid, 2)) : 0.0,
        ];
    }

    private function planFor($locId, $month)
    {
        $saved = [];
        if (Storage::disk('local')->exists(self::PLANS_FILE)) {
            $saved = json_decode(Storage::disk('local')->get(self::PLANS_FILE), true) ?: [];
        }
        if (isset($saved[$month][$locId]) && (float) $saved[$month][$locId] > 0) { return (float) $saved[$month][$locId]; }
        if (isset(self::PLANS[$month][$locId])) { return (float) self::PLANS[$month][$locId]; }
        return null;
    }

    public function savePlan(Request $request)
    {
        $this->ensureAllowed();
        $month = (string) $request->input('month');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) { return redirect('/admin/manager-bonus'); }
        $saved = Storage::disk('local')->exists(self::PLANS_FILE) ? (json_decode(Storage::disk('local')->get(self::PLANS_FILE), true) ?: []) : [];
        foreach (array_keys(self::STORES) as $locId) {
            $v = round((float) str_replace([',', '$'], '', (string) $request->input('plan_' . $locId, '')), 2);
            if ($v > 0) { $saved[$month][$locId] = $v; }
        }
        Storage::disk('local')->put(self::PLANS_FILE, json_encode($saved, JSON_PRETTY_PRINT));
        return redirect('/admin/manager-bonus?month=' . $month)->with('status', ['success' => 1, 'msg' => 'Plan saved.']);
    }

    // Clover net sales for a whole month: the pulled list when we have it,
    // otherwise the ERP's Clover sync.
    private function monthNet($businessId, $locId, $month)
    {
        if (isset(self::CLOVER_NET[$locId][$month])) { return (float) self::CLOVER_NET[$locId][$month]; }
        $s = \Carbon::parse($month . '-01')->startOfDay();
        $e = $s->copy()->endOfMonth();
        if ($s->gt(\Carbon::today())) { return 0.0; }
        return $this->syncedNet($businessId, $locId, $s, $e);
    }

    private function cloverNet($businessId, $locId, \Carbon\Carbon $from, \Carbon\Carbon $to, $wholeMonth)
    {
        $m = $from->format('Y-m');
        if ($wholeMonth && isset(self::CLOVER_NET[$locId][$m])) { return (float) self::CLOVER_NET[$locId][$m]; }
        return $this->syncedNet($businessId, $locId, $from, $to);
    }

    // Clover payments the ERP syncs every 5 minutes: amount minus tax minus tip.
    public function syncedNet($businessId, $locId, \Carbon\Carbon $from, \Carbon\Carbon $to)
    {
        $r = DB::table('clover_payments')
            ->where('business_id', $businessId)->where('location_id', $locId)
            ->where('result', 'SUCCESS')
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(amount_cents - COALESCE(tax_cents,0) - COALESCE(tip_cents,0)),0) as net')
            ->first();
        return round(((int) $r->net) / 100, 2);
    }

    // Clocked hours at this store (ERP time clock), voided excluded, shipping excluded.
    private function laborHours($businessId, $locId, \Carbon\Carbon $from, \Carbon\Carbon $to)
    {
        $rows = DB::table('time_clock_punches as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.business_id', $businessId)->where('p.location_id', $locId)
            ->whereNull('p.voided_at')->whereNotNull('p.clock_out_at')
            ->whereBetween('p.clock_in_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereNotIn('u.first_name', self::SHIPPING_FIRST_NAMES)
            ->get(['p.clock_in_at', 'p.clock_out_at']);
        $h = 0.0;
        foreach ($rows as $r) {
            $h += max(0, \Carbon::parse($r->clock_out_at)->diffInMinutes(\Carbon::parse($r->clock_in_at))) / 60;
        }
        return $h;
    }

    public function markPaid(Request $request)
    {
        $this->ensureAllowed();
        $businessId = $request->session()->get('user.business_id');
        $locId = (int) $request->input('location_id');
        $month = (string) $request->input('month');
        if (!isset(self::STORES[$locId]) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return redirect('/admin/manager-bonus')->with('status', ['success' => 0, 'msg' => 'Pick a store and month.']);
        }
        $r = $this->compute($businessId, $locId, self::STORES[$locId], $month);
        if ($r['owed'] <= 0) {
            return redirect('/admin/manager-bonus?month=' . $month)->with('status', ['success' => 0, 'msg' => 'Nothing owed for ' . $r['store'] . ' that month.']);
        }
        $p = $this->loadPayouts();
        $p[] = [
            'id' => bin2hex(random_bytes(8)), 'month' => $month, 'location_id' => $locId,
            'store' => $r['store'], 'user_id' => $r['manager_id'], 'name' => $r['manager'],
            'amount' => $r['owed'], 'marked_by' => auth()->id(), 'marked_at' => now()->toDateTimeString(),
        ];
        $this->savePayouts($p);
        return redirect('/admin/manager-bonus?month=' . $month)->with('status', ['success' => 1, 'msg' => 'Recorded $' . number_format($r['owed'], 2) . ' for ' . $r['manager'] . '.']);
    }

    public function undo(Request $request)
    {
        $this->ensureAllowed();
        $id = (string) $request->input('id');
        $p = array_values(array_filter($this->loadPayouts(), function ($row) use ($id) { return ($row['id'] ?? '') !== $id; }));
        $this->savePayouts($p);
        return redirect('/admin/manager-bonus')->with('status', ['success' => 1, 'msg' => 'Payment removed.']);
    }

    private function loadPayouts()
    {
        if (!Storage::disk('local')->exists(self::PAYOUTS_FILE)) { return []; }
        $d = json_decode(Storage::disk('local')->get(self::PAYOUTS_FILE), true);
        return is_array($d) ? $d : [];
    }

    private function savePayouts(array $p)
    {
        Storage::disk('local')->put(self::PAYOUTS_FILE, json_encode(array_values($p), JSON_PRETTY_PRINT));
    }

    // Paid manager bonus rows for one person, for their My Earnings statement.
    public static function paidForUser($userId)
    {
        $self = new self();
        return array_values(array_filter($self->loadPayouts(), function ($p) use ($userId) {
            return (int) ($p['user_id'] ?? 0) === (int) $userId;
        }));
    }

    // A manager's own store only, for his My Earnings page (Sarah 2026-11-12:
    // each sees how his bonus is calculated, never the other store). Current
    // month, or December 2026 before the program starts.
    public static function liveForUser($businessId, $userId)
    {
        if (!isset(self::MANAGER_IDS[(int) $userId])) { return null; }
        $locId = self::MANAGER_IDS[(int) $userId];
        $month = \Carbon::now()->format('Y-m');
        if ($month < self::START_MONTH) { $month = self::START_MONTH; }
        $self = new self();
        return $self->compute($businessId, $locId, self::STORES[$locId], $month);
    }
}
