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
    const DEFAULT_RADIUS_M = 200;
    // Accept when distance minus the reported accuracy is inside the radius
    // (Sarah 10/13). The accuracy credit is capped so a very rough fix (city
    // level, several km) can't pass from home.
    const MAX_ACCURACY_CREDIT_M = 300;
    // Auto-learned store IPs expire when not seen on a location-confirmed
    // punch for this many days. Manual IPs never expire.
    const LEARNED_IP_DAYS = 30;

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

    /** Minutes before/after a scheduled start that clock-in is allowed (default 15). */
    public static function scheduleWindow(): int
    {
        $v = (int) (self::settings()['schedule_window_min'] ?? 15);
        return $v > 0 ? min($v, 240) : 15;
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
     * Buddy-punch guard (Sarah 10/13): may this person clock in now at this
     * store? Their Sling shifts (type shift; times are LA) are merged into
     * blocks when back to back (gap <= 15 min, e.g. Open Store Checklist
     * 09:15 then Cashier 10:00). The earliest block that hasn't ended must
     * start within the window of now. Someone already clocked in during
     * that block (back from a break) may clock in again.
     * Returns ['ok' => bool, 'no_data' => bool].
     */
    public static function scheduleAllows(int $userId, ?int $locId, int $businessId): array
    {
        $now = self::now();
        $today = $now->toDateString();
        if (!self::hasScheduleData($today)) {
            return ['ok' => true, 'no_data' => true];
        }
        $win = self::scheduleWindow() * 60;
        $nowTs = $now->timestamp;
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
            ->where('dtstart', '>=', $now->copy()->subHours(24)->format('Y-m-d H:i:s'))
            ->where('dtstart', '<=', $now->copy()->addSeconds($win)->format('Y-m-d H:i:s'))
            ->orderBy('dtstart')
            ->get(['dtstart', 'dtend', 'location_name']);

        $storeName = $locId ? self::storeName($businessId, $locId) : '';
        $blocks = [];
        foreach ($rows as $r) {
            if ($storeName !== '' && $r->location_name && stripos((string) $r->location_name, $storeName) === false) {
                continue; // a shift at the other store
            }
            $st = \Carbon::parse($r->dtstart, self::TZ)->timestamp;
            $en = $r->dtend ? \Carbon::parse($r->dtend, self::TZ)->timestamp : $st + 8 * 3600;
            if ($en <= $st) {
                $en += 86400; // ends after midnight
            }
            $n = count($blocks);
            if ($n && $st <= $blocks[$n - 1]['end'] + 15 * 60) {
                $blocks[$n - 1]['end'] = max($blocks[$n - 1]['end'], $en);
            } else {
                $blocks[] = ['start' => $st, 'end' => $en];
            }
        }
        foreach ($blocks as $b) {
            if ($b['end'] <= $nowTs) {
                continue;
            }
            if (abs($nowTs - $b['start']) <= $win) {
                return ['ok' => true, 'no_data' => false];
            }
            if ($nowTs > $b['start']) {
                // Back from a break: already clocked in during this block.
                $back = DB::table('time_clock_punches')->where('user_id', $userId)->whereNull('voided_at')
                    ->where('clock_in_at', '>=', \Carbon::createFromTimestamp($b['start'] - $win, self::TZ)->format('Y-m-d H:i:s'))
                    ->exists();
                if ($back) {
                    return ['ok' => true, 'no_data' => false];
                }
            }
            return ['ok' => false, 'no_data' => false];
        }
        return ['ok' => false, 'no_data' => false];
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
     * A punch was confirmed at store $locId by location: remember the
     * network it came from (auto-learned, expires after LEARNED_IP_DAYS
     * unseen). Never throws.
     */
    public static function learnIp(int $locId, string $ip): void
    {
        try {
            if (!$locId || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return;
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
            $learned = self::freshLearned($cur['learned_ips'] ?? []);
            if (($learned[$rule] ?? '') === $today) {
                return;
            }
            // An IP belongs to one store: drop it from any other store.
            foreach ($settings['stores'] as $otherId => $other) {
                if ((int) $otherId !== $locId && is_array($other) && isset($other['learned_ips'][$rule])) {
                    unset($settings['stores'][$otherId]['learned_ips'][$rule]);
                }
            }
            $learned[$rule] = $today;
            $cur['learned_ips'] = $learned;
            unset($settings['stores'][(string) $locId]);
            $settings['stores'][$locId] = $cur;
            self::saveSettings($settings);
        } catch (\Throwable $e) {
            \Log::warning('time clock learn ip failed: ' . $e->getMessage());
        }
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
            $learned = self::freshLearned(is_array($cfg) ? ($cfg['learned_ips'] ?? []) : []);
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
                'radius_m' => (int) (is_array($cfg) && !empty($cfg['radius_m']) ? $cfg['radius_m'] : self::DEFAULT_RADIUS_M),
                'ips' => $ips,
                'learned_ips' => $learned,
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

    /** Best-effort real client IP (same rule as LoginController::clientIp). */
    public static function clientIp(Request $request): string
    {
        $cf = $request->headers->get('CF-Connecting-IP');
        if (!empty($cf)) {
            return trim($cf);
        }
        $xff = $request->headers->get('X-Forwarded-For');
        if (!empty($xff)) {
            return trim(explode(',', $xff)[0]);
        }
        return (string) $request->ip();
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
        $lat = isset($geo['lat']) && is_numeric($geo['lat']) ? (float) $geo['lat'] : null;
        $lng = isset($geo['lng']) && is_numeric($geo['lng']) ? (float) $geo['lng'] : null;
        $acc = isset($geo['accuracy']) && is_numeric($geo['accuracy']) ? max(0, (float) $geo['accuracy']) : null;

        $nearest = null;
        $nearestDist = null;
        if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
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
            if ($nearest !== null) {
                $credit = min((float) ($acc ?? 0), self::MAX_ACCURACY_CREDIT_M);
                if ($nearestDist - $credit <= $stores[$nearest]['radius_m']) {
                    return ['location_id' => $nearest, 'verify' => 'gps', 'distance_m' => (int) round($nearestDist)];
                }
            }
        }

        if ($ip !== '') {
            foreach ($stores as $id => $s) {
                foreach (array_merge($s['ips'], array_keys($s['learned_ips'])) as $rule) {
                    if (self::ipMatches($ip, (string) $rule)) {
                        return ['location_id' => $id, 'verify' => 'ip', 'distance_m' => $nearestDist !== null ? (int) round($nearestDist) : null];
                    }
                }
            }
        }

        return ['location_id' => $nearest, 'verify' => 'none', 'distance_m' => $nearestDist !== null ? (int) round($nearestDist) : null];
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
        if (DB::table('time_clock_pins')->where('user_id', $userId)->exists()) {
            DB::table('time_clock_pins')->where('user_id', $userId)->update($row);
        } else {
            DB::table('time_clock_pins')->insert($row + ['user_id' => $userId, 'created_at' => $now]);
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
        $ipKey = 'time_clock_pin_fail_' . md5($ip);
        $allKey = 'time_clock_pin_fail_all';
        // The store networks only get their own limit, so wrong guesses from
        // outside can never lock the stores out.
        $atStore = false;
        try {
            $atStore = self::resolveStore($businessId, $ip, [])['verify'] === 'ip';
        } catch (\Throwable $e) {
            $atStore = false;
        }
        $keys = $atStore ? [$ipKey] : [$ipKey, $allKey];
        try {
            if ((int) \Cache::get($ipKey, 0) >= self::IP_MAX_FAILS || (!$atStore && (int) \Cache::get($allKey, 0) >= self::GLOBAL_MAX_FAILS)) {
                return ['ok' => false, 'user' => null, 'msg' => 'Too many wrong PINs. Wait ' . self::PIN_LOCK_MINUTES . ' minutes, or ask a manager.'];
            }
        } catch (\Throwable $e) {
            // no cache
        }
        $uid = preg_match('/^\d{4}$/', $pin) ? self::pinOwner($businessId, $pin) : null;
        $user = $uid ? DB::table('users')->where('id', $uid)->whereNull('deleted_at')->where('allow_login', 1)->first() : null;
        if (!$user) {
            try {
                foreach ($keys as $k) {
                    \Cache::put($k, (int) \Cache::get($k, 0) + 1, self::PIN_LOCK_MINUTES * 60); // seconds (5.8)
                }
            } catch (\Throwable $e) {
                // ignore
            }
            return ['ok' => false, 'user' => null, 'msg' => 'That PIN did not work. Try again.'];
        }
        // Upgrade an old bcrypt PIN to the lookup format.
        try {
            $row = DB::table('time_clock_pins')->where('user_id', $uid)->first();
            if ($row && strpos((string) $row->pin_hash, 'h1:') !== 0) {
                DB::table('time_clock_pins')->where('id', $row->id)->update(['pin_hash' => self::pinDigest($pin)]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return ['ok' => true, 'user' => $user, 'msg' => ''];
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
        $num = function ($v) { return is_numeric($v) ? (float) $v : null; };
        $err = preg_replace('/[^a-z_]/', '', strtolower((string) $request->input('geo_error', '')));
        return [
            'lat' => $num($request->input('lat')),
            'lng' => $num($request->input('lng')),
            'accuracy' => $num($request->input('accuracy')),
            'error' => $err !== '' ? substr($err, 0, 40) : null,
        ];
    }

    /** Clock in from the phone. Returns ['ok', 'msg']. */
    public static function clockIn(Request $request, $user, ?int $deviceId, string $source = 'phone'): array
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
        ]);

        $store = self::storeName($businessId, $locId);
        if ($where['verify'] === 'gps' && $locId) {
            self::learnIp((int) $locId, $ip);
        }
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
        if ($request) {
            $where = self::resolveStore((int) $open->business_id, $ip, $geo);
            $verify = $where['verify'];
            $dist = $where['distance_m'];
            if ($verify === 'gps' && $where['location_id']) {
                self::learnIp((int) $where['location_id'], $ip);
            }
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
                self::clockOut((int) $userId, $source, $request, false);
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
