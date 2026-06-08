<?php

namespace App\Http\Controllers;

use App\Services\Ebay\EbayInventoryApiClient;
use App\Services\EbayService;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

/**
 * Admin UI for connecting the eBay seller account so the ERP can fetch
 * orders from the Sell Fulfillment API. Three-step OAuth dance:
 *
 *   1. /admin/ebay-seller            → status page (connect / disconnect)
 *   2. /admin/ebay-seller/connect    → redirect to eBay's consent page
 *   3. /admin/ebay-seller/callback   → eBay redirects here with `code`
 *
 * Tokens land in business.api_settings.ebay_seller (no migration).
 */
class EbaySellerAuthController extends Controller
{
    /** @var BusinessUtil */
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        $service = new EbayService($business_id);

        $configured = $service->isConfigured();
        $connected = $configured ? $service->isSellerConnected() : false;

        // Read the saved seller block straight from settings for display.
        $settings = (new BusinessUtil())->getApiSettings($business_id);
        $seller = $settings['ebay_seller'] ?? [];
        $environment = $settings['ebay']['environment'] ?? 'sandbox';
        $ru_name = trim($settings['ebay']['ru_name'] ?? '');
        $callbackUrl = url('/admin/ebay-seller/callback');
        $oauthReady = $configured ? $service->isOAuthRedirectReady($callbackUrl) : false;

        $listingReadiness = $configured
            ? (new EbayInventoryApiClient($business_id, $service))->getReadinessSummary()
            : ['configured' => false, 'seller_connected' => false, 'errors' => ['eBay app credentials missing.']];

        return view('admin.ebay_seller_auth', compact(
            'configured', 'connected', 'seller', 'environment', 'ru_name', 'oauthReady', 'callbackUrl', 'listingReadiness'
        ));
    }

    public function connect(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        $service = new EbayService($business_id);
        if (!$service->isConfigured()) {
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => 'eBay app credentials missing. Set app_id, cert_id, dev_id under Business Settings → Integrations first.',
            ]);
        }
        $callbackUrl = url('/admin/ebay-seller/callback');
        if (!$service->isOAuthRedirectReady($callbackUrl)) {
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => 'Production OAuth requires RuName. Business Settings → Integrations → eBay → paste your RuName (e.g. Sohaib_Ahmad-SohaibAh-Nivess-hqyoql), Save, then try again.',
            ]);
        }
        try {
            $url = $service->getSellerAuthorizationUrl($callbackUrl);
        } catch (\InvalidArgumentException $e) {
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => $e->getMessage(),
            ]);
        }
        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');

        $code = $request->input('code');
        $error = $request->input('error');
        if ($error) {
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => 'eBay returned an error: ' . $error . ' — ' . $request->input('error_description', ''),
            ]);
        }
        if (empty($code)) {
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => 'Missing authorisation code in eBay callback.',
            ]);
        }

        $service = new EbayService($business_id);
        $result = $service->exchangeAuthCode($code, url('/admin/ebay-seller/callback'));

        if (empty($result['success'])) {
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => $result['msg'] ?? 'Token exchange failed.',
            ]);
        }
        return redirect('/admin/ebay-seller')->with('status', [
            'type' => 'success',
            'msg' => 'eBay seller account connected. Sales-by-Channel will now populate the eBay row.',
        ]);
    }

    public function disconnect(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        (new EbayService($business_id))->disconnectSeller();
        return redirect('/admin/ebay-seller')->with('status', [
            'type' => 'success',
            'msg' => 'eBay seller tokens cleared.',
        ]);
    }

    public function checkInventoryLocation(Request $request)
    {
        $this->guardAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $service = new EbayService($business_id);
        if (!$service->isSellerConnected()) {
            return response()->json(['success' => false, 'msg' => 'Seller not connected.'], 422);
        }

        $result = (new EbayInventoryApiClient($business_id, $service))->listInventoryLocations();
        return response()->json($result);
    }

    public function createInventoryLocation(Request $request)
    {
        $this->guardAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $service = new EbayService($business_id);
        if (!$service->isSellerConnected()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'msg' => 'Seller not connected.'], 422);
            }
            return redirect('/admin/ebay-seller')->with('status', [
                'type' => 'error',
                'msg' => 'Seller not connected.',
            ]);
        }

        $result = (new EbayInventoryApiClient($business_id, $service))->createDefaultInventoryLocation();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json($result, !empty($result['success']) ? 200 : 422);
        }

        return redirect('/admin/ebay-seller')->with('status', [
            'type' => !empty($result['success']) ? 'success' : 'error',
            'msg' => $result['msg'] ?? 'Unknown result.',
        ]);
    }

    protected function guardAdmin()
    {
        $user = auth()->user();
        if (!$user || !$this->businessUtil->is_admin($user)) {
            abort(403, 'Admins only.');
        }
    }
}
