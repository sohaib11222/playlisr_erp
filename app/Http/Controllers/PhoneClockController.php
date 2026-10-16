<?php

namespace App\Http\Controllers;

use App\Services\TimeClockService as TC;
use Illuminate\Http\Request;

/**
 * Public time clock at /clock (Sarah, 2026-10-13), as easy as Clover was:
 * the PIN alone says who you are (PINs are unique).
 *
 * - Store computer: full-screen keypad. Type your PIN and it clocks you in
 *   or out (whichever you are not), shows a big confirmation, and resets.
 *   Nothing is remembered.
 * - Phone: the same keypad once, then the phone is remembered (see
 *   TimeClockService) and it is one big Clock in / Clock out button.
 *
 * Store verification (location / IP) is silent for staff; admins see it on
 * Timecards.
 */
class PhoneClockController extends Controller
{
    private function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Reject cross-site POSTs (on top of CSRF). */
    private function sameOrigin(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || $origin === 'null') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        return $host && strcasecmp($host, $request->getHost()) === 0;
    }

    /**
     * A shared computer never remembers one person. Desktop browsers get the
     * keypad on every punch; phones get remember-me. ?mode=computer /
     * ?mode=phone overrides the guess.
     */
    private function isSharedComputer(Request $request, $device): bool
    {
        $mode = (string) $request->query('mode', '');
        if ($mode === 'computer') {
            return true;
        }
        if ($mode === 'phone' || $device) {
            return false;
        }
        $ua = (string) $request->userAgent();
        return !preg_match('/Mobi|Android|iPhone|iPod|iPad|Windows Phone/i', $ua);
    }

    private function json(array $out)
    {
        return $this->noStore(response()->json($out));
    }

    public function page(Request $request)
    {
        $data = ['ready' => false, 'mode' => 'setup', 'device' => null, 'status' => null, 'name' => '', 'switch' => false, 'change' => false, 'computer' => false];
        try {
            if (!TC::ready()) {
                return $this->noStore(response()->view('time_clock.phone', $data));
            }
            $data['ready'] = true;
            $businessId = TC::businessId();
            // One-time backfill of store networks from POS history, after
            // the response (Sarah 10/13). Runs once; owners can rerun it on
            // Time clock settings.
            try {
                if (TC::ipSeenReady() && \Cache::add('tc_pos_backfill_done_v1', 1, 86400 * 365)) {
                    app()->terminating(function () {
                        TC::backfillPosIps();
                    });
                }
            } catch (\Throwable $e) {
                // optional
            }
            $device = TC::deviceFromRequest($request);
            $data['computer'] = $this->isSharedComputer($request, $device);
            if ($request->query('change') === '1') {
                // Change my PIN (Sarah 10/13): same keypad, current PIN,
                // then the new one twice.
                $data['mode'] = 'change';
                $data['change'] = true;
            } elseif ($data['computer']) {
                $data['mode'] = 'computer';
            } elseif ($device && $request->query('switch') !== '1') {
                $data['mode'] = 'phone';
                $data['device'] = $device;
                $data['name'] = trim((string) $device->user->first_name) ?: TC::displayName($device->user);
                $data['status'] = TC::status((int) $device->user->business_id ?: $businessId, (int) $device->user->id);
            } else {
                $data['mode'] = 'setup';
                $data['switch'] = (bool) $device;
            }
        } catch (\Throwable $e) {
            \Log::warning('clock page failed: ' . $e->getMessage());
            $data['ready'] = false;
        }
        return $this->noStore(response()->view('time_clock.phone', $data));
    }

    /** Fresh CSRF token + status, for the page to refresh itself. */
    public function state(Request $request)
    {
        $out = ['csrf' => csrf_token(), 'ready' => false, 'status' => null];
        try {
            if (TC::ready()) {
                $out['ready'] = true;
                $device = TC::deviceFromRequest($request);
                if ($device) {
                    $out['status'] = TC::status((int) $device->user->business_id, (int) $device->user->id);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('clock state failed: ' . $e->getMessage());
        }
        return $this->json($out);
    }

    /** Phone, first time (or switching person): PIN, then remember the phone. */
    public function pin(Request $request)
    {
        if (!$this->sameOrigin($request)) {
            return $this->json(['ok' => false, 'msg' => 'Please reload the page and try again.']);
        }
        try {
            if (!TC::ready()) {
                return $this->json(['ok' => false, 'msg' => 'The clock is not ready yet.']);
            }
            $businessId = TC::businessId();
            $pin = preg_replace('/\D/', '', (string) $request->input('pin'));
            $who = TC::identifyByPin($businessId, $pin, TC::clientIp($request));
            if (!$who['ok']) {
                return $this->json(['ok' => false, 'msg' => $who['msg']]);
            }
            $user = $who['user'];
            $old = TC::deviceFromRequest($request);
            if ($old) {
                TC::revokeDevice((int) $old->id, null);
            }
            $token = TC::issueDevice($businessId, (int) $user->id, $request);
            return $this->json(['ok' => true, 'name' => trim((string) $user->first_name)])
                ->withCookie(cookie(TC::COOKIE, $token, TC::COOKIE_MINUTES, '/', null, $request->isSecure() || app()->environment('production'), true, false, 'lax'));
        } catch (\Throwable $e) {
            \Log::warning('clock pin failed: ' . $e->getMessage());
            return $this->json(['ok' => false, 'msg' => 'Something went wrong. Please try again.']);
        }
    }

    /**
     * Store computer: PIN in, and the person is clocked in if they are out,
     * or out if they are in. Nothing is remembered.
     */
    public function kiosk(Request $request)
    {
        if (!$this->sameOrigin($request)) {
            return $this->json(['ok' => false, 'msg' => 'Please reload the page and try again.']);
        }
        try {
            if (!TC::ready()) {
                return $this->json(['ok' => false, 'msg' => 'The clock is not ready yet.']);
            }
            $businessId = TC::businessId();
            $pin = preg_replace('/\D/', '', (string) $request->input('pin'));
            $who = TC::identifyByPin($businessId, $pin, TC::clientIp($request));
            if (!$who['ok']) {
                return $this->json(['ok' => false, 'msg' => $who['msg']]);
            }
            // A phone remembered for one person can't punch someone else
            // with their PIN (buddy punching from your own phone).
            $device = TC::deviceFromRequest($request);
            if ($device && (int) $device->user->id !== (int) $who['user']->id) {
                return $this->json(['ok' => false, 'msg' => 'This phone is set up for ' . trim((string) $device->user->first_name) . ', so it can only clock them.']);
            }
            return $this->json($this->toggle($request, $who['user'], 'computer', null));
        } catch (\Throwable $e) {
            \Log::warning('computer clock punch failed: ' . $e->getMessage());
            return $this->json(['ok' => false, 'msg' => 'That did not go through. Please try again.']);
        }
    }

    /** Remembered phone: the big button. */
    public function punch(Request $request)
    {
        if (!$this->sameOrigin($request)) {
            return $this->json(['ok' => false, 'msg' => 'Please reload the page and try again.']);
        }
        try {
            if (!TC::ready()) {
                return $this->json(['ok' => false, 'msg' => 'The clock is not ready yet.']);
            }
            $device = TC::deviceFromRequest($request);
            if (!$device) {
                return $this->json(['ok' => false, 'msg' => 'Enter your PIN to set up this phone.', 'setup' => true]);
            }
            $want = $request->input('action') === 'out' ? 'out' : 'in';
            return $this->json($this->toggle($request, $device->user, 'phone', (int) $device->id, $want));
        } catch (\Throwable $e) {
            \Log::warning('phone clock punch failed: ' . $e->getMessage());
            return $this->json(['ok' => false, 'msg' => 'That did not go through. Please try again.']);
        }
    }

    /**
     * Clock in if out, out if in, under a per-person lock so two requests at
     * once can't open two punches. $want (phone) guards against a stale
     * screen: if the person is already in that state, nothing changes.
     */
    private function toggle(Request $request, $user, string $source, ?int $deviceId, ?string $want = null): array
    {
        $r = TC::withUserLock((int) $user->id, function () use ($request, $user, $source, $deviceId, $want) {
            return $this->toggleLocked($request, $user, $source, $deviceId, $want);
        });
        return $r ?: ['ok' => false, 'msg' => 'That did not go through, try again.'];
    }

    private function toggleLocked(Request $request, $user, string $source, ?int $deviceId, ?string $want): array
    {
        $userId = (int) $user->id;
        $businessId = (int) ($user->business_id ?: TC::businessId());
        $first = trim((string) $user->first_name) ?: TC::displayName($user);
        $open = TC::openPunch($userId);
        $time = TC::now()->format('g:i A');

        // Store computer: the same PIN again right after a punch (double
        // tap, network retry, replay) shows the state instead of flipping it.
        if ($want === null) {
            $guard = TC::now()->subSeconds(TC::TOGGLE_GUARD_SEC)->format('Y-m-d H:i:s');
            $recent = $open
                ? $open->clock_in_at >= $guard
                : \DB::table('time_clock_punches')->where('user_id', $userId)->whereNull('voided_at')
                    ->where('clock_out_at', '>=', $guard)->exists();
            if ($recent) {
                $want = $open ? 'in' : 'out';
            }
        }

        // Punches only count at the store (Sarah 10/13): location close to
        // the store, or a store network. Admin switch can turn this off.
        $doing = (!$open && $want !== 'out') ? 'in' : (($open && $want !== 'in') ? 'out' : null);
        $where = null;
        if ($doing !== null && TC::requireAtStore()) {
            $geo = TC::geoFrom($request);
            $where = TC::resolveStore($businessId, TC::clientIp($request), $geo);
            if ($where['verify'] === 'none') {
                $denied = $geo['error'] === 'denied';
                $msg = $denied
                    ? 'Allow location for this page to clock ' . $doing . '.'
                    : (!empty($where['has_fix']) ? 'You need to be at the store to clock ' . $doing . '.' : 'Could not confirm you are at the store, try again.');
                return ['ok' => false, 'blocked' => true, 'geo_denied' => $denied, 'name' => $first, 'msg' => $msg];
            }
        }

        // Buddy-punch guard (Sarah 10/13): clock-in only from 5 minutes (setting)
        // before your Sling shift at this store until it ends (late is
        // allowed and flagged on Timecards). Clock-out is never limited by
        // schedule. If Sling has nothing for today (sync broken), let it
        // through; Timecards flags it "No schedule data".
        $sched = null;
        if ($doing === 'in' && TC::requireSchedule()) {
            $where = $where ?? TC::resolveStore($businessId, TC::clientIp($request), TC::geoFrom($request));
            try {
                $sched = TC::scheduleAllows($userId, $where['location_id'] ?? null, $businessId);
            } catch (\Throwable $e) {
                \Log::warning('time clock schedule check failed: ' . $e->getMessage());
                $sched = ['ok' => true, 'no_data' => true, 'late_min' => null, 'sched_start' => null];
            }
            if (!$sched['ok'] && !empty($sched['early'])) {
                return ['ok' => false, 'blocked' => true, 'geo_denied' => false, 'name' => $first,
                    'msg' => 'You can clock in ' . TC::scheduleWindow() . ' minutes before your shift.'];
            }
            // Not on Sling at all (Sarah 10/16: someone came in to help on a
            // day off): let them clock in, flag it for Fatteen for payroll.
            if (!$sched['ok']) {
                $sched['ok'] = true;
                $sched['unscheduled'] = true;
            }
        }

        if ($doing === 'in') {
            $r = TC::clockIn($request, $user, $deviceId, $source, $sched);
            $st = TC::status($businessId, $userId);
            return ['ok' => $r['ok'], 'name' => $first, 'action' => 'in', 'time' => $time,
                'headline' => $first . ", you're clocked in.", 'detail' => $time . ($st['store'] !== '' ? ' at ' . $st['store'] : ''),
                'msg' => $r['msg'], 'status' => $st];
        }
        if ($doing === 'out') {
            TC::clockOut($userId, $source, $request, true);
            $st = TC::status($businessId, $userId);
            return ['ok' => true, 'name' => $first, 'action' => 'out', 'time' => $time,
                'headline' => $first . ", you're clocked out.", 'detail' => $time . ', ' . $st['today_text'] . ' today',
                'msg' => 'Clocked out at ' . $time . '.', 'status' => $st];
        }
        // Stale screen or repeat: already in the state they asked for.
        $st = TC::status($businessId, $userId);
        return ['ok' => true, 'name' => $first, 'action' => $open ? 'in' : 'out', 'time' => $time,
            'headline' => $first . ($open ? ", you're already clocked in." : ", you're already clocked out."),
            'detail' => $open ? 'Since ' . $st['since'] : $st['today_text'] . ' today',
            'msg' => '', 'status' => $st];
    }

    /**
     * Change my PIN (Sarah 10/13): current PIN, new PIN twice (the page
     * checks the two match). Only at the store (same location / store IP
     * check as punches), and rate limited like punches. A refused new PIN
     * (taken by someone, or too obvious) gets the same answer and counts
     * as a wrong try, so the form can't be used to find coworkers' PINs.
     * PINs are never logged.
     */
    public function changePin(Request $request)
    {
        if (!$this->sameOrigin($request)) {
            return $this->json(['ok' => false, 'msg' => 'Please reload the page and try again.']);
        }
        try {
            if (!TC::ready()) {
                return $this->json(['ok' => false, 'msg' => 'The clock is not ready yet.']);
            }
            $businessId = TC::businessId();
            $ip = TC::clientIp($request);
            if (TC::requireAtStore()) {
                $geo = TC::geoFrom($request);
                $where = TC::resolveStore($businessId, $ip, $geo);
                if ($where['verify'] === 'none') {
                    return $this->json(['ok' => false, 'blocked' => true, 'geo_denied' => $geo['error'] === 'denied',
                        'msg' => 'You need to be at the store to change your PIN.']);
                }
            }
            $cur = preg_replace('/\D/', '', (string) $request->input('pin'));
            $new = preg_replace('/\D/', '', (string) $request->input('new_pin'));
            $who = TC::identifyByPin($businessId, $cur, $ip);
            if (!$who['ok']) {
                return $this->json(['ok' => false, 'msg' => $who['msg'] === 'That PIN did not work, try again.' ? 'Your current PIN did not work, start again.' : $who['msg']]);
            }
            $uid = (int) $who['user']->id;
            if (TC::pinChangeLocked($uid)) {
                return $this->json(['ok' => false, 'msg' => 'Too many tries today, ask a manager.']);
            }
            if (!preg_match('/^\d{4}$/', $new)) {
                return $this->json(['ok' => false, 'msg' => 'A PIN is 4 numbers, start again.']);
            }
            if ($new !== $cur && (TC::isObviousPin($new) || TC::pinOwner($businessId, $new, $uid) !== null)) {
                TC::countPinFailure($ip, $uid);
                return $this->json(['ok' => false, 'msg' => 'Pick a different PIN.']);
            }
            TC::withUserLock($uid, function () use ($businessId, $uid, $new) {
                TC::setPin($businessId, $uid, $new, $uid);
                return true;
            });
            \Log::info('time clock: PIN changed by its owner', ['user_id' => $uid]);
            return $this->json(['ok' => true, 'msg' => 'Your PIN is changed.']);
        } catch (\Throwable $e) {
            \Log::warning('clock change pin failed: ' . $e->getMessage());
            return $this->json(['ok' => false, 'msg' => 'That did not go through, try again.']);
        }
    }

    /**
     * One-time setup link (owner texts it to the store, Sarah 10/13): the
     * GET only shows a button, so link previews in Messages can't use it up
     * or add the wrong network; the POST adds this computer's network to
     * the store as a manual IP and uses up the link.
     */
    public function setupPage(Request $request, $token)
    {
        $data = ['token' => (string) $token, 'store' => '', 'state' => 'invalid', 'msg' => ''];
        try {
            $t = TC::peekSetupToken((string) $token);
            if ($t) {
                $data['store'] = TC::storeName(TC::businessId(), (int) $t['loc']) ?: 'the store';
                $data['state'] = 'ready';
            }
        } catch (\Throwable $e) {
            \Log::warning('clock setup page failed: ' . $e->getMessage());
        }
        return $this->noStore(response()->view('time_clock.setup', $data));
    }

    public function setupUse(Request $request, $token)
    {
        $data = ['token' => '', 'store' => '', 'state' => 'invalid', 'msg' => ''];
        if (!$this->sameOrigin($request)) {
            return $this->noStore(response()->view('time_clock.setup', $data));
        }
        try {
            $t = TC::useSetupToken((string) $token);
            if ($t) {
                $businessId = TC::businessId();
                $ip = TC::clientIp($request);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $rule = TC::addManualIp($businessId, (int) $t['loc'], $ip, 'setup');
                    \Log::info('time clock: store network added by setup link', ['location_id' => (int) $t['loc'], 'network' => $rule, 'link_by' => $t['by'] ?? null]);
                    $data['store'] = TC::storeName($businessId, (int) $t['loc']) ?: 'the store';
                    $data['state'] = 'done';
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('clock setup use failed: ' . $e->getMessage());
        }
        return $this->noStore(response()->view('time_clock.setup', $data));
    }

    public function manifest()
    {
        $v = (int) config('constants.asset_version', 1);
        $m = [
            'name' => 'Nivessa Clock',
            'short_name' => 'Clock',
            'start_url' => '/clock',
            'scope' => '/clock',
            'display' => 'standalone',
            'background_color' => '#111111',
            'theme_color' => '#111111',
            'icons' => [
                ['src' => '/img/clock-icon-192.png?v=' . $v, 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/img/clock-icon-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/img/clock-icon-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return response()->json($m, 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
    }
}
