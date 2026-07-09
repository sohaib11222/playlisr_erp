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

    private function ensureAdmin()
    {
        if (!auth()->check() || !$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'Payroll is admin-only.');
        }
    }

    // ---- Main page -------------------------------------------------------

    public function index(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $request->session()->get('user.business_id');

        [$start, $end] = $this->resolvePeriod($request);

        $config   = $this->loadConfig();
        $settings = $config['settings'];
        $rates    = $config['rates'];        // name_key => ['rate','user_id','store','display_name']

        $hoursDoc = $this->loadHours($start, $end);
        $rows     = $hoursDoc['rows'] ?? [];

        // Commission per user, from the Commissions Owed engine so the numbers
        // line up: owed (still owe now) + cumulative earned. Plus the last
        // commission payout and last paycheck for history columns.
        $comm      = $this->commissionByUser($businessId);   // uid => {listing_owed, listing_earned, sales_owed, sales_earned}
        $lastPaid  = $this->lastCommissionPaidByUser();      // uid => {amount, at}
        $lastCheck = $this->lastPaycheckMap($start);         // ['byUid'=>[], 'byKey'=>[]]

        // Sling schedule for the window, indexed by erp_user_id + date, for late
        // detection.
        $schedule = $this->scheduleIndex($start, $end);

        // Build one person row per distinct imported name.
        $people = $this->buildPeople($rows, $rates, $settings, $schedule);

        // Attach commissions + history, and append commission-only people.
        $people = $this->attachCommissions($people, $comm, $lastPaid, $lastCheck, $businessId);

        // Drop people who no longer work here (hidden list).
        $people = $this->filterHidden($people, $config['hidden'] ?? []);

        // Totals (after hiding departed staff).
        $totals = [
            'reg_hours'   => round(array_sum(array_column($people, 'reg_hours')), 2),
            'ot_hours'    => round(array_sum(array_column($people, 'ot_hours')), 2),
            'dt_hours'    => round(array_sum(array_column($people, 'dt_hours')), 2),
            'wages'       => round(array_sum(array_column($people, 'wages')), 2),
            'sales_comm'  => round(array_sum(array_column($people, 'sales_comm')), 2),
            'listing_comm'=> round(array_sum(array_column($people, 'listing_comm')), 2),
            'comm_earned' => round(array_sum(array_column($people, 'comm_earned')), 2),
            'grand_total' => round(array_sum(array_column($people, 'grand_total')), 2),
            'flags'       => array_sum(array_map(function ($p) { return count($p['flags']); }, $people)),
        ];

        $freelancers = $this->computeFreelancers($config['freelancers']);
        $freelancerTotal = round(array_sum(array_column($freelancers, 'amount')), 2);

        return view('admin.payroll', [
            'start'        => $start,
            'end'          => $end,
            'settings'     => $settings,
            'people'       => $people,
            'totals'       => $totals,
            'freelancers'  => $freelancers,
            'freelancer_total' => $freelancerTotal,
            'imported_at'  => $hoursDoc['imported_at'] ?? null,
            'row_count'    => count($rows),
            'unmatched'    => $this->unmatchedNames($people),
            'sling_ready'  => (new \App\Services\SlingClient())->isConfigured() || SlingShift::query()->exists(),
            'can_see_rates' => $this->canSeeRates(),
            'hidden'       => $config['hidden'] ?? [],
            'last_run_at'  => $this->lastRunSavedAt($start, $end),
        ]);
    }

    // Whether the current user may see explicit pay: hourly rates, wages, and
    // the Rates & settings editor. Owners only (whitelist by first name) so
    // whoever PREPS payroll (e.g. Fatteen / "Nerdy Solutions") can still enter
    // hours + commissions into QuickBooks without seeing what people are paid.
    private function canSeeRates()
    {
        $u = auth()->user();
        if (!$u) { return false; }
        $first = strtolower(trim((string) $u->first_name));
        $last  = strtolower(trim((string) $u->last_name));
        if ($first === 'jonathan' && $last === 'hedvat') { return true; }
        return in_array($first, ['jon', 'jonathan', 'sarah'], true);
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

        $config   = $this->loadConfig();
        $hoursDoc = $this->loadHours($start, $end);
        $schedule = $this->scheduleIndex($start, $end);
        $people   = $this->buildPeople($hoursDoc['rows'] ?? [], $config['rates'], $config['settings'], $schedule);
        $people   = $this->attachCommissions($people, $this->commissionByUser($businessId), $this->lastCommissionPaidByUser(), $this->lastPaycheckMap($start), $businessId);
        $people   = $this->filterHidden($people, $config['hidden'] ?? []);

        $filename = 'payroll-qb-' . $start . '_to_' . $end . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->stream(function () use ($people) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Regular Hours', 'OT Hours', 'Sales Commission', 'Listing Commission']);
            foreach ($people as $p) {
                fputcsv($out, [
                    $p['name'],
                    number_format($p['reg_hours'], 2, '.', ''),
                    number_format($p['ot_hours'], 2, '.', ''),
                    number_format($p['sales_comm'], 2, '.', ''),
                    number_format($p['listing_comm'], 2, '.', ''),
                ]);
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
        foreach ($rows as $r) {
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

            $day = $r['in_date'] ?: substr((string) $r['in'], 0, 10);
            $hrs = (float) $r['elapsed'];
            $byPerson[$key]['days'][$day] = ($byPerson[$key]['days'][$day] ?? 0) + $hrs;

            // Late detection for this punch against the schedule.
            $flag = $this->lateFlag($byPerson[$key]['user_id'], $r, $settings['grace_minutes']);
            if ($flag) { $byPerson[$key]['flags'][] = $flag; }
        }

        $people = [];
        foreach ($byPerson as $key => $p) {
            $reg = $ot = $dt = 0.0;
            foreach ($p['days'] as $dayHours) {
                [$dReg, $dOt, $dDt] = $this->splitDay((float) $dayHours, $settings);
                $reg += $dReg; $ot += $dOt; $dt += $dDt;
            }
            $rate = (float) $p['rate'];
            $wages = $reg * $rate
                + $ot * $rate * (float) $settings['ot_multiplier']
                + $dt * $rate * (float) $settings['dt_multiplier'];

            $people[$key] = [
                'key'          => $key,
                'name'         => $p['name'],
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
                'grand_total'  => round($wages, 2),
                'flags'        => $p['flags'],
                'has_hours'    => true,
            ];
        }
        return $people;
    }

    // Daily split for one workday's hours: anything over the daily OT threshold
    // (8h) is overtime at 1.5x. No double-time — Nivessa just pays OT over 8/day.
    private function splitDay($hours, array $s)
    {
        $otAfter = (float) $s['daily_ot_after'];
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
        foreach ($people as $key => &$p) {
            if (empty($p['user_id'])) {
                $p['user_id'] = $usersByFirst[$key] ?? null;
            }
            $c = $p['user_id'] ? ($comm[$p['user_id']] ?? null) : null;
            if ($c) {
                $p['sales_comm']   = round((float) $c->sales_owed, 2);
                $p['listing_comm'] = round((float) $c->listing_owed, 2);
                $p['comm_earned']  = round((float) $c->listing_earned + (float) $c->sales_earned, 2);
                $p['grand_total']  = round($p['wages'] + $p['sales_comm'] + $p['listing_comm'], 2);
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
            $people['uid_' . $uid] = [
                'key' => 'uid_' . $uid,
                'name' => $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid))) : ('User #' . $uid),
                'user_id' => $uid, 'store' => '', 'rate' => 0,
                'reg_hours' => 0, 'ot_hours' => 0, 'dt_hours' => 0, 'total_hours' => 0,
                'wages' => 0, 'sales_comm' => $owedS, 'listing_comm' => $owedL,
                'comm_earned' => $earned,
                'last_comm_paid' => $lastPaid[$uid] ?? null,
                'last_paycheck' => $lastCheck['byUid'][$uid] ?? null,
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
    private function commissionByUser($businessId)
    {
        $out = [];
        try {
            foreach (app(ListingCommissionController::class)->summaryByUser($businessId) as $uid => $s) {
                $uid = (int) $uid;
                $out[$uid] = (object) ['listing_owed' => (float) $s->owed, 'listing_earned' => (float) $s->earned, 'sales_owed' => 0.0, 'sales_earned' => 0.0];
            }
        } catch (\Throwable $e) {
            \Log::warning('payroll listing pull failed: ' . $e->getMessage());
        }
        try {
            foreach (app(ListingCommissionController::class)->salesSummaryByUser($businessId) as $uid => $s) {
                $uid = (int) $uid;
                if (!isset($out[$uid])) { $out[$uid] = (object) ['listing_owed' => 0.0, 'listing_earned' => 0.0, 'sales_owed' => 0.0, 'sales_earned' => 0.0]; }
                $out[$uid]->sales_owed   = (float) $s->owed;
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

        $config   = $this->loadConfig();
        $hoursDoc = $this->loadHours($start, $end);
        $schedule = $this->scheduleIndex($start, $end);
        $people   = $this->buildPeople($hoursDoc['rows'] ?? [], $config['rates'], $config['settings'], $schedule);
        $people   = $this->attachCommissions($people, $this->commissionByUser($businessId), $this->lastCommissionPaidByUser(), $this->lastPaycheckMap($start), $businessId);
        $people   = $this->filterHidden($people, $config['hidden'] ?? []);

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
            // row; per-day rows carry a date (e.g. "04-July-26") in that first
            // column. Treat blank / numeric / date-looking values as
            // continuation of the person above so dates never become "people".
            $name = $get('name');
            if ($name === '' || is_numeric($name) || $this->looksLikeDate($name)) { $name = $lastName; }
            else { $lastName = $name; }
            if ($name === '') { continue; }

            // Datetimes: prefer split date+time columns, else a combined column.
            $inDate  = $this->normDate($get('in_date'));
            $inTime  = $get('in_time');
            $outDate = $this->normDate($get('out_date'));
            $outTime = $get('out_time');
            $in  = $this->joinDateTime($inDate, $inTime, $get('in'));
            $out = $this->joinDateTime($outDate ?: $inDate, $outTime, $get('out'));

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
    // two weeks (Thu-start bi-weekly like the sheet's 4/30-5/13 window).
    private function resolvePeriod(Request $request)
    {
        $start = (string) $request->input('start', '');
        $end   = (string) $request->input('end', '');
        $ok = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
        if ($ok($start) && $ok($end)) { return [$start, $end]; }
        $endD   = \Carbon::now()->subDay();
        $startD = $endD->copy()->subDays(13);
        return [$startD->toDateString(), $endD->toDateString()];
    }

    private function url($start, $end)
    {
        return '/payroll?start=' . urlencode($start) . '&end=' . urlencode($end);
    }
}
