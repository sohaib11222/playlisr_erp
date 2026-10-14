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
        $data = ['ready' => false, 'mode' => 'setup', 'device' => null, 'status' => null, 'name' => '', 'switch' => false];
        try {
            if (!TC::ready()) {
                return $this->noStore(response()->view('time_clock.phone', $data));
            }
            $data['ready'] = true;
            $businessId = TC::businessId();
            $device = TC::deviceFromRequest($request);
            if ($this->isSharedComputer($request, $device)) {
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

        // Buddy-punch guard (Sarah 10/13): clock-in only from 15 minutes
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
            if (!$sched['ok']) {
                return ['ok' => false, 'blocked' => true, 'geo_denied' => false, 'name' => $first,
                    'msg' => 'You are not on the schedule here right now.'];
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
            $this->afterClockOut($user);
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
     * Nick (shipping): clocking out posts the daily #shipping summary, same
     * as his End Shift (the Clover clock-in watch was removed 10/12). Runs
     * after the response; once per day on the website side.
     */
    private function afterClockOut($user): void
    {
        try {
            if (\App\Services\ShippingShiftNotifier::isShippingStaff($user)) {
                app()->terminating(function () {
                    \App\Services\ShippingShiftNotifier::postSummary('erp_clock_out');
                });
            }
        } catch (\Throwable $e) {
            \Log::warning('shipping summary on clock-out failed: ' . $e->getMessage());
        }
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
