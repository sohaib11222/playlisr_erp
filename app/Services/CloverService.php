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
        $clover = $this->settings['clover'] ?? [];
        
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
        $clover = $this->settings['clover'] ?? [];
        
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
            $clover = $this->settings['clover'] ?? [];
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
            $clover = $this->settings['clover'] ?? [];
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
        $clover = $this->settings['clover'] ?? [];
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
        $clover = $this->settings['clover'] ?? [];
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
            $clover = $this->settings['clover'] ?? [];
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
            $clover = $this->settings['clover'] ?? [];
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
            $clover = $this->settings['clover'] ?? [];
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

