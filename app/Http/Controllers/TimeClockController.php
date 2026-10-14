<?php

namespace App\Http\Controllers;

use App\Services\TimeClockService as TC;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phone time clock admin (Sarah, 2026-10-13): Timecards (review, fix with a
 * reason, CSV), Time clock settings (PINs, remembered phones, store
 * location + Wi-Fi), and a printable QR sheet. Staff clock in at /clock
 * (PhoneClockController). Admin-only.
 */
class TimeClockController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function ensureAdmin()
    {
        if (!auth()->check() || !$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'The time clock admin pages are admin-only.');
        }
    }

    private function businessId(Request $request): int
    {
        return (int) ($request->session()->get('user.business_id') ?: TC::businessId());
    }

    private function back(Request $request, $ok, $msg, $to = null)
    {
        $r = $to ? redirect($to) : redirect()->back();
        return $r->with('status', ['success' => $ok ? 1 : 0, 'msg' => $msg]);
    }

    // ---- Timecards -------------------------------------------------------

    private function filters(Request $request): array
    {
        $ok = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
        $start = $request->input('start');
        $end = $request->input('end');
        if (!$ok($start) || !$ok($end)) {
            $end = TC::now()->toDateString();
            $start = TC::now()->subDays(13)->toDateString();
        }
        return [
            'start' => $start,
            'end' => $end,
            'user_id' => (int) $request->input('user_id') ?: null,
            'location_id' => (int) $request->input('location_id') ?: null,
            'flag' => in_array($request->input('flag'), ['missing', 'long', 'unverified', 'late', 'stayed', 'edited', 'voided'], true) ? $request->input('flag') : '',
        ];
    }

    private function punchQuery(int $businessId, array $f)
    {
        $q = DB::table('time_clock_punches as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('business_locations as l', 'l.id', '=', 'p.location_id')
            ->where('p.business_id', $businessId)
            ->where('p.clock_in_at', '>=', $f['start'] . ' 00:00:00')
            ->where('p.clock_in_at', '<=', $f['end'] . ' 23:59:59')
            ->select('p.*', 'u.first_name', 'u.last_name', 'l.name as location_name')
            ->orderBy('p.clock_in_at', 'desc');
        if ($f['user_id']) {
            $q->where('p.user_id', $f['user_id']);
        }
        if ($f['location_id']) {
            $q->where('p.location_id', $f['location_id']);
        }
        if ($f['flag'] === 'voided') {
            $q->whereNotNull('p.voided_at');
        } else {
            $q->whereNull('p.voided_at');
            if ($f['flag'] === 'missing') {
                $q->whereNull('p.clock_out_at');
            } elseif ($f['flag'] === 'long') {
                $cut = TC::now()->subHours(10)->format('Y-m-d H:i:s');
                $q->where(function ($w) use ($cut) {
                    $w->where(function ($o) use ($cut) { $o->whereNull('p.clock_out_at')->where('p.clock_in_at', '<', $cut); })
                      ->orWhereRaw('TIMESTAMPDIFF(MINUTE, p.clock_in_at, p.clock_out_at) > 600');
                });
            } elseif ($f['flag'] === 'unverified') {
                $q->where(function ($w) { $w->where('p.in_verify', 'none')->orWhere('p.out_verify', 'none'); });
            } elseif ($f['flag'] === 'late') {
                if (TC::hasLateColumns()) {
                    $q->where('p.late_min', '>', 0);
                } else {
                    $q->whereRaw('1 = 0');
                }
            } elseif ($f['flag'] === 'stayed') {
                $q->whereNotNull('p.clock_out_at'); // narrowed after scheduleFlags()
            } elseif ($f['flag'] === 'edited') {
                $q->where('p.edited', 1);
            }
        }
        return $q;
    }

    /** LA dates with no Sling shifts at all (sync broken): [date => true]. */
    private $noSchedDates = [];

    private function loadNoScheduleDates($rows): void
    {
        $this->noSchedDates = [];
        foreach ($rows->pluck('clock_in_at')->map(function ($d) { return substr((string) $d, 0, 10); })->unique() as $date) {
            if ($date !== '' && !TC::hasScheduleData($date)) {
                $this->noSchedDates[$date] = true;
            }
        }
    }

    /**
     * Minutes stayed past the scheduled shift end for each punch (Sarah
     * 10/13, info only: hours are never trimmed). Sling blocks are loaded
     * once per person and store. [punch id => minutes]
     */
    private $stayed = [];

    private function loadStayed($rows): void
    {
        $this->stayed = [];
        if (!count($rows)) {
            return;
        }
        try {
            $from = \Carbon::parse($rows->min('clock_in_at'))->subDay()->format('Y-m-d H:i:s');
            $to = \Carbon::parse($rows->max('clock_in_at'))->addDay()->format('Y-m-d H:i:s');
            $cache = [];
            foreach ($rows as $p) {
                if (!$p->clock_out_at || $p->voided_at) {
                    continue;
                }
                $store = $p->location_name ? TC::shortStoreName((string) $p->location_name) : '';
                $k = $p->user_id . '|' . $store;
                if (!isset($cache[$k])) {
                    $cache[$k] = TC::scheduleBlocks((int) $p->user_id, $from, $to, $store);
                }
                $bf = TC::blockFlags($cache[$k], (string) $p->clock_in_at, (string) $p->clock_out_at);
                if ($bf['stayed_min']) {
                    $this->stayed[(int) $p->id] = $bf['stayed_min'];
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('timecards stayed-past-shift failed: ' . $e->getMessage());
        }
    }

    /** Hours + flags for display/export. */
    private function decorate($p)
    {
        $p->name = trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')) ?: ('User #' . $p->user_id);
        $p->store = $p->location_name ? TC::shortStoreName($p->location_name) : '';
        $p->hours = null;
        if ($p->clock_out_at) {
            $sec = strtotime($p->clock_out_at) - strtotime($p->clock_in_at);
            $p->hours = $sec > 0 ? round($sec / 3600, 2) : 0;
        }
        $flags = [];
        if (!$p->clock_out_at && !$p->voided_at) {
            $stillOpen = strtotime($p->clock_in_at) >= TC::now()->subHours(TC::OPEN_MAX_HOURS)->timestamp;
            $flags[] = $stillOpen ? 'Still clocked in' : 'Missing clock-out';
            if ($stillOpen && strtotime($p->clock_in_at) < TC::now()->subHours(10)->timestamp) {
                $flags[] = 'Clocked in over 10 hours';
            }
        }
        if (!empty($p->late_min) && (int) $p->late_min > 0) {
            $flags[] = 'Late ' . (int) $p->late_min . ' min' . (!empty($p->sched_start_at) ? ' (shift ' . \Carbon::parse($p->sched_start_at)->format('g:i A') . ')' : '');
        }
        if ($p->in_verify === 'none') { $flags[] = 'Clock-in not verified at store'; }
        // The keypad (store computer mode) used on a phone: someone may be
        // typing a coworker's PIN on their own phone.
        if ($p->clock_in_source === 'computer' && preg_match('/Mobi|Android|iPhone|iPod/i', (string) $p->in_user_agent)) {
            $flags[] = 'PIN typed on a phone';
        }
        if (in_array($p->clock_in_source, ['phone', 'computer'], true) && isset($this->noSchedDates[substr((string) $p->clock_in_at, 0, 10)])) {
            $flags[] = 'No schedule data';
        }
        if ($p->out_verify === 'none') { $flags[] = 'Clock-out not verified at store'; }
        $p->stayed_min = $this->stayed[(int) $p->id] ?? null;
        if ($p->stayed_min) { $flags[] = 'Stayed ' . $p->stayed_min . ' min past shift'; }
        if ($p->hours !== null && $p->hours > 10) { $flags[] = 'Over 10 hours'; }
        if ($p->edited) { $flags[] = 'Edited'; }
        if ($p->voided_at) { $flags[] = 'Removed'; }
        $p->flags = $flags;
        return $p;
    }

    public function timecards(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $this->businessId($request);
        $f = $this->filters($request);
        $ready = TC::ready();
        $punches = collect();
        $history = [];
        $summary = [];
        $stayedWeeks = [];
        if ($ready) {
            $punches = $this->punchQuery($businessId, $f)->limit(3000)->get();
            $this->loadNoScheduleDates($punches);
            $this->loadStayed($punches);
            $punches = $punches->map(function ($p) { return $this->decorate($p); });
            if ($f['flag'] === 'stayed') {
                $punches = $punches->filter(function ($p) { return $p->stayed_min; })->values();
            }
            $ids = $punches->pluck('id')->all();
            if ($ids) {
                foreach (DB::table('time_clock_punch_edits as e')->leftJoin('users as u', 'u.id', '=', 'e.edited_by')
                    ->whereIn('e.punch_id', $ids)->orderBy('e.id')
                    ->get(['e.*', 'u.first_name as editor']) as $e) {
                    $history[$e->punch_id][] = $e;
                }
            }
            foreach ($punches as $p) {
                if ($p->voided_at) { continue; }
                $s = $summary[$p->user_id] ?? ['name' => $p->name, 'shifts' => 0, 'hours' => 0, 'flags' => 0];
                $s['shifts']++;
                $s['hours'] += (float) $p->hours;
                $s['flags'] += count(array_diff($p->flags, ['Edited', 'Still clocked in']));
                $summary[$p->user_id] = $s;
                // Weekly extra minutes past shift (weeks start Monday).
                if ($p->stayed_min) {
                    $wk = \Carbon::parse($p->clock_in_at)->startOfWeek()->toDateString();
                    $stayedWeeks[$p->user_id]['name'] = $p->name;
                    $stayedWeeks[$p->user_id]['weeks'][$wk] = ($stayedWeeks[$p->user_id]['weeks'][$wk] ?? 0) + $p->stayed_min;
                }
            }
            uasort($stayedWeeks, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
            foreach ($stayedWeeks as &$sw) { krsort($sw['weeks']); }
            unset($sw);
            uasort($summary, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }
        $users = TC::staff($businessId);
        $locations = TC::locations($businessId);
        return view('time_clock.timecards', compact('f', 'ready', 'punches', 'history', 'summary', 'stayedWeeks', 'users', 'locations') + ['canEdit' => $this->canEditPunches(), 'me' => (int) auth()->id()]);
    }

    public function exportCsv(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $this->businessId($request);
        $f = $this->filters($request);
        $rows = TC::ready() ? $this->punchQuery($businessId, $f)->get() : collect();
        $this->loadNoScheduleDates($rows);
        $this->loadStayed($rows);
        $rows = $rows->map(function ($p) { return $this->decorate($p); });
        if ($f['flag'] === 'stayed') {
            $rows = $rows->filter(function ($p) { return $p->stayed_min; })->values();
        }
        $filename = 'timecards-' . $f['start'] . '_to_' . $f['end'] . '.csv';
        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Store', 'Date', 'Clock In', 'Clock Out', 'Hours', 'Flags', 'Clock-in check', 'Clock-out check', 'Clocked out by', 'Clock In IP', 'Clock Out IP', 'Clock In Lat', 'Clock In Lng', 'Accuracy (m)', 'Distance to store (m)']);
            // Text cells starting with = + - @ would run as formulas in Excel.
            $safe = function ($v) {
                $v = (string) $v;
                return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
            };
            foreach ($rows as $p) {
                fputcsv($out, [
                    $safe($p->name),
                    $safe($p->store),
                    substr($p->clock_in_at, 0, 10),
                    \Carbon::parse($p->clock_in_at)->format('g:i A'),
                    $p->clock_out_at ? \Carbon::parse($p->clock_out_at)->format('Y-m-d g:i A') : '',
                    $p->hours === null ? '' : number_format($p->hours, 2, '.', ''),
                    $safe(implode('; ', $p->flags)),
                    TC::verifyLabel($p->in_verify),
                    TC::verifyLabel($p->out_verify),
                    TC::sourceLabel($p->clock_out_source),
                    $p->clock_in_ip,
                    $p->clock_out_ip,
                    $p->in_lat,
                    $p->in_lng,
                    $p->in_accuracy_m,
                    $p->in_distance_m,
                ]);
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    private function parseLocal($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return \Carbon::parse(str_replace('T', ' ', $v), TC::TZ)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function snapshot($p): array
    {
        return [
            'user_id' => (int) $p->user_id,
            'location_id' => $p->location_id ? (int) $p->location_id : null,
            'clock_in_at' => $p->clock_in_at,
            'clock_out_at' => $p->clock_out_at,
        ];
    }

    /** Add a punch (no id) or edit one (id). Reason required either way. */
    public function savePunch(Request $request)
    {
        $this->ensurePunchEditor();
        if (!TC::ready()) {
            return $this->back($request, false, 'The time clock tables are not set up yet (the migration has not run).');
        }
        $businessId = $this->businessId($request);
        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            return $this->back($request, false, 'Please add a reason for the change.');
        }
        $in = $this->parseLocal($request->input('clock_in_at'));
        $out = $this->parseLocal($request->input('clock_out_at'));
        if (!$in) {
            return $this->back($request, false, 'Please enter a clock-in time.');
        }
        if ($out && strtotime($out) <= strtotime($in)) {
            return $this->back($request, false, 'Clock-out has to be after clock-in.');
        }
        // No times in the future (Sarah 10/13). One minute of slack for a
        // form filled in right now.
        $limit = TC::now()->addMinute()->format('Y-m-d H:i:s');
        if ($in > $limit || ($out && $out > $limit)) {
            return $this->back($request, false, 'Times can not be in the future.');
        }
        $locId = (int) $request->input('location_id') ?: null;
        if ($locId && !isset(TC::locations($businessId)[$locId])) {
            return $this->back($request, false, 'Pick a store from the list.');
        }
        $now = TC::nowString();
        $id = (int) $request->input('id');
        $me = (int) auth()->id();

        if ($id) {
            $p = DB::table('time_clock_punches')->where('id', $id)->where('business_id', $businessId)->first();
            if (!$p) {
                return $this->back($request, false, 'That punch was not found.');
            }
            if ((int) $p->user_id === $me) {
                return $this->back($request, false, 'You can not change your own punches. Ask Jon, Sarah or Fatteen.');
            }
            if (!$out && !$p->voided_at && $this->hasOtherOpen((int) $p->user_id, $id)) {
                return $this->back($request, false, 'They already have an open punch. Add a clock-out time.');
            }
            $before = $this->snapshot($p);
            $upd = ['clock_in_at' => $in, 'clock_out_at' => $out, 'location_id' => $locId, 'edited' => 1, 'updated_by' => auth()->id(), 'updated_at' => $now];
            if ($out && !$p->clock_out_at) {
                $upd['clock_out_source'] = 'admin';
            }
            DB::table('time_clock_punches')->where('id', $id)->update($upd);
            TC::logEdit($id, 'edit', $reason, $before, $this->snapshot(DB::table('time_clock_punches')->where('id', $id)->first()));
            return $this->back($request, true, 'Punch updated.');
        }

        $userId = (int) $request->input('user_id');
        if (!DB::table('users')->where('id', $userId)->where('business_id', $businessId)->exists()) {
            return $this->back($request, false, 'Please pick a person.');
        }
        if ($userId === $me) {
            return $this->back($request, false, 'You can not add your own punches. Ask Jon, Sarah or Fatteen.');
        }
        if (!$out && $this->hasOtherOpen($userId, 0)) {
            return $this->back($request, false, 'They are already clocked in. Add a clock-out time.');
        }
        $newId = DB::table('time_clock_punches')->insertGetId([
            'business_id' => $businessId,
            'user_id' => $userId,
            'location_id' => $locId,
            'clock_in_at' => $in,
            'clock_out_at' => $out,
            'clock_in_source' => 'admin',
            'clock_out_source' => $out ? 'admin' : null,
            'created_by' => auth()->id(),
            'edited' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        TC::logEdit($newId, 'add', $reason, null, $this->snapshot(DB::table('time_clock_punches')->where('id', $newId)->first()));
        return $this->back($request, true, 'Punch added.');
    }

    /** Does the person have an open punch other than $exceptId? */
    private function hasOtherOpen(int $userId, int $exceptId): bool
    {
        return DB::table('time_clock_punches')->where('user_id', $userId)->where('id', '!=', $exceptId)
            ->whereNull('clock_out_at')->whereNull('voided_at')
            ->where('clock_in_at', '>=', TC::now()->subHours(TC::OPEN_MAX_HOURS)->format('Y-m-d H:i:s'))->exists();
    }

    /** Remove (void) or restore a punch. Never deletes the row. */
    public function voidPunch(Request $request)
    {
        $this->ensurePunchEditor();
        if (!TC::ready()) {
            return $this->back($request, false, 'The time clock tables are not set up yet.');
        }
        $businessId = $this->businessId($request);
        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            return $this->back($request, false, 'Please add a reason.');
        }
        $id = (int) $request->input('id');
        $p = DB::table('time_clock_punches')->where('id', $id)->where('business_id', $businessId)->first();
        if (!$p) {
            return $this->back($request, false, 'That punch was not found.');
        }
        if ((int) $p->user_id === (int) auth()->id()) {
            return $this->back($request, false, 'You can not change your own punches. Ask Jon, Sarah or Fatteen.');
        }
        $restore = $request->input('restore') === '1';
        $now = TC::nowString();
        DB::table('time_clock_punches')->where('id', $id)->update($restore
            ? ['voided_at' => null, 'voided_by' => null, 'void_reason' => null, 'updated_by' => auth()->id(), 'updated_at' => $now]
            : ['voided_at' => $now, 'voided_by' => auth()->id(), 'void_reason' => mb_substr($reason, 0, 500), 'updated_by' => auth()->id(), 'updated_at' => $now]);
        TC::logEdit($id, $restore ? 'restore' : 'void', $reason, $this->snapshot($p), null);
        return $this->back($request, true, $restore ? 'Punch restored.' : 'Punch removed. It stays in the history and can be restored.');
    }

    // ---- Settings: PINs, phones, stores ---------------------------------

    public function settingsPage(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $this->businessId($request);
        $ready = TC::ready();
        $stores = TC::stores($businessId);
        $locations = TC::locations($businessId);
        $saved = TC::settings()['stores'];
        $ip = TC::clientIp($request);
        $network = TC::networkFor($ip);
        $ipStore = TC::resolveStore($businessId, $ip, []);

        // People: clock roster (Sling, last 30 days) first, then other staff.
        $roster = TC::roster($businessId);
        $staff = TC::staff($businessId);
        $people = $roster + $staff;
        $pins = [];
        $devices = collect();
        if ($ready) {
            foreach (DB::table('time_clock_pins')->where('business_id', $businessId)->get() as $p) {
                $pins[(int) $p->user_id] = $p;
            }
            $devices = DB::table('time_clock_devices as d')->leftJoin('users as u', 'u.id', '=', 'd.user_id')
                ->where('d.business_id', $businessId)->whereNull('d.revoked_at')
                ->orderBy('u.first_name')->orderBy('d.last_used_at', 'desc')
                ->get(['d.*', 'u.first_name', 'u.last_name']);
        }

        // Store IP suggestions: the IPs of punches confirmed by location, and
        // the Outside-Store Logins history (where staff log in to the ERP).
        $suggest = [];
        try {
            if ($ready) {
                foreach (DB::table('time_clock_punches')->where('business_id', $businessId)->where('in_verify', 'gps')
                    ->whereNotNull('clock_in_ip')->whereNotNull('location_id')
                    ->where('clock_in_at', '>=', TC::now()->subDays(30)->format('Y-m-d H:i:s'))
                    ->select('clock_in_ip', 'location_id', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('clock_in_ip', 'location_id')->orderBy('cnt', 'desc')->limit(10)->get() as $r) {
                    $suggest[] = ['ip' => $r->clock_in_ip, 'location_id' => (int) $r->location_id, 'why' => $r->cnt . ' clock-ins confirmed by location'];
                }
            }
            if (\Schema::hasTable('login_activities')) {
                foreach (DB::table('login_activities')->where('business_id', $businessId)->where('successful', 1)->where('is_admin', 0)
                    ->whereNotNull('ip_address')
                    ->where('created_at', '>=', TC::now()->subDays(30)->format('Y-m-d H:i:s'))
                    ->select('ip_address', DB::raw('COUNT(*) as cnt'), DB::raw('COUNT(DISTINCT user_id) as people'))
                    ->groupBy('ip_address')->orderBy('cnt', 'desc')->limit(6)->get() as $r) {
                    $suggest[] = ['ip' => $r->ip_address, 'location_id' => null, 'why' => $r->cnt . ' ERP logins by ' . $r->people . ' staff (Outside-Store Logins)'];
                }
            }
        } catch (\Throwable $e) {
            // suggestions are optional
        }

        // POS candidates not trusted yet: [location_id => [net => [people, days, best day sales]]]
        $posSeen = [];
        try {
            if (TC::ipSeenReady()) {
                foreach (DB::table('time_clock_ip_seen')->where('day', '>=', TC::now()->subDays(TC::LEARNED_IP_DAYS)->toDateString())
                    ->select('location_id', 'net', DB::raw('COUNT(DISTINCT user_id) as people'), DB::raw('COUNT(DISTINCT day) as days'), DB::raw('MAX(day) as last_day'))
                    ->groupBy('location_id', 'net')->orderBy('last_day', 'desc')->limit(40)->get() as $r) {
                    $posSeen[(int) $r->location_id][$r->net] = $r;
                }
            }
        } catch (\Throwable $e) {
            // optional
        }
        $setupLink = $request->session()->get('tc_setup_link');
        $posManagers = TC::posManagerIds();
        $slackIds = [];
        try {
            $slackIds = TC::slackIds($businessId);
        } catch (\Throwable $e) {
            // optional
        }
        $requireAtStore = TC::requireAtStore();
        $isOwner = $this->isOwner();
        $requireSchedule = TC::requireSchedule();
        $scheduleWindow = TC::scheduleWindow();
        return view('time_clock.settings', compact('posManagers', 'slackIds', 'posSeen', 'setupLink', 'isOwner', 'requireAtStore', 'requireSchedule', 'scheduleWindow', 'ready', 'stores', 'locations', 'saved', 'ip', 'network', 'ipStore', 'roster', 'people', 'pins', 'devices', 'suggest'));
    }

    public function saveStores(Request $request)
    {
        $this->ensureAdmin();
        // Store locations, store networks and the anti-cheat switches decide
        // where punches count: owners only, so nobody can add their home IP.
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can change the store settings.');
        }
        $businessId = $this->businessId($request);
        $locations = TC::locations($businessId);
        $settings = TC::settings();
        $bad = [];
        $stores = [];
        foreach ((array) $request->input('stores', []) as $locId => $in) {
            $locId = (int) $locId;
            if (!isset($locations[$locId]) || !is_array($in)) { continue; }
            $lat = trim((string) ($in['lat'] ?? ''));
            $lng = trim((string) ($in['lng'] ?? ''));
            $ips = [];
            foreach (preg_split('/[\s,]+/', (string) ($in['ips'] ?? '')) as $rule) {
                $rule = trim($rule);
                if ($rule === '') { continue; }
                if (!filter_var(explode('/', $rule)[0], FILTER_VALIDATE_IP)) { $bad[] = $rule; continue; }
                $ips[] = $rule;
            }
            $prev = $settings['stores'][$locId] ?? $settings['stores'][(string) $locId] ?? [];
            $learned = TC::freshLearned(is_array($prev) ? ($prev['auto_ips'] ?? []) : []);
            // Forgotten auto IPs stay forgotten (not learned again).
            $blocked = is_array($prev) && is_array($prev['auto_blocked'] ?? null) ? $prev['auto_blocked'] : [];
            foreach ((array) ($in['forget_learned'] ?? []) as $rule) {
                unset($learned[(string) $rule]);
                $blocked[(string) $rule] = TC::now()->toDateString();
            }
            $stores[$locId] = [
                'auto_ips' => $learned,
                'auto_src' => array_intersect_key(is_array($prev) && is_array($prev['auto_src'] ?? null) ? $prev['auto_src'] : [], $learned),
                'manual_src' => array_intersect_key(is_array($prev) && is_array($prev['manual_src'] ?? null) ? $prev['manual_src'] : [], array_flip(array_values(array_unique($ips)))),
                'auto_blocked' => $blocked,
                'radius_v2' => true,
                'lat' => is_numeric($lat) && abs((float) $lat) <= 90 ? (float) $lat : '',
                'lng' => is_numeric($lng) && abs((float) $lng) <= 180 ? (float) $lng : '',
                'radius_m' => max(50, min(2000, (int) ($in['radius_m'] ?? TC::DEFAULT_RADIUS_M) ?: TC::DEFAULT_RADIUS_M)),
                'ips' => array_values(array_unique($ips)),
                'disabled' => !empty($in['disabled']),
            ];
        }
        $settings['stores'] = $stores;
        $settings['require_at_store'] = $request->input('require_at_store') === '1';
        $settings['require_schedule'] = $request->input('require_schedule') === '1';
        $settings['schedule_window_min'] = max(1, min(240, (int) $request->input('schedule_window_min', TC::DEFAULT_WINDOW_MIN) ?: TC::DEFAULT_WINDOW_MIN));
        $settings['window_v2'] = true;
        TC::saveSettings($settings);
        $msg = 'Store settings saved.';
        if ($bad) { $msg .= ' Skipped these because they are not IP addresses: ' . implode(', ', $bad) . '.'; }
        return $this->back($request, true, $msg, '/time-clock/settings');
    }

    /** "Add this network to store X" (open the page on the store Wi-Fi). */
    public function allowIp(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can add store networks.');
        }
        $businessId = $this->businessId($request);
        $locId = (int) $request->input('location_id');
        $locations = TC::locations($businessId);
        if (!isset($locations[$locId])) {
            return $this->back($request, false, 'Pick a store.', '/time-clock/settings');
        }
        $ip = trim((string) $request->input('ip')) ?: TC::clientIp($request);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->back($request, false, 'That is not an IP address.', '/time-clock/settings');
        }
        $rule = TC::addManualIp($businessId, $locId, $ip, 'manual');
        return $this->back($request, true, $rule . ' now counts as ' . TC::shortStoreName($locations[$locId]) . '.', '/time-clock/settings');
    }

    public function setPin(Request $request)
    {
        $this->ensureAdmin();
        if (!TC::ready()) {
            return $this->back($request, false, 'The time clock tables are not set up yet (the migration has not run).', '/time-clock/settings');
        }
        $businessId = $this->businessId($request);
        $userId = (int) $request->input('user_id');
        $user = DB::table('users')->where('id', $userId)->where('business_id', $businessId)->first();
        if (!$user) {
            return $this->back($request, false, 'Pick a person.', '/time-clock/settings');
        }
        // PINs are unique: the PIN alone says who is clocking in.
        $pin = trim((string) $request->input('pin'));
        if ($pin === '') {
            $pin = TC::uniqueRandomPin($businessId, $userId);
            if ($pin === null) {
                return $this->back($request, false, 'Could not find a free PIN. Try again.', '/time-clock/settings');
            }
        }
        if (!preg_match('/^\d{4}$/', $pin)) {
            return $this->back($request, false, 'A PIN is exactly 4 numbers.', '/time-clock/settings');
        }
        if (TC::isObviousPin($pin)) {
            return $this->back($request, false, 'Pick a less obvious PIN than ' . $pin . '.', '/time-clock/settings');
        }
        if (TC::pinOwner($businessId, $pin, $userId) !== null) {
            return $this->back($request, false, 'Someone else already has PIN ' . $pin . '. Pick another one, or leave it blank for a random one.', '/time-clock/settings');
        }
        TC::setPin($businessId, $userId, $pin, auth()->id());
        return $this->back($request, true, 'PIN for ' . TC::displayName($user) . ' is ' . $pin . '. Tell them in person; it is not shown again.', '/time-clock/settings');
    }

    /**
     * Who may add, edit or remove punches on Timecards (Sarah 10/13): Jon
     * (Jonathan Hedvat), Sarah Hedvat and Fatteen (username "fatteen",
     * account "Nerdy Solutions"), by user ID. The IDs are looked up once
     * (oldest matching account wins, so a later rename can't take over)
     * and pinned in the time clock settings file.
     */
    private function punchEditorIds(): array
    {
        $s = TC::settings();
        if (!empty($s['punch_editors']) && is_array($s['punch_editors']) && count($s['punch_editors']) === 3) {
            return array_map('intval', array_values($s['punch_editors']));
        }
        $ids = [];
        try {
            $ids['jon'] = (int) DB::table('users')->whereNull('deleted_at')->where(DB::raw('LOWER(TRIM(last_name))'), 'hedvat')
                ->whereIn(DB::raw('LOWER(TRIM(first_name))'), ['jon', 'jonathan'])->orderBy('id')->value('id');
            $ids['sarah'] = (int) DB::table('users')->whereNull('deleted_at')->where(DB::raw('LOWER(TRIM(last_name))'), 'hedvat')
                ->where(DB::raw('LOWER(TRIM(first_name))'), 'sarah')->orderBy('id')->value('id');
            $ids['fatteen'] = (int) DB::table('users')->whereNull('deleted_at')->where(DB::raw('LOWER(TRIM(username))'), 'fatteen')
                ->orderBy('id')->value('id');
        } catch (\Throwable $e) {
            \Log::warning('time clock punch editors lookup failed: ' . $e->getMessage());
            return [];
        }
        $ids = array_filter($ids);
        if (count($ids) === 3) {
            try {
                $s = TC::settings();
                $s['punch_editors'] = $ids;
                TC::saveSettings($s);
            } catch (\Throwable $e) {
                // use them unpinned
            }
        }
        return array_map('intval', array_values($ids));
    }

    private function canEditPunches(): bool
    {
        return auth()->check() && in_array((int) auth()->id(), $this->punchEditorIds(), true);
    }

    private function ensurePunchEditor(): void
    {
        $this->ensureAdmin();
        if (!$this->canEditPunches()) {
            abort(403, 'Only Jon, Sarah and Fatteen can change punches.');
        }
    }

    /** Owners only (same rule as payroll rates): first name jon/jonathan/sarah. */
    private function isOwner(): bool
    {
        $u = auth()->user();
        if (!$u) {
            return false;
        }
        return in_array(strtolower(trim((string) $u->first_name)), ['jon', 'jonathan', 'sarah'], true);
    }

    /**
     * Owner-only: give everyone on the clock list (Sling, last 30 days) who
     * has no PIN a unique random PIN, and show printable slips once. The
     * PINs are never stored in plain text, logged, or put in the session.
     */
    public function bulkPins(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can hand out PINs in bulk.');
        }
        if (!TC::ready()) {
            return $this->back($request, false, 'The time clock tables are not set up yet.', '/time-clock/settings');
        }
        $businessId = $this->businessId($request);
        $have = DB::table('time_clock_pins')->where('business_id', $businessId)->pluck('user_id')->map(function ($v) { return (int) $v; })->all();
        $slips = [];
        foreach (TC::roster($businessId) as $uid => $name) {
            if (in_array((int) $uid, $have, true)) {
                continue;
            }
            $pin = TC::uniqueRandomPin($businessId, (int) $uid);
            if ($pin === null) {
                continue;
            }
            TC::setPin($businessId, (int) $uid, $pin, auth()->id());
            $slips[] = ['name' => $name, 'pin' => $pin];
        }
        \Log::info('time clock: bulk PINs generated', [
            'by_user_id' => auth()->id(),
            'count' => count($slips),
            'user_names' => array_column($slips, 'name'),
        ]);
        if (!$slips) {
            return $this->back($request, true, 'Everyone on the clock list already has a PIN.', '/time-clock/settings');
        }
        return response()->view('time_clock.pin_slips', ['slips' => $slips, 'url' => url('/clock')])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Owner-only: everyone on the clock list with a Slack ID and no PIN
     * gets a new unique PIN as a Slack DM. A PIN is saved only when its DM
     * went through. The result page shows names and sent / not sent only.
     */
    public function slackPins(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can send PINs.');
        }
        if (!TC::ready()) {
            return $this->back($request, false, 'The time clock tables are not set up yet.', '/time-clock/settings');
        }
        @set_time_limit(300);
        $businessId = $this->businessId($request);
        $slackIds = TC::slackIds($businessId);
        $have = DB::table('time_clock_pins')->where('business_id', $businessId)->pluck('user_id')->map(function ($v) { return (int) $v; })->all();
        $results = [];
        foreach (TC::roster($businessId) as $uid => $name) {
            if (in_array((int) $uid, $have, true)) {
                continue;
            }
            if (empty($slackIds[(int) $uid])) {
                $results[] = ['name' => $name, 'sent' => false, 'why' => 'No Slack ID, set by hand'];
                continue;
            }
            $user = DB::table('users')->where('id', (int) $uid)->first();
            $r = $user ? TC::sendNewPinOnSlack($businessId, $user, $slackIds[(int) $uid], auth()->id()) : ['ok' => false, 'error' => 'user not found'];
            $results[] = ['name' => $name, 'sent' => $r['ok'], 'why' => $r['ok'] ? '' : 'Not sent (' . $r['error'] . '), set by hand'];
        }
        \Log::info('time clock: Slack PINs sent', ['by_user_id' => auth()->id(), 'sent' => count(array_filter(array_column($results, 'sent'))), 'not_sent' => count(array_filter($results, function ($r) { return !$r['sent']; }))]);
        return response()->view('time_clock.slack_result', ['results' => $results, 'title' => 'PINs sent on Slack'])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Owner-only: reset one person's PIN and DM the new one on Slack. */
    public function slackResend(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can send PINs.');
        }
        if (!TC::ready()) {
            return $this->back($request, false, 'The time clock tables are not set up yet.', '/time-clock/settings');
        }
        $businessId = $this->businessId($request);
        $uid = (int) $request->input('user_id');
        $user = DB::table('users')->where('id', $uid)->where('business_id', $businessId)->first();
        if (!$user) {
            return $this->back($request, false, 'Pick a person.', '/time-clock/settings');
        }
        $sid = TC::slackIds($businessId)[$uid] ?? '';
        if ($sid === '') {
            return $this->back($request, false, TC::displayName($user) . ' has no Slack ID yet.', '/time-clock/settings');
        }
        $r = TC::sendNewPinOnSlack($businessId, $user, $sid, auth()->id());
        return $this->back($request, $r['ok'], $r['ok']
            ? 'New PIN sent to ' . TC::displayName($user) . ' on Slack.'
            : 'Not sent to ' . TC::displayName($user) . ' (' . $r['error'] . '). Their PIN did not change.', '/time-clock/settings');
    }

    /** Owner-only: who sees the Manager duty on the POS duty picker. */
    public function savePosManagers(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can change the manager list.');
        }
        $businessId = $this->businessId($request);
        $ids = [];
        foreach ((array) $request->input('manager_ids', []) as $id) {
            $id = (int) $id;
            if ($id && DB::table('users')->where('id', $id)->where('business_id', $businessId)->exists()) {
                $ids[] = $id;
            }
        }
        TC::savePosManagerIds($ids);
        return $this->back($request, true, 'Manager list saved.', '/time-clock/settings');
    }

    /** Owner-only: set or clear someone's Slack user ID. */
    public function saveSlackId(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can change Slack IDs.');
        }
        $businessId = $this->businessId($request);
        $uid = (int) $request->input('user_id');
        if (!DB::table('users')->where('id', $uid)->where('business_id', $businessId)->exists()) {
            return $this->back($request, false, 'Pick a person.', '/time-clock/settings');
        }
        $sid = strtoupper(trim((string) $request->input('slack_id')));
        if ($sid !== '' && !TC::validSlackId($sid)) {
            return $this->back($request, false, 'A Slack ID looks like U07D33PMQLA.', '/time-clock/settings');
        }
        TC::slackIds($businessId); // seed first so the seed can't overwrite this
        TC::saveSlackId($uid, $sid);
        return $this->back($request, true, 'Slack ID saved.', '/time-clock/settings');
    }

    public function clearPin(Request $request)
    {
        $this->ensureAdmin();
        if (TC::ready()) {
            DB::table('time_clock_pins')->where('user_id', (int) $request->input('user_id'))->where('business_id', $this->businessId($request))->delete();
        }
        return $this->back($request, true, 'PIN removed. Phones already set up keep working until you revoke them.', '/time-clock/settings');
    }

    public function revokeDevice(Request $request)
    {
        $this->ensureAdmin();
        if (TC::ready()) {
            $businessId = $this->businessId($request);
            if ($request->input('user_id')) {
                foreach (DB::table('time_clock_devices')->where('business_id', $businessId)->where('user_id', (int) $request->input('user_id'))->whereNull('revoked_at')->pluck('id') as $id) {
                    TC::revokeDevice((int) $id, auth()->id());
                }
            } else {
                $id = (int) DB::table('time_clock_devices')->where('business_id', $businessId)->where('id', (int) $request->input('id'))->value('id');
                if ($id) { TC::revokeDevice($id, auth()->id()); }
            }
        }
        return $this->back($request, true, 'Phone signed out. They need their PIN next time.', '/time-clock/settings');
    }

    /** Owner: a single-use link (24 hours) that sets up a store computer. */
    public function setupLink(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can make setup links.');
        }
        $businessId = $this->businessId($request);
        $locId = (int) $request->input('location_id');
        $locations = TC::locations($businessId);
        if (!isset($locations[$locId])) {
            return $this->back($request, false, 'Pick a store.', '/time-clock/settings');
        }
        $token = TC::makeSetupToken($locId, auth()->id());
        return redirect('/time-clock/settings')->with('tc_setup_link', [
            'url' => url('/clock/setup/' . $token),
            'store' => TC::shortStoreName($locations[$locId]),
        ]);
    }

    /** Owner: scan POS history (logins + register opens) for store networks again. */
    public function posBackfill(Request $request)
    {
        $this->ensureAdmin();
        if (!$this->isOwner()) {
            abort(403, 'Only the owners can do this.');
        }
        if (!TC::ipSeenReady()) {
            return $this->back($request, false, 'The table for this is not set up yet (the migration has not run).', '/time-clock/settings');
        }
        $promoted = TC::backfillPosIps();
        $n = array_sum(array_map('count', $promoted));
        return $this->back($request, true, $n ? $n . ' store network(s) added from POS history.' : 'No new store networks found in POS history.', '/time-clock/settings');
    }

    /** Printable sheet: one QR per store pointing at /clock. */
    public function qrSheet(Request $request)
    {
        $this->ensureAdmin();
        $businessId = $this->businessId($request);
        $url = url('/clock');
        $qr = null;
        try {
            $qr = \DNS2D::getBarcodePNG($url, 'QRCODE', 10, 10);
        } catch (\Throwable $e) {
            \Log::warning('time clock QR failed: ' . $e->getMessage());
        }
        $stores = TC::stores($businessId);
        return view('time_clock.qr', compact('url', 'qr', 'stores'));
    }
}
