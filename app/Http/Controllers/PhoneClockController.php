<?php

namespace App\Http\Controllers;

use App\Services\TimeClockService as TC;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public phone time clock at /clock (Sarah, 2026-10-13). No ERP login: the
 * first time on a phone staff pick their name and enter their 4-digit PIN,
 * then the phone is remembered (see TimeClockService). Staff open it and
 * tap Clock in / Clock out.
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
     * Store computers (Sarah 2026-10-13): a shared computer never remembers
     * one person. Desktop browsers get name + PIN on every punch; phones get
     * remember-me. ?mode=computer / ?mode=phone overrides the guess.
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

    public function page(Request $request)
    {
        $data = ['ready' => false, 'device' => null, 'status' => null, 'roster' => [], 'switch' => false, 'kiosk' => false, 'flash' => session('clock_flash')];
        try {
            if (!TC::ready()) {
                return $this->noStore(response()->view('time_clock.phone', $data));
            }
            $data['ready'] = true;
            $businessId = TC::businessId();
            $device = TC::deviceFromRequest($request);
            if ($this->isSharedComputer($request, $device)) {
                $data['kiosk'] = true;
                $data['roster'] = TC::roster($businessId);
                $data['kiosk_store'] = TC::resolveStore($businessId, TC::clientIp($request), []);
                $data['kiosk_store']['name'] = TC::storeName($businessId, $data['kiosk_store']['location_id']);
                return $this->noStore(response()->view('time_clock.phone', $data));
            }
            $data['switch'] = $request->query('switch') === '1';
            if ($device && !$data['switch']) {
                $data['device'] = $device;
                $data['name'] = trim((string) $device->user->first_name) ?: TC::displayName($device->user);
                $data['status'] = TC::status((int) $device->user->business_id ?: $businessId, (int) $device->user->id);
            } else {
                $data['roster'] = TC::roster($businessId);
                $data['current_name'] = $device ? TC::displayName($device->user) : null;
            }
        } catch (\Throwable $e) {
            \Log::warning('phone clock page failed: ' . $e->getMessage());
            $data['ready'] = false;
            $data['error'] = true;
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
            \Log::warning('phone clock state failed: ' . $e->getMessage());
        }
        return $this->noStore(response()->json($out));
    }

    /** First time on a phone (or switching person): name + PIN. */
    public function pin(Request $request)
    {
        $back = function ($msg) {
            return redirect('/clock' . (request()->input('switch') === '1' ? '?switch=1' : ''))->with('clock_flash', ['ok' => false, 'msg' => $msg]);
        };
        if (!$this->sameOrigin($request)) {
            return $back('Please open the clock page again and retry.');
        }
        if (!TC::ready()) {
            return redirect('/clock');
        }
        try {
            $businessId = TC::businessId();
            $userId = (int) $request->input('user_id');
            $pin = preg_replace('/\D/', '', (string) $request->input('pin'));
            $roster = TC::roster($businessId);
            if (!$userId || !isset($roster[$userId])) {
                return $back('Please pick your name.');
            }
            if (strlen($pin) !== 4) {
                return $back('Your PIN is 4 numbers.');
            }
            $check = TC::checkPin($userId, $pin, TC::clientIp($request));
            if (!$check['ok']) {
                return $back($check['msg']);
            }
            // Switching person: retire the old remembered phone.
            $old = TC::deviceFromRequest($request);
            if ($old) {
                TC::revokeDevice((int) $old->id, null);
            }
            $token = TC::issueDevice($businessId, $userId, $request);
            $first = trim((string) DB::table('users')->where('id', $userId)->value('first_name'));
            return redirect('/clock')
                ->with('clock_flash', ['ok' => true, 'msg' => 'Hi ' . $first . '. This phone is set up. Next time just open this page and tap the button.', 'new' => true])
                ->withCookie(cookie(TC::COOKIE, $token, TC::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'lax'));
        } catch (\Throwable $e) {
            \Log::warning('phone clock pin failed: ' . $e->getMessage());
            return $back('Something went wrong. Please try again.');
        }
    }

    /** Clock in / out. JSON for the page script, redirect for no-JS. */
    public function punch(Request $request)
    {
        $json = $request->expectsJson() || $request->ajax();
        $reply = function ($ok, $msg, $status = null) use ($json) {
            if ($json) {
                return response()->json(['ok' => $ok, 'msg' => $msg, 'status' => $status])->header('Cache-Control', 'no-store');
            }
            return redirect('/clock')->with('clock_flash', ['ok' => $ok, 'msg' => $msg]);
        };
        if (!$this->sameOrigin($request)) {
            return $reply(false, 'Please open the clock page again and retry.');
        }
        try {
            if (!TC::ready()) {
                return $reply(false, 'The time clock is not set up yet. Write your time down and tell a manager.');
            }
            $device = TC::deviceFromRequest($request);
            if (!$device) {
                return $reply(false, 'This phone is not set up. Pick your name and enter your PIN.');
            }
            $user = $device->user;
            $businessId = (int) $user->business_id;
            $action = $request->input('action') === 'out' ? 'out' : 'in';

            if ($action === 'in') {
                $r = TC::clockIn($request, $user, (int) $device->id);
                return $reply($r['ok'], $r['msg'], TC::status($businessId, (int) $user->id));
            }

            $id = TC::clockOut((int) $user->id, 'phone', $request, true);
            if (!$id) {
                return $reply(true, 'You were not clocked in.', TC::status($businessId, (int) $user->id));
            }
            $this->afterClockOut($user);
            return $reply(true, 'Clocked out at ' . TC::now()->format('g:i A') . '. Thanks!', TC::status($businessId, (int) $user->id));
        } catch (\Throwable $e) {
            \Log::warning('phone clock punch failed: ' . $e->getMessage());
            return $reply(false, 'That did not go through. Please try again, or tell a manager.');
        }
    }

    /**
     * Shared store computer: name + PIN + Clock in / Clock out in one step.
     * Nothing is remembered. The store is confirmed by the store IP
     * allowlist (or location, if the browser sent one).
     */
    public function kiosk(Request $request)
    {
        $json = $request->expectsJson() || $request->ajax();
        $reply = function ($ok, $msg, $status = null) use ($json) {
            if ($json) {
                return response()->json(['ok' => $ok, 'msg' => $msg, 'status' => $status])->header('Cache-Control', 'no-store');
            }
            return redirect('/clock?mode=computer')->with('clock_flash', ['ok' => $ok, 'msg' => $msg]);
        };
        if (!$this->sameOrigin($request)) {
            return $reply(false, 'Please reload the page and try again.');
        }
        try {
            if (!TC::ready()) {
                return $reply(false, 'The time clock is not set up yet. Write your time down and tell a manager.');
            }
            $businessId = TC::businessId();
            $userId = (int) $request->input('user_id');
            $pin = preg_replace('/\D/', '', (string) $request->input('pin'));
            $roster = TC::roster($businessId);
            if (!$userId || !isset($roster[$userId])) {
                return $reply(false, 'Please pick your name.');
            }
            if (strlen($pin) !== 4) {
                return $reply(false, 'Your PIN is 4 numbers.');
            }
            $check = TC::checkPin($userId, $pin, TC::clientIp($request));
            if (!$check['ok']) {
                return $reply(false, $check['msg']);
            }
            $user = DB::table('users')->where('id', $userId)->first();
            $first = trim((string) $user->first_name);
            $action = $request->input('action') === 'out' ? 'out' : 'in';
            if ($action === 'in') {
                $r = TC::clockIn($request, $user, null, 'computer');
                return $reply($r['ok'], $first . ': ' . $r['msg'], TC::status($businessId, $userId));
            }
            $id = TC::clockOut($userId, 'computer', $request, true);
            if (!$id) {
                return $reply(true, $first . ': you were not clocked in.', TC::status($businessId, $userId));
            }
            $this->afterClockOut($user);
            return $reply(true, $first . ': clocked out at ' . TC::now()->format('g:i A') . '. Thanks!', TC::status($businessId, $userId));
        } catch (\Throwable $e) {
            \Log::warning('computer clock punch failed: ' . $e->getMessage());
            return $reply(false, 'That did not go through. Please try again, or tell a manager.');
        }
    }

    /**
     * Nick (shipping): clocking out on his phone posts the daily #shipping
     * summary, same as his End Shift (the Clover clock-in watch was removed
     * 10/12). Runs after the response; once per day on the website side.
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
