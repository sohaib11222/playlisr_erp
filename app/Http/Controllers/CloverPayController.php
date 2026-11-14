<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\CloverPayService;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pay on Clover: setup page for admins (/admin/clover-pay) and the small POS
 * endpoints the card popup calls. See CloverPayService for the design.
 *
 * Safety for the register: every POS endpoint returns JSON and never throws,
 * and the button only shows at a store whose switch is on and fully set up.
 * If anything fails, the cashier keeps the normal flow (key it into Clover by
 * hand, tap Approved).
 */
class CloverPayController extends Controller
{
    private function businessId(Request $request)
    {
        return (int) ($request->session()->get('user.business_id') ?: auth()->user()->business_id);
    }

    private function isAdmin()
    {
        $u = auth()->user();
        return $u && app(BusinessUtil::class)->is_admin($u);
    }

    private function guardAdmin()
    {
        if (!$this->isAdmin()) {
            abort(403, 'Only Jon and Sarah can change Clover settings.');
        }
    }

    // ---- admin page --------------------------------------------------------

    public function index(Request $request)
    {
        $this->guardAdmin();
        $bid = $this->businessId($request);
        $svc = new CloverPayService($bid);
        $locations = BusinessLocation::where('business_id', $bid)->orderBy('name')->get(['id', 'name']);
        $devices = [];
        foreach ($locations as $l) {
            $lc = $svc->locationConfig($l->id);
            if (!empty($lc['access_token']) && $request->input('devices') == $l->id) {
                $devices[$l->id] = $svc->devices($l->id);
            }
        }
        return view('clover_pay.admin', [
            'cfg' => $svc->config(),
            'svc' => $svc,
            'locations' => $locations,
            'devices' => $devices,
            'attempts' => CloverPayService::attempts(30),
        ]);
    }

    public function saveApp(Request $request)
    {
        $this->guardAdmin();
        $svc = new CloverPayService($this->businessId($request));
        $changes = [
            'environment' => $request->input('environment') === 'sandbox' ? 'sandbox' : 'production',
        ];
        foreach (['app_id', 'raid'] as $k) {
            $v = trim((string) $request->input($k));
            if ($v !== '') {
                $changes[$k] = $v;
            }
        }
        // Secret is never shown back; only replaced when a new one is pasted.
        $secret = trim((string) $request->input('app_secret'));
        if ($secret !== '') {
            $changes['app_secret'] = $secret;
        }
        $svc->saveConfig($changes);
        return redirect('/admin/clover-pay')->with('status', ['success' => 1, 'msg' => 'Saved the Clover app settings.']);
    }

    public function saveLocation(Request $request)
    {
        $this->guardAdmin();
        $bid = $this->businessId($request);
        $locId = (int) $request->input('location_id');
        if (!BusinessLocation::where('business_id', $bid)->where('id', $locId)->exists()) {
            abort(404);
        }
        $svc = new CloverPayService($bid);
        $changes = ['enabled' => (bool) $request->input('enabled')];
        if ($request->has('device_serial')) {
            $changes['device_serial'] = trim((string) $request->input('device_serial'));
        }
        $svc->saveConfig($changes, $locId);
        return redirect('/admin/clover-pay')->with('status', ['success' => 1, 'msg' => 'Saved.']);
    }

    /** Start the per-store OAuth install. Reuses the registered callback URL. */
    public function connect(Request $request)
    {
        $this->guardAdmin();
        $bid = $this->businessId($request);
        $locId = (int) $request->input('location_id');
        $svc = new CloverPayService($bid);
        if (empty($svc->config()['app_id'])) {
            return redirect('/admin/clover-pay')->with('status', ['success' => 0, 'msg' => 'Save the App ID first.']);
        }
        $payload = base64_encode(json_encode(['bid' => $bid, 'rp' => 1, 'loc' => $locId, 'exp' => time() + 900, 'nonce' => Str::random(24)]));
        // Same signing key as CloverController::parseCloverOAuthState.
        $key = (string) config('app.key');
        if (strpos($key, 'base64:') === 0) {
            $key = base64_decode(substr($key, 7));
        }
        $state = $payload . '.' . hash_hmac('sha256', $payload, $key);
        return redirect()->away($svc->authorizeUrl($state, route('business.clover.callback')));
    }

    /**
     * Called from CloverController@callback when the state says it is a Pay
     * on Clover install. Returns a redirect.
     */
    public static function finishConnect(Request $request, array $parsed)
    {
        $bid = (int) $parsed['bid'];
        $locId = (int) ($parsed['loc'] ?? 0);
        $svc = new CloverPayService($bid);
        $code = (string) $request->input('code');
        if ($code === '' || !$locId) {
            return redirect('/admin/clover-pay')->with('status', ['success' => 0, 'msg' => 'Clover did not send a code back. Try Connect again.']);
        }
        $res = $svc->exchangeCode($code);
        if (empty($res['success']) || empty($res['data']['access_token'])) {
            return redirect('/admin/clover-pay')->with('status', ['success' => 0, 'msg' => 'Clover connect failed: ' . ($res['msg'] ?? 'no token')]);
        }
        $d = $res['data'];
        $merchant = $request->input('merchant_id') ?: ($request->input('merchantId') ?: $request->input('mId'));
        $changes = [
            'access_token' => $d['access_token'],
            'refresh_token' => $d['refresh_token'] ?? '',
            'access_token_expiration' => $d['access_token_expiration'] ?? '',
            'refresh_token_expiration' => $d['refresh_token_expiration'] ?? '',
            'connected_at' => date('Y-m-d H:i:s'),
        ];
        if ($merchant) {
            $changes['merchant_id'] = $merchant;
        }
        $svc->saveConfig($changes, $locId);
        return redirect('/admin/clover-pay?devices=' . $locId)->with('status', ['success' => 1, 'msg' => 'Store connected to Clover. Pick its Flex below.']);
    }

    public function ping(Request $request)
    {
        $this->guardAdmin();
        $svc = new CloverPayService($this->businessId($request));
        $res = $svc->ping((int) $request->input('location_id'));
        return redirect('/admin/clover-pay')->with('status', [
            'success' => !empty($res['success']) ? 1 : 0,
            'msg' => !empty($res['success']) ? 'The Flex answered. It is ready for Pay on Clover.' : ('The Flex did not answer: ' . ($res['msg'] ?? '') . '. Make sure Cloud Pay Display is open on it.'),
        ]);
    }

    // ---- POS endpoints (JSON, never throw) ----------------------------------

    public function status(Request $request)
    {
        try {
            $svc = new CloverPayService($this->businessId($request));
            return response()->json(['enabled' => $svc->isReady((int) $request->input('location_id'))]);
        } catch (\Throwable $e) {
            return response()->json(['enabled' => false]);
        }
    }

    public function pay(Request $request)
    {
        @set_time_limit(150);
        $bid = $this->businessId($request);
        $locId = (int) $request->input('location_id');
        $cents = (int) round(((float) $request->input('amount')) * 100);
        $taxCents = $request->filled('tax') ? (int) round(((float) $request->input('tax')) * 100) : null;
        $externalId = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('external_id')), 0, 32) ?: ('NV' . strtoupper(Str::random(14)));
        $me = auth()->user();
        try {
            $svc = new CloverPayService($bid);
            if (!$svc->isReady($locId)) {
                return response()->json(['success' => false, 'msg' => 'Pay on Clover is not set up for this store. Key the amount into Clover instead.']);
            }
            if ($cents <= 0) {
                return response()->json(['success' => false, 'msg' => 'Nothing to charge.']);
            }
            CloverPayService::logAttempt([
                'external_id' => $externalId,
                'business_id' => $bid,
                'location_id' => $locId,
                'user_id' => $me ? $me->id : null,
                'user' => $me ? trim($me->first_name . ' ' . $me->last_name) : '',
                'amount_cents' => $cents,
                'status' => 'sent',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $res = $svc->pay($locId, $cents, $externalId, $externalId, $taxCents);
            $p = $res['data']['payment'] ?? ($res['data'] ?? []);
            $ok = !empty($res['success']) && !empty($p['id']) && strtoupper($p['result'] ?? 'SUCCESS') === 'SUCCESS';
            CloverPayService::logAttempt([
                'external_id' => $externalId,
                'status' => $ok ? 'paid' : (!empty($res['timeout']) ? 'unknown' : 'failed'),
                'clover_payment_id' => $p['id'] ?? null,
                'clover_order_id' => $p['order']['id'] ?? null,
                'msg' => $res['msg'] ?? null,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            try { $svc->welcome($locId); } catch (\Throwable $e) {}
            if ($ok) {
                return response()->json([
                    'success' => true,
                    'payment_id' => $p['id'],
                    'external_id' => $externalId,
                    'amount' => ($p['amount'] ?? $cents) / 100,
                    'card' => trim(($p['cardTransaction']['cardType'] ?? '') . ' ' . ($p['cardTransaction']['last4'] ?? '')),
                ]);
            }
            return response()->json([
                'success' => false,
                'unknown' => !empty($res['timeout']),
                'external_id' => $externalId,
                'msg' => !empty($res['timeout'])
                    ? 'No answer from Clover yet. Check the Clover screen before trying again.'
                    : ('Not paid: ' . ($res['msg'] ?? 'declined or cancelled') . '.'),
            ]);
        } catch (\Throwable $e) {
            CloverPayService::logAttempt(['external_id' => $externalId, 'status' => 'error', 'msg' => $e->getMessage()]);
            return response()->json(['success' => false, 'unknown' => true, 'external_id' => $externalId, 'msg' => 'Something went wrong talking to Clover. Check the Clover screen.']);
        }
    }

    /** After a lost reply: did that payment go through? */
    public function recover(Request $request)
    {
        try {
            $svc = new CloverPayService($this->businessId($request));
            $locId = (int) $request->input('location_id');
            $ext = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('external_id')), 0, 32);
            $res = $svc->findByExternalId($locId, $ext);
            $p = $res['data']['payment'] ?? ($res['data'] ?? []);
            if (!empty($res['success']) && !empty($p['id']) && strtoupper($p['result'] ?? 'SUCCESS') === 'SUCCESS') {
                CloverPayService::logAttempt(['external_id' => $ext, 'status' => 'paid', 'clover_payment_id' => $p['id'], 'finished_at' => date('Y-m-d H:i:s')]);
                return response()->json(['success' => true, 'payment_id' => $p['id'], 'external_id' => $ext]);
            }
            return response()->json(['success' => false, 'msg' => 'Clover has no finished payment for this sale.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'msg' => 'Could not check with Clover.']);
        }
    }

    public function cancel(Request $request)
    {
        try {
            $svc = new CloverPayService($this->businessId($request));
            $svc->cancelOnDevice((int) $request->input('location_id'));
        } catch (\Throwable $e) {}
        return response()->json(['success' => true]);
    }
}
