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
        
        // Check for Ecommerce API Tokens (simpler method)
        if (!empty($clover['public_token']) && !empty($clover['private_token']) && !empty($clover['merchant_id'])) {
            return true;
        }
        
        // Check for OAuth credentials (alternative method)
        if (!empty($clover['app_id']) && !empty($clover['app_secret']) && !empty($clover['merchant_id'])) {
            return true;
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

        try {
            // Try to fetch a small number of customers to test connection
            $result = $this->getCustomers(1, 0);
            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'msg' => 'Connection test failed: ' . $e->getMessage()
            ];
        }
    }
}

