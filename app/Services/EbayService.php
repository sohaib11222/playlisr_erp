<?php

namespace App\Services;

use App\Category;
use App\Models\EbayOauthToken;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class EbayService
{
    private const SANDBOX_BASE_URL = 'https://api.sandbox.ebay.com';
    private const PRODUCTION_BASE_URL = 'https://api.ebay.com';
    
    private string $appId;
    private string $certId;
    private string $devId;
    private string $baseUrl;

    public function __construct()
    {
        $this->appId = '''';
        $this->certId = '''';
        $this->devId = '''';
        $this->baseUrl = self::PRODUCTION_BASE_URL; // Using sandbox for development
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
            $defaultOptions[CURLOPT_POSTFIELDS] = http_build_query($options['post']);
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
} 