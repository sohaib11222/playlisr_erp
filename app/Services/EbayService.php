<?php

namespace App\Services;

use App\Category;
use App\Models\EbayOauthToken;
use App\Utils\BusinessUtil;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EbayService
{
    private const SANDBOX_BASE_URL = 'https://api.sandbox.ebay.com';
    private const PRODUCTION_BASE_URL = 'https://api.ebay.com';
    
    private $businessUtil;
    private $businessId;
    private $settings;
    private string $appId;
    private string $certId;
    private string $devId;
    private string $baseUrl;

    public function __construct($businessId = null)
    {
        $this->businessUtil = new BusinessUtil();
        
        // Safely get business_id - try session first, fallback to auth user
        if ($businessId) {
            $this->businessId = $businessId;
        } else {
            try {
                $session = request()->getSession();
                if ($session && $session->has('user.business_id')) {
                    $this->businessId = $session->get('user.business_id');
                } else {
                    $this->businessId = auth()->user()->business_id ?? null;
                }
            } catch (\Exception $e) {
                $this->businessId = auth()->user()->business_id ?? null;
            }
        }
        
        if ($this->businessId) {
            $this->settings = $this->businessUtil->getApiSettings($this->businessId);
            
            $ebay = $this->settings['ebay'] ?? [];
            // Prefer Business Settings UI; optional .env fallbacks (never commit real values)
            $this->appId = $ebay['app_id'] ?? env('EBAY_APP_ID', '');
            $this->certId = $ebay['cert_id'] ?? env('EBAY_CERT_ID', '');
            $this->devId = $ebay['dev_id'] ?? env('EBAY_DEV_ID', '');
            
            $environment = $ebay['environment'] ?? 'sandbox';
            $this->baseUrl = $environment === 'production' 
                ? self::PRODUCTION_BASE_URL 
                : self::SANDBOX_BASE_URL;
        } else {
            $this->appId = env('EBAY_APP_ID', '');
            $this->certId = env('EBAY_CERT_ID', '');
            $this->devId = env('EBAY_DEV_ID', '');
            $this->baseUrl = self::SANDBOX_BASE_URL;
        }
    }

    /**
     * Check if eBay is configured with required credentials
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->appId) && 
               !empty($this->certId) && 
               !empty($this->devId);
    }

    private function makeRequest(string $url, array $options = []): array
    {
        $ch = curl_init();
        
        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (isset($options['headers'])) {
            $defaultOptions[CURLOPT_HTTPHEADER] = array_map(
                function($key, $value) {
                    return "$key: $value";
                },
                array_keys($options['headers']),
                array_values($options['headers'])
            );
        }

        if (isset($options['post'])) {
            $defaultOptions[CURLOPT_POST] = true;
            // If post data is already a string (JSON), use it directly
            if (is_string($options['post'])) {
                $defaultOptions[CURLOPT_POSTFIELDS] = $options['post'];
            } else {
                $defaultOptions[CURLOPT_POSTFIELDS] = http_build_query($options['post']);
            }
        }

        if (isset($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
            $defaultOptions[CURLOPT_URL] = $url;
        }

        curl_setopt_array($ch, $defaultOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL Error: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
        }

        return json_decode($response, true) ?? [];
    }

    private function getOAuthToken(): array
    {
        // Check if we have a valid token in the database
        $token = EbayOauthToken::where('expires_at', '>', Carbon::now())->first();

        if ($token) {
            return [
                'access_token' => $token->access_token,
                'token_type' => $token->token_type,
                'expires_in' => $token->expires_in
            ];
        }

        // If no valid token, get a new one
        $scopes = [
            'https://api.ebay.com/oauth/api_scope',
            // 'https://api.ebay.com/oauth/api_scope/buy.item.bulk'
        ];

        $response = $this->makeRequest($this->baseUrl . '/identity/v1/oauth2/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->appId . ':' . $this->certId)
            ],
            'post' => [
                'grant_type' => 'client_credentials',
                'scope' => implode(' ', $scopes)
            ]
        ]);

        if (isset($response['access_token'])) {
            // Store the token in database
            EbayOauthToken::create([
                'access_token' => $response['access_token'],
                'token_type' => $response['token_type'],
                'expires_in' => $response['expires_in'],
                'expires_at' => Carbon::now()->addSeconds($response['expires_in'] - 300) // Subtract 5 minutes for safety
            ]);

            return $response;
        }

        throw new \Exception('Failed to get eBay OAuth token: ' . json_encode($response));
    }

    public function getEbayCategoryIds($productCategoryId)
    {
        if (empty($productCategoryId)) {
            return [];
        }

        $ebayCategoryIds = [];
        $productCategory = Category::where('id', $productCategoryId)->first();

        if (empty($productCategory)) {
            return [];
        }

        $ebayCategoryIds = str_replace(' ', '', $productCategory->ebay_category_ids);
        $ebayCategoryIds = explode(',', $ebayCategoryIds);

        return [$ebayCategoryIds[0] ?? ''];
    }

    public function searchProducts(string $query, ?string $gtin = null, ?string $categoryIds = null, ?string $compatibilityFilter = null): array
    {
        $token = $this->getOAuthToken()['access_token'];

        return $this->makeRequest($this->baseUrl . '/buy/browse/v1/item_summary/search', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'query' => [
                'q' => $query,
                'limit' => 10,
                'category_ids' => $categoryIds,
                'compatibility_filter' => $compatibilityFilter,
                'gtin' => $gtin
            ]
        ]);
    }

    public function getProductPrice(string $query, ?string $gtin = null, ?string $categoryIds = null, ?string $compatibilityFilter = null): array
    {
        $results = $this->searchProducts($query, $gtin, $categoryIds, $compatibilityFilter);

        if (!isset($results['itemSummaries'])) {
            return [];
        }

        $items = [];
        foreach ($results['itemSummaries'] as $item) {
            $items[] = [
                'title' => $item['title'],
                'price' => $item['price'],
                'currency' => $item['price']['currency'],
                'value' => $item['price']['value'],
                'item_id' => $item['itemId'],
                'item_web_url' => $item['itemWebUrl']
            ];
        }

        return $items;
    }

    public function getPriceRecommendations(string $query, ?string $gtin = null, ?string $categoryIds = null, ?string $compatibilityFilter = null)
    {
        $itemSummaries = $this->getProductPrice($query, $gtin, $categoryIds, $compatibilityFilter);
        if (empty($itemSummaries)) {
            return [
                'highest' => 0,
                'lowest' => 0,
                'median' => 0,
                'average' => 0
            ];
        }

        $prices = array_map(function($item) {
            return $item['value'];
        }, $itemSummaries);

        sort($prices);
        $count = count($prices);
        
        // Calculate median
        if ($count % 2 == 0) {
            $median = ($prices[($count / 2) - 1] + $prices[$count / 2]) / 2;
        } else {
            $median = $prices[floor($count / 2)];
        }

        // Calculate average
        $average = array_sum($prices) / $count;

        // Get highest and lowest prices
        $highest = end($prices);
        $lowest = $prices[0];

        return [
            'highest' => number_format($highest, 2, '.', ''),
            'lowest' => number_format($lowest, 2, '.', ''),
            'median' => number_format($median, 2, '.', ''),
            'average' => number_format($average, 2, '.', '')
        ];
    }

    /**
     * Create an eBay listing for a product
     *
     * @param array $productData
     * @return array
     */
    public function createListing($productData)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'eBay API credentials not configured. Please configure in Business Settings > Integrations.'
            ];
        }

        try {
            $token = $this->getOAuthToken()['access_token'];
            
            // Build listing request
            $listingRequest = [
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => $productData['quantity'] ?? 1
                    ]
                ],
                'condition' => $productData['condition'] ?? 'NEW',
                'product' => [
                    'title' => $productData['title'] ?? '',
                    'description' => $productData['description'] ?? '',
                    'aspects' => $productData['aspects'] ?? []
                ],
                'pricingSummary' => [
                    'price' => [
                        'value' => $productData['price'] ?? 0,
                        'currency' => $productData['currency'] ?? 'USD'
                    ]
                ],
                'categoryId' => $productData['category_id'] ?? '',
                'format' => $productData['format'] ?? 'FIXED_PRICE',
                'listingDuration' => $productData['listing_duration'] ?? 'GTC'
            ];

            $response = $this->makeRequest($this->baseUrl . '/sell/inventory/v1/offer', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'post' => json_encode($listingRequest)
            ]);

            return [
                'success' => true,
                'data' => $response,
                'listing_id' => $response['offerId'] ?? null
            ];
        } catch (\Exception $e) {
            Log::error('eBay Listing Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'eBay listing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update an eBay listing
     *
     * @param string $listingId
     * @param array $productData
     * @return array
     */
    public function updateListing($listingId, $productData)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'eBay API credentials not configured.'
            ];
        }

        try {
            $token = $this->getOAuthToken()['access_token'];
            
            $listingRequest = [
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => $productData['quantity'] ?? 1
                    ]
                ],
                'pricingSummary' => [
                    'price' => [
                        'value' => $productData['price'] ?? 0,
                        'currency' => $productData['currency'] ?? 'USD'
                    ]
                ]
            ];

            $response = $this->makeRequest($this->baseUrl . '/sell/inventory/v1/offer/' . $listingId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'post' => json_encode($listingRequest)
            ]);

            return [
                'success' => true,
                'data' => $response
            ];
        } catch (\Exception $e) {
            Log::error('eBay Update Listing Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'eBay update listing error: ' . $e->getMessage()
            ];
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Seller OAuth — required for /sell/fulfillment/v1/order
    //
    // The catalog/browse APIs work with a client_credentials app token
    // (handled by getOAuthToken above). Pulling the seller's own orders
    // requires user consent: the seller authorises our app once via
    // eBay's OAuth flow, we get back a refresh_token (valid ~18 months),
    // and we mint short-lived access tokens from it on demand.
    //
    // Tokens are stored in business.api_settings.ebay_seller — same JSON
    // column the rest of the eBay/Discogs creds live in, so no migration.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build the eBay OAuth consent URL the seller is redirected to.
     * RuName must be registered in eBay Developer Console alongside the
     * app's redirect URL; we accept it from settings (ebay.ru_name).
     */
    public function getSellerAuthorizationUrl($redirect_uri)
    {
        $ebay = $this->settings['ebay'] ?? [];
        $environment = $ebay['environment'] ?? 'sandbox';
        $auth_base = $environment === 'production'
            ? 'https://auth.ebay.com/oauth2/authorize'
            : 'https://auth.sandbox.ebay.com/oauth2/authorize';

        $scopes = [
            'https://api.ebay.com/oauth/api_scope',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
        ];

        $params = [
            'client_id' => $this->appId,
            'response_type' => 'code',
            // eBay requires RuName in production; in sandbox it accepts a
            // raw URL but we still pass redirect_uri so the callback page
            // can verify state.
            'redirect_uri' => !empty($ebay['ru_name']) ? $ebay['ru_name'] : $redirect_uri,
            'scope' => implode(' ', $scopes),
            'state' => bin2hex(random_bytes(8)),
            'prompt' => 'login',
        ];
        return $auth_base . '?' . http_build_query($params);
    }

    /**
     * Exchange an OAuth authorisation code for a refresh+access token,
     * and stash both in business.api_settings.ebay_seller.
     *
     * Returns ['success' => true] on success or ['success' => false,
     * 'msg' => ...] on any failure.
     */
    public function exchangeAuthCode($code, $redirect_uri)
    {
        if (empty($this->appId) || empty($this->certId)) {
            return ['success' => false, 'msg' => 'eBay app_id / cert_id not configured.'];
        }
        $ebay = $this->settings['ebay'] ?? [];
        $redirect = !empty($ebay['ru_name']) ? $ebay['ru_name'] : $redirect_uri;

        try {
            $response = $this->makeRequest($this->baseUrl . '/identity/v1/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->appId . ':' . $this->certId),
                ],
                'post' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirect,
                ],
            ]);

            if (empty($response['refresh_token'])) {
                return ['success' => false, 'msg' => 'eBay did not return a refresh_token: ' . json_encode($response)];
            }

            $this->saveSellerTokens([
                'refresh_token' => $response['refresh_token'],
                'refresh_token_expires_at' => Carbon::now()->addSeconds((int)($response['refresh_token_expires_in'] ?? 47304000))->toDateTimeString(),
                'access_token' => $response['access_token'] ?? null,
                'access_token_expires_at' => isset($response['expires_in'])
                    ? Carbon::now()->addSeconds((int)$response['expires_in'] - 60)->toDateTimeString()
                    : null,
                'connected_at' => now()->toDateTimeString(),
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'msg' => 'eBay token exchange failed: ' . $e->getMessage()];
        }
    }

    /** True if a seller refresh_token is stored AND not expired. */
    public function isSellerConnected()
    {
        $sel = $this->settings['ebay_seller'] ?? [];
        if (empty($sel['refresh_token'])) return false;
        if (!empty($sel['refresh_token_expires_at']) &&
            strtotime($sel['refresh_token_expires_at']) < time()) {
            return false;
        }
        return true;
    }

    /**
     * Get a valid seller access_token, refreshing via refresh_token if
     * the cached one is missing or expired. Returns null on failure.
     */
    public function getSellerAccessToken()
    {
        $sel = $this->settings['ebay_seller'] ?? [];
        if (empty($sel['refresh_token'])) return null;

        // Use cached access_token if it has more than 60s of life left.
        if (!empty($sel['access_token']) && !empty($sel['access_token_expires_at']) &&
            strtotime($sel['access_token_expires_at']) > time() + 60) {
            return $sel['access_token'];
        }

        try {
            $scopes = ['https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'];
            $response = $this->makeRequest($this->baseUrl . '/identity/v1/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode($this->appId . ':' . $this->certId),
                ],
                'post' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $sel['refresh_token'],
                    'scope' => implode(' ', $scopes),
                ],
            ]);
            if (empty($response['access_token'])) return null;

            $sel['access_token'] = $response['access_token'];
            $sel['access_token_expires_at'] = Carbon::now()->addSeconds((int)$response['expires_in'] - 60)->toDateTimeString();
            $this->saveSellerTokens($sel);

            return $sel['access_token'];
        } catch (\Exception $e) {
            Log::warning('eBay refresh token mint failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Pull a single page of seller orders for a date range. Returns the
     * decoded response (with `orders`, `total`, `next`) or ['error' => …].
     */
    public function fetchOrders($created_after, $created_before, $offset = 0, $limit = 200)
    {
        $token = $this->getSellerAccessToken();
        if (!$token) {
            return ['error' => 'No seller token (not connected, or refresh failed).'];
        }

        $filter = sprintf('creationdate:[%s..%s]', $created_after, $created_before);
        $params = [
            'filter' => $filter,
            'limit' => max(1, min(200, (int)$limit)),
            'offset' => max(0, (int)$offset),
        ];

        $url = $this->baseUrl . '/sell/fulfillment/v1/order?' . http_build_query($params);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) return ['error' => 'cURL: ' . $err];
            if ($code !== 200) return ['error' => 'eBay HTTP ' . $code, 'body' => $body];
            $data = json_decode($body, true);
            return is_array($data) ? $data : ['error' => 'Invalid JSON from eBay'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Persist the ebay_seller block back into business.api_settings. */
    protected function saveSellerTokens(array $seller_data)
    {
        $business = \App\Business::find($this->businessId);
        if (!$business) return;
        $api = $business->api_settings ?? [];
        if (is_string($api)) $api = json_decode($api, true) ?? [];
        $api['ebay_seller'] = array_merge($api['ebay_seller'] ?? [], $seller_data);
        $business->api_settings = $api;
        $business->save();
        // Refresh in-memory copy so subsequent calls in this request see the new tokens.
        $this->settings['ebay_seller'] = $api['ebay_seller'];
    }

    /** Disconnect — wipe stored seller tokens. */
    public function disconnectSeller()
    {
        $business = \App\Business::find($this->businessId);
        if (!$business) return;
        $api = $business->api_settings ?? [];
        if (is_string($api)) $api = json_decode($api, true) ?? [];
        unset($api['ebay_seller']);
        $business->api_settings = $api;
        $business->save();
        $this->settings['ebay_seller'] = null;
    }
} 