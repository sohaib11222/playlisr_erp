<?php

namespace App\Http\Controllers;

use App\Business;
use App\QuickBooksConnection;
use App\Services\QuickBooksService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuickBooksController extends Controller
{
    /**
     * HMAC-signed OAuth state so the callback does not rely on session (Intuit redirect can arrive without a logged-in session).
     */
    protected function getAppKeyForSigning()
    {
        $key = config('app.key');
        if (strpos($key, 'base64:') === 0) {
            return base64_decode(substr($key, 7));
        }

        return $key;
    }

    protected function buildQuickBooksOAuthState($businessId)
    {
        $payload = json_encode([
            'bid' => (int) $businessId,
            'exp' => time() + 600,
            'nonce' => Str::random(32),
        ]);
        $payloadB64 = base64_encode($payload);
        $sig = hash_hmac('sha256', $payloadB64, $this->getAppKeyForSigning());

        return $payloadB64 . '.' . $sig;
    }

    /**
     * @return array|null
     */
    protected function parseQuickBooksOAuthState($state)
    {
        if (empty($state) || strpos($state, '.') === false) {
            return null;
        }
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }
        list($payloadB64, $sig) = $parts;
        $expected = hash_hmac('sha256', $payloadB64, $this->getAppKeyForSigning());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(base64_decode($payloadB64), true);
        if (!is_array($payload) || empty($payload['bid']) || empty($payload['exp'])) {
            return null;
        }
        if (time() > (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    protected function quickBooksCallbackRedirect(Request $request, array $status)
    {
        if (auth()->check()) {
            return redirect()->route('business.getBusinessSettings')->with('status', $status);
        }

        return redirect()->route('login')->with('status', $status);
    }

    public function connect(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);

        if (!$qbService->isConfigured()) {
            return redirect()->route('business.getBusinessSettings')->with('status', [
                'success' => 0,
                'msg' => 'Please configure QuickBooks Client ID and Client Secret in Integrations settings first.',
            ]);
        }

        $state = $this->buildQuickBooksOAuthState($business_id);

        return redirect()->away($qbService->getAuthorizationUrl($state));
    }

    public function callback(Request $request)
    {
        $parsed = $this->parseQuickBooksOAuthState($request->input('state'));
        if (empty($parsed)) {
            return $this->quickBooksCallbackRedirect($request, [
                'success' => 0,
                'msg' => 'QuickBooks authorization failed due to invalid or expired state. Please try again.',
            ]);
        }

        $business_id = (int) $parsed['bid'];
        $code = $request->input('code');
        $realmId = $request->input('realmId');

        if (empty($code) || empty($realmId)) {
            return $this->quickBooksCallbackRedirect($request, [
                'success' => 0,
                'msg' => 'QuickBooks callback is missing required parameters.',
            ]);
        }

        $qbService = new QuickBooksService($business_id);
        $tokenResult = $qbService->exchangeAuthorizationCode($code);

        if (empty($tokenResult['success'])) {
            return $this->quickBooksCallbackRedirect($request, [
                'success' => 0,
                'msg' => !empty($tokenResult['msg']) ? $tokenResult['msg'] : 'QuickBooks connection failed.',
            ]);
        }

        $tokenData = $tokenResult['data'];
        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);
        $refreshExpiresIn = (int) ($tokenData['x_refresh_token_expires_in'] ?? 0);

        QuickBooksConnection::updateOrCreate(
            ['business_id' => $business_id],
            [
                'realm_id' => $realmId,
                'access_token' => !empty($accessToken) ? encrypt($accessToken) : null,
                'refresh_token' => !empty($refreshToken) ? encrypt($refreshToken) : null,
                'token_expires_at' => $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
                'refresh_expires_at' => $refreshExpiresIn > 0 ? Carbon::now()->addSeconds($refreshExpiresIn) : null,
                'environment' => $qbService->getEnvironment(),
                'is_active' => 1,
            ]
        );

        $business = Business::find($business_id);
        if (!empty($business)) {
            $api_settings = is_array($business->api_settings) ? $business->api_settings : [];
            if (empty($api_settings['quickbooks']) || !is_array($api_settings['quickbooks'])) {
                $api_settings['quickbooks'] = [];
            }
            $api_settings['quickbooks']['realm_id'] = $realmId;
            $api_settings['quickbooks']['connected_at'] = Carbon::now()->toDateTimeString();
            $business->api_settings = $api_settings;
            $business->save();
        }

        $qbServiceFresh = new QuickBooksService($business_id);
        $provision = $qbServiceFresh->ensureDefaultSalesItem();
        $statusMsg = 'QuickBooks connected successfully.';
        if (!empty($provision['success'])) {
            $statusMsg .= ' ' . ($provision['msg'] ?? 'Default sales item is ready.');
        } else {
            $statusMsg .= ' ' . ($provision['msg'] ?? 'Could not auto-create the default sales item; set Default Sales Item ID manually or click Test Connection to retry.');
        }

        return $this->quickBooksCallbackRedirect($request, [
            'success' => 1,
            'msg' => $statusMsg,
        ]);
    }

    public function disconnect(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');
            $connection = QuickBooksConnection::where('business_id', $business_id)->first();

            if (!empty($connection)) {
                $connection->is_active = 0;
                $connection->access_token = null;
                $connection->refresh_token = null;
                $connection->token_expires_at = null;
                $connection->refresh_expires_at = null;
                $connection->save();
            }

            return response()->json([
                'success' => true,
                'msg' => 'QuickBooks disconnected successfully.',
            ]);
        } catch (\Exception $e) {
            \Log::error('QuickBooks disconnect failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'msg' => 'Unable to disconnect QuickBooks: ' . $e->getMessage(),
            ]);
        }
    }

    public function testConnection(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $result = $qbService->testConnection();

        return response()->json($result);
    }

    public function syncSale(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'transaction_id' => 'required|integer|min:1',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $result = $qbService->syncSaleTransaction((int) $request->input('transaction_id'));

        return response()->json($result);
    }

    public function dashboard(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $connection = QuickBooksConnection::where('business_id', $business_id)->first();
        $logs = $qbService->getRecentSyncLogs(100);

        return view('quickbooks.dashboard', compact('connection', 'logs'));
    }

    public function backfill(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'from_date' => 'required|date',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $result = $qbService->backfillSalesFromDate($request->input('from_date'));

        return redirect()->action('QuickBooksController@dashboard')->with('status', [
            'success' => !empty($result['success']) ? 1 : 0,
            'msg' => !empty($result['msg']) ? $result['msg'] : 'Backfill finished.',
        ]);
    }
}

