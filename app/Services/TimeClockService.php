<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Phone time clock (Sarah, 2026-10-13). Clover's Shifts clock-in app is no
 * longer supported, so staff clock in and out from their own phones at
 * /clock (no ERP login). First time on a phone they pick their name and
 * enter a 4-digit PIN; the phone is then remembered with a long-lived,
 * httpOnly, encrypted cookie holding a random token (only its sha256 is
 * stored in time_clock_devices, so an admin can revoke it).
 *
 * Store check: browser location within each store's radius (coordinates
 * in storage/app/time-clock/settings.json, editable on Time clock
 * settings), else the store IP allowlist. If neither confirms the store
 * the punch still goes through, marked "not verified" for admins.
 *
 * Until the migration runs every method quietly does nothing (ready()).
 * Times are America/Los_Angeles (the app timezone).
 */
class TimeClockService
{
    const TZ = 'America/Los_Angeles';
    const SETTINGS_FILE = 'time-clock/settings.json';
    const COOKIE = 'nv_timeclock';
    const COOKIE_MINUTES = 576000; // 400 days, the most browsers keep a cookie
    // A punch still open after this many hours is a missed clock-out, not
    // "currently clocked in".
    const OPEN_MAX_HOURS = 18;
    const PIN_LOCK_MINUTES = 15;
    const IP_MAX_FAILS = 15;
    const GLOBAL_MAX_FAILS = 60;
    const DEFAULT_RADIUS_M = 100;
    // Location passes only when distance + reported accuracy <= radius +
    // RADIUS_SLACK_M (Sarah 10/13, tightened): a rough fix fails instead of
    // helping, so nobody passes from blocks away. The phone retries once
    // for a better fix, then the store Wi-Fi (IP allowlist) is the fallback.
    const RADIUS_SLACK_M = 50;
    // Auto-learned store IPs expire when not seen on a location-confirmed
    // punch for this many days. Manual IPs never expire.
    const LEARNED_IP_DAYS = 30;
    // An IP is only auto-learned once location-confirmed punches on it come
    // from at least this many different people on this many different days
    // (Sarah 10/13): one person GPS-spoofing from home can't teach the
    // clock their home network.
    const LEARN_MIN_PEOPLE = 2;
    const LEARN_MIN_DAYS = 2;
    // Clock-in more than this many minutes after the shift start is "late".
    const LATE_GRACE_MIN = 15;
    // Clock-in opens this many minutes before a shift (editable on settings).
    const DEFAULT_WINDOW_MIN = 5;
    // Clock-out more than this many minutes after the shift end is flagged
    // "Stayed N min past shift" on Timecards and Payroll (hours unchanged).
    const STAYED_GRACE_MIN = 15;
    // Store computer: the same PIN again within this many seconds of a punch
    // shows the current state instead of flipping it (double taps, retries).
    const TOGGLE_GUARD_SEC = 60;
    const STORE_IP_MAX_FAILS = 30;
    const GLOBAL_DAY_MAX_FAILS = 300;

    // Geocoded once (OpenStreetMap / Census, 2026-10-13) from the addresses
    // on nivessa.com. Matched to business_locations by name. Admins can
    // change them on Time clock settings.
    const DEFAULT_STORES = [
        'hollywood' => ['lat' => 34.1014255, 'lng' => -118.3307521, 'address' => '6434 Hollywood Blvd, Los Angeles, CA 90028'],
        'pico'      => ['lat' => 34.0508953, 'lng' => -118.3639600, 'address' => '5770 W Pico Blvd, Los Angeles, CA 90019'],
    ];

    private static $tableOk = null;

    public static function ready(): bool
    {
        if (self::$tableOk !== null) {
            return self::$tableOk;
        }
        // Cached so hooks (register close, End Shift) don't ask
        // information_schema every time: yes for a day, no for 5 minutes.
        try {
            $cached = \Cache::get('time_clock_tables_ready_v2');
            if ($cached === 1) {
                return self::$tableOk = true;
            }
            if ($cached === 0) {
                return self::$tableOk = false;
            }
        } catch (\Throwable $e) {
            // no cache, check directly
        }
        try {
            self::$tableOk = Schema::hasTable('time_clock_punches')
                && Schema::hasTable('time_clock_punch_edits')
                && Schema::hasTable('time_clock_pins')
                && Schema::hasTable('time_clock_devices');
        } catch (\Throwable $e) {
            self::$tableOk = false;
        }
        try {
            \Cache::put('time_clock_tables_ready_v2', self::$tableOk ? 1 : 0, self::$tableOk ? 86400 : 300); // seconds (5.8)
        } catch (\Throwable $e) {
            // ignore
        }
        return self::$tableOk;
    }

    public static function now(): \Carbon\Carbon
    {
        return \Carbon::now(self::TZ);
    }

    public static function nowString(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    /** The business the public page belongs to (single-business install). */
    public static function businessId(): int
    {
        $s = self::settings();
        if (!empty($s['business_id'])) {
            return (int) $s['business_id'];
        }
        try {
            return (int) (DB::table('business')->min('id') ?: 1);
        } catch (\Throwable $e) {
            return 1;
        }
    }

    // ---- Settings (store coordinates, radius, IP allowlist) --------------

    public static function settings(): array
    {
        $d = ['stores' => [], 'business_id' => null];
        try {
            if (Storage::disk('local')->exists(self::SETTINGS_FILE)) {
                $j = json_decode(Storage::disk('local')->get(self::SETTINGS_FILE), true);
                if (is_array($j)) {
                    $d = array_merge($d, $j);
                }
            }
        } catch (\Throwable $e) {
            // defaults
        }
        if (!is_array($d['stores'])) {
            $d['stores'] = [];
        }
        return $d;
    }

    public static function saveSettings(array $s): void
    {
        Storage::disk('local')->put(self::SETTINGS_FILE, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** "Require being at the store" (default on; admin switch, no deploy). */
    public static function requireAtStore(): bool
    {
        $s = self::settings();
        return !array_key_exists('require_at_store', $s) || !empty($s['require_at_store']);
    }

    /** "Only allow clock-in near a scheduled shift" (default on). */
    public static function requireSchedule(): bool
    {
        $s = self::settings();
        return !array_key_exists('require_schedule', $s) || !empty($s['require_schedule']);
    }

    /**
     * Minutes before a scheduled start that clock-in opens (default 5, Sarah
     * 10/13: no paying for unneeded early time). A saved 15 from before the
     * change was just the old default.
     */
    public static function scheduleWindow(): int
    {
        $s = self::settings();
        $v = (int) ($s['schedule_window_min'] ?? self::DEFAULT_WINDOW_MIN);
        if ($v === 15 && empty($s['window_v2'])) {
            $v = self::DEFAULT_WINDOW_MIN;
        }
        return $v > 0 ? min($v, 240) : self::DEFAULT_WINDOW_MIN;
    }

    /**
     * The person's Sling shifts (type shift, LA times) between $from and $to
     * (dtstart, 'Y-m-d H:i:s'), at the store named $storeName ('' = any),
     * merged into blocks when back to back (gap <= 15 min). A shift ending
     * before it starts runs past midnight. [['start' => ts, 'end' => ts]]
     */
    public static function scheduleBlocks(int $userId, string $from, string $to, string $storeName = ''): array
    {
        if (!Schema::hasTable('sling_shifts')) {
            return [];
        }
        $email = strtolower(trim((string) DB::table('users')->where('id', $userId)->value('email')));
        $rows = DB::table('sling_shifts')->where('event_type', 'shift')
            ->where(function ($q) use ($userId, $email) {
                $q->where('erp_user_id', $userId);
                if ($email !== '') {
                    $q->orWhere(function ($o) use ($email) {
                        $o->whereNull('erp_user_id')->where(DB::raw('LOWER(user_email)'), $email);
                    });
                }
            })
            ->where('dtstart', '>=', $from)
            ->where('dtstart', '<=', $to)
            ->orderBy('dtstart')
            ->get(['dtstart', 'dtend', 'location_name']);
        $blocks = [];
        foreach ($rows as $r) {
            if ($storeName !== '' && $r->location_name && stripos((string) $r->location_name, $storeName) === false) {
                continue; // a shift at the other store
            }
            $st = \Carbon::parse($r->dtstart, self::TZ)->timestamp;
            $en = $r->dtend ? \Carbon::parse($r->dtend, self::TZ)->timestamp : $st + 8 * 3600;
            if ($en <= $st) {
                $en += 86400;
            }
            $n = count($blocks);
            if ($n && $st <= $blocks[$n - 1]['end'] + 15 * 60) {
                $blocks[$n - 1]['end'] = max($blocks[$n - 1]['end'], $en);
            } else {
                $blocks[] = ['start' => $st, 'end' => $en];
            }
        }
        return $blocks;
    }

    /**
     * Scheduled-time flags for a finished or open punch, given that
     * person's blocks: the block the clock-in falls in (from an hour before
     * its start to its end), and minutes stayed past its end when over
     * STAYED_GRACE_MIN. Info only: recorded hours are never changed (CA
     * pays all time worked). ['block' => ?array, 'stayed_min' => ?int]
     */
    public static function blockFlags(array $blocks, string $clockIn, ?string $clockOut): array
    {
        $in = strtotime($clockIn);
        foreach ($blocks as $b) {
            if ($in >= $b['start'] - 3600 && $in < $b['end']) {
                $stayed = null;
                if ($clockOut) {
                    $over = strtotime($clockOut) - $b['end'];
                    if ($over > self::STAYED_GRACE_MIN * 60) {
                        $stayed = (int) floor($over / 60);
                    }
                }
                return ['block' => $b, 'stayed_min' => $stayed];
            }
        }
        return ['block' => null, 'stayed_min' => null];
    }

    /** Does sling_shifts have any shift on this LA date (sync working)? */
    public static function hasScheduleData(string $date): bool
    {
        try {
            if (!Schema::hasTable('sling_shifts')) {
                return false;
            }
            return DB::table('sling_shifts')->where('event_type', 'shift')
                ->where(function ($q) use ($date) {
                    $q->whereBetween('dtstart', [$date . ' 00:00:00', $date . ' 23:59:59'])
                      ->orWhere(function ($o) use ($date) {
                          $o->where('dtstart', '<', $date . ' 00:00:00')->where('dtend', '>', $date . ' 00:00:00');
                      });
                })->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Buddy-punch guard (Sarah 10/13, late rule 10/13 evening): may this
     * person clock in now at this store? Their Sling shifts (type shift;
     * times are LA) are merged into blocks when back to back (gap <= 15
     * min, e.g. Open Store Checklist 09:15 then Cashier 10:00; a shift
     * ending before it starts runs past midnight). Clock-in is allowed from
     * the window (15 min) before a block starts until that block ends.
     * More than LATE_GRACE_MIN after the start is "late", unless they were
     * already clocked in during that block (back from a break).
     * Returns ['ok', 'no_data', 'late_min' (int|null), 'sched_start' (Y-m-d H:i:s|null)].
     */
    public static function scheduleAllows(int $userId, ?int $locId, int $businessId): array
    {
        $now = self::now();
        $today = $now->toDateString();
        if (!self::hasScheduleData($today)) {
            return ['ok' => true, 'no_data' => true, 'late_min' => null, 'sched_start' => null];
        }
        $win = self::scheduleWindow() * 60;
        $nowTs = $now->timestamp;
        $storeName = $locId ? self::storeName($businessId, $locId) : '';
        // Look ahead 12 hours too, to tell "too early" from "not scheduled".
        $blocks = self::scheduleBlocks($userId, $now->copy()->subHours(24)->format('Y-m-d H:i:s'),
            $now->copy()->addHours(12)->format('Y-m-d H:i:s'), $storeName);
        foreach ($blocks as $b) {
            if ($nowTs < $b['start'] - $win || $nowTs >= $b['end']) {
                continue; // too early for this block, or it is over
            }
            $late = null;
            $lateSec = $nowTs - $b['start'];
            if ($lateSec > self::LATE_GRACE_MIN * 60) {
                // Back from a break: already clocked in during this block.
                $back = DB::table('time_clock_punches')->where('user_id', $userId)->whereNull('voided_at')
                    ->where('clock_in_at', '>=', \Carbon::createFromTimestamp($b['start'] - $win, self::TZ)->format('Y-m-d H:i:s'))
                    ->exists();
                if (!$back) {
                    $late = (int) floor($lateSec / 60);
                }
            }
            return ['ok' => true, 'no_data' => false, 'late_min' => $late,
                'sched_start' => \Carbon::createFromTimestamp($b['start'], self::TZ)->format('Y-m-d H:i:s')];
        }
        $early = false;
        foreach ($blocks as $b) {
            if ($b['start'] - $win > $nowTs && \Carbon::createFromTimestamp($b['start'], self::TZ)->toDateString() === $today) {
                $early = true;
                break;
            }
        }
        return ['ok' => false, 'no_data' => false, 'late_min' => null, 'sched_start' => null, 'early' => $early];
    }

    /** Learned IPs seen within LEARNED_IP_DAYS: [rule => 'Y-m-d']. */
    public static function freshLearned($learned): array
    {
        $out = [];
        $cut = self::now()->subDays(self::LEARNED_IP_DAYS)->toDateString();
        foreach ((array) $learned as $rule => $seen) {
            if (is_string($rule) && $rule !== '' && is_string($seen) && $seen >= $cut) {
                $out[$rule] = $seen;
            }
        }
        return $out;
    }

    /**
     * A punch was confirmed at store $locId by location. Remember the
     * network it came from as a store network only when location-confirmed
     * punches on it come from LEARN_MIN_PEOPLE different people on
     * LEARN_MIN_DAYS different days in the last LEARNED_IP_DAYS (so one
     * person faking their location at home can't add their home IP).
     * An IP already learned is just refreshed. Stored under "auto_ips"
     * (the older "learned_ips" list had no such check and is ignored).
     * Call after the punch is saved. Never throws.
     */
    public static function learnIp(int $locId, string $ip): void
    {
        try {
            if (!$locId || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return; // a private / loopback address says nothing about where someone is
            }
            $rule = self::networkFor($ip);
            $today = self::now()->toDateString();
            $settings = self::settings();
            $cur = $settings['stores'][$locId] ?? $settings['stores'][(string) $locId] ?? [];
            if (!is_array($cur)) {
                $cur = [];
            }
            if (in_array($rule, (array) ($cur['ips'] ?? []), true)) {
                return; // already a manual IP
            }
            $learned = self::freshLearned($cur['auto_ips'] ?? []);
            if (($learned[$rule] ?? '') === $today) {
                return;
            }
            if (!isset($learned[$rule]) && !self::ipHasEnoughEvidence($locId, $rule)) {
                return;
            }
            self::trustAuto($locId, $rule, 'gps');
        } catch (\Throwable $e) {
            \Log::warning('time clock learn ip failed: ' . $e->getMessage());
        }
    }

    /**
     * Make $rule a trusted auto IP for store $locId (seen today), or just
     * refresh its date when it already is one. $src: gps (clock location),
     * pos (POS sales / register open and close). An IP belongs to one
     * store, so it is dropped from any other store. Writes the settings
     * file at most once per IP per day.
     */
    public static function trustAuto(int $locId, string $rule, string $src): bool
    {
        $today = self::now()->toDateString();
        $settings = self::settings();
        $cur = $settings['stores'][$locId] ?? $settings['stores'][(string) $locId] ?? [];
        if (!is_array($cur)) {
            $cur = [];
        }
        if (in_array($rule, (array) ($cur['ips'] ?? []), true)) {
            return true; // already a manual IP
        }
        if (isset($cur['auto_blocked'][$rule])) {
            return false; // an owner removed it
        }
        $learned = self::freshLearned($cur['auto_ips'] ?? []);
        if (($learned[$rule] ?? '') === $today) {
            return true;
        }
        foreach ($settings['stores'] as $otherId => $other) {
            if ((int) $otherId !== $locId && is_array($other) && isset($other['auto_ips'][$rule])) {
                unset($settings['stores'][$otherId]['auto_ips'][$rule], $settings['stores'][$otherId]['auto_src'][$rule]);
            }
        }
        $src0 = is_array($cur['auto_src'] ?? null) ? $cur['auto_src'] : [];
        if (!isset($learned[$rule]) || empty($src0[$rule])) {
            $src0[$rule] = $src;
        }
        $learned[$rule] = $today;
        $cur['auto_ips'] = $learned;
        $cur['auto_src'] = array_intersect_key($src0, $learned);
        unset($cur['learned_ips']);
        unset($settings['stores'][(string) $locId]);
        $settings['stores'][$locId] = $cur;
        self::saveSettings($settings);
        return true;
    }

    /** A punch passed on a store IP: keep that auto IP from expiring. Never throws. */
    public static function touchAutoIp(?int $locId, string $ip): void
    {
        try {
            if (!$locId || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return;
            }
            $rule = self::networkFor($ip);
            $st = self::stores(self::businessId())[$locId] ?? null;
            if ($st && isset($st['learned_ips'][$rule]) && $st['learned_ips'][$rule] !== self::now()->toDateString()) {
                self::trustAuto($locId, $rule, 'gps');
            }
        } catch (\Throwable $e) {
            // optional
        }
    }

    // ---- Store IPs learned from POS activity (Sarah 10/13) ---------------
    //
    // The store computers ring sales, so the network they ring from is the
    // store's. Each finalized sale and register open/close records its real
    // client IP (clientIp(), never X-Forwarded-For) as a candidate for that
    // location in time_clock_ip_seen. It becomes a trusted auto IP once
    // seen from POS_MIN_PEOPLE staff on POS_MIN_DAYS days, or with
    // POS_DAY_SALES sales on one day (last LEARNED_IP_DAYS days).

    const POS_MIN_PEOPLE = 2;
    const POS_MIN_DAYS = 2;
    const POS_DAY_SALES = 10;

    public static function ipSeenReady(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (\Cache::get('time_clock_ip_seen_v1') === 1) {
                return $ok = true;
            }
            $ok = Schema::hasTable('time_clock_ip_seen');
            if ($ok) {
                \Cache::put('time_clock_ip_seen_v1', 1, 86400);
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * POS hook: a sale was finalized / a register opened or closed at
     * $locId by $userId. Cheap: one cache counter per call, and at most one
     * DB upsert (plus the promotion check) per IP, store and person per
     * hour. Never throws.
     */
    public static function notePosIp(?Request $request, $locId, $userId, int $sales = 1): void
    {
        try {
            $locId = (int) $locId;
            $userId = (int) $userId;
            if (!$request || !$locId || !$userId) {
                return;
            }
            $ip = self::clientIp($request);
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return;
            }
            $rule = self::networkFor($ip);
            $day = self::now()->toDateString();
            $k = md5($locId . '|' . $rule . '|' . $userId . '|' . $day);
            $cntKey = 'tc_pos_cnt_' . $k;
            \Cache::add($cntKey, 0, 90000); // seconds (5.8)
            $cnt = (int) \Cache::increment($cntKey, $sales);
            if (!\Cache::add('tc_pos_hr_' . $k . '_' . self::now()->format('H'), 1, 3600)) {
                return; // already written this hour
            }
            if (!self::ipSeenReady()) {
                return;
            }
            self::upsertSeen($locId, $rule, $userId, $day, max(1, $cnt), 'pos');
            self::promoteIfEarned($locId, $rule);
        } catch (\Throwable $e) {
            \Log::warning('time clock POS ip note failed: ' . $e->getMessage());
        }
    }

    private static function upsertSeen(int $locId, string $rule, int $userId, string $day, int $cnt, string $src): void
    {
        $now = self::nowString();
        DB::statement('INSERT INTO time_clock_ip_seen (location_id, net, user_id, day, cnt, source, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE cnt = GREATEST(cnt, VALUES(cnt)), updated_at = VALUES(updated_at)',
            [$locId, mb_substr($rule, 0, 64), $userId, $day, $cnt, $src, $now, $now]);
    }

    /** Promote $rule at $locId to a trusted auto IP when the POS rule is met. */
    public static function promoteIfEarned(int $locId, string $rule): bool
    {
        $since = self::now()->subDays(self::LEARNED_IP_DAYS)->toDateString();
        $rows = DB::table('time_clock_ip_seen')->where('location_id', $locId)->where('net', $rule)
            ->where('day', '>=', $since)->get(['user_id', 'day', 'cnt']);
        $people = [];
        $days = [];
        $perDay = [];
        foreach ($rows as $r) {
            $people[(int) $r->user_id] = true;
            $days[(string) $r->day] = true;
            $perDay[(string) $r->day] = ($perDay[(string) $r->day] ?? 0) + (int) $r->cnt;
        }
        $earned = (count($people) >= self::POS_MIN_PEOPLE && count($days) >= self::POS_MIN_DAYS)
            || ($perDay && max($perDay) >= self::POS_DAY_SALES);
        if (!$earned) {
            return false;
        }
        // Seen at two stores (shouldn't happen): leave it to the owner.
        $elsewhere = DB::table('time_clock_ip_seen')->where('net', $rule)->where('location_id', '!=', $locId)
            ->where('day', '>=', $since)->exists();
        if ($elsewhere) {
            return false;
        }
        return self::trustAuto($locId, $rule, 'pos');
    }

    /**
     * One-time backfill from history: successful staff logins
     * (login_activities.ip_address) matched to a cash register the same
     * person opened within 3 hours after logging in (that gives the
     * store), last LEARNED_IP_DAYS days. Returns [location_id => [rules
     * promoted]]. Never throws.
     */
    public static function backfillPosIps(): array
    {
        $promoted = [];
        try {
            if (!self::ipSeenReady() || !Schema::hasTable('login_activities') || !Schema::hasTable('cash_registers')) {
                return [];
            }
            $since = self::now()->subDays(self::LEARNED_IP_DAYS)->format('Y-m-d H:i:s');
            $rows = DB::select('SELECT la.ip_address AS ip, la.user_id AS uid, DATE(la.created_at) AS d, cr.location_id AS loc, COUNT(DISTINCT cr.id) AS n
                FROM login_activities la
                JOIN cash_registers cr ON cr.user_id = la.user_id
                    AND cr.created_at >= la.created_at AND cr.created_at <= DATE_ADD(la.created_at, INTERVAL 3 HOUR)
                WHERE la.successful = 1 AND la.user_id IS NOT NULL AND la.ip_address IS NOT NULL
                    AND la.created_at >= ? AND cr.location_id IS NOT NULL
                GROUP BY la.ip_address, la.user_id, DATE(la.created_at), cr.location_id', [$since]);
            $pairs = [];
            foreach ($rows as $r) {
                $ip = trim((string) $r->ip);
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    continue;
                }
                $rule = self::networkFor($ip);
                self::upsertSeen((int) $r->loc, $rule, (int) $r->uid, (string) $r->d, 1, 'backfill');
                $pairs[(int) $r->loc . '|' . $rule] = [(int) $r->loc, $rule];
            }
            foreach ($pairs as $pr) {
                if (self::promoteIfEarned($pr[0], $pr[1])) {
                    $promoted[$pr[0]][] = $pr[1];
                }
            }
            \Log::info('time clock POS ip backfill', ['candidates' => count($pairs), 'promoted' => $promoted]);
        } catch (\Throwable $e) {
            \Log::warning('time clock POS ip backfill failed: ' . $e->getMessage());
        }
        return $promoted;
    }

    // ---- One-time setup links (owner texts it to the store) --------------

    /** New single-use link for store $locId, valid 24 hours. Returns the token. */
    public static function makeSetupToken(int $locId, ?int $by): string
    {
        $token = bin2hex(random_bytes(24));
        \Cache::put('tc_setup_' . hash('sha256', $token), ['loc' => $locId, 'by' => $by], 86400); // seconds (5.8)
        return $token;
    }

    /** Look up a setup token without using it. */
    public static function peekSetupToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $v = \Cache::get('tc_setup_' . hash('sha256', $token));
        return is_array($v) && !empty($v['loc']) ? $v : null;
    }

    /** Use a setup token once (null when unknown, used or expired). */
    public static function useSetupToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $v = \Cache::pull('tc_setup_' . hash('sha256', $token));
        return is_array($v) && !empty($v['loc']) ? $v : null;
    }

    /** Add $ip's network to store $locId as a manual IP ($src: manual, setup). */
    public static function addManualIp(int $businessId, int $locId, string $ip, string $src): string
    {
        $rule = self::networkFor($ip);
        $settings = self::settings();
        $cur = $settings['stores'][$locId] ?? $settings['stores'][(string) $locId] ?? [];
        if (!is_array($cur)) {
            $cur = [];
        }
        $list = (array) ($cur['ips'] ?? []);
        if (!in_array($rule, $list, true)) {
            $list[] = $rule;
        }
        $cur['ips'] = array_values($list);
        $ms = is_array($cur['manual_src'] ?? null) ? $cur['manual_src'] : [];
        $ms[$rule] = ['src' => $src, 'at' => self::now()->toDateString()];
        $cur['manual_src'] = $ms;
        $st = self::stores($businessId)[$locId] ?? null;
        if ($st && (!isset($cur['lat']) || $cur['lat'] === '')) {
            $cur['lat'] = $st['lat'];
            $cur['lng'] = $st['lng'];
            $cur['radius_m'] = $st['radius_m'];
            $cur['radius_v2'] = true;
        }
        unset($settings['stores'][(string) $locId]);
        $settings['stores'][$locId] = $cur;
        self::saveSettings($settings);
        return $rule;
    }

    /** Location-confirmed punches on $rule at $locId: enough people and days? */
    private static function ipHasEnoughEvidence(int $locId, string $rule): bool
    {
        $since = self::now()->subDays(self::LEARNED_IP_DAYS)->format('Y-m-d H:i:s');
        $people = [];
        $days = [];
        $rows = DB::table('time_clock_punches')->where('location_id', $locId)->whereNull('voided_at')
            ->where('clock_in_at', '>=', $since)
            ->where(function ($q) {
                $q->where('in_verify', 'gps')->orWhere('out_verify', 'gps');
            })
            ->orderBy('id', 'desc')->limit(2000)
            ->get(['user_id', 'clock_in_at', 'clock_out_at', 'in_verify', 'out_verify', 'clock_in_ip', 'clock_out_ip']);
        foreach ($rows as $r) {
            foreach ([['in_verify', 'clock_in_ip', 'clock_in_at'], ['out_verify', 'clock_out_ip', 'clock_out_at']] as $c) {
                [$v, $ipCol, $atCol] = $c;
                if ($r->$v !== 'gps' || !$r->$ipCol || !$r->$atCol) {
                    continue;
                }
                if (filter_var($r->$ipCol, FILTER_VALIDATE_IP) && self::networkFor((string) $r->$ipCol) === $rule) {
                    $people[(int) $r->user_id] = true;
                    $days[substr((string) $r->$atCol, 0, 10)] = true;
                }
            }
        }
        return count($people) >= self::LEARN_MIN_PEOPLE && count($days) >= self::LEARN_MIN_DAYS;
    }

    /** Active business locations: [id => name]. */
    public static function locations(int $businessId): array
    {
        try {
            $q = DB::table('business_locations')->where('business_id', $businessId);
            if (Schema::hasColumn('business_locations', 'is_active')) {
                $q->where('is_active', 1);
            }
            return $q->orderBy('id')->pluck('name', 'id')->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Store check config per location: [id => name, lat, lng, radius_m, ips[]].
     * Saved settings win; Hollywood and Pico get the geocoded defaults.
     * Locations with no coordinates and no IPs are not stores for the clock
     * (warehouse etc.) and are left out.
     */
    public static function stores(int $businessId): array
    {
        $saved = self::settings()['stores'];
        $out = [];
        foreach (self::locations($businessId) as $id => $name) {
            $cfg = $saved[$id] ?? $saved[(string) $id] ?? null;
            $def = null;
            foreach (self::DEFAULT_STORES as $needle => $d) {
                if (stripos((string) $name, $needle) !== false) {
                    $def = $d;
                    break;
                }
            }
            $lat = is_array($cfg) && isset($cfg['lat']) && $cfg['lat'] !== '' ? (float) $cfg['lat'] : ($def['lat'] ?? null);
            $lng = is_array($cfg) && isset($cfg['lng']) && $cfg['lng'] !== '' ? (float) $cfg['lng'] : ($def['lng'] ?? null);
            $ips = is_array($cfg) && isset($cfg['ips']) ? array_values(array_filter((array) $cfg['ips'])) : [];
            $learned = self::freshLearned(is_array($cfg) ? ($cfg['auto_ips'] ?? []) : []);
            if (is_array($cfg) && !empty($cfg['disabled'])) {
                $lat = $lng = null;
                $ips = [];
                $learned = [];
            }
            if ($lat === null && $lng === null && !$ips && !$learned) {
                continue;
            }
            $out[(int) $id] = [
                'id' => (int) $id,
                'name' => self::shortStoreName((string) $name),
                'full_name' => (string) $name,
                'lat' => $lat,
                'lng' => $lng,
                // A saved 200 from before 10/14 was just the old default.
                'radius_m' => (int) (is_array($cfg) && !empty($cfg['radius_m']) && (!empty($cfg['radius_v2']) || (int) $cfg['radius_m'] !== 200) ? $cfg['radius_m'] : self::DEFAULT_RADIUS_M),
                'ips' => $ips,
                'learned_ips' => $learned,
                'auto_src' => is_array($cfg) && is_array($cfg['auto_src'] ?? null) ? $cfg['auto_src'] : [],
                'manual_src' => is_array($cfg) && is_array($cfg['manual_src'] ?? null) ? $cfg['manual_src'] : [],
                'address' => $def['address'] ?? '',
            ];
        }
        return $out;
    }

    /** "Nivessa Hollywood" -> "Hollywood". */
    public static function shortStoreName(string $name): string
    {
        foreach (array_keys(self::DEFAULT_STORES) as $needle) {
            if (stripos($name, $needle) !== false) {
                return ucfirst($needle);
            }
        }
        return trim($name);
    }

    /**
     * The client IP for store checks and PIN limits. The server answers the
     * internet directly (nginx, no CDN), so CF-Connecting-IP and
     * X-Forwarded-For are whatever the client typed and must not be trusted:
     * anyone could send a store IP in them (Sarah 10/13 audit). They are only
     * read when the connection itself comes from this machine or a private
     * network (a local proxy), and then the last X-Forwarded-For hop, the
     * one that proxy added, is used.
     */
    public static function clientIp(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR', '');
        if ($remote === '') {
            $remote = (string) $request->ip();
        }
        $isLocal = filter_var($remote, FILTER_VALIDATE_IP)
            && !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($isLocal) {
            $xff = (string) $request->headers->get('X-Forwarded-For', '');
            if ($xff !== '') {
                $hops = array_values(array_filter(array_map('trim', explode(',', $xff))));
                $last = $hops ? end($hops) : '';
                if (filter_var($last, FILTER_VALIDATE_IP)) {
                    return $last;
                }
            }
        }
        return $remote;
    }

    /**
     * What to save when an admin clicks "allow this network": the exact IPv4
     * address, or the /64 for IPv6 (every phone on the same Wi-Fi gets its
     * own IPv6 address inside that /64).
     */
    public static function networkFor(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            $net = substr($bin, 0, 8) . str_repeat("\0", 8);
            return inet_ntop($net) . '/64';
        }
        return $ip;
    }

    /** Exact IP or CIDR (IPv4 or IPv6) match. */
    public static function ipMatches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '' || $ip === '') {
            return false;
        }
        if (strpos($rule, '/') === false) {
            return strcasecmp($ip, $rule) === 0;
        }
        [$net, $bits] = explode('/', $rule, 2);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = max(0, min(strlen($ipBin) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /**
     * Work out which store a punch is at.
     * $geo = ['lat','lng','accuracy','error'] from the phone (any may be null).
     * Returns ['location_id', 'verify' => gps|ip|none, 'distance_m'].
     */
    public static function resolveStore(int $businessId, string $ip, array $geo): array
    {
        $stores = self::stores($businessId);
        // Validate everything the phone sent (it can send anything): finite
        // numbers in range, and not 0,0. A fix with no usable accuracy
        // (missing, zero, negative, broken) is not trusted at all.
        $lat = isset($geo['lat']) && is_numeric($geo['lat']) ? (float) $geo['lat'] : null;
        $lng = isset($geo['lng']) && is_numeric($geo['lng']) ? (float) $geo['lng'] : null;
        $acc = isset($geo['accuracy']) && is_numeric($geo['accuracy']) ? (float) $geo['accuracy'] : null;
        if ($acc !== null && (!is_finite($acc) || $acc <= 0)) {
            $acc = null;
        }
        $geoOk = $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng)
            && abs($lat) <= 90 && abs($lng) <= 180 && !($lat == 0.0 && $lng == 0.0);

        $nearest = null;
        $nearestDist = null;
        if ($geoOk) {
            foreach ($stores as $id => $s) {
                if ($s['lat'] === null || $s['lng'] === null) {
                    continue;
                }
                $d = self::distanceM($lat, $lng, (float) $s['lat'], (float) $s['lng']);
                if ($nearestDist === null || $d < $nearestDist) {
                    $nearestDist = $d;
                    $nearest = $id;
                }
            }
            if ($nearest !== null && $acc !== null) {
                if ($nearestDist + $acc <= $stores[$nearest]['radius_m'] + self::RADIUS_SLACK_M) {
                    return ['location_id' => $nearest, 'verify' => 'gps', 'distance_m' => (int) round($nearestDist), 'has_fix' => true];
                }
            }
        }

        if ($ip !== '') {
            foreach ($stores as $id => $s) {
                foreach (array_merge($s['ips'], array_keys($s['learned_ips'])) as $rule) {
                    if (self::ipMatches($ip, (string) $rule)) {
                        return ['location_id' => $id, 'verify' => 'ip', 'distance_m' => $nearestDist !== null ? (int) round($nearestDist) : null, 'has_fix' => $geoOk];
                    }
                }
            }
        }

        return ['location_id' => $nearest, 'verify' => 'none', 'distance_m' => $nearestDist !== null ? (int) round($nearestDist) : null, 'has_fix' => $geoOk];
    }

    /** Store from the person's Sling shift today, else their last punch. */
    private static function guessStore(int $businessId, int $userId): ?int
    {
        $stores = self::stores($businessId);
        try {
            if (Schema::hasTable('sling_shifts')) {
                $today = self::now()->toDateString();
                $locName = DB::table('sling_shifts')->where('erp_user_id', $userId)
                    ->where('event_type', 'shift')
                    ->where('dtstart', '>=', $today . ' 00:00:00')
                    ->where('dtstart', '<=', $today . ' 23:59:59')
                    ->orderBy('dtstart')->value('location_name');
                if ($locName) {
                    foreach ($stores as $id => $s) {
                        if (stripos((string) $locName, $s['name']) !== false) {
                            return $id;
                        }
                    }
                }
            }
            $last = DB::table('time_clock_punches')->where('user_id', $userId)->whereNull('voided_at')
                ->whereNotNull('location_id')->orderBy('clock_in_at', 'desc')->value('location_id');
            if ($last) {
                return (int) $last;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return null;
    }

    // ---- Staff roster ----------------------------------------------------

    /**
     * Who can pick their name on /clock: ERP users with a Sling shift in the
     * last 30 days (or the next 2 weeks), plus anyone an admin gave a PIN.
     * Ex-staff (allow_login = 0) are left out. [user_id => display name]
     */
    public static function roster(int $businessId): array
    {
        $ids = [];
        try {
            if (Schema::hasTable('sling_shifts')) {
                $from = self::now()->subDays(30)->format('Y-m-d 00:00:00');
                $to = self::now()->addDays(14)->format('Y-m-d 23:59:59');
                $rows = DB::table('sling_shifts')
                    ->where('event_type', 'shift')
                    ->whereBetween('dtstart', [$from, $to])
                    ->select('erp_user_id', 'user_email', 'user_name')
                    ->distinct()->get();
                $byEmail = [];
                $emails = $rows->pluck('user_email')->filter()->map(function ($e) { return strtolower(trim($e)); })->unique()->values()->all();
                if ($emails) {
                    foreach (DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at')
                        ->whereIn(DB::raw('LOWER(email)'), $emails)->get(['id', 'email']) as $u) {
                        $byEmail[strtolower(trim($u->email))] = (int) $u->id;
                    }
                }
                foreach ($rows as $r) {
                    if ($r->erp_user_id) {
                        $ids[(int) $r->erp_user_id] = true;
                    } elseif ($r->user_email && isset($byEmail[strtolower(trim($r->user_email))])) {
                        $ids[$byEmail[strtolower(trim($r->user_email))]] = true;
                    }
                }
            }
            if (self::ready()) {
                foreach (DB::table('time_clock_pins')->where('business_id', $businessId)->pluck('user_id') as $uid) {
                    $ids[(int) $uid] = true;
                }
            }
            if (!$ids) {
                return [];
            }
            return DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at')
                ->where('allow_login', 1)
                ->whereIn('id', array_keys($ids))
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(function ($u) { return [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)]; })
                ->toArray();
        } catch (\Throwable $e) {
            \Log::warning('time clock roster failed: ' . $e->getMessage());
            return [];
        }
    }

    /** All current staff (status active, allow_login) for admin dropdowns. */
    public static function staff(int $businessId): array
    {
        try {
            return DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at')
                ->where('allow_login', 1)
                ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'active'); })
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(function ($u) { return [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)]; })
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function displayName($user): string
    {
        return trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ('User #' . ($user->id ?? ''));
    }

    // ---- PINs ------------------------------------------------------------
    //
    // PIN only, like Clover (Sarah 2026-10-13): the PIN alone says who it is,
    // so PINs are unique per person. Stored as an HMAC with the app key
    // ("h1:<hex>") so the person can be found by PIN; a leaked table is no
    // use without the key. Older bcrypt PINs still work and are upgraded on
    // first use.

    public static function pinDigest(string $pin): string
    {
        return 'h1:' . hash_hmac('sha256', 'time-clock-pin|' . $pin, (string) config('app.key'));
    }

    /** User id holding this PIN (other than $exceptUserId), or null. */
    public static function pinOwner(int $businessId, string $pin, int $exceptUserId = 0): ?int
    {
        $digest = self::pinDigest($pin);
        $uid = DB::table('time_clock_pins')->where('business_id', $businessId)->where('pin_hash', $digest)
            ->where('user_id', '!=', $exceptUserId)->value('user_id');
        if ($uid) {
            return (int) $uid;
        }
        foreach (DB::table('time_clock_pins')->where('business_id', $businessId)->where('pin_hash', 'not like', 'h1:%')
            ->where('user_id', '!=', $exceptUserId)->get(['user_id', 'pin_hash']) as $r) {
            try {
                if (Hash::check($pin, $r->pin_hash)) {
                    return (int) $r->user_id;
                }
            } catch (\Throwable $e) {
                // not a bcrypt hash
            }
        }
        return null;
    }

    /** A random 4-digit PIN nobody else has (and not an obvious one). */
    public static function uniqueRandomPin(int $businessId, int $userId): ?string
    {
        for ($i = 0; $i < 60; $i++) {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (self::isObviousPin($pin)) {
                continue;
            }
            if (self::pinOwner($businessId, $pin, $userId) === null) {
                return $pin;
            }
        }
        return null;
    }

    public static function isObviousPin(string $pin): bool
    {
        return preg_match('/^(\d)\1{3}$/', $pin) === 1 || in_array($pin, ['1234', '4321', '0123', '9876', '2580'], true);
    }

    public static function setPin(int $businessId, int $userId, string $pin, ?int $by): void
    {
        $now = self::nowString();
        $row = ['business_id' => $businessId, 'pin_hash' => self::pinDigest($pin), 'failed_attempts' => 0, 'locked_until' => null, 'set_by' => $by, 'updated_at' => $now];
        if (self::hasPinSecret()) {
            $row['pin_secret'] = \Crypt::encryptString($pin);
        }
        if (DB::table('time_clock_pins')->where('user_id', $userId)->exists()) {
            DB::table('time_clock_pins')->where('user_id', $userId)->update($row);
        } else {
            DB::table('time_clock_pins')->insert($row + ['user_id' => $userId, 'created_at' => $now]);
        }
    }

    private static $hasPinSecret = null;

    public static function hasPinSecret(): bool
    {
        if (self::$hasPinSecret === null) {
            try {
                self::$hasPinSecret = Schema::hasColumn('time_clock_pins', 'pin_secret');
            } catch (\Throwable $e) {
                self::$hasPinSecret = false;
            }
        }
        return self::$hasPinSecret;
    }

    /** The plain PIN from a time_clock_pins row, or null if not known yet. */
    public static function readablePin($row): ?string
    {
        if (!$row || empty($row->pin_secret)) {
            return null;
        }
        try {
            return \Crypt::decryptString($row->pin_secret);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Who is this PIN? Wrong PINs are limited per network (IP_MAX_FAILS in
     * PIN_LOCK_MINUTES, using the real client IP) and across everyone
     * (GLOBAL_MAX_FAILS), since a PIN can't be tied to a person until it's
     * right. Returns ['ok' => bool, 'user' => users row, 'msg'].
     */
    public static function identifyByPin(int $businessId, string $pin, string $ip): array
    {
        // Counted per network (an IPv6 phone can rotate through a whole /64),
        // plus a 15-minute and a daily cap across everyone off the store
        // networks, since a PIN can't be tied to a person until it's right.
        $net = filter_var($ip, FILTER_VALIDATE_IP) ? self::networkFor($ip) : $ip;
        $ipKey = 'time_clock_pin_fail_' . md5($net);
        $allKey = 'time_clock_pin_fail_all';
        $dayKey = 'time_clock_pin_fail_day_' . self::now()->toDateString();
        // The store networks only get their own (higher) limit, so wrong
        // guesses from outside can never lock the stores out.
        $atStore = false;
        try {
            $atStore = self::resolveStore($businessId, $ip, [])['verify'] === 'ip';
        } catch (\Throwable $e) {
            $atStore = false;
        }
        $keys = $atStore ? [$ipKey => self::PIN_LOCK_MINUTES * 60] : [$ipKey => self::PIN_LOCK_MINUTES * 60, $allKey => self::PIN_LOCK_MINUTES * 60, $dayKey => 86400];
        try {
            $locked = (int) \Cache::get($ipKey, 0) >= ($atStore ? self::STORE_IP_MAX_FAILS : self::IP_MAX_FAILS)
                || (!$atStore && ((int) \Cache::get($allKey, 0) >= self::GLOBAL_MAX_FAILS || (int) \Cache::get($dayKey, 0) >= self::GLOBAL_DAY_MAX_FAILS));
            if ($locked) {
                return ['ok' => false, 'user' => null, 'msg' => 'Too many wrong PINs, wait ' . self::PIN_LOCK_MINUTES . ' minutes.'];
            }
        } catch (\Throwable $e) {
            // no cache
        }
        $uid = preg_match('/^\d{4}$/', $pin) ? self::pinOwner($businessId, $pin) : null;
        $user = $uid ? DB::table('users')->where('id', $uid)->whereNull('deleted_at')->where('allow_login', 1)->first() : null;
        if (!$user) {
            try {
                foreach ($keys as $k => $ttl) {
                    $n = (int) \Cache::get($k, 0) + 1;
                    \Cache::put($k, $n, $ttl); // seconds (5.8)
                    if ($k === $allKey && $n === self::GLOBAL_MAX_FAILS) {
                        \Log::warning('time clock: PIN guessing limit reached for everyone off the store networks', ['last_ip' => $ip]);
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            return ['ok' => false, 'user' => null, 'msg' => 'That PIN did not work, try again.'];
        }
        // Upgrade an old bcrypt PIN to the lookup format.
        try {
            $row = DB::table('time_clock_pins')->where('user_id', $uid)->first();
            if ($row && strpos((string) $row->pin_hash, 'h1:') !== 0) {
                DB::table('time_clock_pins')->where('id', $row->id)->update(['pin_hash' => self::pinDigest($pin)]);
            }
            // PINs set before pin_secret existed: keep a copy for the owners' view.
            if ($row && self::hasPinSecret() && empty($row->pin_secret)) {
                DB::table('time_clock_pins')->where('id', $row->id)->update(['pin_secret' => \Crypt::encryptString($pin)]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return ['ok' => true, 'user' => $user, 'msg' => ''];
    }

    /**
     * A refused new PIN on Change my PIN: counts as a wrong PIN for the
     * network (same limit as punches) and toward that person's daily cap.
     */
    public static function countPinFailure(string $ip, int $userId): void
    {
        try {
            $net = filter_var($ip, FILTER_VALIDATE_IP) ? self::networkFor($ip) : $ip;
            $ipKey = 'time_clock_pin_fail_' . md5($net);
            \Cache::put($ipKey, (int) \Cache::get($ipKey, 0) + 1, self::PIN_LOCK_MINUTES * 60); // seconds (5.8)
            $uKey = 'time_clock_pin_change_fail_' . $userId . '_' . self::now()->toDateString();
            \Cache::put($uKey, (int) \Cache::get($uKey, 0) + 1, 86400);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** Too many refused new PINs today for this person (5). */
    public static function pinChangeLocked(int $userId): bool
    {
        try {
            return (int) \Cache::get('time_clock_pin_change_fail_' . $userId . '_' . self::now()->toDateString(), 0) >= 5;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---- Slack PIN delivery (Sarah 10/13) ---------------------------------
    //
    // PINs go to each person as a Slack DM through the nivessa.com API
    // (POST /erp/slack-dm, ERP key; it posts with the Quo Threads bot), so
    // nobody sees them. Slack user IDs live in settings.json "slack_ids"
    // [user_id => Slack ID], editable on Time clock settings. PINs are never
    // logged, stored in plain text or shown.

    // Slack IDs Sarah's team looked up (10/13), matched to ERP users by
    // email (ERP login email or Sling email) the first time they're needed.
    const SLACK_SEED = [
        'alecklusza@gmail.com' => 'U07SA4VQYUF',
        'soundslikeandy@gmail.com' => 'U0845G7FDNU',
        'clherr5@gmail.com' => 'U0BUBKUKTGU',
        'codyandrewtinsley@gmail.com' => 'U0BDX9RM30E',
        'dgbryant632@gmail.com' => 'U096X42NQ4U',
        'heyimhomeo@gmail.com' => 'U0B277N040K',
        'sternbergjai@gmail.com' => 'U0BUMGB723T',
        'luis3pt@gmail.com' => 'U08FQKLKKHS',
        'inthesink1999@gmail.com' => 'U08FQKLKKHS',
        'manoloaf95@gmail.com' => 'U08CE6SSGDS',
        'littlesonbooking@gmail.com' => 'U0BU7V0U0GJ',
        'nikolamatic214@gmail.com' => 'U081P5QEZSQ',
        'quentonhol@gmail.com' => 'U0BHE0QD2DR',
        'willyanez19@gmail.com' => 'U0BB5RCA411',
        '19zheimlich@gmail.com' => 'U07L3J8D6FP',
        'zellalezak@gmail.com' => 'U08C7RAPH0X',
    ];

    public static function validSlackId(string $id): bool
    {
        return preg_match('/^[UW][A-Z0-9]{6,20}$/', $id) === 1;
    }

    /** [user_id => Slack ID]; seeds from SLACK_SEED once. */
    public static function slackIds(int $businessId): array
    {
        $s = self::settings();
        $map = is_array($s['slack_ids'] ?? null) ? $s['slack_ids'] : [];
        if (empty($s['slack_seeded'])) {
            try {
                $emails = array_keys(self::SLACK_SEED);
                $byUser = [];
                foreach (DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at')
                    ->whereIn(DB::raw('LOWER(email)'), $emails)->get(['id', 'email']) as $u) {
                    $byUser[(int) $u->id] = self::SLACK_SEED[strtolower(trim($u->email))] ?? null;
                }
                if (Schema::hasTable('sling_shifts')) {
                    foreach (DB::table('sling_shifts')->whereNotNull('erp_user_id')->whereIn(DB::raw('LOWER(user_email)'), $emails)
                        ->select('erp_user_id', 'user_email')->distinct()->get() as $r) {
                        if (!isset($byUser[(int) $r->erp_user_id])) {
                            $byUser[(int) $r->erp_user_id] = self::SLACK_SEED[strtolower(trim($r->user_email))] ?? null;
                        }
                    }
                }
                foreach ($byUser as $uid => $sid) {
                    if ($sid && !isset($map[$uid]) && !isset($map[(string) $uid])) {
                        $map[$uid] = $sid;
                    }
                }
                $s = self::settings();
                $s['slack_ids'] = $map;
                $s['slack_seeded'] = self::nowString();
                self::saveSettings($s);
            } catch (\Throwable $e) {
                \Log::warning('time clock slack id seed failed: ' . $e->getMessage());
            }
        }
        $out = [];
        foreach ($map as $uid => $sid) {
            if (is_string($sid) && self::validSlackId($sid)) {
                $out[(int) $uid] = $sid;
            }
        }
        return $out;
    }

    public static function saveSlackId(int $userId, string $slackId): void
    {
        $s = self::settings();
        $map = is_array($s['slack_ids'] ?? null) ? $s['slack_ids'] : [];
        unset($map[(string) $userId]);
        if ($slackId === '') {
            unset($map[$userId]);
        } else {
            $map[$userId] = $slackId;
        }
        $s['slack_ids'] = $map;
        self::saveSettings($s);
    }

    /**
     * "Manolo clocked in" in #pico / #hollywood (Sarah 10/13, so she knows
     * who is on staff). Sent after the response so the punch never waits on
     * Slack; failures only log.
     */
    public static function announceClockIn($user, string $store): void
    {
        self::announce($user, $store, 'clocked in ' . "\u{1F44B}\u{1F3FC}");
    }

    /** Same, for clock-outs (phone, keypad, End Shift, register close). */
    public static function announceClockOut(int $userId, int $businessId, $locationId): void
    {
        try {
            $user = DB::table('users')->where('id', $userId)->first(['id', 'first_name', 'last_name']);
            if ($user) {
                self::announce($user, self::storeName($businessId, $locationId), 'clocked out');
            }
        } catch (\Throwable $e) {
            \Log::warning('time clock: clock-out Slack post error: ' . $e->getMessage());
        }
    }

    private static function announce($user, string $store, string $what): void
    {
        $s = strtolower($store);
        $key = strpos($s, 'pico') !== false ? 'pico' : (strpos($s, 'holly') !== false ? 'hollywood' : '');
        if ($key === '') {
            return;
        }
        $name = trim((string) ($user->first_name ?? ''));
        $text = ($name !== '' ? $name : self::displayName($user)) . ' ' . $what;
        app()->terminating(function () use ($key, $text) {
            try {
                $apiKey = \App\Services\ShippingShiftNotifier::erpApiKey();
                if ($apiKey === '') {
                    return;
                }
                $ch = curl_init(\App\Services\ShippingShiftNotifier::baseUrl() . '/erp/slack-store-post');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['store' => $key, 'text' => $text]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-erp-key: ' . $apiKey],
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $raw = curl_exec($ch);
                curl_close($ch);
                $j = json_decode((string) $raw, true);
                if (!is_array($j) || empty($j['success'])) {
                    \Log::warning('time clock: clock-in Slack post failed', ['store' => $key, 'error' => is_array($j) ? ($j['error'] ?? '') : 'no response']);
                }
            } catch (\Throwable $e) {
                \Log::warning('time clock: clock-in Slack post error: ' . $e->getMessage());
            }
        });
    }

    /** DM $text to Slack user $slackId via nivessa.com. ['ok' => bool, 'error' => code]. Never logs $text. */
    public static function slackDm(string $slackId, string $text): array
    {
        try {
            if (!self::validSlackId($slackId)) {
                return ['ok' => false, 'error' => 'bad Slack ID'];
            }
            $key = \App\Services\ShippingShiftNotifier::erpApiKey();
            if ($key === '') {
                return ['ok' => false, 'error' => 'ERP key missing'];
            }
            $ch = curl_init(\App\Services\ShippingShiftNotifier::baseUrl() . '/erp/slack-dm');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['slack_user_id' => $slackId, 'text' => $text]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-erp-key: ' . $key],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $j = json_decode((string) $raw, true);
            if ($code === 200 && is_array($j) && !empty($j['success'])) {
                return ['ok' => true, 'error' => ''];
            }
            $err = is_array($j) ? (string) ($j['error'] ?? $j['message'] ?? ('HTTP ' . $code)) : ('HTTP ' . $code);
            return ['ok' => false, 'error' => mb_substr($err, 0, 80)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'could not reach the website'];
        }
    }

    public static function pinMessage(string $firstName, string $pin): string
    {
        return 'Hi ' . $firstName . ', your Nivessa time clock PIN is ' . $pin . '. Clock in at https://playlist.nivessa.com/clock. You can change it with Change my PIN on that page. Please keep it private.';
    }

    /**
     * New unique PIN for $userId, DMed on Slack; saved only when the DM
     * went through (otherwise the person keeps what they had). Returns
     * ['ok' => bool, 'error' => string]. The PIN never leaves this method
     * except inside the DM.
     */
    public static function sendNewPinOnSlack(int $businessId, $user, string $slackId, ?int $by): array
    {
        $uid = (int) $user->id;
        $r = self::withUserLock($uid, function () use ($businessId, $user, $uid, $slackId, $by) {
            $pin = self::uniqueRandomPin($businessId, $uid);
            if ($pin === null) {
                return ['ok' => false, 'error' => 'no free PIN'];
            }
            $first = trim((string) $user->first_name) ?: 'there';
            $sent = self::slackDm($slackId, self::pinMessage($first, $pin));
            if (!$sent['ok']) {
                return $sent;
            }
            try {
                self::setPin($businessId, $uid, $pin, $by);
            } catch (\Throwable $e) {
                \Log::warning('time clock: PIN was DMed but not saved for user ' . $uid);
                return ['ok' => false, 'error' => 'sent but not saved, re-send'];
            }
            return ['ok' => true, 'error' => ''];
        });
        return $r ?: ['ok' => false, 'error' => 'busy, try again'];
    }

    // ---- POS "Manager" duty (Sarah 10/13) --------------------------------
    //
    // Who sees Manager on the POS duty picker: admins plus this owner-editable
    // list (Time clock settings). Defaults to Zak (Pico, user 18) and Luis
    // (Hollywood, user 26). Manager is a shift-tracking label only: it grants
    // no permission; it works like Cashier (store + drawer count + register).
    const DEFAULT_POS_MANAGERS = [18, 26];

    public static function posManagerIds(): array
    {
        $s = self::settings();
        $ids = array_key_exists('pos_managers', $s) && is_array($s['pos_managers']) ? $s['pos_managers'] : self::DEFAULT_POS_MANAGERS;
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function isPosManager($user): bool
    {
        return $user && in_array((int) $user->id, self::posManagerIds(), true);
    }

    public static function savePosManagerIds(array $ids): void
    {
        $s = self::settings();
        $s['pos_managers'] = array_values(array_unique(array_filter(array_map('intval', $ids))));
        self::saveSettings($s);
    }

    /** Duties that run a register (cashier rules apply): Cashier and Manager. */
    public static function isRegisterDuty($duty): bool
    {
        return in_array($duty, ['cashier', 'manager'], true);
    }

    /** "6h 12m" */
    public static function hoursText(float $hours): string
    {
        $min = (int) round($hours * 60);
        return intdiv($min, 60) . 'h ' . ($min % 60) . 'm';
    }

    // ---- Remembered phones -----------------------------------------------

    /** Remember this phone for the user; returns the raw token for the cookie. */
    public static function issueDevice(int $businessId, int $userId, Request $request): string
    {
        $token = bin2hex(random_bytes(32));
        $now = self::nowString();
        DB::table('time_clock_devices')->insert([
            'business_id' => $businessId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'ip' => mb_substr(self::clientIp($request), 0, 64),
            'last_used_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $token;
    }

    /** The remembered phone (device row with ->user) for this request, or null. */
    public static function deviceFromRequest(Request $request)
    {
        if (!self::ready()) {
            return null;
        }
        $token = (string) $request->cookie(self::COOKIE);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $d = DB::table('time_clock_devices')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        if (!$d) {
            return null;
        }
        $user = DB::table('users')->where('id', $d->user_id)->whereNull('deleted_at')->where('allow_login', 1)->first();
        if (!$user) {
            return null;
        }
        $d->user = $user;
        // Touch at most every 10 minutes.
        if (!$d->last_used_at || strtotime($d->last_used_at) < self::now()->timestamp - 600) {
            try {
                DB::table('time_clock_devices')->where('id', $d->id)->update(['last_used_at' => self::nowString()]);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return $d;
    }

    public static function revokeDevice(int $deviceId, ?int $by): void
    {
        DB::table('time_clock_devices')->where('id', $deviceId)->whereNull('revoked_at')
            ->update(['revoked_at' => self::nowString(), 'revoked_by' => $by, 'updated_at' => self::nowString()]);
    }

    // ---- State -----------------------------------------------------------

    /** The user's open punch (clocked in within OPEN_MAX_HOURS), or null. */
    public static function openPunch(int $userId)
    {
        if (!self::ready()) {
            return null;
        }
        return DB::table('time_clock_punches')
            ->where('user_id', $userId)
            ->whereNull('clock_out_at')
            ->whereNull('voided_at')
            ->where('clock_in_at', '>=', self::now()->subHours(self::OPEN_MAX_HOURS)->format('Y-m-d H:i:s'))
            ->orderBy('clock_in_at', 'desc')
            ->first();
    }

    /** Hours worked today (open punch counted up to now). */
    public static function todayHours(int $userId): float
    {
        $start = self::now()->startOfDay();
        $sec = 0;
        foreach (DB::table('time_clock_punches')->where('user_id', $userId)->whereNull('voided_at')
            ->where(function ($q) use ($start) {
                $q->where('clock_in_at', '>=', $start->format('Y-m-d H:i:s'))
                    ->orWhere('clock_out_at', '>=', $start->format('Y-m-d H:i:s'));
            })->get(['clock_in_at', 'clock_out_at']) as $p) {
            $in = max(strtotime($p->clock_in_at), $start->timestamp);
            if ($p->clock_out_at) {
                $out = strtotime($p->clock_out_at);
            } elseif (strtotime($p->clock_in_at) >= self::now()->subHours(self::OPEN_MAX_HOURS)->timestamp) {
                $out = self::now()->timestamp;
            } else {
                continue;
            }
            $sec += max(0, $out - $in);
        }
        return round($sec / 3600, 2);
    }

    public static function storeName(int $businessId, $locationId): string
    {
        if (!$locationId) {
            return '';
        }
        $stores = self::stores($businessId);
        if (isset($stores[(int) $locationId])) {
            return $stores[(int) $locationId]['name'];
        }
        $locs = self::locations($businessId);
        return isset($locs[(int) $locationId]) ? self::shortStoreName($locs[(int) $locationId]) : '';
    }

    /** Status for the phone page / JSON. */
    public static function status(int $businessId, int $userId): array
    {
        $open = self::openPunch($userId);
        $out = [
            'clocked_in' => (bool) $open,
            'since' => $open ? \Carbon::parse($open->clock_in_at, self::TZ)->format('g:i A') : null,
            'store' => $open ? self::storeName($businessId, $open->location_id) : '',
            'today_hours' => self::todayHours($userId),
            'today_text' => '',
            'last_out' => null,
        ];
        $out['today_text'] = self::hoursText($out['today_hours']);
        if (!$open) {
            $last = DB::table('time_clock_punches')->where('user_id', $userId)->whereNull('voided_at')
                ->whereNotNull('clock_out_at')
                ->where('clock_out_at', '>=', self::now()->startOfDay()->format('Y-m-d H:i:s'))
                ->orderBy('clock_out_at', 'desc')->first();
            if ($last) {
                $out['last_out'] = \Carbon::parse($last->clock_out_at, self::TZ)->format('g:i A');
                $out['store'] = self::storeName($businessId, $last->location_id);
            }
        }
        return $out;
    }

    // ---- Actions ---------------------------------------------------------

    public static function geoFrom(Request $request): array
    {
        // Out-of-range or non-finite values become null, so they can neither
        // pass the store check nor break the insert (decimal / unsigned columns).
        $num = function ($v, $max) {
            if (!is_numeric($v)) {
                return null;
            }
            $f = (float) $v;
            return is_finite($f) && abs($f) <= $max ? $f : null;
        };
        $err = preg_replace('/[^a-z_]/', '', strtolower((string) $request->input('geo_error', '')));
        $lat = $num($request->input('lat'), 90);
        $lng = $num($request->input('lng'), 180);
        if ($lat === null || $lng === null) {
            $lat = $lng = null;
        }
        $acc = $num($request->input('accuracy'), 4000000);
        return [
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => ($acc !== null && $acc > 0) ? $acc : null,
            'error' => $err !== '' ? substr($err, 0, 40) : null,
        ];
    }

    /**
     * Run $fn while holding a per-person MySQL lock, so two requests at the
     * same moment (double tap, phone + computer) can't both see "not
     * clocked in" and open two punches. Returns $fn's result, or null when
     * the lock could not be had within a few seconds.
     */
    public static function withUserLock(int $userId, callable $fn)
    {
        $name = 'tc_user_' . $userId;
        $got = false;
        try {
            $got = (int) (DB::selectOne('SELECT GET_LOCK(?, 3) AS l', [$name])->l ?? 0) === 1;
        } catch (\Throwable $e) {
            \Log::warning('time clock lock failed: ' . $e->getMessage());
            return null;
        }
        if (!$got) {
            return null;
        }
        try {
            return $fn();
        } finally {
            try {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS r', [$name]);
            } catch (\Throwable $e) {
                // released when the connection closes anyway
            }
        }
    }

    /** Do the late columns exist (migration 2026_10_14)? Cached. */
    public static function hasLateColumns(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $c = \Cache::get('time_clock_late_cols_v1');
            if ($c === 1) {
                return $ok = true;
            }
            $ok = Schema::hasColumn('time_clock_punches', 'late_min') && Schema::hasColumn('time_clock_punches', 'sched_start_at');
            if ($ok) {
                \Cache::put('time_clock_late_cols_v1', 1, 86400);
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * Clock in. $sched is scheduleAllows() output (late minutes are saved
     * for Timecards). Call inside withUserLock. Returns ['ok', 'msg'].
     */
    public static function clockIn(Request $request, $user, ?int $deviceId, string $source = 'phone', ?array $sched = null): array
    {
        if (!self::ready()) {
            return ['ok' => false, 'msg' => 'The time clock is not set up yet. Write your time down and tell a manager.'];
        }
        $userId = (int) $user->id;
        if ($open = self::openPunch($userId)) {
            return ['ok' => true, 'msg' => 'You are already clocked in since ' . \Carbon::parse($open->clock_in_at, self::TZ)->format('g:i A') . '.'];
        }
        $businessId = (int) ($user->business_id ?: self::businessId());
        $ip = self::clientIp($request);
        $geo = self::geoFrom($request);
        $where = self::resolveStore($businessId, $ip, $geo);
        $locId = $where['location_id'];
        if ($locId === null) {
            $locId = self::guessStore($businessId, $userId);
        }
        $now = self::nowString();
        DB::table('time_clock_punches')->insert([
            'business_id' => $businessId,
            'user_id' => $userId,
            'location_id' => $locId,
            'device_id' => $deviceId,
            'clock_in_at' => $now,
            'in_verify' => $where['verify'],
            'clock_in_ip' => mb_substr($ip, 0, 64),
            'in_lat' => $geo['lat'],
            'in_lng' => $geo['lng'],
            'in_accuracy_m' => $geo['accuracy'] !== null ? (int) min(4000000, round($geo['accuracy'])) : null,
            'in_distance_m' => $where['distance_m'],
            'in_geo_error' => $geo['error'],
            'in_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'clock_in_source' => $source,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ] + (self::hasLateColumns() && $sched ? [
            'late_min' => isset($sched['late_min']) ? (int) min(65000, max(0, $sched['late_min'])) : null,
            'sched_start_at' => $sched['sched_start'] ?? null,
        ] : []));

        $store = self::storeName($businessId, $locId);
        if ($where['verify'] === 'gps' && $locId) {
            self::learnIp((int) $locId, $ip);
        } elseif ($where['verify'] === 'ip') {
            self::touchAutoIp($where['location_id'], $ip);
        }
        self::announceClockIn($user, $store);
        ShippingShiftNotifier::onClock($user, 'in');
        // Store verification stays silent for staff; admins see it on Timecards.
        $msg = 'Clocked in at ' . self::now()->format('g:i A') . ($store !== '' ? ' at ' . $store : '') . '.';
        return ['ok' => true, 'msg' => $msg];
    }

    /**
     * Close the user's open punch. Returns the punch id closed, or null if
     * they were not clocked in. $request may be null (hooks with no client).
     * $withGeo: read lat/lng from the request (the phone page sends it).
     */
    public static function clockOut(int $userId, string $source, ?Request $request = null, bool $withGeo = false): ?int
    {
        if (!self::ready()) {
            return null;
        }
        $open = self::openPunch($userId);
        if (!$open) {
            return null;
        }
        $ip = $request ? self::clientIp($request) : '';
        $geo = ($request && $withGeo) ? self::geoFrom($request) : ['lat' => null, 'lng' => null, 'accuracy' => null, 'error' => null];
        $verify = null;
        $dist = null;
        $where = null;
        if ($request) {
            $where = self::resolveStore((int) $open->business_id, $ip, $geo);
            $verify = $where['verify'];
            $dist = $where['distance_m'];
        }
        $now = self::nowString();
        DB::table('time_clock_punches')->where('id', $open->id)->whereNull('clock_out_at')->update([
            'clock_out_at' => $now,
            'out_verify' => $verify,
            'clock_out_ip' => $ip !== '' ? mb_substr($ip, 0, 64) : null,
            'out_lat' => $geo['lat'],
            'out_lng' => $geo['lng'],
            'out_accuracy_m' => $geo['accuracy'] !== null ? (int) min(4000000, round($geo['accuracy'])) : null,
            'out_distance_m' => $dist,
            'out_geo_error' => $geo['error'],
            'out_user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'clock_out_source' => $source,
            'updated_at' => $now,
        ]);
        self::announceClockOut($userId, (int) $open->business_id, $open->location_id);
        try {
            ShippingShiftNotifier::onClock(DB::table('users')->where('id', $userId)->first(['id', 'first_name', 'last_name']), 'out');
        } catch (\Throwable $e) {
            \Log::warning('shipping summary clock-out hook failed: ' . $e->getMessage());
        }
        if ($verify === 'gps' && !empty($where['location_id'])) {
            self::learnIp((int) $where['location_id'], $ip);
        } elseif ($verify === 'ip' && !empty($where['location_id'])) {
            self::touchAutoIp((int) $where['location_id'], $ip);
        }
        return (int) $open->id;
    }

    /**
     * For End Shift / register close: clock the user out. Never throws, and
     * costs two indexed queries at most.
     */
    public static function clockOutFromHook($userId, string $source, ?Request $request = null): void
    {
        try {
            if ($userId && self::ready()) {
                self::withUserLock((int) $userId, function () use ($userId, $source, $request) {
                    return self::clockOut((int) $userId, $source, $request, false);
                });
            }
        } catch (\Throwable $e) {
            \Log::warning('time clock ' . $source . ' clock-out failed: ' . $e->getMessage());
        }
    }

    /** Record an admin change in the edit history. */
    public static function logEdit(int $punchId, string $action, string $reason, $before, $after): void
    {
        DB::table('time_clock_punch_edits')->insert([
            'punch_id' => $punchId,
            'edited_by' => auth()->id(),
            'action' => $action,
            'reason' => mb_substr($reason, 0, 500),
            'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after),
            'created_at' => self::nowString(),
            'updated_at' => self::nowString(),
        ]);
    }

    public static function verifyLabel($v): string
    {
        $map = ['gps' => 'At store (location)', 'ip' => 'At store (Wi-Fi)', 'none' => 'Not verified at store'];
        return $v ? ($map[$v] ?? $v) : '';
    }

    public static function sourceLabel($s): string
    {
        $map = ['phone' => 'Phone', 'computer' => 'Store computer', 'button' => 'Phone', 'register_close' => 'Register close', 'end_shift' => 'End Shift', 'admin' => 'Admin'];
        return $s ? ($map[$s] ?? $s) : '';
    }
}
