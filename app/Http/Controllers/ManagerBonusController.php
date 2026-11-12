<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Store manager bonus (Sarah 2026-11-12, starts 12/1/2026).
// Each month, the store's manager earns 2% of the store's PRE-TAX sales above
// the same calendar month last year, paid only if the store stayed within its
// monthly labor-hour budget (Sabina's daily caps summed over the month, vs
// actual ERP time-clock hours at that store). Paid at month-end payroll.
// Payouts are ledgered in storage/app/manager-bonus-payouts.json.
class ManagerBonusController extends Controller
{
    const START_MONTH = '2026-12';
    const RATE = 2.0;
    const TAX_RATE = 0.0975; // CA rate used by the historical import; growth is paid before tax
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
        // In-progress month: compare to-date (through yesterday) against the
        // same days last year, so the number is fair mid-month.
        $through = $complete ? $end->copy() : $today->copy()->subDay()->endOfDay();
        if ($through->lt($start)) { $through = $start->copy()->subSecond(); }

        $lyStart = $start->copy()->subYear();
        $lyThrough = $lyStart->copy()->addDays($start->diffInDays($through))->endOfDay();

        $sales = $through->gte($start) ? $this->storeSales($businessId, $locId, $start, $through) : 0.0;
        $lySales = $through->gte($start) ? $this->storeSales($businessId, $locId, $lyStart, $lyThrough) : 0.0;
        $growth = round($sales - $lySales, 2);
        $lyFull = $this->storeSales($businessId, $locId, $lyStart, $lyStart->copy()->endOfMonth());

        // Labor budget over the same days.
        $budget = 0;
        for ($d = $start->copy(); $d->lte($through); $d->addDay()) {
            $budget += in_array($d->dayOfWeekIso, [5, 6, 7], true) ? $cfg['peak'] : $cfg['regular'];
        }
        $hours = $through->gte($start) ? $this->laborHours($businessId, $locId, $start, $through) : 0.0;
        $withinBudget = $hours <= $budget;

        $growthPreTax = round($growth / (1 + self::TAX_RATE), 2);
        $earned = ($growth > 0 && $withinBudget) ? round($growthPreTax * self::RATE / 100, 2) : 0.0;

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
            'through' => $through->gte($start) ? $through->format('m/d/y') : null,
            'sales' => round($sales, 2),
            'ly_sales' => round($lySales, 2),
            'growth' => $growth,
            'growth_pretax' => $growthPreTax,
            'ly_full' => round($lyFull, 2),
            'growth_pct' => $lySales > 0 ? round($growth / $lySales * 100, 1) : null,
            'hours' => round($hours, 2),
            'budget' => $budget,
            'within_budget' => $withinBudget,
            'earned' => $earned,
            'paid' => round($paid, 2),
            'owed' => ($month >= self::START_MONTH && $complete) ? max(0, round($earned - $paid, 2)) : 0.0,
        ];
    }

    // Store sales EXACTLY as the Like-for-Like report counts them (tax
    // included, live + imported history, larger of sheet vs register for the
    // period, Whatnot excluded), so every number here ties to /reports/lfl-sales.
    // Last year's register rows carry no separate tax, so tax is taken out of
    // the GROWTH instead (see TAX_RATE), the same way for both years.
    private function storeSales($businessId, $locId, \Carbon\Carbon $from, \Carbon\Carbon $to)
    {
        $r = DB::table('transactions')
            ->where('business_id', $businessId)->where('location_id', $locId)
            ->where('type', 'sell')->where('status', 'final')
            ->where(function ($w) { $w->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
            ->whereBetween('transaction_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN import_source LIKE 'nivessa_backend_sales_%' THEN final_total ELSE 0 END),0) as sheet_rev,
                COALESCE(SUM(CASE WHEN import_source IS NULL THEN final_total ELSE 0 END),0) as register_rev,
                COALESCE(SUM(CASE WHEN import_source IS NOT NULL AND import_source NOT LIKE 'nivessa_backend_sales_%' THEN final_total ELSE 0 END),0) as other_rev
            ")->first();
        return max((float) $r->sheet_rev, (float) $r->register_rev) + (float) $r->other_rev;
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
}
