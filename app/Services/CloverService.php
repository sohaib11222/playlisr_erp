<?php

namespace App\Services;

use App\Business;
use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\Log;

class CloverService
{
    private $businessUtil;
    private $businessId;
    private $locationId;
    private $settings;

    /**
     * @param int|null $businessId  Business to load settings for. Defaults to the session user's business.
     * @param int|null $locationId  When set, the service prefers per-location Clover creds
     *                              (settings.clover.locations.<locationId>). Falls back to the
     *                              legacy top-level settings.clover.* if per-location isn't configured.
     */
    public function __construct($businessId = null, $locationId = null)
    {
        $this->businessUtil = new BusinessUtil();
        $this->businessId = $businessId ?? request()->session()->get('user.business_id');
        $this->locationId = $locationId;
        $this->settings = $this->businessUtil->getApiSettings($this->businessId);
    }

    /**
     * Set the location after construction — useful for workers / commands
     * that iterate over locations with one service instance.
     */
    public function forLocation($locationId)
    {
        $this->locationId = $locationId;
        return $this;
    }

    /**
     * Returns the effective Clover credentials array for the current location.
     * Order of precedence:
     *   1. settings.clover.locations.<locationId>  (per-location override)
     *   2. settings.clover                         (business-wide default / legacy)
     *
     * Keeps backward compatibility: if a business only has one Clover and no
     * per-location mapping, all existing callers keep working unchanged.
     */
    private function getClover()
    {
        $clover = $this->settings['clover'] ?? [];
        if ($this->locationId && !empty($clover['locations'][$this->locationId])) {
            // Merge so per-location entries fall back to top-level for fields
            // they don't override (e.g. environment).
            return array_merge($clover, $clover['locations'][$this->locationId]);
        }
        return $clover;
    }

    /**
     * Check if Clover is configured with required credentials
     * Supports both OAuth (App ID/Secret) and Ecommerce API Tokens (Public/Private)
     *
     * @return bool
     */
    public function isConfigured()
    {
        $clover = $this->getClover();

        if ($this->locationIsConfigured($clover)) {
            return true;
        }

        // No specific location scoped — also accept "any per-location entry
        // has creds". Keeps the settings-page Test Connection banner from
        // crying 'not configured' when the business has set up per-location
        // creds but left the top-level single-merchant fields empty.
        if (!$this->locationId) {
            $allLocs = $this->settings['clover']['locations'] ?? [];
            foreach ($allLocs as $locCreds) {
                if ($this->locationIsConfigured((array) $locCreds)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the list of scopes that have Clover creds configured for this business.
     *
     * Each element is either:
     *   - null  → top-level single-merchant creds are set
     *   - int   → per-location creds are set for that ERP location_id
     *
     * Used by the sync command so a multi-store business (Hollywood + Pico,
     * each with its own Clover account) pulls payments from every configured
     * merchant, not just the top-level one.
     */
    public function getConfiguredScopes(): array
    {
        $scopes = [];
        $topLevel = $this->settings['clover'] ?? [];
        if ($this->locationIsConfigured($topLevel)) {
            $scopes[] = null;
        }
        foreach ($this->settings['clover']['locations'] ?? [] as $locId => $locCreds) {
            if ($this->locationIsConfigured((array) $locCreds)) {
                $scopes[] = (int) $locId;
            }
        }
        return $scopes;
    }

    /** One merged creds array → is it enough to call Clover? */
    private function locationIsConfigured(array $clover)
    {
        // Private token alone is enough — Clover's Ecommerce API uses it as
        // the bearer. Older installs also used public_token; still accepted.
        if (!empty($clover['merchant_id'])) {
            if (!empty($clover['private_token'])) return true;
            if (!empty($clover['public_token']) && !empty($clover['private_token'])) return true;
            if (!empty($clover['app_id']) && !empty($clover['app_secret'])) return true;
            if (!empty($clover['access_token'])) return true;
        }
        return false;
    }

    /**
     * Get authentication token for Clover API
     * Supports both Ecommerce API Tokens and OAuth tokens
     *
     * @return string|null
     */
    private function getAccessToken()
    {
        $clover = $this->getClover();
        
        // Method 1: Use Ecommerce API Private Token (simpler, recommended)
        if (!empty($clover['private_token'])) {
            return $clover['private_token'];
        }
        
        // Method 2: Use stored OAuth access token
        if (!empty($clover['access_token'])) {
            return $clover['access_token'];
        }

        // Method 3: Try to get OAuth token (requires OAuth flow)
        if (!empty($clover['app_id']) && !empty($clover['app_secret'])) {
            try {
                $baseUrl = $clover['environment'] === 'production' 
                    ? 'https://api.clover.com' 
                    : 'https://sandbox.dev.clover.com';

                $ch = curl_init($baseUrl . '/oauth/token');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'client_id' => $clover['app_id'],
                        'client_secret' => $clover['app_secret'],
                        'code' => '', // This would be obtained from OAuth flow
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/x-www-form-urlencoded'
                    ]
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    return $data['access_token'] ?? null;
                }
            } catch (\Exception $e) {
                Log::error('Clover OAuth Error: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Send payment to Clover device
     *
     * @param float $amount
     * @param string $orderId
     * @return array
     */
    public function sendPayment($amount, $orderId)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured. Please configure in Business Settings > Integrations.'
            ];
        }

        try {
            $clover = $this->getClover();
            $baseUrl = $clover['environment'] === 'production' 
                ? 'https://api.clover.com' 
                : 'https://sandbox.dev.clover.com';

            $accessToken = $this->getAccessToken();
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'msg' => 'Failed to obtain Clover access token. Please check your credentials.'
                ];
            }

            // Create payment request
            $paymentData = [
                'amount' => (int)($amount * 100), // Clover uses cents
                'externalReferenceId' => $orderId,
                'tipAmount' => 0,
                'taxAmount' => 0
            ];

            // For Ecommerce API, use public token in header, private token for auth
            $publicToken = $clover['public_token'] ?? '';
            $authHeader = !empty($publicToken) 
                ? 'Bearer ' . $accessToken 
                : 'Bearer ' . $accessToken;
            
            $headers = [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json'
            ];
            
            // Add public token if using ecommerce API tokens
            if (!empty($publicToken)) {
                $headers[] = 'X-Clover-Public-Token: ' . $publicToken;
            }
            
            $ch = curl_init($baseUrl . '/v3/merchants/' . $clover['merchant_id'] . '/payments');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($paymentData),
                CURLOPT_HTTPHEADER => $headers
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL Error: ' . $error);
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $data,
                    'payment_id' => $data['id'] ?? null
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
            }
        } catch (\Exception $e) {
            Log::error('Clover Payment Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Clover payment error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get payment status from Clover
     *
     * @param string $paymentId
     * @return array
     */
    public function getPaymentStatus($paymentId)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured.'
            ];
        }

        try {
            $clover = $this->getClover();
            $baseUrl = $clover['environment'] === 'production' 
                ? 'https://api.clover.com' 
                : 'https://sandbox.dev.clover.com';

            $accessToken = $this->getAccessToken();
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'msg' => 'Failed to obtain Clover access token.'
                ];
            }

            $ch = curl_init($baseUrl . '/v3/merchants/' . $clover['merchant_id'] . '/payments/' . $paymentId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode);
            }
        } catch (\Exception $e) {
            Log::error('Clover Payment Status Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Error checking payment status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get base URL for Clover API
     *
     * @return string
     */
    private function getBaseUrl()
    {
        $clover = $this->getClover();
        return $clover['environment'] === 'production' 
            ? 'https://api.clover.com' 
            : 'https://sandbox.dev.clover.com';
    }

    /**
     * Get API headers with authentication
     *
     * @return array
     */
    private function getApiHeaders()
    {
        $clover = $this->getClover();
        $accessToken = $this->getAccessToken();
        $publicToken = $clover['public_token'] ?? '';
        
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];
        
        if (!empty($publicToken)) {
            $headers[] = 'X-Clover-Public-Token: ' . $publicToken;
        }
        
        return $headers;
    }

    /**
     * Pull payments from Clover for a given date range.
     *
     * Clover's /v3/merchants/{mid}/payments endpoint returns payments with
     * createdTime as a millisecond epoch. We filter by a date range (UTC)
     * using Clover's standard filter syntax:
     *     ?filter=createdTime>=<ms>&filter=createdTime<=<ms>
     * and expand employee so the employee name is on the response.
     *
     * Paginates via offset/limit — Clover caps individual responses at 1000
     * elements; this method walks the offsets until fewer than $limit come
     * back in a page, which is the idiomatic "no more pages" signal.
     *
     * @param  \Carbon\Carbon|string  $startDate  inclusive
     * @param  \Carbon\Carbon|string  $endDate    inclusive
     * @param  int                    $limit      per-page
     * @return array  [ 'success' => bool, 'payments' => [...], 'msg' => string ]
     */
    public function getPayments($startDate, $endDate, $limit = 1000)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'msg' => 'Clover API credentials not configured.', 'payments' => []];
        }

        try {
            $clover = $this->getClover();
            $merchantId = $clover['merchant_id'] ?? '';
            if (empty($merchantId)) {
                return ['success' => false, 'msg' => 'Clover merchant ID not configured.', 'payments' => []];
            }

            $start = \Carbon\Carbon::parse($startDate)->startOfDay();
            $end   = \Carbon\Carbon::parse($endDate)->endOfDay();
            $startMs = $start->timestamp * 1000;
            $endMs   = $end->timestamp * 1000;

            $baseUrl = $this->getBaseUrl();
            $allPayments = [];
            $offset = 0;

            do {
                $qs = http_build_query([
                    'expand' => 'employee',
                    'limit'  => $limit,
                    'offset' => $offset,
                ]);
                $filters = 'filter=' . rawurlencode('createdTime>=' . $startMs)
                         . '&filter=' . rawurlencode('createdTime<=' . $endMs);

                $url = $baseUrl . '/v3/merchants/' . $merchantId . '/payments?' . $qs . '&' . $filters;

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $this->getApiHeaders(),
                    CURLOPT_TIMEOUT        => 60,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    throw new \Exception('cURL error: ' . $error);
                }
                if ($httpCode !== 200) {
                    throw new \Exception('HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 400));
                }

                $data = json_decode($response, true);
                $elements = $data['elements'] ?? [];
                $allPayments = array_merge($allPayments, $elements);

                // Advance or stop
                $offset += $limit;
                $pageWasFull = count($elements) === $limit;
            } while ($pageWasFull);

            return [
                'success'  => true,
                'payments' => $allPayments,
                'count'    => count($allPayments),
            ];
        } catch (\Exception $e) {
            Log::error('Clover getPayments error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg'     => 'Error fetching payments: ' . $e->getMessage(),
                'payments' => [],
            ];
        }
    }

    /**
     * Get customers from Clover
     *
     * @param int $limit Limit number of customers to fetch
     * @param int $offset Offset for pagination
     * @return array
     */
    public function getCustomers($limit = 100, $offset = 0)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured.'
            ];
        }

        try {
            $clover = $this->getClover();
            $merchantId = $clover['merchant_id'] ?? '';
            
            if (empty($merchantId)) {
                return [
                    'success' => false,
                    'msg' => 'Clover merchant ID not configured.'
                ];
            }

            $baseUrl = $this->getBaseUrl();
            $url = $baseUrl . '/v3/merchants/' . $merchantId . '/customers';
            $url .= '?limit=' . $limit . '&offset=' . $offset;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->getApiHeaders()
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL Error: ' . $error);
            }

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'success' => true,
                    'customers' => $data['elements'] ?? [],
                    'total' => $data['count'] ?? count($data['elements'] ?? []),
                    'has_more' => !empty($data['href']) && strpos($data['href'], 'offset') !== false
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
            }
        } catch (\Exception $e) {
            Log::error('Clover Get Customers Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Error fetching customers: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get customer orders from Clover
     *
     * @param string $customerId Clover customer ID
     * @return array
     */
    public function getCustomerOrders($customerId)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured.'
            ];
        }

        try {
            $clover = $this->getClover();
            $merchantId = $clover['merchant_id'] ?? '';
            
            if (empty($merchantId)) {
                return [
                    'success' => false,
                    'msg' => 'Clover merchant ID not configured.'
                ];
            }

            $baseUrl = $this->getBaseUrl();
            $url = $baseUrl . '/v3/merchants/' . $merchantId . '/customers/' . $customerId . '/orders';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->getApiHeaders()
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'success' => true,
                    'orders' => $data['elements'] ?? []
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode);
            }
        } catch (\Exception $e) {
            Log::error('Clover Get Customer Orders Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Error fetching customer orders: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fetch a single Clover customer, optionally expanding related objects.
     * Used by the rewards sync which wants the metadata + address + orders
     * in one hit. 404s are surfaced as a soft miss so callers can skip the
     * contact without failing the whole run.
     */
    public function getCustomer($customerId, $expand = 'metadata,addresses,emailAddresses,phoneNumbers')
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'msg' => 'Clover API credentials not configured.'];
        }
        $clover = $this->getClover();
        $merchantId = $clover['merchant_id'] ?? '';
        if (empty($merchantId)) {
            return ['success' => false, 'msg' => 'Clover merchant ID not configured.'];
        }

        $url = $this->getBaseUrl() . '/v3/merchants/' . $merchantId . '/customers/' . rawurlencode($customerId);
        if (!empty($expand)) {
            $url .= '?expand=' . rawurlencode($expand);
        }

        $result = $this->curl($url);
        if (empty($result['success'])) {
            // If the curl helper reported an HTTP error, flag 404s so the
            // caller can mark the contact as missing in Clover.
            $notFound = is_string($result['msg'] ?? null) && strpos($result['msg'], 'HTTP 404') === 0;
            return array_merge($result, ['not_found' => $notFound]);
        }
        return ['success' => true, 'customer' => $result['data'] ?? []];
    }

    /**
     * Page through every Clover customer with metadata + address/contact
     * fields expanded. Used by the rewards sync to pull the full roster in
     * one shot instead of one HTTP call per contact.
     *
     * Caps at $safetyOffset to avoid runaway loops. Nivessa's real customer
     * count is well under that.
     */
    public function getAllCustomersExpanded($limit = 100, $safetyOffset = 50000)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'customers' => [], 'msg' => 'Clover API credentials not configured.'];
        }
        $clover = $this->getClover();
        $merchantId = $clover['merchant_id'] ?? '';
        if (empty($merchantId)) {
            return ['success' => false, 'customers' => [], 'msg' => 'Clover merchant ID not configured.'];
        }

        $all = [];
        $offset = 0;
        $expand = 'metadata,addresses,emailAddresses,phoneNumbers';
        $baseUrl = $this->getBaseUrl();

        while ($offset < $safetyOffset) {
            $url = $baseUrl . '/v3/merchants/' . $merchantId . '/customers'
                 . '?limit=' . $limit
                 . '&offset=' . $offset
                 . '&expand=' . rawurlencode($expand);

            $result = $this->curl($url);
            if (empty($result['success'])) {
                Log::error('Clover getAllCustomersExpanded error: ' . ($result['msg'] ?? 'unknown'));
                return ['success' => false, 'customers' => $all, 'msg' => $result['msg'] ?? 'unknown'];
            }

            $elements = $result['data']['elements'] ?? [];
            if (empty($elements)) {
                break;
            }
            $all = array_merge($all, $elements);
            if (count($elements) < $limit) {
                break;
            }
            $offset += $limit;
        }

        return ['success' => true, 'customers' => $all, 'count' => count($all)];
    }

    /**
     * Extract the best-available reward-points value from a Clover customer
     * payload (as returned by /customers or /customers/{id} with
     * expand=metadata). Returns null when no points field was present —
     * distinct from 0 — so callers can choose whether to overwrite.
     *
     * Different Clover loyalty / rewards apps write to different metadata
     * keys; we check the ones seen in the wild.
     */
    public function extractRewardPoints(array $cloverCustomer)
    {
        $meta = $cloverCustomer['metadata'] ?? [];
        if (!is_array($meta)) {
            return null;
        }
        $candidates = ['rewardPoints', 'loyaltyPoints', 'points', 'pointBalance', 'rewards_points', 'reward_points'];
        foreach ($candidates as $k) {
            if (isset($meta[$k]) && is_numeric($meta[$k])) {
                return (int) $meta[$k];
            }
        }
        return null;
    }

    /**
     * Reduce a Clover customer's order history to the two numbers the ERP's
     * loyalty card cares about: gross lifetime spend (dollars) and most-
     * recent order date. Order totals are in cents on the Clover side.
     */
    public function getCustomerLifetimeStats($customerId)
    {
        $result = $this->getCustomerOrders($customerId);
        if (empty($result['success'])) {
            return [
                'success' => false,
                'lifetime_purchases' => 0.0,
                'last_purchase_date' => null,
                'order_count' => 0,
                'msg' => $result['msg'] ?? 'Failed to fetch orders',
            ];
        }

        $orders = $result['orders'] ?? [];
        $totalCents = 0;
        $mostRecentMs = 0;
        foreach ($orders as $o) {
            if (!empty($o['deleted'])) {
                continue;
            }
            $totalCents += (int) ($o['total'] ?? 0);
            $created = (int) ($o['createdTime'] ?? 0);
            if ($created > $mostRecentMs) {
                $mostRecentMs = $created;
            }
        }

        $lastDate = null;
        if ($mostRecentMs > 0) {
            try {
                $lastDate = \Carbon\Carbon::createFromTimestampMs($mostRecentMs)
                    ->setTimezone(config('app.timezone'))
                    ->toDateString();
            } catch (\Exception $e) {
                $lastDate = null;
            }
        }

        return [
            'success' => true,
            'lifetime_purchases' => $totalCents / 100.0,
            'last_purchase_date' => $lastDate,
            'order_count' => count($orders),
        ];
    }

    /**
     * Card-slip summary for a shift window. Pulls payments from Clover in
     * the [$startTs, $endTs] range, filters to successful credit-card
     * tenders only, and returns the slip count + total cents.
     *
     * Used by the close-register modal to auto-fill 'Total card slips' so
     * cashiers don't have to eyeball-count swipes. Cash, gift-card, and
     * other non-card tenders are excluded — per Sarah 2026-04-21: "total
     * card slips should be all credit card transactions from clover not
     * cash".
     *
     * @param  int|string|\DateTimeInterface $startTs  Shift start (unix seconds, ISO string, or DateTime)
     * @param  int|string|\DateTimeInterface $endTs    Shift end (same formats)
     * @return array  ['success' => bool, 'card_slip_count' => int,
     *                 'card_total' => float (dollars), 'error' => ?string]
     */
    public function getCardSlipCountForShift($startTs, $endTs)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'card_slip_count' => 0,
                'card_total' => 0.0,
                'error' => 'Clover not configured for this location.',
            ];
        }

        $startMs = $this->toEpochMs($startTs);
        $endMs   = $this->toEpochMs($endTs);
        if ($startMs === null || $endMs === null || $endMs < $startMs) {
            return [
                'success' => false,
                'card_slip_count' => 0,
                'card_total' => 0.0,
                'error' => 'Invalid shift window.',
            ];
        }

        $clover = $this->getClover();
        $merchantId = $clover['merchant_id'] ?? '';
        $baseUrl = $this->getBaseUrl();

        $count = 0;
        $totalCents = 0;
        $offset = 0;
        $limit = 1000;   // Clover's max per page
        $safetyCap = 10; // up to 10k payments per shift — way more than a real day

        try {
            for ($page = 0; $page < $safetyCap; $page++) {
                $qs = http_build_query([
                    'filter' => 'createdTime>=' . $startMs,
                    'filter2' => 'createdTime<=' . $endMs,  // Clover accepts multiple filters
                    'limit' => $limit,
                    'offset' => $offset,
                    'expand' => 'tender',
                ]);
                // http_build_query won't give us two `filter=` params, so build manually:
                $qs = 'filter=' . rawurlencode('createdTime>=' . $startMs)
                    . '&filter=' . rawurlencode('createdTime<=' . $endMs)
                    . '&limit=' . $limit
                    . '&offset=' . $offset
                    . '&expand=tender';

                $url = $baseUrl . '/v3/merchants/' . $merchantId . '/payments?' . $qs;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $this->getApiHeaders(),
                    CURLOPT_TIMEOUT => 15,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    throw new \Exception('cURL error: ' . $curlError);
                }
                if ($httpCode < 200 || $httpCode >= 300) {
                    throw new \Exception('Clover HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
                }

                $data = json_decode($response, true) ?: [];
                $elements = $data['elements'] ?? [];
                if (empty($elements)) {
                    break;
                }

                foreach ($elements as $p) {
                    // Clover marks refunded/failed with non-zero result fields.
                    // Skip anything that isn't a successful charge.
                    if (!empty($p['voided']) || !empty($p['refunded']) || ($p['result'] ?? 'SUCCESS') !== 'SUCCESS') {
                        continue;
                    }
                    if (!$this->isCreditCardTender($p['tender'] ?? [])) {
                        continue;
                    }
                    $count++;
                    $totalCents += (int) ($p['amount'] ?? 0);
                }

                if (count($elements) < $limit) {
                    break;  // last page
                }
                $offset += $limit;
            }
        } catch (\Exception $e) {
            Log::error('Clover getCardSlipCountForShift error: ' . $e->getMessage());
            return [
                'success' => false,
                'card_slip_count' => 0,
                'card_total' => 0.0,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'card_slip_count' => $count,
            'card_total' => $totalCents / 100.0,
            'error' => null,
        ];
    }

    /**
     * Is a Clover tender object a credit/debit card swipe (vs cash, gift
     * card, house account, etc.)? Clover tags cards with labelKey values
     * like "com.clover.tender.credit_card" / "credit_debit" / "mag_stripe".
     */
    private function isCreditCardTender(array $tender)
    {
        $key   = strtolower((string) ($tender['labelKey'] ?? ''));
        $label = strtolower((string) ($tender['label'] ?? ''));
        if ($key !== '') {
            // Cash / gift card / store credit all have their own labelKeys;
            // anything that isn't one of those and has 'card' or 'credit'
            // or 'debit' in the key is treated as a card swipe.
            if (strpos($key, 'cash') !== false) return false;
            if (strpos($key, 'gift') !== false) return false;
            if (strpos($key, 'check') !== false) return false;
            if (strpos($key, 'card') !== false) return true;
            if (strpos($key, 'credit') !== false) return true;
            if (strpos($key, 'debit') !== false) return true;
        }
        // Fallback to the human label if labelKey was empty.
        if (strpos($label, 'card') !== false) return true;
        if (strpos($label, 'credit') !== false) return true;
        if (strpos($label, 'debit') !== false) return true;
        return false;
    }

    /** Coerce various timestamp inputs to Clover's createdTime format (epoch ms). */
    private function toEpochMs($t)
    {
        if ($t === null || $t === '') return null;
        if (is_int($t)) return $t < 10000000000 ? $t * 1000 : $t;  // seconds vs already-ms
        if ($t instanceof \DateTimeInterface) return $t->getTimestamp() * 1000;
        $parsed = strtotime((string) $t);
        return $parsed === false ? null : $parsed * 1000;
    }

    /* ---------------------------------------------------------------------
     * Items / Inventory (Clover ⇄ ERP)
     * ------------------------------------------------------------------- */

    /**
     * Pull items from Clover. If $modifiedSince is set (Carbon|string|int),
     * only items with modifiedTime>=that watermark come back — cheap
     * incremental sync. Walks all pages via offset/limit.
     */
    public function getItems($modifiedSince = null, $limit = 1000)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'msg' => 'Clover not configured.', 'items' => []];
        }
        try {
            $clover = $this->getClover();
            $merchantId = $clover['merchant_id'] ?? '';
            $baseUrl = $this->getBaseUrl();

            $all = [];
            $offset = 0;
            $sinceMs = $modifiedSince ? $this->toEpochMs($modifiedSince) : null;

            do {
                $qs = 'limit=' . $limit . '&offset=' . $offset . '&expand=itemStock,categories';
                if ($sinceMs) {
                    $qs .= '&filter=' . rawurlencode('modifiedTime>=' . $sinceMs);
                }
                $url = $baseUrl . '/v3/merchants/' . $merchantId . '/items?' . $qs;
                $resp = $this->curl($url, 'GET');
                if (!$resp['success']) return $resp + ['items' => []];

                $elements = $resp['data']['elements'] ?? [];
                $all = array_merge($all, $elements);
                $offset += $limit;
                $full = count($elements) === $limit;
            } while ($full);

            return ['success' => true, 'items' => $all, 'count' => count($all)];
        } catch (\Exception $e) {
            Log::error('Clover getItems error: ' . $e->getMessage());
            return ['success' => false, 'msg' => $e->getMessage(), 'items' => []];
        }
    }

    /** Create a Clover item from an ERP product. Returns the new clover_item_id on success. */
    public function createItem(array $item)
    {
        if (!$this->isConfigured()) return ['success' => false, 'msg' => 'Clover not configured.'];
        $clover = $this->getClover();
        $url = $this->getBaseUrl() . '/v3/merchants/' . $clover['merchant_id'] . '/items';
        $body = $this->itemPayload($item);
        $resp = $this->curl($url, 'POST', $body);
        if ($resp['success']) {
            $resp['clover_item_id'] = $resp['data']['id'] ?? null;
        }
        return $resp;
    }

    /** Patch an existing Clover item. */
    public function updateItem($cloverItemId, array $item)
    {
        if (!$this->isConfigured()) return ['success' => false, 'msg' => 'Clover not configured.'];
        $clover = $this->getClover();
        $url = $this->getBaseUrl() . '/v3/merchants/' . $clover['merchant_id'] . '/items/' . $cloverItemId;
        return $this->curl($url, 'POST', $this->itemPayload($item));  // Clover uses POST for updates
    }

    /**
     * Set inventory quantity on a Clover item. Clover tracks stock on a
     * separate /item_stocks/{itemId} endpoint — nullable because not every
     * item tracks stock (gift cards, services, etc.).
     */
    public function updateItemStock($cloverItemId, $quantity)
    {
        if (!$this->isConfigured()) return ['success' => false, 'msg' => 'Clover not configured.'];
        $clover = $this->getClover();
        $url = $this->getBaseUrl() . '/v3/merchants/' . $clover['merchant_id']
             . '/item_stocks/' . $cloverItemId;
        return $this->curl($url, 'POST', ['quantity' => (int) round($quantity)]);
    }

    /** Build Clover's item body from a normalized ERP product array. */
    private function itemPayload(array $item): array
    {
        $payload = [];
        if (isset($item['name']))        $payload['name']      = (string) $item['name'];
        if (isset($item['sku']))         $payload['sku']       = (string) $item['sku'];
        if (isset($item['code']))        $payload['code']      = (string) $item['code'];
        if (isset($item['price']))       $payload['price']     = (int) round(((float) $item['price']) * 100);
        if (isset($item['hidden']))      $payload['hidden']    = (bool) $item['hidden'];
        if (isset($item['priceType']))   $payload['priceType'] = (string) $item['priceType'];
        return $payload;
    }

    /* ---------------------------------------------------------------------
     * Orders (Clover → ERP)
     * ------------------------------------------------------------------- */

    /**
     * Pull orders in a date range with line items expanded. Same pagination
     * shape as getPayments(). state=locked filters to completed orders so we
     * don't churn on in-progress carts.
     */
    public function getOrders($startDate, $endDate, $limit = 1000)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'msg' => 'Clover not configured.', 'orders' => []];
        }
        try {
            $clover = $this->getClover();
            $merchantId = $clover['merchant_id'] ?? '';
            $baseUrl = $this->getBaseUrl();

            $startMs = $this->toEpochMs($startDate);
            $endMs   = $this->toEpochMs($endDate);

            $all = [];
            $offset = 0;
            do {
                $qs = 'limit=' . $limit . '&offset=' . $offset
                    . '&expand=' . rawurlencode('lineItems,payments,customers,employee')
                    . '&filter=' . rawurlencode('modifiedTime>=' . $startMs)
                    . '&filter=' . rawurlencode('modifiedTime<=' . $endMs)
                    . '&filter=' . rawurlencode('state=locked');
                $url = $baseUrl . '/v3/merchants/' . $merchantId . '/orders?' . $qs;
                $resp = $this->curl($url, 'GET');
                if (!$resp['success']) return $resp + ['orders' => []];

                $elements = $resp['data']['elements'] ?? [];
                $all = array_merge($all, $elements);
                $offset += $limit;
                $full = count($elements) === $limit;
            } while ($full);

            return ['success' => true, 'orders' => $all, 'count' => count($all)];
        } catch (\Exception $e) {
            Log::error('Clover getOrders error: ' . $e->getMessage());
            return ['success' => false, 'msg' => $e->getMessage(), 'orders' => []];
        }
    }

    /* ---------------------------------------------------------------------
     * Customers push (ERP → Clover)
     * ------------------------------------------------------------------- */

    public function createCustomer(array $contact)
    {
        if (!$this->isConfigured()) return ['success' => false, 'msg' => 'Clover not configured.'];
        $clover = $this->getClover();
        $url = $this->getBaseUrl() . '/v3/merchants/' . $clover['merchant_id'] . '/customers';
        $resp = $this->curl($url, 'POST', $this->customerPayload($contact));
        if ($resp['success']) {
            $resp['clover_customer_id'] = $resp['data']['id'] ?? null;
        }
        return $resp;
    }

    public function updateCustomer($cloverCustomerId, array $contact)
    {
        if (!$this->isConfigured()) return ['success' => false, 'msg' => 'Clover not configured.'];
        $clover = $this->getClover();
        $url = $this->getBaseUrl() . '/v3/merchants/' . $clover['merchant_id']
             . '/customers/' . $cloverCustomerId;
        return $this->curl($url, 'POST', $this->customerPayload($contact));
    }

    private function customerPayload(array $c): array
    {
        $payload = [
            'firstName' => (string) ($c['first_name'] ?? ''),
            'lastName'  => (string) ($c['last_name'] ?? ''),
        ];
        // Clover accepts arrays of emails / phones / addresses; send a single
        // primary one if we have it and let downstream merges do the rest.
        if (!empty($c['email'])) {
            $payload['emailAddresses'] = [['emailAddress' => $c['email']]];
        }
        if (!empty($c['mobile'])) {
            $payload['phoneNumbers'] = [['phoneNumber' => $c['mobile']]];
        }
        $addr = array_filter([
            'address1' => $c['address_line_1'] ?? null,
            'address2' => $c['address_line_2'] ?? null,
            'city'     => $c['city'] ?? null,
            'state'    => $c['state'] ?? null,
            'zip'      => $c['zip_code'] ?? null,
            'country'  => $c['country'] ?? null,
        ]);
        if (!empty($addr)) {
            $payload['addresses'] = [$addr];
        }
        return $payload;
    }

    /* ---------------------------------------------------------------------
     * Webhook verification
     * ------------------------------------------------------------------- */

    /**
     * Clover sends webhooks with an `X-Clover-Auth` header matching the
     * "Webhook signing secret" configured in the app's Clover dashboard.
     * We store the same secret in settings.clover.webhook_secret and
     * string-compare in constant time.
     *
     * Clover also fires a one-time `verificationCode` handshake when the
     * webhook URL is first saved. $handshakeCode returns non-null when the
     * caller should echo it back as the response body (Clover's "verify"
     * step); controller code treats that as the success path.
     */
    public function verifyWebhook(array $headers, string $rawBody, ?string &$handshakeCode = null): bool
    {
        $handshakeCode = null;

        // Handshake: Clover posts a JSON body { "verificationCode": "..." }
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) && !empty($decoded['verificationCode'])) {
            $handshakeCode = (string) $decoded['verificationCode'];
            return true;
        }

        $clover = $this->getClover();
        $expected = (string) ($clover['webhook_secret'] ?? '');
        if ($expected === '') {
            // No secret configured → accept but log loudly so Sarah sees it
            // in settings. Better than 403-ing real events during onboarding.
            Log::warning('Clover webhook received but no webhook_secret configured — accepting unauthenticated.');
            return true;
        }

        $got = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Clover-Auth') === 0) {
                $got = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                break;
            }
        }
        return hash_equals($expected, $got);
    }

    /* ---------------------------------------------------------------------
     * Shared cURL helper. Centralizes headers, timeout, and error shape so
     * we're not repeating the curl dance in every method above.
     * ------------------------------------------------------------------- */

    private function curl(string $url, string $method = 'GET', $body = null): array
    {
        try {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $this->getApiHeaders(),
                CURLOPT_TIMEOUT        => 60,
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : (string) $body;
            } elseif ($method !== 'GET') {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : (string) $body;
                }
            }
            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'msg' => 'cURL: ' . $error];
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                return ['success' => false, 'msg' => 'HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 400)];
            }
            return ['success' => true, 'data' => json_decode((string) $response, true)];
        } catch (\Exception $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Test Clover API connection
     *
     * @return array
     */
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured.'
            ];
        }

        // If a specific location is scoped, test just that one.
        if ($this->locationId) {
            return $this->probeConfiguredLocation($this->locationId);
        }

        // Otherwise: if top-level creds are set, test those; if only
        // per-location creds are set, probe each location and report all.
        $topLevel = $this->settings['clover'] ?? [];
        if ($this->locationIsConfigured($topLevel)) {
            try {
                return $this->getCustomers(1, 0);
            } catch (\Exception $e) {
                return ['success' => false, 'msg' => 'Connection test failed: ' . $e->getMessage()];
            }
        }

        $allLocs = $this->settings['clover']['locations'] ?? [];
        if (empty($allLocs)) {
            return ['success' => false, 'msg' => 'Clover API credentials not configured.'];
        }

        $results = [];
        $okCount = 0;
        foreach ($allLocs as $locId => $locCreds) {
            if (!$this->locationIsConfigured((array) $locCreds)) continue;
            $probe = $this->probeConfiguredLocation($locId);
            $locName = optional(\App\BusinessLocation::find($locId))->name ?? ('Location #' . $locId);
            if (!empty($probe['success'])) {
                $okCount++;
                $results[] = '✓ ' . $locName;
            } else {
                $results[] = '✗ ' . $locName . ' — ' . ($probe['msg'] ?? 'unknown error');
            }
        }
        return [
            'success' => $okCount > 0 && $okCount === count($results),
            'msg' => empty($results)
                ? 'No per-location Clover credentials found.'
                : implode(' · ', $results),
        ];
    }

    /** Scope to a location, try a cheap read, restore prior scope. */
    private function probeConfiguredLocation($locationId)
    {
        $prev = $this->locationId;
        $this->locationId = $locationId;
        try {
            $result = $this->getCustomers(1, 0);
        } catch (\Exception $e) {
            $result = ['success' => false, 'msg' => 'Connection failed: ' . $e->getMessage()];
        }
        $this->locationId = $prev;
        return $result;
    }
}

