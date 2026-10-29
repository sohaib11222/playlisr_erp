<?php

namespace App\Http\Controllers;

use App\SlingShift;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Payroll Calculator (bi-weekly).
//
// Puts one pay run together the way Sarah's payroll spreadsheet does, but wired
// to live ERP data so nothing is re-keyed:
//
//   1. HOURS come from Clover clock-in/out (pasted or uploaded per period).
//      We group each person's punches by workday, split regular vs overtime
//      (California daily rule: >8h/day = 1.5x, >12h/day = 2x) and pay at their
//      saved hourly rate.
//   2. LATE FLAGS compare each punch to the Sling SCHEDULE (sling_shifts, which
//      the ERP already syncs): clocked in after the shift start, or out well
//      after the shift end, both past a grace window. Soft warnings only — they
//      never change pay.
//   3. COMMISSIONS are pulled straight from the existing engines so the numbers
//      match the Employee Leaderboard / Commissions Owed page to the penny:
//      listing commission via ListingCommissionController::summaryByUser and the
//      sales-goal bonus via salesSummaryByUser.
//   4. FREELANCERS (Fahrul, Sohaib, Roy, Fatteen, etc.) are tracked separately
//      as contractor payments with their own pay model + paid/method status —
//      they do NOT go through QuickBooks payroll.
//   5. QUICKBOOKS OUTPUT: a copy/CSV panel listing exactly what to enter in QB
//      per W2 person — regular hours, OT hours, sales commission, listing
//      commission. No auto-push; entry stays manual (matches today).
//
// Nothing here needs a migration. Per-person rates + OT settings + the
// freelancer roster live in storage/app/payroll/config.json, and each period's
// imported punches live in storage/app/payroll/hours-<start>_<end>.json. This
// mirrors how listing/sales commission "paid" state is kept (Sarah doesn't run
// ERP migrations).
class PayrollController extends Controller
{
    protected $businessUtil;

    const CONFIG_FILE = 'payroll/config.json';

    // California daily-overtime defaults. Editable on the page.
    const DEFAULTS = [
        'daily_ot_after' => 8,    // hours/day before 1.5x kicks in
        'daily_dt_after' => 12,   // hours/day before 2x kicks in
        'ot_multiplier'  => 1.5,
        'dt_multiplier'  => 2.0,
        'grace_minutes'  => 5,    // late only flags past this many minutes
    ];

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    // Payroll is only for Sarah, Jon and Fatteen (his ERP login is
    // "Nerdy-Solutions", user #10) - Sarah 2026-10-19.
    private function ensureAdmin()
    {
        if (!$this->isPayrollUser()) {
            abort(403, 'Payroll is only open to Sarah, Jon and Fatteen.');
        }
    }

    private function isPayrollUser()
    {
        // Exact accounts, not names, so a renamed login can never get in:
        // 1 = Jonathan Hedvat, 8 = Sarah Hedvat, 10 = Fatteen (Nerdy-Solutions).
        $u = auth()->user();
        return $u && in_array((int) $u->id, [1, 8, 10], true);
    }

    // ---- Main page -------------------------------------------------------

    public function index(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $request->session()->get('user.business_id');

        [$start, $end] = $this->resolvePeriod($request);
        $this->autoLoadErpHours($businessId, $start, $end);

        [$people, $config, $hoursDoc, $run] = $this->assemble($businessId, $start, $end);
        $settings = $config['settings'];
        $rows     = $hoursDoc['rows'] ?? [];

        // Totals (after hiding departed staff). Commission totals only count
        // what this run actually pays (month-end, or the people ticked).
        $totals = [
            'reg_hours'   => round(array_sum(array_column($people, 'reg_hours')), 2),
            'ot_hours'    => round(array_sum(array_column($people, 'ot_hours')), 2),
            'dt_hours'    => round(array_sum(array_column($people, 'dt_hours')), 2),
            'wages'       => round(array_sum(array_column($people, 'wages')), 2),
            'sales_comm'  => round(array_sum(array_map(function ($p) { return $p['comm_included'] ? $p['sales_comm'] : 0; }, $people)), 2),
            'listing_comm'=> round(array_sum(array_map(function ($p) { return $p['comm_included'] ? $p['listing_comm'] : 0; }, $people)), 2),
            'party_comm'  => round(array_sum(array_map(function ($p) { return $p['comm_included'] ? $p['party_comm'] : 0; }, $people)), 2),
            'adj_total'   => round(array_sum(array_column($people, 'adj_dollars')), 2),
            'comm_earned' => round(array_sum(array_column($people, 'comm_earned')), 2),
            'grand_total' => round(array_sum(array_column($people, 'grand_total')), 2),
            'flags'       => array_sum(array_map(function ($p) { return count($p['flags']); }, $people)),
            'checks'      => array_sum(array_map(function ($p) { return count($p['checks']); }, $people)),
        ];

        $freelancers = $this->computeFreelancers($config['freelancers']);
        // Golden (Whatnot host, contractor): owed since his last payment, from
        // the Whatnot Seller page (items + stream hours + tips). Sarah 10/23.
        try {
            $ws = \App\Services\WhatnotSeller::settings();
            $wsFrom = \Carbon\Carbon::parse($ws['paid_thru'])->addDay()->format('Y-m-d');
            $wsTo = min($end, \App\Services\WhatnotSeller::now()->format('Y-m-d'));
            if ($wsFrom <= $wsTo) {
                $wo = \App\Services\WhatnotSeller::owed((int) $businessId, $wsFrom, $wsTo);
                $freelancers[] = [
                    'id' => 'whatnot-seller', 'auto' => true,
                    'name' => $ws['seller_name'] . ' (Whatnot)', 'model' => 'whatnot',
                    'amount' => $wo['total'], 'method' => 'Venmo (Jon)', 'paid' => false,
                    'note' => (int) $wo['items'] . ' items, ' . \App\Services\WhatnotSeller::hoursLabel($wo['minutes']) . ' on Whatnot, $' . number_format($wo['tips'], 2) . ' tips, ' . \Carbon\Carbon::parse($wsFrom)->format('m/d/y') . ' to ' . \Carbon\Carbon::parse($wsTo)->format('m/d/y'),
                    'statement' => url('/reports/whatnot-seller/statement') . '?' . http_build_query(['start_date' => $wsFrom, 'end_date' => $wsTo]),
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll whatnot row skipped: ' . $e->getMessage());
        }
        $freelancerTotal = round(array_sum(array_column($freelancers, 'amount')), 2);

        return view('admin.payroll', [
            'start'        => $start,
            'end'          => $end,
            'settings'     => $settings,
            'people'       => $people,
            'totals'       => $totals,
            'qb'           => $this->qbBlocks($people),
            'run'          => $run,
            'freelancers'  => $freelancers,
            'freelancer_total' => $freelancerTotal,
            'imported_at'  => $hoursDoc['imported_at'] ?? null,
            'hours_doc'    => $hoursDoc,
            'hours_source' => $hoursDoc['source'] ?? 'clover',
            'row_count'    => count($rows),
            'unmatched'    => $this->unmatchedNames($people),
            'sling_ready'  => (new \App\Services\SlingClient())->isConfigured() || SlingShift::query()->exists(),
            'can_see_rates' => $this->canSeeRates(),
            'hidden'       => $config['hidden'] ?? [],
            'last_run_at'  => $this->lastRunSavedAt($start, $end),
            'erp_users'    => $this->erpUserOptions($businessId),
            'notes'        => $this->loadNotes($start, $end),
            'companies'    => self::COMPANIES,
        ]);
    }

    // ---- One pay run, end to end (Sarah 2026-10-15) ----------------------
    //
    // Everything the payroll Google Sheet did, on one page:
    //   - hours per person split by COMPANY (Pico hours -> Nivessa One, Hollywood
    //     and warehouse -> Nivessa Two; a per-person override pins everyone's
    //     hours to one company, e.g. Luis is Nivessa Two only), with daily OT
    //     going to whichever store the hours past 8 were worked at;
    //   - the per-day shift log (the sheet's bottom blocks), so totals are
    //     always the sum of the actual punches;
    //   - one-off ADJUSTMENTS per person and company: back pay, bonus, other $,
    //     or an hours offset (how an overpay is taken back: fewer hours on the
    //     next check, per QuickBooks support);
    //   - commissions only on the month-end run, or for people ticked by hand;
    //   - cross-checks against the register log and the Sling schedule;
    //   - one QuickBooks block per company in QB's own pay-type order.
    const COMPANIES = [
        'one' => 'Nivessa One (Pico)',
        'two' => 'Nivessa Two (Hollywood)',
    ];

    private function assemble($businessId, $start, $end)
    {
        $config   = $this->loadConfig();
        $hoursDoc = $this->loadHours($start, $end);
        $run      = $this->loadRun($start, $end);
        $schedule = $this->scheduleIndex($start, $end);

        $people = $this->buildPeople($hoursDoc['rows'] ?? [], $config['rates'], $config['settings'], $schedule);
        $people = $this->attachCommissions($people, $this->commissionByUser($businessId), $this->lastCommissionPaidByUser(), $this->lastPaycheckMap($start), $businessId);
        $people = $this->attachParty($people, $businessId);
        $people = $this->filterHidden($people, $config['hidden'] ?? []);
        $people = $this->applyRun($people, $run, $config['rates'], $config['settings']);
        $people = $this->attachChecks($people, $businessId, $start, $end);
        return [$people, $config, $hoursDoc, $run];
    }

    // Which company a punch's hours belong to. A person override wins.
    private function companyFor(array $rc, $location)
    {
        $ov = $rc['company'] ?? 'auto';
        if (isset(self::COMPANIES[$ov])) { return $ov; }
        return stripos((string) $location, 'pico') !== false ? 'one' : 'two';
    }

    // ---- Run file: commissions switch + adjustments ----------------------

    private function runFile($start, $end) { return 'payroll/run-' . $start . '_' . $end . '.json'; }

    private function loadRun($start, $end)
    {
        $default = ['include_commissions' => false, 'include_uids' => [], 'adjustments' => []];
        $f = $this->runFile($start, $end);
        if (!Storage::disk('local')->exists($f)) { return $default; }
        $d = json_decode(Storage::disk('local')->get($f), true);
        return is_array($d) ? array_merge($default, $d) : $default;
    }

    private function saveRunFile($start, $end, array $run)
    {
        Storage::disk('local')->put($this->runFile($start, $end), json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function saveCommissionMode(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $run = $this->loadRun($start, $end);
        $run['include_commissions'] = (bool) $request->input('include_commissions');
        $run['include_uids'] = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('include_uids', [])))));
        $this->saveRunFile($start, $end, $run);
        $msg = $run['include_commissions'] ? 'Commissions are included for everyone this run.'
            : (count($run['include_uids']) ? 'Commissions included only for the people ticked.' : 'No commissions this run (paid at month-end).');
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => $msg]);
    }

    const ADJ_TYPES = [
        'back_pay'     => 'Back pay',
        'bonus'        => 'Bonus',
        'hours_offset' => 'Hours offset (overpay)',
        'other'        => 'Other pay',
    ];

    public function saveAdjustment(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $who = (string) $request->input('who', '');       // "uid:12" or "key:andy"
        $type = (string) $request->input('type', '');
        $co = (string) $request->input('company', '');
        if (!isset(self::ADJ_TYPES[$type]) || !isset(self::COMPANIES[$co]) || $who === '') {
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => 'Pick a person, a company and a type.']);
        }
        $amount = round((float) $request->input('amount', 0), 2);
        $hours  = round((float) $request->input('hours', 0), 2);
        if ($type === 'hours_offset' ? $hours == 0.0 : $amount == 0.0) {
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => $type === 'hours_offset' ? 'Enter the hours (negative takes hours off).' : 'Enter an amount.']);
        }
        [$kind, $val] = array_pad(explode(':', $who, 2), 2, '');
        $run = $this->loadRun($start, $end);
        $run['adjustments'][] = [
            'id'      => bin2hex(random_bytes(6)),
            'user_id' => $kind === 'uid' ? (int) $val : null,
            'key'     => $kind === 'key' ? $this->nameKey($val) : '',
            'name'    => trim((string) $request->input('name', '')),
            'company' => $co,
            'type'    => $type,
            'amount'  => $type === 'hours_offset' ? 0.0 : $amount,
            'hours'   => $type === 'hours_offset' ? $hours : 0.0,
            'note'    => mb_substr(trim((string) $request->input('note', '')), 0, 300),
            'added_by'=> $request->session()->get('user.id'),
            'added_at'=> now()->toDateTimeString(),
        ];
        $this->saveRunFile($start, $end, $run);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Added ' . strtolower(self::ADJ_TYPES[$type]) . '.']);
    }

    public function deleteAdjustment(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));
        $run = $this->loadRun($start, $end);
        $run['adjustments'] = array_values(array_filter($run['adjustments'], function ($a) use ($id) { return ($a['id'] ?? '') !== $id; }));
        $this->saveRunFile($start, $end, $run);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Removed that adjustment.']);
    }

    // Commission switch + adjustments folded into each person, then wages and
    // the grand total recomputed per company.
    private function applyRun(array $people, array $run, array $rates, array $settings)
    {
        $incAll = !empty($run['include_commissions']);
        $incUids = array_flip(array_map('intval', $run['include_uids'] ?? []));
        $mult = (float) $settings['ot_multiplier'];

        foreach ($people as &$p) {
            $p['comm_included'] = $incAll || (!empty($p['user_id']) && isset($incUids[(int) $p['user_id']]));
            $p['adjustments'] = [];
            foreach ($run['adjustments'] ?? [] as $a) {
                $hit = (!empty($a['user_id']) && (int) $a['user_id'] === (int) ($p['user_id'] ?? 0))
                    || (empty($a['user_id']) && !empty($a['key']) && $a['key'] === $p['key']);
                if ($hit) { $p['adjustments'][] = $a; }
            }

            // Per-company pay lines, in QB's pay-type order.
            $lines = [];
            foreach (self::COMPANIES as $co => $label) {
                $lines[$co] = [
                    'reg' => (float) ($p['co'][$co]['reg'] ?? 0), 'ot' => (float) ($p['co'][$co]['ot'] ?? 0),
                    'party' => 0.0, 'listing' => 0.0, 'sales' => 0.0, 'back_pay' => 0.0, 'bonus' => 0.0, 'other' => 0.0,
                ];
            }
            $adjDollars = 0.0;
            foreach ($p['adjustments'] as $a) {
                $co = $a['company'];
                if (!isset($lines[$co])) { continue; }
                if ($a['type'] === 'hours_offset') { $lines[$co]['reg'] += (float) $a['hours']; continue; }
                $lines[$co][$a['type']] += (float) $a['amount'];
                $adjDollars += (float) $a['amount'];
            }
            if ($p['comm_included']) {
                $home = $p['home_company'];
                $lines[$home]['listing'] += $p['listing_comm'];
                $lines[$home]['sales'] += $p['sales_comm'];
                foreach ($p['party_lines'] as $pl) { $lines[$pl['company']]['party'] += $pl['amount']; }
            }

            $rate = (float) $p['rate'];
            $wages = 0.0; $gross = [];
            foreach ($lines as $co => &$l) {
                foreach ($l as $k => $v) { $l[$k] = round($v, 2); }
                $w = $l['reg'] * $rate + $l['ot'] * $rate * $mult;
                $wages += $w;
                $gross[$co] = round($w + $l['party'] + $l['listing'] + $l['sales'] + $l['back_pay'] + $l['bonus'] + $l['other'], 2);
            }
            unset($l);
            $p['lines'] = $lines;
            $p['gross_by_company'] = $gross;
            $p['adj_dollars'] = round($adjDollars, 2);
            $p['wages'] = round($wages, 2);
            $p['grand_total'] = round(array_sum($gross), 2);
            $p['pay_type'] = $rates[$p['key']]['pay_type'] ?? 'regular';
            $p['paid_by'] = $rates[$p['key']]['paid_by'] ?? 'qb';
            $p['rate_cfg'] = $rates[$p['key']] ?? [];
        }
        unset($p);
        return $people;
    }

    // What to type into QuickBooks, one block per company. Anyone paid by
    // Zelle is listed separately (they never go through QB payroll).
    private function qbBlocks(array $people)
    {
        $out = [];
        foreach (self::COMPANIES as $co => $label) {
            $rows = []; $zelle = [];
            foreach ($people as $p) {
                $l = $p['lines'][$co] ?? null;
                if (!$l) { continue; }
                $any = false;
                foreach ($l as $v) { if (abs($v) > 0.004) { $any = true; break; } }
                if (!$any) { continue; }
                $row = ['name' => $p['name'], 'pay_type' => $p['pay_type'], 'gross' => $p['gross_by_company'][$co] ?? 0] + $l;
                if ($p['paid_by'] === 'zelle') { $zelle[] = $row; } else { $rows[] = $row; }
            }
            usort($rows, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $out[$co] = ['label' => $label, 'rows' => $rows, 'zelle' => $zelle];
        }
        return $out;
    }

    // Listening-party shares this payroll would pay, per person, each tied to
    // the company of the store the party was at.
    private function attachParty(array $people, $businessId)
    {
        try {
            $shares = $this->cachedPull('party', $businessId, function () use ($businessId) {
                return app(ListingCommissionController::class)->unpaidPartySharesByUser($businessId);
            });
        } catch (\Throwable $e) {
            \Log::warning('payroll party pull failed: ' . $e->getMessage());
            $shares = [];
        }
        foreach ($people as &$p) {
            $p['party_comm'] = 0.0;
            $p['party_lines'] = [];
            $uid = (int) ($p['user_id'] ?? 0);
            if ($uid && isset($shares[$uid])) {
                foreach ($shares[$uid]['parties'] as $pt) {
                    $p['party_lines'][] = [
                        'company' => stripos((string) $pt['store'], 'pico') !== false ? 'one' : 'two',
                        'amount'  => round((float) $pt['amount'], 2),
                        'label'   => $pt['event'] . ' (' . $pt['store'] . ', ' . \Carbon::parse($pt['date'])->format('m/d/y') . ')',
                    ];
                }
                $p['party_comm'] = round((float) $shares[$uid]['amount'], 2);
            }
        }
        unset($p);
        return $people;
    }

    // Cross-checks that caught real mistakes on the 10/1-10/14 run: punches
    // left open, a register opened well before the clock-in (Zak 10/9), a
    // register run with no clock-in at all, and a Sling shift nobody clocked.
    // Since 10/29 (Sarah) they show in the shift log's Comments next to the
    // shift they belong to, not in a separate box; a day with a problem but
    // no shift (missing punch) gets its own comment-only row. Also flags
    // shifts over 10 hours and shifts that aren't on the Sling schedule.
    private function attachChecks(array $people, $businessId, $start, $end)
    {
        $byUid = [];
        foreach ($people as $i => $p) { if (!empty($p['user_id'])) { $byUid[(int) $p['user_id']] = $i; } }
        foreach ($people as &$p) { $p['checks'] = $p['checks'] ?? []; }
        unset($p);
        if (empty($byUid)) { return $people; }

        $today = now()->toDateString();
        $dayFlags = [];
        $add = function ($i, $day, $text) use (&$people, &$dayFlags) {
            $dayFlags[$i][$day][] = $text;
            $people[$i]['checks'][] = $text;
        };

        try {
            if (\App\Services\TimeClockService::ready()) {
                $open = DB::table('time_clock_punches')->where('business_id', $businessId)->whereNull('voided_at')
                    ->whereNull('clock_out_at')->whereBetween('clock_in_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
                    ->get(['user_id', 'clock_in_at']);
                foreach ($open as $o) {
                    // Clocked in today = still on shift, not a problem yet.
                    if (substr((string) $o->clock_in_at, 0, 10) >= $today) { continue; }
                    if (isset($byUid[(int) $o->user_id])) {
                        $add($byUid[(int) $o->user_id], substr((string) $o->clock_in_at, 0, 10),
                            'Clocked in ' . \Carbon::parse($o->clock_in_at)->format('g:ia') . ' but never clocked out (not counted until fixed on Timecards)');
                    }
                }
            }
        } catch (\Throwable $e) { \Log::warning('payroll open-punch check failed: ' . $e->getMessage()); }

        try {
            $regs = DB::table('cash_registers')->where('business_id', $businessId)->whereNotNull('user_id')
                ->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->get(['user_id', 'created_at', 'closed_at']);
            foreach ($regs as $r) {
                $uid = (int) $r->user_id;
                if (!isset($byUid[$uid])) { continue; }
                $i = $byUid[$uid];
                $day = substr((string) $r->created_at, 0, 10);
                if ($day >= $today) { continue; } // today's shift isn't finished yet
                $firstIn = $people[$i]['first_in'][$day] ?? null;
                $openT = \Carbon::parse($r->created_at);
                if (!$firstIn) {
                    // Also for people with no hours at all (Willy 10/16 ran a
                    // register with no punch and never showed up here).
                    $add($i, $day, 'Ran a register ' . $openT->format('g:ia') . ($r->closed_at ? '-' . \Carbon::parse($r->closed_at)->format('g:ia') : '') . ' but no hours that day');
                } elseif ($openT->lt(\Carbon::parse($firstIn)->subMinutes(20))) {
                    $add($i, $day, 'Register opened ' . $openT->format('g:ia') . ', first clock-in ' . \Carbon::parse($firstIn)->format('g:ia'));
                }
            }
        } catch (\Throwable $e) { \Log::warning('payroll register check failed: ' . $e->getMessage()); }

        // Refused punches (not at the store / too early): show when they tried,
        // so a person blocked by the clock is paid from when they really
        // started or stopped. Several tries in a row show once.
        try {
            $seen = [];
            foreach (\App\Services\TimeClockService::blockedAttempts() as $b) {
                $uid = (int) ($b['user_id'] ?? 0);
                $day = substr((string) ($b['at'] ?? ''), 0, 10);
                if (!isset($byUid[$uid]) || $day < $start || $day > $end) { continue; }
                $k = $uid . '|' . $day . '|' . ($b['doing'] ?? '') . '|' . substr((string) $b['at'], 11, 4);
                if (isset($seen[$k])) { continue; }
                $seen[$k] = true;
                $add($byUid[$uid], $day, 'Tried to clock ' . ($b['doing'] ?? 'in') . ' at ' . \Carbon::parse($b['at'])->format('g:ia') . ' but the clock refused (' . rtrim((string) ($b['reason'] ?? ''), '.') . ')');
            }
        } catch (\Throwable $e) { \Log::warning('payroll blocked-punch check failed: ' . $e->getMessage()); }

        foreach ($byUid as $uid => $i) {
            foreach ($this->scheduleCache[$uid] ?? [] as $day => $s) {
                if ($day > $end || $day >= $today) { continue; }
                // Under an hour on Sling = a quick check-in (Zak's weekend
                // 2:45-3:15), not a shift anyone clocks in for.
                if (!empty($s['start']) && !empty($s['end']) && strtotime($s['end']) - strtotime($s['start']) < 3600) { continue; }
                if (!isset($people[$i]['first_in'][$day])) {
                    $add($i, $day, 'On Sling ' . \Carbon::parse($s['start'])->format('g:ia')
                        . ($s['end'] ? '-' . \Carbon::parse($s['end'])->format('g:ia') : '') . ' but no hours');
                }
            }
            foreach ($people[$i]['log'] ?? [] as $lg) {
                if ((float) $lg['hours'] > 10) {
                    $add($i, $lg['day'], 'Over 10 hours, check the clock-in/out');
                }
                if (!empty($lg['in']) && !isset($this->scheduleCache[$uid][$lg['day']])) {
                    $add($i, $lg['day'], 'Not on Sling this day');
                }
            }
        }

        // Put each day's flags on that day's first shift row; a day with no
        // shift gets a comment-only row, kept in date order.
        foreach ($dayFlags as $i => $days) {
            $log = $people[$i]['log'] ?? [];
            foreach ($days as $day => $texts) {
                $texts = array_values(array_unique($texts));
                $hit = false;
                foreach ($log as $k => $lg) {
                    if ($lg['day'] === $day) { $log[$k]['flags'] = array_merge($log[$k]['flags'] ?? [], $texts); $hit = true; break; }
                }
                if (!$hit) {
                    $log[] = ['idx' => null, 'day' => $day, 'in' => null, 'out' => null, 'hours' => 0, 'store' => '', 'company' => '', 'comment' => '', 'flags' => $texts];
                }
            }
            $order = array_keys($log);
            usort($order, function ($a, $b) use ($log) { return strcmp($log[$a]['day'], $log[$b]['day']) ?: $a <=> $b; });
            $people[$i]['log'] = array_map(function ($k) use ($log) { return $log[$k]; }, $order);
        }
        return $people;
    }

    // Free-text notes per person per period (Fatteen can jot anything). Keyed by
    // the person's row key. Separate JSON file per period, no migration.
    private function notesFile($start, $end) { return 'payroll/notes-' . $start . '_' . $end . '.json'; }

    private function loadNotes($start, $end)
    {
        $f = $this->notesFile($start, $end);
        if (!Storage::disk('local')->exists($f)) { return []; }
        $d = json_decode(Storage::disk('local')->get($f), true);
        return is_array($d) ? $d : [];
    }

    public function saveNotes(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $out = [];
        foreach ((array) $request->input('notes', []) as $k => $v) {
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
            $v = trim((string) $v);
            if ($k !== '' && $v !== '') { $out[$k] = mb_substr($v, 0, 500); }
        }
        Storage::disk('local')->put($this->notesFile($start, $end), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($request->ajax()) { return response()->json(['ok' => true]); }
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Saved notes.']);
    }

    // Active ERP staff as [{id, name}] for the "link to ERP user" dropdown in
    // Rates & settings, so Sarah picks a person by name instead of hunting for
    // a numeric id. Active = allow_login on, not soft-deleted.
    private function erpUserOptions($businessId)
    {
        return DB::table('users')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->where('allow_login', 1)
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'surname'])
            ->map(function ($u) {
                $nm = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $u->id));
                return ['id' => (int) $u->id, 'name' => $nm];
            })->values()->all();
    }

    // Whether the current user may see explicit pay: hourly rates, wages, and
    // the Rates & settings editor. Owners only (whitelist by first name) so
    // whoever PREPS payroll (e.g. Fatteen / "Nerdy Solutions") can still enter
    // hours + commissions into QuickBooks without seeing what people are paid.
    private function canSeeRates()
    {
        return $this->isPayrollUser();
    }

    // ---- Hours import ----------------------------------------------------

    // Accepts either a pasted block of text (CSV/TSV, e.g. straight from the
    // Clover timecard export or Sarah's sheet) or an uploaded .csv file. Stores
    // the parsed punches for the period; the page recomputes live so editing a
    // rate reflects immediately without re-importing.
    public function importHours(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);

        $raw = (string) $request->input('paste', '');
        if ($request->hasFile('file')) {
            $raw = (string) file_get_contents($request->file('file')->getRealPath());
        }
        $raw = trim($raw);
        if ($raw === '') {
            return redirect($this->url($start, $end))
                ->with('status', ['success' => 0, 'msg' => 'Nothing to import — paste the Clover hours or choose a file.']);
        }

        $parsed = $this->parsePunches($raw);
        if (empty($parsed['rows'])) {
            return redirect($this->url($start, $end))->with('status', [
                'success' => 0,
                'msg' => 'Could not find any clock in/out rows. Expected columns like Name, Clock In Date, Clock In Time, Clock Out Date, Clock Out Time.',
            ]);
        }

        // Auto-seed a rate entry for any new name so it appears in the rate
        // editor (rate 0 until Sarah sets it).
        $config = $this->loadConfig();
        foreach ($parsed['rows'] as $r) {
            $key = $this->nameKey($r['name']);
            if ($key !== '' && !isset($config['rates'][$key])) {
                $config['rates'][$key] = [
                    'rate' => 0, 'user_id' => null,
                    'store' => $r['location'] ?? '', 'display_name' => $r['name'],
                ];
            }
        }
        $this->saveConfig($config);

        $this->saveHours($start, $end, [
            'rows'        => $parsed['rows'],
            'imported_at' => now()->toDateTimeString(),
            'imported_by' => $request->session()->get('user.id'),
        ]);

        $msg = 'Imported ' . count($parsed['rows']) . ' punches for '
            . count(array_unique(array_map(function ($r) { return $this->nameKey($r['name']); }, $parsed['rows'])))
            . ' people.';
        if (!empty($parsed['skipped'])) {
            $msg .= ' Skipped ' . $parsed['skipped'] . ' unreadable row(s).';
        }
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Load this period's hours from the ERP time clock (time_clock_punches)
    // instead of a Clover paste (Sarah 2026-10-13). Produces the same row
    // shape as parsePunches, so OT, late flags and the QB export work the
    // same. Replaces this period's imported hours, like a re-import does.
    public function loadErpHours(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $businessId = (int) $request->session()->get('user.business_id');

        if (!\App\Services\TimeClockService::ready()) {
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => 'The ERP time clock is not set up yet (the migration has not run). Use the Clover import for now.']);
        }

        $got = $this->erpHoursRows($businessId, $start, $end);
        if ($got === null) {
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => 'Could not read the ERP time clock. Use the Clover import for now.']);
        }
        [$rows, $missing, $config] = $got;

        if (empty($rows)) {
            $msg = 'No finished ERP clock-ins in this period yet.';
            if ($missing) { $msg .= ' ' . $missing . ' punch(es) have no clock-out; fix them on Timecards.'; }
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => $msg]);
        }

        $this->saveConfig($config);
        $this->saveHours($start, $end, [
            'rows'        => $rows,
            'source'      => 'erp_time_clock',
            'imported_at' => now()->toDateTimeString(),
            'imported_by' => $request->session()->get('user.id'),
        ]);

        $msg = 'Loaded ' . count($rows) . ' time clock punches for '
            . count(array_unique(array_map(function ($r) { return $this->nameKey($r['name']); }, $rows))) . ' people.';
        if ($missing) {
            $msg .= ' Left out ' . $missing . ' punch(es) with no clock-out. Fix them on Timecards and load again.';
        }
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Time clock punches for the period as hours rows (same shape as a
    // Clover import). Returns [rows, missing clock-outs, config] or null if
    // the time clock could not be read.
    private function erpHoursRows($businessId, $start, $end)
    {
        try {
            $punches = DB::table('time_clock_punches as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('business_locations as l', 'l.id', '=', 'p.location_id')
            ->where('p.business_id', $businessId)
            ->whereNull('p.voided_at')
            // From the day before: a shift that started the night before the
            // period and ran past midnight has hours inside the period.
            ->where('p.clock_in_at', '>=', \Carbon::parse($start)->subDay()->toDateString() . ' 00:00:00')
            ->where('p.clock_in_at', '<=', $end . ' 23:59:59')
            ->orderBy('p.clock_in_at')
            ->get(['p.*', 'u.first_name', 'u.last_name', 'l.name as location_name']);
        } catch (\Throwable $e) {
            \Log::warning('payroll load ERP hours failed: ' . $e->getMessage());
            return null;
        }

        $config = $this->loadConfig();
        // ERP user id -> existing rate key, so hours land on the person's
        // saved rate even when the rate entry was keyed off a Clover name.
        $keyByUid = [];
        foreach ($config['rates'] as $key => $rc) {
            if (!empty($rc['user_id'])) { $keyByUid[(int) $rc['user_id']] = $key; }
        }

        $rows = [];
        $missing = 0;
        // Schedule flags per punch, same rules as Timecards (Sarah 10/13):
        // late in (saved at clock-in) and stayed past the shift end. Info
        // only; recorded hours are never trimmed (CA pays all time worked).
        $blockCache = [];
        $blocksFrom = \Carbon::parse($start)->subDays(2)->format('Y-m-d H:i:s');
        $blocksTo = \Carbon::parse($end)->addDay()->format('Y-m-d 23:59:59');
        foreach ($punches as $p) {
            $inPeriod = substr($p->clock_in_at, 0, 10) >= $start;
            if (!$p->clock_out_at) { if ($inPeriod) { $missing++; } continue; }
            $sec = strtotime($p->clock_out_at) - strtotime($p->clock_in_at);
            if ($sec <= 0) { continue; }
            $uid = (int) $p->user_id;
            if (isset($keyByUid[$uid])) {
                $rk = $keyByUid[$uid];
                $name = $config['rates'][$rk]['display_name'] ?? trim($p->first_name . ' ' . $p->last_name);
                // The row must land on that same rate key.
                if ($this->nameKey($name) !== $rk) { $name = ucfirst($rk) . ' ' . trim((string) $p->last_name); }
            } else {
                $name = trim($p->first_name . ' ' . $p->last_name) ?: ('User ' . $uid);
                $key = $this->nameKey($name);
                if ($key !== '' && !isset($config['rates'][$key])) {
                    $config['rates'][$key] = ['rate' => 0, 'user_id' => $uid, 'store' => (string) $p->location_name, 'display_name' => $name];
                } elseif ($key !== '' && empty($config['rates'][$key]['user_id'])) {
                    $config['rates'][$key]['user_id'] = $uid;
                }
            }
            // Daily OT is per calendar day (midnight to midnight, LA): a punch
            // past midnight is split so each day gets its own hours. The
            // first part keeps the real clock-in (late check), the last part
            // the real clock-out. strtotime runs in the app timezone (LA), so
            // DST nights count real elapsed time.
            $tcFlags = [];
            try {
                $dayLabel = \Carbon::parse($p->clock_in_at)->format('D M j');
                if (!empty($p->late_min) && (int) $p->late_min > 0) {
                    $tcFlags[] = $dayLabel . ': in ' . (int) $p->late_min . 'm late';
                }
                $storeShort = \App\Services\TimeClockService::shortStoreName((string) $p->location_name);
                $bk = $uid . '|' . $storeShort;
                if (!isset($blockCache[$bk])) {
                    $blockCache[$bk] = \App\Services\TimeClockService::scheduleBlocks($uid, $blocksFrom, $blocksTo, $storeShort);
                }
                $bf = \App\Services\TimeClockService::blockFlags($blockCache[$bk], (string) $p->clock_in_at, (string) $p->clock_out_at);
                if ($bf['stayed_min']) {
                    $tcFlags[] = $dayLabel . ': stayed ' . $bf['stayed_min'] . ' min past shift';
                }
            } catch (\Throwable $e) {
                \Log::warning('payroll time clock flags failed: ' . $e->getMessage());
            }
            $inTs = strtotime($p->clock_in_at);
            $outTs = strtotime($p->clock_out_at);
            $cur = $inTs;
            $flagsGiven = false;
            $guard = 0;
            while ($cur < $outTs && $guard++ < 4) {
                $day = date('Y-m-d', $cur);
                $next = strtotime($day . ' 00:00:00 +1 day');
                $partEnd = min($outTs, $next);
                if ($day >= $start && $day <= $end && $partEnd > $cur) {
                    $rows[] = [
                        'name'     => $name,
                        'in'       => $cur === $inTs ? $p->clock_in_at : null,
                        'out'      => $partEnd === $outTs ? $p->clock_out_at : null,
                        'in_date'  => $day,
                        'elapsed'  => round(($partEnd - $cur) / 3600, 4),
                        'location' => \App\Services\TimeClockService::shortStoreName((string) $p->location_name),
                        'comment'  => trim(($p->edited ? 'edited in Timecards' : '') . ($outTs > $next || $cur !== $inTs ? ' split at midnight' : '')),
                        'tc_flags' => $flagsGiven ? [] : $tcFlags,
                    ];
                    $flagsGiven = true;
                }
                $cur = $partEnd;
            }
        }

        return [$rows, $missing, $config];
    }

    // Hours fill in by themselves from the ERP time clock: a period with no
    // hours yet, or one still on untouched time clock hours, reloads when the
    // page opens (at most every 5 min). Hand-edited or Clover-imported hours
    // are never overwritten.
    private function autoLoadErpHours($businessId, $start, $end)
    {
        try {
            if (!\App\Services\TimeClockService::ready() || $start > now()->toDateString()) { return; }
            $doc = $this->loadHours($start, $end);
            $has = !empty($doc['rows']);
            if ($has && (($doc['source'] ?? '') !== 'erp_time_clock' || !empty($doc['edited']))) { return; }
            if ($has && !empty($doc['imported_at']) && \Carbon::parse($doc['imported_at'])->gt(now()->subMinutes(5))) { return; }
            $got = $this->erpHoursRows($businessId, $start, $end);
            if ($got === null || empty($got[0])) { return; }
            $this->saveConfig($got[2]);
            $this->saveHours($start, $end, [
                'rows' => $got[0], 'source' => 'erp_time_clock', 'auto' => true,
                'imported_at' => now()->toDateTimeString(), 'imported_by' => null,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('payroll auto-load hours failed: ' . $e->getMessage());
        }
    }

    // Edit, add or delete one shift-log row right on the payroll page (for
    // Fatteen's review). Dates are typed mm/dd/yy, times like 3:31 PM. Once
    // hours are edited by hand they stop auto-reloading from the time clock.
    public function saveShift(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $doc = $this->loadHours($start, $end);
        $rows = $doc['rows'] ?? [];
        $action = (string) $request->input('action', 'save');
        $idx = (int) $request->input('idx', -1);
        $back = function ($ok, $msg, $hours = null) use ($start, $end, $request) {
            if ($request->ajax()) { return response()->json(['ok' => (bool) $ok, 'msg' => $msg, 'hours' => $hours]); }
            return redirect($this->url($start, $end) . '#shift-log')->with('status', ['success' => $ok ? 1 : 0, 'msg' => $msg]);
        };

        // Overnight shift shown as one row: its second piece (after midnight)
        // is replaced by the edited whole shift, or deleted with it.
        $idx2 = $request->input('idx2', '') !== '' ? (int) $request->input('idx2') : -1;
        $dropSecond = function (array $rows) use ($idx2, $idx) {
            if ($idx2 >= 0 && $idx2 !== $idx && isset($rows[$idx2])) { unset($rows[$idx2]); }
            return $rows;
        };
        if ($action === 'delete') {
            if (!isset($rows[$idx])) { return $back(false, 'That shift was not found. Reload the page and try again.'); }
            $rows = $dropSecond($rows); $rows = array_values($rows); $idx = $idx - ($idx2 >= 0 && $idx2 < $idx ? 1 : 0);
            $gone = $rows[$idx];
            array_splice($rows, $idx, 1);
            $msg = 'Deleted ' . $gone['name'] . "'s shift" . ($gone['in'] ? ' from ' . \Carbon::parse($gone['in'])->format('m/d/y g:ia') : '') . '.';
        } else {
            $parse = function ($d, $t) {
                $d = trim((string) $d); $t = strtoupper(trim((string) $t));
                if ($d === '' || $t === '') { return null; }
                foreach (['m/d/y g:i A', 'm/d/y g:i:s A', 'm/d/Y g:i A', 'm/d/Y g:i:s A', 'm/d/y H:i', 'Y-m-d H:i', 'Y-m-d g:i A'] as $f) {
                    try { $c = \Carbon::createFromFormat($f, $d . ' ' . $t); if ($c) { return $c; } } catch (\Throwable $e) {}
                }
                try { return \Carbon::parse($d . ' ' . $t); } catch (\Throwable $e) { return null; }
            };
            $in = $parse($request->input('in_date'), $request->input('in_time'));
            $out = $parse($request->input('out_date') ?: $request->input('in_date'), $request->input('out_time'));
            if (!$in || !$out) { return $back(false, 'Could not read that date or time. Use mm/dd/yy and a time like 3:31 PM.'); }
            if ($out->lte($in)) { return $back(false, 'Clock out has to be after clock in.'); }
            $hours = round(($out->timestamp - $in->timestamp) / 3600, 4);
            if ($hours > 16) { return $back(false, 'That shift is over 16 hours. Check the dates.'); }
            $row = [
                'in' => $in->toDateTimeString(), 'out' => $out->toDateTimeString(), 'in_date' => $in->toDateString(),
                'elapsed' => $hours, 'location' => (string) $request->input('location', ''),
                'comment' => trim((string) $request->input('comment', '')),
            ];
            if ($action === 'add') {
                $name = trim((string) $request->input('name', ''));
                if ($name === '') { return $back(false, 'Pick who the shift is for.'); }
                $rows[] = ['name' => $name] + $row;
                $msg = 'Added a ' . number_format($hours, 2) . ' hr shift for ' . $name . ' on ' . $in->format('m/d/y') . '.';
            } else {
                if (!isset($rows[$idx])) { return $back(false, 'That shift was not found. Reload the page and try again.'); }
                $rows[$idx] = array_merge($rows[$idx], $row);
                unset($rows[$idx]['tc_flags']);
                $rows = $dropSecond($rows);
                $msg = 'Saved ' . $rows[$idx]['name'] . "'s " . $in->format('m/d/y') . ' shift: ' . number_format($hours, 2) . ' hrs.';
            }
        }
        $u = auth()->user();
        $doc['rows'] = array_values($rows);
        $doc['edited'] = true;
        $doc['edited_at'] = now()->toDateTimeString();
        $doc['edited_by'] = trim($u->first_name . ' ' . $u->last_name);
        $this->saveHours($start, $end, $doc);
        return $back(true, $msg, isset($hours) ? round($hours, 2) : null);
    }

    // ---- Rate + settings editor -----------------------------------------

    public function saveRates(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->canSeeRates()) { abort(403, 'Only owners can change pay rates.'); }
        [$start, $end] = $this->resolvePeriod($request);

        $config = $this->loadConfig();
        $inRates   = (array) $request->input('rate', []);      // name_key => rate
        $inUsers   = (array) $request->input('user_id', []);   // name_key => erp user id
        $inStores  = (array) $request->input('store', []);     // name_key => store

        foreach ($inRates as $key => $rate) {
            $key = $this->nameKey($key);
            if ($key === '') { continue; }
            if (!isset($config['rates'][$key])) {
                $config['rates'][$key] = ['rate' => 0, 'user_id' => null, 'store' => '', 'display_name' => ucfirst($key)];
            }
            $config['rates'][$key]['rate']    = round((float) $rate, 4);
            $config['rates'][$key]['user_id'] = ($inUsers[$key] ?? '') !== '' ? (int) $inUsers[$key] : null;
            $config['rates'][$key]['store']   = trim((string) ($inStores[$key] ?? $config['rates'][$key]['store'] ?? ''));
            $co = (string) (((array) $request->input('company', []))[$key] ?? 'auto');
            $config['rates'][$key]['company'] = isset(self::COMPANIES[$co]) ? $co : 'auto';
            $pt = (string) (((array) $request->input('pay_type', []))[$key] ?? 'regular');
            $config['rates'][$key]['pay_type'] = $pt === 'manager' ? 'manager' : 'regular';
            $pb = (string) (((array) $request->input('paid_by', []))[$key] ?? 'qb');
            $config['rates'][$key]['paid_by'] = $pb === 'zelle' ? 'zelle' : 'qb';
        }

        // OT / grace settings.
        $config['settings'] = [
            'daily_ot_after' => max(0, (float) $request->input('daily_ot_after', self::DEFAULTS['daily_ot_after'])),
            'daily_dt_after' => max(0, (float) $request->input('daily_dt_after', self::DEFAULTS['daily_dt_after'])),
            'ot_multiplier'  => max(1, (float) $request->input('ot_multiplier', self::DEFAULTS['ot_multiplier'])),
            'dt_multiplier'  => max(1, (float) $request->input('dt_multiplier', self::DEFAULTS['dt_multiplier'])),
            'grace_minutes'  => max(0, (int) $request->input('grace_minutes', self::DEFAULTS['grace_minutes'])),
        ];

        $this->saveConfig($config);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Saved rates & settings.']);
    }

    // ---- Freelancers -----------------------------------------------------

    public function saveFreelancer(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);

        $config = $this->loadConfig();
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));

        $entry = [
            'id'     => $id ?: bin2hex(random_bytes(8)),
            'name'   => trim((string) $request->input('name')),
            'model'  => in_array($request->input('model'), ['hourly', 'flat', 'units']) ? $request->input('model') : 'flat',
            'rate'   => round((float) $request->input('f_rate', 0), 4),   // $/hr or $/unit
            'qty'    => round((float) $request->input('qty', 0), 4),      // hours or unit count
            'amount' => round((float) $request->input('amount', 0), 2),   // used when model = flat
            'method' => trim((string) $request->input('method')),         // paypal / payment link / etc.
            // Older Laravel here has no Request::boolean() — cast manually.
            'paid'   => filter_var($request->input('paid'), FILTER_VALIDATE_BOOLEAN),
            'note'   => trim((string) $request->input('note')),
        ];
        if ($entry['name'] === '') {
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => 'Freelancer needs a name.']);
        }

        $found = false;
        foreach ($config['freelancers'] as &$f) {
            if (($f['id'] ?? '') === $entry['id']) { $f = $entry; $found = true; break; }
        }
        unset($f);
        if (!$found) { $config['freelancers'][] = $entry; }

        $this->saveConfig($config);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Saved freelancer ' . $entry['name'] . '.']);
    }

    public function deleteFreelancer(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));

        $config = $this->loadConfig();
        $config['freelancers'] = array_values(array_filter($config['freelancers'], function ($f) use ($id) {
            return ($f['id'] ?? '') !== $id;
        }));
        $this->saveConfig($config);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Freelancer removed.']);
    }

    // ---- QuickBooks CSV export ------------------------------------------

    public function exportCsv(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $request->session()->get('user.business_id');
        [$start, $end] = $this->resolvePeriod($request);

        [$people] = $this->assemble($businessId, $start, $end);
        $qb = $this->qbBlocks($people);

        $filename = 'payroll-qb-' . $start . '_to_' . $end . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->stream(function () use ($qb) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Company', 'Paid by', 'Name', 'Regular Hours', 'Manager Hours', 'OT Hours', 'Listening Party Commission', 'Listing Commission', 'Sales Commission', 'Back Pay', 'Bonus', 'Other Pay']);
            $n = function ($v) { return number_format((float) $v, 2, '.', ''); };
            foreach ($qb as $co => $block) {
                foreach (['QuickBooks' => $block['rows'], 'Zelle' => $block['zelle']] as $by => $rows) {
                    foreach ($rows as $r) {
                        $mgr = $r['pay_type'] === 'manager';
                        fputcsv($out, [$block['label'], $by, $r['name'],
                            $mgr ? '' : $n($r['reg']), $mgr ? $n($r['reg']) : '', $n($r['ot']),
                            $n($r['party']), $n($r['listing']), $n($r['sales']), $n($r['back_pay']), $n($r['bonus']), $n($r['other'])]);
                    }
                }
            }
            fclose($out);
        }, 200, $headers);
    }

    // ---- Computation helpers --------------------------------------------

    // Group each person's punches by workday, split reg/ot/dt hours, price at
    // their rate, and gather late flags against the Sling schedule.
    private function buildPeople(array $rows, array $rates, array $settings, array $schedule)
    {
        // name_key => ['days' => [date => hours], 'store', 'flags', 'user_id', 'rate', 'name']
        $byPerson = [];
        foreach ($rows as $idx => $r) {
            // Defensive: never treat a date-looking value as a person (guards
            // against hours imported before the parser learned to skip the
            // per-day date rows — those rows are dropped until a re-import
            // re-attributes them to the right person).
            if ($this->looksLikeDate($r['name']) || is_numeric(trim((string) $r['name']))) { continue; }
            $key = $this->nameKey($r['name']);
            if ($key === '') { continue; }

            if (!isset($byPerson[$key])) {
                $rc = $rates[$key] ?? [];
                $byPerson[$key] = [
                    'key'     => $key,
                    'name'    => $rates[$key]['display_name'] ?? $r['name'],
                    'user_id' => isset($rc['user_id']) ? $rc['user_id'] : null,
                    'rate'    => (float) ($rc['rate'] ?? 0),
                    'store'   => $rc['store'] ?? ($r['location'] ?? ''),
                    'days'    => [],
                    'flags'   => [],
                ];
            }

            // Group hours by workday for the daily-OT split. If a row has no
            // usable date, give it its own bucket so it can't merge with other
            // days and fake overtime.
            $day = $r['in_date'] ?: ($r['in'] ? substr((string) $r['in'], 0, 10) : ('row-' . $idx));
            $hrs = (float) $r['elapsed'];
            $byPerson[$key]['days'][$day] = ($byPerson[$key]['days'][$day] ?? 0) + $hrs;

            // Late detection for this punch against the schedule. ERP time
            // clock rows carry their own flags (same rules as Timecards).
            if (isset($r['tc_flags']) && is_array($r['tc_flags'])) {
                foreach ($r['tc_flags'] as $tf) { $byPerson[$key]['flags'][] = (string) $tf; }
            } else {
                $flag = $this->lateFlag($byPerson[$key]['user_id'], $r, $settings['grace_minutes']);
                if ($flag) { $byPerson[$key]['flags'][] = $flag; }
            }
        }

        // Per-company split + shift log. Rows are walked in time order per day:
        // the first 8h of a day are regular, anything after is OT, and each
        // slice lands on the company of the store it was worked at.
        $otAfter = (float) $settings['daily_ot_after'];
        if ($otAfter <= 0) { $otAfter = 8; }
        $rowsByKey = [];
        foreach ($rows as $idx => $r) {
            if ($this->looksLikeDate($r['name']) || is_numeric(trim((string) $r['name']))) { continue; }
            $k = $this->nameKey($r['name']);
            if ($k === '' || !isset($byPerson[$k])) { continue; }
            $day = $r['in_date'] ?: ($r['in'] ? substr((string) $r['in'], 0, 10) : ('row-' . $idx));
            $rowsByKey[$k][] = ['idx' => $idx, 'day' => $day, 'sort' => $day . ' ' . ($r['in'] ? substr((string) $r['in'], 11) : '00:00:00') . sprintf('%06d', $idx), 'r' => $r];
        }
        foreach ($byPerson as $key => &$bp) {
            $rc = $rates[$key] ?? [];
            $bp['co'] = [];
            foreach (array_keys(self::COMPANIES) as $c) { $bp['co'][$c] = ['reg' => 0.0, 'ot' => 0.0]; }
            $bp['log'] = [];
            $bp['first_in'] = [];
            $list = $rowsByKey[$key] ?? [];
            usort($list, function ($a, $b) { return strcmp($a['sort'], $b['sort']); });
            $dayAcc = [];
            foreach ($list as $it) {
                $r = $it['r']; $day = $it['day'];
                $h = (float) $r['elapsed'];
                $co = $this->companyFor($rc, $r['location'] ?? '');
                $before = $dayAcc[$day] ?? 0.0;
                $regPart = max(0.0, min($h, $otAfter - $before));
                $bp['co'][$co]['reg'] += $regPart;
                $bp['co'][$co]['ot'] += $h - $regPart;
                $dayAcc[$day] = $before + $h;
                if (!empty($r['in']) && (!isset($bp['first_in'][$day]) || $r['in'] < $bp['first_in'][$day])) { $bp['first_in'][$day] = $r['in']; }
                // An overnight shift is stored as two pieces (split at midnight
                // so each day's OT is right). Show it as ONE row like the sheet.
                $lastI = count($bp['log']) - 1;
                if ($lastI >= 0 && empty($r['in']) && empty($bp['log'][$lastI]['out']) && !isset($bp['log'][$lastI]['idx2'])) {
                    $bp['log'][$lastI]['out'] = $r['out'] ?? null;
                    $bp['log'][$lastI]['hours'] = round($bp['log'][$lastI]['hours'] + $h, 2);
                    $bp['log'][$lastI]['idx2'] = $it['idx'];
                    $bp['log'][$lastI]['comment'] = trim(str_replace('split at midnight', '', $bp['log'][$lastI]['comment']));
                    continue;
                }
                $bp['log'][] = [
                    'idx' => $it['idx'], 'day' => $day, 'in' => $r['in'] ?? null, 'out' => $r['out'] ?? null, 'hours' => round($h, 2),
                    'store' => (string) ($r['location'] ?? ''), 'company' => $co, 'comment' => (string) ($r['comment'] ?? ''),
                ];
            }
        }
        unset($bp);

        $people = [];
        foreach ($byPerson as $key => $p) {
            $reg = $ot = $dt = 0.0;
            foreach ($p['days'] as $dayHours) {
                [$dReg, $dOt, $dDt] = $this->splitDay((float) $dayHours, $settings);
                $reg += $dReg; $ot += $dOt; $dt += $dDt;
            }
            // Home company: a person override, else wherever most hours were.
            $rcHome = $rates[$key]['company'] ?? 'auto';
            if (isset(self::COMPANIES[$rcHome])) {
                $home = $rcHome;
            } else {
                $home = (($p['co']['one']['reg'] + $p['co']['one']['ot']) > ($p['co']['two']['reg'] + $p['co']['two']['ot'])) ? 'one' : 'two';
            }
            $rate = (float) $p['rate'];
            $wages = $reg * $rate
                + $ot * $rate * (float) $settings['ot_multiplier']
                + $dt * $rate * (float) $settings['dt_multiplier'];

            [$fn, $ln] = $this->splitName($p['name']);
            $people[$key] = [
                'key'          => $key,
                'name'         => $p['name'],
                'first_name'   => $fn,
                'last_name'    => $ln,
                'user_id'      => $p['user_id'],
                'store'        => $p['store'],
                'rate'         => $rate,
                'reg_hours'    => round($reg, 2),
                'ot_hours'     => round($ot, 2),
                'dt_hours'     => round($dt, 2),
                'total_hours'  => round($reg + $ot + $dt, 2),
                'wages'        => round($wages, 2),
                'sales_comm'   => 0.0,
                'listing_comm' => 0.0,
                'comm_earned'  => 0.0,
                'last_comm_paid' => null,
                'last_paycheck'  => null,
                'memo'         => '',
                'grand_total'  => round($wages, 2),
                'flags'        => $p['flags'],
                'has_hours'    => true,
                'co'           => $p['co'],
                'log'          => $p['log'],
                'first_in'     => $p['first_in'],
                'home_company' => $home,
                'one_hours'    => round($p['co']['one']['reg'] + $p['co']['one']['ot'], 2),
                'two_hours'    => round($p['co']['two']['reg'] + $p['co']['two']['ot'], 2),
            ];
        }
        return $people;
    }

    // Daily split for one workday's hours: anything over the daily OT threshold
    // (8h) is overtime at 1.5x. No double-time — Nivessa just pays OT over 8/day.
    private function splitDay($hours, array $s)
    {
        // Guard: a blank/zero threshold must not turn every hour into OT.
        $otAfter = (float) $s['daily_ot_after'];
        if ($otAfter <= 0) { $otAfter = 8; }
        $reg = min($hours, $otAfter);
        $ot  = max(0, $hours - $otAfter);
        return [$reg, $ot, 0.0];
    }

    // Compare one punch to the person's scheduled shift that day. Returns a
    // short human string if late in / late out past grace, else null.
    private function lateFlag($userId, array $r, $graceMin)
    {
        if (!$userId) { return null; }
        $day = $r['in_date'] ?: substr((string) $r['in'], 0, 10);
        if (!isset($this->scheduleCache[$userId][$day])) { return null; }
        $sched = $this->scheduleCache[$userId][$day];

        try {
            $grace = (int) $graceMin;
            $parts = [];

            if (!empty($r['in']) && !empty($sched['start'])) {
                $in   = \Carbon::parse($r['in']);
                $sched_start = \Carbon::parse($sched['start']);
                $lateMin = $sched_start->diffInMinutes($in, false); // + if in is after start
                if ($lateMin > $grace) {
                    $parts[] = 'in ' . $lateMin . 'm late (' . $sched_start->format('g:ia') . ' sched)';
                }
            }
            if (!empty($r['out']) && !empty($sched['end'])) {
                $out = \Carbon::parse($r['out']);
                $sched_end = \Carbon::parse($sched['end']);
                $overMin = $sched_end->diffInMinutes($out, false); // + if out is after end
                if ($overMin > $grace) {
                    $parts[] = 'out ' . $overMin . 'm past end (' . $sched_end->format('g:ia') . ' sched)';
                }
            }
            if (empty($parts)) { return null; }
            return \Carbon::parse($day)->format('D M j') . ': ' . implode(', ', $parts);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var array cached schedule: user_id => date => ['start','end'] */
    private $scheduleCache = [];

    // Build the schedule lookup once per request from sling_shifts.
    private function scheduleIndex($start, $end)
    {
        $this->scheduleCache = [];
        try {
            $shifts = SlingShift::whereNotNull('erp_user_id')
                ->where('event_type', SlingShift::TYPE_SHIFT)
                ->where('dtstart', '>=', $start . ' 00:00:00')
                ->where('dtstart', '<=', $end . ' 23:59:59')
                ->get(['erp_user_id', 'dtstart', 'dtend']);

            foreach ($shifts as $sh) {
                if (!$sh->dtstart) { continue; }
                $uid = (int) $sh->erp_user_id;
                $day = $sh->dtstart->toDateString();
                $s = $sh->dtstart->toDateTimeString();
                $e = $sh->dtend ? $sh->dtend->toDateTimeString() : null;
                if (!isset($this->scheduleCache[$uid][$day])) {
                    $this->scheduleCache[$uid][$day] = ['start' => $s, 'end' => $e];
                } else {
                    // Widen to earliest start / latest end if multiple shifts.
                    if ($s < $this->scheduleCache[$uid][$day]['start']) { $this->scheduleCache[$uid][$day]['start'] = $s; }
                    if ($e && (empty($this->scheduleCache[$uid][$day]['end']) || $e > $this->scheduleCache[$uid][$day]['end'])) {
                        $this->scheduleCache[$uid][$day]['end'] = $e;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll schedule index failed: ' . $e->getMessage());
        }
        return $this->scheduleCache;
    }

    // Attach listing + sales commission (owed + earned) and history to each
    // person, resolving their ERP user_id, then append commission-only people.
    private function attachCommissions(array $people, $comm, $lastPaid, $lastCheck, $businessId)
    {
        // Resolve user_id per person: explicit config override, else auto-match
        // by first name against ERP users.
        $usersByFirst = $this->usersByFirstName($businessId);
        $usersById = DB::table('users')->where('business_id', $businessId)
            ->get(['id', 'first_name', 'last_name', 'surname'])->keyBy('id');
        foreach ($people as $key => &$p) {
            if (empty($p['user_id'])) {
                $p['user_id'] = $usersByFirst[$key] ?? null;
            }
            // Prefer the ERP first/last name when linked (Clover only gives a
            // first name), so the Last name column + sorting are meaningful.
            if ($p['user_id'] && isset($usersById[$p['user_id']])) {
                $uu = $usersById[$p['user_id']];
                if (trim((string) ($uu->first_name ?? '')) !== '') { $p['first_name'] = trim((string) $uu->first_name); }
                if (trim((string) ($uu->last_name ?? '')) !== '')  { $p['last_name']  = trim((string) $uu->last_name); }
            }
            $c = $p['user_id'] ? ($comm[$p['user_id']] ?? null) : null;
            if ($c) {
                $p['sales_comm']   = round((float) $c->sales_owed, 2);
                $p['listing_comm'] = round((float) $c->listing_owed, 2);
                $p['comm_earned']  = round((float) $c->listing_earned + (float) $c->sales_earned, 2);
                $p['grand_total']  = round($p['wages'] + $p['sales_comm'] + $p['listing_comm'], 2);
                $p['memo']         = $this->commissionMemo($p['sales_comm'], $p['listing_comm']);
            }
            if ($p['user_id']) {
                $p['last_comm_paid'] = $lastPaid[$p['user_id']] ?? null;
            }
            // Last paycheck: prefer the ERP user link, fall back to the name key.
            $p['last_paycheck'] = ($p['user_id'] ? ($lastCheck['byUid'][$p['user_id']] ?? null) : null)
                ?? ($lastCheck['byKey'][$key] ?? null);
        }
        unset($p);

        // Anyone owed commission but with no punches this period still needs to
        // be paid — surface them as hours-less rows.
        $seenUids = array_filter(array_column($people, 'user_id'));
        foreach ((array) $comm as $uid => $c) {
            $uid = (int) $uid;
            if ($uid <= 0 || in_array($uid, $seenUids)) { continue; }
            $owedL = round((float) $c->listing_owed, 2);
            $owedS = round((float) $c->sales_owed, 2);
            $earned = round((float) $c->listing_earned + (float) $c->sales_earned, 2);
            if ($owedL <= 0 && $owedS <= 0) { continue; }
            $u = DB::table('users')->where('id', $uid)->first();
            $nm = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid))) : ('User #' . $uid);
            [$cfn, $cln] = $this->splitName($nm, $u);
            $people['uid_' . $uid] = [
                'key' => 'uid_' . $uid,
                'name' => $nm,
                'first_name' => $cfn,
                'last_name' => $cln,
                'co' => ['one' => ['reg' => 0, 'ot' => 0], 'two' => ['reg' => 0, 'ot' => 0]],
                'log' => [], 'first_in' => [], 'one_hours' => 0, 'two_hours' => 0,
                'home_company' => stripos((string) ($u->location ?? ''), 'pico') !== false ? 'one' : 'two',
                'user_id' => $uid, 'store' => '', 'rate' => 0,
                'reg_hours' => 0, 'ot_hours' => 0, 'dt_hours' => 0, 'total_hours' => 0,
                'wages' => 0, 'sales_comm' => $owedS, 'listing_comm' => $owedL,
                'comm_earned' => $earned,
                'last_comm_paid' => $lastPaid[$uid] ?? null,
                'last_paycheck' => $lastCheck['byUid'][$uid] ?? null,
                'memo' => $this->commissionMemo($owedS, $owedL),
                'grand_total' => round($owedL + $owedS, 2), 'flags' => [], 'has_hours' => false,
            ];
        }

        // Sort: people with hours first (by pay), then commission-only.
        uasort($people, function ($a, $b) {
            if ($a['has_hours'] !== $b['has_hours']) { return $a['has_hours'] ? -1 : 1; }
            return $b['grand_total'] <=> $a['grand_total'];
        });
        return array_values($people);
    }

    private function unmatchedNames(array $people)
    {
        $out = [];
        foreach ($people as $p) {
            if ($p['has_hours'] && ($p['rate'] <= 0 || empty($p['user_id']))) {
                $out[] = [
                    'name' => $p['name'],
                    'no_rate' => $p['rate'] <= 0,
                    'no_user' => empty($p['user_id']),
                ];
            }
        }
        return $out;
    }

    private function computeFreelancers(array $freelancers)
    {
        $out = [];
        foreach ($freelancers as $f) {
            $model = $f['model'] ?? 'flat';
            if ($model === 'hourly' || $model === 'units') {
                $amount = round((float) ($f['rate'] ?? 0) * (float) ($f['qty'] ?? 0), 2);
            } else {
                $amount = round((float) ($f['amount'] ?? 0), 2);
            }
            $out[] = array_merge($f, ['amount' => $amount]);
        }
        usort($out, function ($a, $b) { return strcmp(strtolower($a['name']), strtolower($b['name'])); });
        return $out;
    }

    // ---- Commission sources (reuse existing engines) --------------------

    // Per-user commission: owed + cumulative earned for both listing and sales,
    // straight from ListingCommissionController so it reconciles with the
    // Commissions Owed page. Keyed by ERP user_id.
    // The commission pulls take ~15s (same math as the Commissions page), so
    // payroll caches them for 10 minutes; ?fresh=1 recomputes right away.
    private function cachedPull($key, $businessId, callable $fn)
    {
        $ck = 'payroll:' . $key . ':' . $businessId;
        if (request()->input('fresh')) { \Cache::forget($ck); }
        return \Cache::remember($ck, 600, $fn);
    }

    private function commissionByUser($businessId)
    {
        return $this->cachedPull('comm', $businessId, function () use ($businessId) { return $this->commissionByUserFresh($businessId); });
    }

    private function commissionByUserFresh($businessId)
    {
        $out = [];
        try {
            // Same listing owed as the Commissions page (earned minus paid).
            foreach (app(ListingCommissionController::class)->listingNetByUser($businessId) as $uid => $s) {
                $uid = (int) $uid;
                $out[$uid] = (object) ['listing_owed' => (float) $s['owed'], 'listing_earned' => (float) $s['earned'], 'sales_owed' => 0.0, 'sales_earned' => 0.0];
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll listing pull failed: ' . $e->getMessage());
        }
        try {
            $lc = app(ListingCommissionController::class);
            $summary = $lc->salesSummaryByUser($businessId);
            // Sales OWED is period-based (days since each person's last settle),
            // the same number the Commissions page shows - not lifetime earned
            // minus paid (2026-10-01).
            $period = $lc->periodSalesOwedByUser($businessId, $summary);
            foreach ($summary as $uid => $s) {
                $uid = (int) $uid;
                if (!isset($out[$uid])) { $out[$uid] = (object) ['listing_owed' => 0.0, 'listing_earned' => 0.0, 'sales_owed' => 0.0, 'sales_earned' => 0.0]; }
                $out[$uid]->sales_owed   = (float) ($period['owed'][$uid] ?? 0);
                $out[$uid]->sales_earned = (float) $s->earned;
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll sales pull failed: ' . $e->getMessage());
        }
        return $out;
    }

    // Most recent commission payout per user (listing OR sales), read directly
    // from the two payout ledgers ListingCommissionController writes. Returns
    // uid => (object){amount, at}.
    private function lastCommissionPaidByUser()
    {
        $out = [];
        foreach (['listing-commission-payouts.json', 'sales-commission-payouts.json'] as $file) {
            if (!Storage::disk('local')->exists($file)) { continue; }
            $data = json_decode(Storage::disk('local')->get($file), true);
            if (!is_array($data)) { continue; }
            foreach ($data as $p) {
                $uid = (int) ($p['user_id'] ?? 0);
                if ($uid <= 0) { continue; }
                $at = (string) ($p['marked_at'] ?? '');
                if (!isset($out[$uid]) || strcmp($at, $out[$uid]->at) > 0) {
                    $out[$uid] = (object) ['amount' => round((float) ($p['amount'] ?? 0), 2), 'at' => $at];
                }
            }
        }
        return $out;
    }

    // Plain-English pay-stub memo so each person understands what their
    // commission is for, per our rules. Mirrors the wording on the Commissions
    // Owed page. Uses this period's owed (what they're being paid now).
    private function commissionMemo($salesOwed, $listingOwed)
    {
        $listFrom  = \Carbon::parse(ListingCommissionController::DEFAULT_FROM)->format('M j, Y');
        $salesFrom = \Carbon::parse(ListingCommissionController::SALES_BONUS_FROM)->format('M j, Y');
        $parts = [];
        if ($listingOwed > 0) {
            $parts[] = 'Listing commission $' . number_format($listingOwed, 2)
                . ' - 2% of the sale price of each used item you listed/barcoded that has since sold (listings since ' . $listFrom . ').';
        }
        if ($salesOwed > 0) {
            $parts[] = 'Sales bonus $' . number_format($salesOwed, 2)
                . ' - 2% of your register sales above your daily goal, or 4% during peak hours, added up day by day (since ' . $salesFrom . ').';
        }
        return implode('  ', $parts);
    }

    // ---- Hidden (departed staff) ----------------------------------------

    // Remove people on the hidden list (departed staff) from the pay run. Match
    // on ERP user_id when known, else the name key, else the full display name.
    private function filterHidden(array $people, array $hidden)
    {
        if (empty($hidden)) { return $people; }
        $uids = []; $keys = []; $names = [];
        foreach ($hidden as $h) {
            if (!empty($h['user_id'])) { $uids[(int) $h['user_id']] = true; }
            if (!empty($h['key']))     { $keys[$h['key']] = true; }
            if (!empty($h['name']))    { $names[strtolower(trim($h['name']))] = true; }
        }
        return array_values(array_filter($people, function ($p) use ($uids, $keys, $names) {
            if (!empty($p['user_id']) && isset($uids[(int) $p['user_id']])) { return false; }
            if (!empty($p['key']) && isset($keys[$p['key']])) { return false; }
            if (isset($names[strtolower(trim($p['name']))])) { return false; }
            return true;
        }));
    }

    public function hide(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $config = $this->loadConfig();
        $entry = [
            'id'      => bin2hex(random_bytes(6)),
            'user_id' => (int) $request->input('user_id') ?: null,
            'key'     => $this->nameKey($request->input('key', '')),
            'name'    => trim((string) $request->input('name')),
        ];
        if (empty($entry['user_id']) && $entry['key'] === '' && $entry['name'] === '') {
            return redirect($this->url($start, $end))->with('status', ['success' => 0, 'msg' => 'Nothing to hide.']);
        }
        $config['hidden'][] = $entry;
        $this->saveConfig($config);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Hid ' . ($entry['name'] ?: 'that person') . ' — no longer shown on payroll.']);
    }

    public function unhide(Request $request)
    {
        $this->ensureAdmin();
        [$start, $end] = $this->resolvePeriod($request);
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));
        $config = $this->loadConfig();
        $config['hidden'] = array_values(array_filter($config['hidden'] ?? [], function ($h) use ($id) {
            return ($h['id'] ?? '') !== $id;
        }));
        $this->saveConfig($config);
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Un-hid — they show on payroll again.']);
    }

    // ---- Saved runs (for "last paycheck") -------------------------------

    // Snapshot this period's per-person totals so the NEXT run can show each
    // person's last paycheck. Owner-only (it captures pay).
    public function saveRun(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->canSeeRates()) { abort(403, 'Only owners can save a pay run.'); }
        [$start, $end] = $this->resolvePeriod($request);
        $businessId = $request->session()->get('user.business_id');

        [$people] = $this->assemble($businessId, $start, $end);

        $snap = [];
        foreach ($people as $p) {
            $snap[] = ['key' => $p['key'], 'user_id' => $p['user_id'], 'name' => $p['name'], 'total' => $p['grand_total']];
        }
        Storage::disk('local')->put(
            'payroll/runs/' . $start . '_' . $end . '.json',
            json_encode(['start' => $start, 'end' => $end, 'saved_at' => now()->toDateTimeString(), 'people' => $snap], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        return redirect($this->url($start, $end))->with('status', ['success' => 1, 'msg' => 'Saved this pay run. It becomes the "last paycheck" reference for the next period.']);
    }

    // Most recent saved run that ENDED before $start → maps of prior totals.
    private function lastPaycheckMap($start)
    {
        $out = ['byUid' => [], 'byKey' => []];
        try {
            $best = null;
            foreach (Storage::disk('local')->files('payroll/runs') as $file) {
                if (substr($file, -5) !== '.json') { continue; }
                $doc = json_decode(Storage::disk('local')->get($file), true);
                if (!is_array($doc) || empty($doc['end'])) { continue; }
                if (strcmp($doc['end'], $start) >= 0) { continue; } // must end before this period
                if ($best === null || strcmp($doc['end'], $best['end']) > 0) { $best = $doc; }
            }
            if ($best) {
                foreach (($best['people'] ?? []) as $p) {
                    if (!empty($p['user_id'])) { $out['byUid'][(int) $p['user_id']] = (float) $p['total']; }
                    if (!empty($p['key']))     { $out['byKey'][$p['key']] = (float) $p['total']; }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll last-paycheck lookup failed: ' . $e->getMessage());
        }
        return $out;
    }

    private function lastRunSavedAt($start, $end)
    {
        $f = 'payroll/runs/' . $start . '_' . $end . '.json';
        if (!Storage::disk('local')->exists($f)) { return null; }
        $doc = json_decode(Storage::disk('local')->get($f), true);
        return is_array($doc) ? ($doc['saved_at'] ?? null) : null;
    }

    // first-name (name_key) => user_id, for auto-matching imported names to ERP
    // users so commissions line up without manual mapping.
    private function usersByFirstName($businessId)
    {
        $map = [];
        try {
            $users = DB::table('users')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->get(['id', 'first_name']);
            foreach ($users as $u) {
                $key = $this->nameKey($u->first_name);
                // Only keep unique first-name matches; ambiguous names stay
                // unmapped and can be set explicitly in the rate editor.
                if ($key === '') { continue; }
                if (array_key_exists($key, $map)) { $map[$key] = null; }
                else { $map[$key] = (int) $u->id; }
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll user map failed: ' . $e->getMessage());
        }
        return array_filter($map);
    }

    // ---- Punch parsing ---------------------------------------------------

    // Flexible CSV/TSV parser for a clock in/out export. Detects the delimiter
    // and maps columns by header keywords, so it accepts the Clover timecard
    // export or the layout in Sarah's payroll sheet (Name, Clock In Date, Clock
    // In Time, Clock Out Date, Clock Out Time, Elapsed, Location). Names blank
    // on continuation rows carry down from the row above.
    private function parsePunches($raw)
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), function ($l) { return $l !== ''; }));
        if (empty($lines)) { return ['rows' => [], 'skipped' => 0]; }

        $delim = (strpos($lines[0], "\t") !== false) ? "\t" : ',';

        // Find the header row (first row that mentions a clock/name column).
        $headerIdx = 0;
        foreach ($lines as $i => $l) {
            $low = strtolower($l);
            if (strpos($low, 'clock in') !== false || (strpos($low, 'name') !== false && strpos($low, 'time') !== false)
                || (strpos($low, 'name') !== false && strpos($low, 'date') !== false)) {
                $headerIdx = $i; break;
            }
        }
        $header = str_getcsv($lines[$headerIdx], $delim);
        $col = $this->mapColumns($header);
        if (!isset($col['name']) && !isset($col['in']) && !isset($col['in_date'])) {
            // No recognizable header — bail rather than guess wrong.
            return ['rows' => [], 'skipped' => 0];
        }

        $rows = [];
        $skipped = 0;
        $lastName = '';
        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $c = str_getcsv($lines[$i], $delim);
            $get = function ($k) use ($c, $col) {
                return isset($col[$k]) && isset($c[$col[$k]]) ? trim((string) $c[$col[$k]]) : '';
            };

            // The Clover export repeats the person's name only on their first
            // row; per-day rows carry the DATE (e.g. "04-July-26") in that same
            // first column. So a date-looking value = continuation of the person
            // above AND the workday for this row — capture it as the date so
            // hours land on the right day (otherwise every day collapses into
            // one bucket and triggers phantom overtime).
            $rawName = $get('name');
            $carriedDate = '';
            if ($rawName === '' || is_numeric($rawName) || $this->looksLikeDate($rawName)) {
                if ($rawName !== '' && $this->looksLikeDate($rawName)) { $carriedDate = $this->normDate($rawName); }
                $name = $lastName;
            } else {
                $name = $rawName; $lastName = $name;
            }
            if ($name === '') { continue; }

            // Datetimes: prefer split date+time columns, else the date carried in
            // the first column, else a combined column.
            $inDate  = $this->normDate($get('in_date')) ?: $carriedDate;
            $inTime  = $get('in_time');
            $outDate = $this->normDate($get('out_date')) ?: $inDate;
            $outTime = $get('out_time');
            $in  = $this->joinDateTime($inDate, $inTime, $get('in'));
            $out = $this->joinDateTime($outDate, $outTime, $get('out'));

            $elapsed = $this->parseHours($get('elapsed'));
            if ($elapsed <= 0 && $in && $out) {
                // Older Carbon here has no floatDiffInHours — use raw seconds.
                $sec = strtotime($out) - strtotime($in);
                $elapsed = $sec > 0 ? round($sec / 3600, 4) : 0;
            }
            if ($name === '' || $elapsed <= 0) { $skipped++; continue; }

            $rows[] = [
                'name'     => $name,
                'in'       => $in,
                'out'      => $out,
                'in_date'  => $in ? substr($in, 0, 10) : ($inDate ?: ''),
                'elapsed'  => $elapsed,
                'location' => $get('location'),
                'comment'  => $get('comment'),
            ];
        }
        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function mapColumns(array $header)
    {
        $col = [];
        foreach ($header as $i => $h) {
            $h = strtolower(trim($h));
            if ($h === '') { continue; }
            $has = function (...$needles) use ($h) {
                foreach ($needles as $n) { if (strpos($h, $n) !== false) { return true; } }
                return false;
            };
            if (!isset($col['name']) && $has('name', 'employee')) { $col['name'] = $i; continue; }
            if (!isset($col['in_date']) && $has('clock in date', 'in date')) { $col['in_date'] = $i; continue; }
            if (!isset($col['in_time']) && ($has('clock in time', 'in time', 'time in'))) { $col['in_time'] = $i; continue; }
            if (!isset($col['out_date']) && $has('clock out date', 'out date')) { $col['out_date'] = $i; continue; }
            if (!isset($col['out_time']) && ($has('clock out time', 'out time', 'time out'))) { $col['out_time'] = $i; continue; }
            if (!isset($col['in']) && ($has('clock in', 'in') && !$has('date', 'time'))) { $col['in'] = $i; continue; }
            if (!isset($col['out']) && ($has('clock out', 'out') && !$has('date', 'time'))) { $col['out'] = $i; continue; }
            if (!isset($col['elapsed']) && $has('elapsed', 'total hours', 'hours', 'duration')) { $col['elapsed'] = $i; continue; }
            if (!isset($col['location']) && $has('location', 'store', 'site')) { $col['location'] = $i; continue; }
            if (!isset($col['comment']) && $has('comment', 'note')) { $col['comment'] = $i; continue; }
        }
        return $col;
    }

    private function joinDateTime($date, $time, $combined = '')
    {
        if ($combined !== '') {
            try { return \Carbon::parse($combined)->toDateTimeString(); } catch (\Throwable $e) {}
        }
        $date = trim((string) $date); $time = trim((string) $time);
        if ($date === '' && $time === '') { return ''; }
        try { return \Carbon::parse(trim($date . ' ' . $time))->toDateTimeString(); }
        catch (\Throwable $e) { return ''; }
    }

    // Does this cell look like a date rather than a person's name? Used so the
    // per-day rows in a Clover export don't get treated as employees.
    private function looksLikeDate($s)
    {
        $s = strtolower(trim((string) $s));
        if ($s === '') { return false; }
        if (preg_match('#^\d{1,4}[-/]\d{1,2}[-/]\d{1,4}$#', $s)) { return true; }              // 2026-07-08, 7/8/26
        if (preg_match('/\d/', $s) && preg_match('/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/', $s)) { return true; } // 04-July-26
        return false;
    }

    private function normDate($d)
    {
        $d = trim((string) $d);
        if ($d === '') { return ''; }
        try { return \Carbon::parse($d)->toDateString(); }
        catch (\Throwable $e) { return $d; }
    }

    // "5.23", "5:14", "05:14:00" -> hours as float.
    private function parseHours($v)
    {
        $v = trim((string) $v);
        if ($v === '') { return 0.0; }
        if (strpos($v, ':') !== false) {
            $p = array_map('intval', explode(':', $v));
            $h = $p[0] ?? 0; $m = $p[1] ?? 0; $s = $p[2] ?? 0;
            return round($h + $m / 60 + $s / 3600, 4);
        }
        return is_numeric($v) ? round((float) $v, 4) : 0.0;
    }

    // ---- Config / storage ------------------------------------------------

    private function loadConfig()
    {
        $default = ['rates' => [], 'settings' => self::DEFAULTS, 'freelancers' => [], 'hidden' => []];
        if (!Storage::disk('local')->exists(self::CONFIG_FILE)) { return $default; }
        $data = json_decode(Storage::disk('local')->get(self::CONFIG_FILE), true);
        if (!is_array($data)) { return $default; }
        $data['rates']       = $data['rates'] ?? [];
        $data['freelancers'] = $data['freelancers'] ?? [];
        $data['hidden']      = $data['hidden'] ?? [];
        $data['settings']    = array_merge(self::DEFAULTS, $data['settings'] ?? []);
        return $data;
    }

    private function saveConfig(array $config)
    {
        Storage::disk('local')->put(self::CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function hoursFile($start, $end)
    {
        return 'payroll/hours-' . $start . '_' . $end . '.json';
    }

    private function loadHours($start, $end)
    {
        $f = $this->hoursFile($start, $end);
        if (!Storage::disk('local')->exists($f)) { return ['rows' => []]; }
        $data = json_decode(Storage::disk('local')->get($f), true);
        return is_array($data) ? $data : ['rows' => []];
    }

    private function saveHours($start, $end, array $doc)
    {
        Storage::disk('local')->put($this->hoursFile($start, $end), json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // ---- Misc ------------------------------------------------------------

    // Split a display name into [first, last]. Prefers an ERP users row when
    // given (authoritative), else splits on the first space.
    private function splitName($full, $u = null)
    {
        if ($u && trim((string) ($u->first_name ?? '')) !== '') {
            return [trim((string) $u->first_name), trim((string) ($u->last_name ?? ''))];
        }
        $full = trim((string) $full);
        $parts = preg_split('/\s+/', $full, 2);
        return [$parts[0] ?? $full, isset($parts[1]) ? trim($parts[1]) : ''];
    }

    // Normalize a name to a match key = lowercased first token (Clover uses
    // first names; the rate/user maps key off the same).
    private function nameKey($name)
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') { return ''; }
        $name = preg_replace('/\s+/', ' ', $name);
        $first = explode(' ', $name)[0];
        return preg_replace('/[^a-z0-9]/', '', $first);
    }

    // Resolve the pay period from the request, defaulting to the last complete
    // bi-weekly period (anchored on 10/1-10/14/2026).
    private function resolvePeriod(Request $request)
    {
        $start = (string) $request->input('start', '');
        $end   = (string) $request->input('end', '');
        $ok = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
        if ($ok($start) && $ok($end)) { return [$start, $end]; }
        // Bi-weekly periods anchored on 10/1/2026-10/14/2026. Default = the
        // most recent period that has fully ended (the one being paid).
        $anchor = \Carbon::parse('2026-10-01')->startOfDay();
        $today  = \Carbon::now()->startOfDay();
        $n = (int) floor($anchor->diffInDays($today, false) / 14);
        $startD = $anchor->copy()->addDays(14 * ($n - 1));
        return [$startD->toDateString(), $startD->copy()->addDays(13)->toDateString()];
    }

    private function url($start, $end)
    {
        return '/payroll?start=' . urlencode($start) . '&end=' . urlencode($end);
    }
}
