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

        $state = Str::random(40);
        $request->session()->put('quickbooks_oauth_state', $state);
        $request->session()->put('quickbooks_oauth_business_id', $business_id);

        return redirect()->away($qbService->getAuthorizationUrl($state));
    }

    public function callback(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $expectedState = $request->session()->get('quickbooks_oauth_state');
        $business_id = $request->session()->get('quickbooks_oauth_business_id');

        if (empty($expectedState) || $request->input('state') !== $expectedState) {
            return redirect()->route('business.getBusinessSettings')->with('status', [
                'success' => 0,
                'msg' => 'QuickBooks authorization failed due to invalid state. Please try again.',
            ]);
        }

        $code = $request->input('code');
        $realmId = $request->input('realmId');

        if (empty($code) || empty($realmId) || empty($business_id)) {
            return redirect()->route('business.getBusinessSettings')->with('status', [
                'success' => 0,
                'msg' => 'QuickBooks callback is missing required parameters.',
            ]);
        }

        $qbService = new QuickBooksService($business_id);
        $tokenResult = $qbService->exchangeAuthorizationCode($code);

        if (empty($tokenResult['success'])) {
            return redirect()->route('business.getBusinessSettings')->with('status', [
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

        $request->session()->forget('quickbooks_oauth_state');
        $request->session()->forget('quickbooks_oauth_business_id');

        return redirect()->route('business.getBusinessSettings')->with('status', [
            'success' => 1,
            'msg' => 'QuickBooks connected successfully.',
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
}

