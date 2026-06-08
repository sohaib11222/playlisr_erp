<?php

namespace App\Services\Ebay;

use App\DataTransferObjects\EbayListingDraft;
use App\Services\EbayService;
use App\Utils\BusinessUtil;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EbayInventoryApiClient
{
    /** @var EbayService */
    private $ebayService;

    /** @var BusinessUtil */
    private $businessUtil;

    /** @var int */
    private $businessId;

    public function __construct($businessId, EbayService $ebayService = null, BusinessUtil $businessUtil = null)
    {
        $this->businessId = (int) $businessId;
        $this->ebayService = $ebayService ?: new EbayService($businessId);
        $this->businessUtil = $businessUtil ?: new BusinessUtil();
    }

    /**
     * Publish a product draft to eBay (inventory item → offer → publish).
     *
     * @param EbayListingDraft $draft
     * @return array
     */
    public function publishListing(EbayListingDraft $draft)
    {
        $token = $this->ebayService->getSellerAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'msg' => 'Could not get an eBay seller token. Please re-connect your eBay account.',
            ];
        }

        $prereqs = $this->getListingPrereqs($token);
        if (!empty($prereqs['error'])) {
            return ['success' => false, 'msg' => $prereqs['error']];
        }

        $offerLocationKey = $this->resolveOfferLocationKey($draft, $prereqs);
        if ($offerLocationKey === '') {
            return [
                'success' => false,
                'msg' => 'No eBay inventory location for this product\'s store. Create Pico + Hollywood locations at /admin/ebay-seller.',
            ];
        }

        $sku = $draft->sku;
        $title = trim((string) $draft->title);
        if (mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 80);
        }
        $price = (float) $draft->price;
        $quantity = max(1, (int) $draft->quantity);
        $imageUrls = array_slice(array_values(array_filter($draft->image_urls, 'strlen')), 0, 12);
        $description = $draft->description ?: $title;

        try {
            Log::info('eBay listing: creating inventory item', ['sku' => $sku, 'product_id' => $draft->product_id]);

            $inventoryItem = [
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => $quantity,
                    ],
                ],
                'condition' => $draft->condition ?: 'USED_GOOD',
                'product' => [
                    'title' => $title,
                    'description' => $description,
                    'imageUrls' => $imageUrls,
                ],
            ];

            $res = $this->sellerApiRequest(
                'PUT',
                '/sell/inventory/v1/inventory_item/' . rawurlencode($sku),
                $token,
                $inventoryItem,
                ['Content-Language: en-US']
            );
            if ($res['status'] < 200 || $res['status'] >= 300) {
                return ['success' => false, 'msg' => 'eBay inventory item failed: ' . $this->ebayErr($res)];
            }

            Log::info('eBay listing: creating offer', ['sku' => $sku, 'product_id' => $draft->product_id]);

            $offerPayload = [
                'sku' => $sku,
                'marketplaceId' => 'EBAY_US',
                'format' => 'FIXED_PRICE',
                'availableQuantity' => $quantity,
                'categoryId' => $draft->category_id,
                'listingDescription' => $description,
                'pricingSummary' => [
                    'price' => [
                        'value' => number_format($price, 2, '.', ''),
                        'currency' => $draft->currency ?: 'USD',
                    ],
                ],
                'listingPolicies' => [
                    'fulfillmentPolicyId' => $prereqs['fulfillmentPolicyId'],
                    'paymentPolicyId' => $prereqs['paymentPolicyId'],
                    'returnPolicyId' => $prereqs['returnPolicyId'],
                ],
                'merchantLocationKey' => $offerLocationKey,
            ];

            $offerId = null;
            $res = $this->sellerApiRequest('POST', '/sell/inventory/v1/offer', $token, $offerPayload);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                $offerId = $res['body']['offerId'] ?? null;
            } elseif ($this->ebayErrorId($res) == 25002) {
                $existing = $this->sellerApiRequest(
                    'GET',
                    '/sell/inventory/v1/offer?sku=' . rawurlencode($sku) . '&marketplace_id=EBAY_US',
                    $token
                );
                $offerId = $existing['body']['offers'][0]['offerId'] ?? null;
                if ($offerId) {
                    $this->sellerApiRequest('PUT', '/sell/inventory/v1/offer/' . $offerId, $token, $offerPayload);
                }
            }

            if (!$offerId) {
                return ['success' => false, 'msg' => 'eBay offer failed: ' . $this->ebayErr($res)];
            }

            Log::info('eBay listing: publishing offer', ['sku' => $sku, 'offer_id' => $offerId, 'product_id' => $draft->product_id]);

            $res = $this->sellerApiRequest('POST', '/sell/inventory/v1/offer/' . $offerId . '/publish', $token);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                return [
                    'success' => false,
                    'msg' => 'eBay publish failed: ' . $this->ebayErr($res),
                    'offer_id' => $offerId,
                ];
            }

            $listingId = $res['body']['listingId'] ?? $offerId;
            Log::info('eBay listing: published', [
                'sku' => $sku,
                'offer_id' => $offerId,
                'listing_id' => $listingId,
                'product_id' => $draft->product_id,
            ]);

            return [
                'success' => true,
                'listing_id' => $listingId,
                'offer_id' => $offerId,
                'data' => $res['body'],
            ];
        } catch (\Exception $e) {
            Log::error('eBay Listing Error: ' . $e->getMessage(), ['product_id' => $draft->product_id]);
            return ['success' => false, 'msg' => 'eBay listing error: ' . $e->getMessage()];
        }
    }

    /**
     * Check seller policies + inventory location. Cached 24h in api_settings.ebay.policy_cache.
     *
     * @param string|null $token
     * @param bool $forceRefresh
     * @return array
     */
    public function getListingPrereqs($token = null, $forceRefresh = false)
    {
        $settings = $this->businessUtil->getApiSettings($this->businessId);
        $cache = $settings['ebay']['policy_cache'] ?? [];

        if (!$forceRefresh && !empty($cache['cached_at'])) {
            $cachedAt = strtotime($cache['cached_at']);
            if ($cachedAt && $cachedAt > time() - 86400
                && !empty($cache['fulfillmentPolicyId'])
                && !empty($cache['paymentPolicyId'])
                && !empty($cache['returnPolicyId'])
                && !empty($cache['merchantLocationKey'])) {
                return $cache;
            }
        }

        if (!$token) {
            $token = $this->ebayService->getSellerAccessToken();
        }
        if (!$token) {
            return ['error' => 'No seller token (not connected, or refresh failed).'];
        }

        $policyMap = [
            'fulfillmentPolicyId' => ['path' => '/sell/account/v1/fulfillment_policy', 'list' => 'fulfillmentPolicies', 'id' => 'fulfillmentPolicyId', 'label' => 'shipping (fulfillment)'],
            'paymentPolicyId' => ['path' => '/sell/account/v1/payment_policy', 'list' => 'paymentPolicies', 'id' => 'paymentPolicyId', 'label' => 'payment'],
            'returnPolicyId' => ['path' => '/sell/account/v1/return_policy', 'list' => 'returnPolicies', 'id' => 'returnPolicyId', 'label' => 'return'],
        ];

        $out = [];
        foreach ($policyMap as $key => $cfg) {
            $res = $this->sellerApiRequest('GET', $cfg['path'] . '?marketplace_id=EBAY_US', $token);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                return ['error' => 'Could not read eBay ' . $cfg['label'] . ' policies: ' . $this->ebayErr($res)];
            }
            $id = $res['body'][$cfg['list']][0][$cfg['id']] ?? null;
            if (!$id) {
                return ['error' => 'No eBay ' . $cfg['label'] . ' business policy found. Create one in eBay Seller Hub → Business policies, then try again.'];
            }
            $out[$key] = $id;
        }

        $res = $this->sellerApiRequest('GET', '/sell/inventory/v1/location', $token);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['error' => 'Could not read eBay inventory locations: ' . $this->ebayErr($res)];
        }
        $locKey = $res['body']['locations'][0]['merchantLocationKey'] ?? null;
        if (!$locKey) {
            return ['error' => 'No eBay inventory location set up. Add a location in eBay Seller Hub, then try again.'];
        }
        $out['merchantLocationKey'] = $locKey;
        $out['cached_at'] = Carbon::now()->toDateTimeString();

        $this->savePolicyCache($out);

        return $out;
    }

    /**
     * List inventory API locations on the connected seller account.
     *
     * @return array{success: bool, locations: array, msg?: string}
     */
    public function listInventoryLocations()
    {
        $token = $this->ebayService->getSellerAccessToken();
        if (!$token) {
            return ['success' => false, 'locations' => [], 'msg' => 'No seller token — connect your eBay account first.'];
        }

        $res = $this->sellerApiRequest('GET', '/sell/inventory/v1/location', $token);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'success' => false,
                'locations' => [],
                'msg' => 'Could not read eBay inventory locations: ' . $this->ebayErr($res),
            ];
        }

        $locations = $res['body']['locations'] ?? [];
        return [
            'success' => true,
            'locations' => is_array($locations) ? $locations : [],
            'msg' => count($locations) . ' location(s) found.',
        ];
    }

    /**
     * Create eBay WAREHOUSE locations for each active ERP store (Pico, Hollywood, …).
     * Skips keys that already exist; saves ERP location_id → merchantLocationKey map.
     *
     * @return array{success: bool, msg: string, created?: string[], skipped?: string[], location_map?: array}
     */
    public function createDefaultInventoryLocation()
    {
        $token = $this->ebayService->getSellerAccessToken();
        if (!$token) {
            return ['success' => false, 'msg' => 'No seller token — connect your eBay account first.'];
        }

        $existing = $this->listInventoryLocations();
        if (empty($existing['success'])) {
            return ['success' => false, 'msg' => $existing['msg'] ?? 'Could not check existing locations.'];
        }

        $existingKeys = [];
        foreach ($existing['locations'] as $loc) {
            if (!empty($loc['merchantLocationKey'])) {
                $existingKeys[$loc['merchantLocationKey']] = true;
            }
        }

        $erpLocations = $this->erpShipFromLocations();

        if ($erpLocations->isEmpty()) {
            return $this->createSingleWarehouseLocation($token, 'nivessa-warehouse', 'Nivessa Warehouse', $this->fallbackAddress());
        }

        $created = [];
        $skipped = [];
        $errors = [];
        $locationMap = [];

        foreach ($erpLocations as $erpLoc) {
            $key = self::merchantKeyForLocationName($erpLoc->name, (int) $erpLoc->id);
            $locationMap[(string) $erpLoc->id] = $key;

            if (isset($existingKeys[$key])) {
                $skipped[] = $erpLoc->name . ' → ' . $key;
                continue;
            }

            $address = $this->addressFromBusinessLocation($erpLoc);
            $payload = [
                'name' => 'Nivessa ' . $erpLoc->name,
                'locationTypes' => ['WAREHOUSE'],
                'merchantLocationStatus' => 'ENABLED',
                'location' => ['address' => $address],
            ];

            $res = $this->sellerApiRequest(
                'POST',
                '/sell/inventory/v1/location/' . rawurlencode($key),
                $token,
                $payload,
                ['Content-Language: en-US']
            );

            if ($res['status'] < 200 || $res['status'] >= 300) {
                $errors[] = $erpLoc->name . ': ' . $this->ebayErr($res);
                continue;
            }

            $existingKeys[$key] = true;
            $created[] = $erpLoc->name . ' → ' . $key . ' (' . $this->formatAddress($address) . ')';
        }

        $this->saveLocationMap($locationMap);
        $this->getListingPrereqs($token, true);

        if (!empty($errors) && empty($created) && empty($skipped)) {
            return ['success' => false, 'msg' => implode(' ', $errors)];
        }

        $parts = [];
        if (!empty($created)) {
            $parts[] = 'Created: ' . implode('; ', $created);
        }
        if (!empty($skipped)) {
            $parts[] = 'Already on eBay: ' . implode('; ', $skipped);
        }
        if (!empty($errors)) {
            $parts[] = 'Errors: ' . implode('; ', $errors);
        }

        return [
            'success' => empty($errors) || !empty($created) || !empty($skipped),
            'msg' => implode("\n", $parts),
            'created' => $created,
            'skipped' => $skipped,
            'location_map' => $locationMap,
        ];
    }

    public static function merchantKeyForLocationName($name, $id = null)
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', (string) $name), '-'));
        if ($slug === '') {
            $slug = 'loc-' . (int) $id;
        }
        return 'nivessa-' . $slug;
    }

    protected function createSingleWarehouseLocation($token, $key, $label, array $address)
    {
        $res = $this->sellerApiRequest(
            'POST',
            '/sell/inventory/v1/location/' . rawurlencode($key),
            $token,
            [
                'name' => $label,
                'locationTypes' => ['WAREHOUSE'],
                'merchantLocationStatus' => 'ENABLED',
                'location' => ['address' => $address],
            ],
            ['Content-Language: en-US']
        );

        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['success' => false, 'msg' => 'Create location failed: ' . $this->ebayErr($res)];
        }

        $this->getListingPrereqs($token, true);
        return [
            'success' => true,
            'msg' => 'Created inventory location "' . $key . '" (' . $this->formatAddress($address) . ').',
            'merchantLocationKey' => $key,
        ];
    }

    protected function resolveOfferLocationKey(EbayListingDraft $draft, array $prereqs)
    {
        $key = trim((string) ($draft->merchant_location_key ?? ''));
        if ($key !== '') {
            return $key;
        }
        return $prereqs['merchantLocationKey'] ?? '';
    }

    protected function saveLocationMap(array $locationMap)
    {
        $business = \App\Business::find($this->businessId);
        if (!$business) {
            return;
        }
        $api = $business->api_settings ?? [];
        if (is_string($api)) {
            $api = json_decode($api, true) ?? [];
        }
        $api['ebay'] = array_merge($api['ebay'] ?? [], ['location_map' => $locationMap]);
        $business->api_settings = $api;
        $business->save();
    }

    /**
     * Readiness summary for admin UI (no product draft required).
     *
     * @return array
     */
    public function getReadinessSummary()
    {
        $summary = [
            'configured' => $this->ebayService->isConfigured(),
            'seller_connected' => $this->ebayService->isSellerConnected(),
            'oauth_ready' => false,
            'policies_ok' => false,
            'location_ok' => false,
            'default_category_set' => false,
            'locations' => [],
            'errors' => [],
        ];

        $settings = $this->businessUtil->getApiSettings($this->businessId);
        $ebay = $settings['ebay'] ?? [];
        $callbackUrl = url('/admin/ebay-seller/callback');
        $summary['oauth_ready'] = $this->ebayService->isOAuthRedirectReady($callbackUrl);
        $summary['default_category_set'] = trim((string) ($ebay['default_category_id'] ?? '')) !== '';

        if (!$summary['configured']) {
            $summary['errors'][] = 'eBay app credentials missing.';
            return $summary;
        }
        if (!$summary['oauth_ready']) {
            $summary['errors'][] = 'RuName not configured for production OAuth.';
        }
        if (!$summary['seller_connected']) {
            $summary['errors'][] = 'Seller account not connected.';
            return $summary;
        }

        $token = $this->ebayService->getSellerAccessToken();
        if (!$token) {
            $summary['errors'][] = 'Could not get seller token.';
            return $summary;
        }

        $policyCheck = $this->checkBusinessPolicies($token);
        $summary['policies_ok'] = !empty($policyCheck['ok']);
        if (!$summary['policies_ok'] && !empty($policyCheck['error'])) {
            $summary['errors'][] = $policyCheck['error'];
        }

        $locResult = $this->listInventoryLocations();
        $erpStores = $this->erpShipFromLocations(['id', 'name']);
        $summary['erp_locations'] = $erpStores->map(function ($loc) use ($ebay) {
            $map = $ebay['location_map'] ?? [];
            $key = $map[(string) $loc->id] ?? self::merchantKeyForLocationName($loc->name, (int) $loc->id);
            return ['id' => $loc->id, 'name' => $loc->name, 'merchant_location_key' => $key];
        })->values()->all();
        $summary['location_map'] = $ebay['location_map'] ?? [];

        if (!empty($locResult['success'])) {
            $summary['locations'] = $locResult['locations'];
            $ebayKeys = array_filter(array_map(function ($loc) {
                return $loc['merchantLocationKey'] ?? null;
            }, $locResult['locations']));
            $needed = count($summary['erp_locations']);
            $summary['location_ok'] = $needed === 0
                ? count($ebayKeys) > 0
                : count(array_intersect(
                    $ebayKeys,
                    array_column($summary['erp_locations'], 'merchant_location_key')
                )) >= $needed;
            if (!$summary['location_ok']) {
                $summary['errors'][] = 'eBay inventory locations missing for one or more stores (Pico, Hollywood).';
            }
        } else {
            $summary['errors'][] = $locResult['msg'] ?? 'Could not read inventory locations.';
        }

        if (!$summary['default_category_set']) {
            $summary['errors'][] = 'No default eBay category ID set in Business Settings (optional fallback when product category has no mapping).';
        }

        return $summary;
    }

    /**
     * @return array{ok: bool, error?: string, fulfillmentPolicyId?: string, paymentPolicyId?: string, returnPolicyId?: string}
     */
    protected function checkBusinessPolicies($token)
    {
        $policyMap = [
            'fulfillmentPolicyId' => ['path' => '/sell/account/v1/fulfillment_policy', 'list' => 'fulfillmentPolicies', 'id' => 'fulfillmentPolicyId', 'label' => 'shipping (fulfillment)'],
            'paymentPolicyId' => ['path' => '/sell/account/v1/payment_policy', 'list' => 'paymentPolicies', 'id' => 'paymentPolicyId', 'label' => 'payment'],
            'returnPolicyId' => ['path' => '/sell/account/v1/return_policy', 'list' => 'returnPolicies', 'id' => 'returnPolicyId', 'label' => 'return'],
        ];

        $out = ['ok' => true];
        foreach ($policyMap as $key => $cfg) {
            $res = $this->sellerApiRequest('GET', $cfg['path'] . '?marketplace_id=EBAY_US', $token);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                return ['ok' => false, 'error' => 'Could not read eBay ' . $cfg['label'] . ' policies: ' . $this->ebayErr($res)];
            }
            $id = $res['body'][$cfg['list']][0][$cfg['id']] ?? null;
            if (!$id) {
                return ['ok' => false, 'error' => 'No eBay ' . $cfg['label'] . ' business policy found. Create one in eBay Seller Hub → Business policies.'];
            }
            $out[$key] = $id;
        }
        return $out;
    }

    /**
     * Physical retail stores that ship eBay orders (Pico, Hollywood).
     * Virtual marketplace bins (Discogs/eBay Warehouse) are excluded.
     */
    protected function erpShipFromLocations(array $columns = ['*'])
    {
        return \App\BusinessLocation::where('business_id', $this->businessId)
            ->active()
            ->orderBy('name')
            ->get($columns)
            ->filter(function ($loc) {
                return self::isEbayShipFromLocation($loc->name);
            })
            ->values();
    }

    public static function isEbayShipFromLocation($name)
    {
        $normalized = strtolower(trim((string) $name));
        if ($normalized === '') {
            return false;
        }
        foreach (['discogs warehouse', 'ebay warehouse'] as $virtual) {
            if ($normalized === $virtual || strpos($normalized, $virtual) !== false) {
                return false;
            }
        }
        return true;
    }

    protected function addressFromBusinessLocation(\App\BusinessLocation $loc)
    {
        $country = $this->normalizeCountryCode($loc->country);
        $city = trim((string) ($loc->city ?? ''));
        $state = trim((string) ($loc->state ?? ''));
        $postal = trim((string) ($loc->zip_code ?? ''));
        $line1 = trim((string) ($loc->landmark ?: $loc->name ?: ''));

        $address = ['country' => $country];
        if ($line1 !== '') {
            $address['addressLine1'] = $line1;
        }
        if ($city !== '') {
            $address['city'] = $city;
        }
        if ($state !== '') {
            $address['stateOrProvince'] = $state;
        }
        if ($postal !== '') {
            $address['postalCode'] = $postal;
        }

        $hasPostalBundle = $postal !== '';
        $hasCityStateBundle = $city !== '' && $state !== '';
        if (!$hasPostalBundle && !$hasCityStateBundle) {
            return $this->fallbackAddress();
        }

        return $address;
    }

    protected function normalizeCountryCode($value)
    {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '') {
            return 'US';
        }
        if (strlen($raw) === 2 && ctype_alpha($raw)) {
            return $raw;
        }

        $map = [
            'USA' => 'US',
            'U.S.' => 'US',
            'U.S.A.' => 'US',
            'UNITED STATES' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'AMERICA' => 'US',
        ];

        return $map[$raw] ?? 'US';
    }

    protected function fallbackAddress()
    {
        return [
            'city' => 'Los Angeles',
            'stateOrProvince' => 'CA',
            'postalCode' => '90028',
            'country' => 'US',
        ];
    }

    protected function formatAddress(array $address)
    {
        $parts = array_filter([
            $address['city'] ?? null,
            $address['stateOrProvince'] ?? null,
            $address['postalCode'] ?? null,
            $address['country'] ?? null,
        ]);
        return implode(', ', $parts);
    }

    private function savePolicyCache(array $cache)
    {
        $business = \App\Business::find($this->businessId);
        if (!$business) {
            return;
        }
        $api = $business->api_settings ?? [];
        if (is_string($api)) {
            $api = json_decode($api, true) ?? [];
        }
        $api['ebay'] = array_merge($api['ebay'] ?? [], ['policy_cache' => $cache]);
        $business->api_settings = $api;
        $business->save();
    }

    private function sellerApiRequest($method, $path, $token, $jsonBody = null, array $extraHeaders = [])
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        $baseUrl = $this->ebayService->getApiBaseUrl();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['status' => 0, 'body' => ['errors' => [['message' => 'cURL: ' . $err]]]];
        }
        $decoded = json_decode((string) $body, true);
        return ['status' => $code, 'body' => is_array($decoded) ? $decoded : []];
    }

    private function ebayErrorId($res)
    {
        return $res['body']['errors'][0]['errorId'] ?? null;
    }

    private function ebayErr($res)
    {
        $status = $res['status'] ?? '?';
        $errors = $res['body']['errors'] ?? null;
        if (is_array($errors) && !empty($errors)) {
            $msgs = array_map(function ($e) {
                return $e['message'] ?? ($e['longMessage'] ?? '');
            }, $errors);
            return 'HTTP ' . $status . ' — ' . implode('; ', array_filter($msgs));
        }
        return 'HTTP ' . $status;
    }
}
