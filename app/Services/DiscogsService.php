<?php

namespace App\Services;

use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\Log;

class DiscogsService
{
    private $businessUtil;
    private $businessId;
    private $settings;
    private string $baseUrl = 'https://api.discogs.com/';
    private string $token;
    private string $databaseSearchUrl = 'database/search';
    private string $priceSuggestionUrl = 'marketplace/price_suggestions';

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
            
            $discogs = $this->settings['discogs'] ?? [];
            $this->token = $discogs['token'] ?? '';
        } else {
            $this->token = '';
        }
    }

    /**
     * Check if Discogs is configured with required token
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->token);
    }

    public function searchProductPrice($query, $artist = null, $title = null)
    {
        $release = $this->getRelease($query, $artist, $title);
        if (!empty($release['error'])) {
            return [
                'error' => true,
                'message' => $release['message'],
                'prices' => []
            ];
        }

        $releaseId = $release['data']->results[0]->id ?? 0;
        
        $sub_categories = $release['data']->results[0]->genre ?? '';
        $prices = [];

        if (!empty($releaseId)) {
            $priceSuggestions = $this->getPriceSuggesions($releaseId);

            if (!empty($priceSuggestions['error'])) {
                return [
                    'error' => true,
                    'message' => $priceSuggestions['message'],
                    'prices' => []
                ];
            }
        
            foreach ($priceSuggestions['data'] as $key => $price) {
                $prices[] = [
                    'condition' => $key,
                    'value' => number_format($price->value, 2),
                    'currency' => $price->currency,
                ];
            }
        }

        return [
            'error' => false,
            'message' => 'Success',
            'prices' => $prices,
            'sub_categories' => $sub_categories
        ];
    }


    public function getRelease($query, $artist = null, $title = null)
    {
        $url = $this->getReleaseApiUrl([
            'query' => $query,
            'artist' => $artist,
            'title' => $title
        ]);

        $response = $this->callApi($url);

        if (!empty($response['error'])) {
            return [
                'error' => true,
                'message' => $response['message']
            ];
        }

        return [
            'error' => false,
            'message' => 'Success',
            'data' => $response['data']
        ];
    }

    public function getPriceSuggesions($releaseId)
    {
        $url = $this->getPriceSuggestionApiUrl($releaseId);
        $response = $this->callApi($url);

        if (!empty($response['error'])) {
            return [
                'error' => true,
                'message' => $response['message']
            ];
        }

        return $response;
    }

    public function getReleaseApiUrl($filter)
    {
        $query = $filter['query'] ?? '';
        $artist = $filter['artist'] ?? '';
        $title = $filter['title'] ?? '';

        $url = $this->baseUrl . $this->databaseSearchUrl . '?token=' . $this->token.'&type=release';

        if (!empty($query)) {
            $url .= '&q=' . urlencode($query);
        }
        if (!empty($artist)) {
            $url .= '&artist=' . urlencode($artist);
        }
        if (!empty($title)) {
            $url .= '&title=' . urlencode($title);
        }

        return $url;
    }

    public function getPriceSuggestionApiUrl($releaseId)
    {
        $url = $this->baseUrl . $this->priceSuggestionUrl . '/' . $releaseId . '?token=' . $this->token;
        return $url;
    }

    public function callApi($url, $method = 'GET', $data = [])
    {
        try {
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $headers = [
                'User-Agent: NivessaPlaylist/1.0 +https://playlist.nivessa.com',
                'Accept: application/vnd.discogs.v2.plaintext+json',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
                'Cache-Control: no-cache'
            ];
            
            // Add token to URL for GET requests, or in Authorization header for POST
            if ($method === 'POST' && !empty($this->token)) {
                $headers[] = 'Authorization: Discogs token=' . $this->token;
            }
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                }
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL Error: ' . $error);
            }
    
            if ($httpCode !== 200 && $httpCode !== 201) {
                throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
            }

            if (empty($response)) {
                throw new \Exception('Empty Response');
            }

            return [
                'error' => false,
                'message' => 'Success',
                'data' => json_decode($response)
            ];
        } catch (\Exception $e) {
            return [
                "error" => true,
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Create a Discogs listing for a product
     *
     * @param array $productData
     * @return array
     */
    public function createListing($productData)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Discogs API token not configured. Please configure in Business Settings > Integrations.'
            ];
        }

        try {
            $url = $this->baseUrl . 'marketplace/listings';
            
            $listingData = [
                'release_id' => $productData['release_id'] ?? null,
                'price' => $productData['price'] ?? 0,
                'status' => $productData['status'] ?? 'For Sale',
                'sleeve_condition' => $productData['sleeve_condition'] ?? 'Mint (M)',
                'condition' => $productData['condition'] ?? 'Mint (M)',
                'comments' => $productData['comments'] ?? '',
                'allow_offers' => $productData['allow_offers'] ?? true,
                'external_id' => $productData['external_id'] ?? null
            ];

            $response = $this->callApi($url, 'POST', $listingData);

            if (!empty($response['error'])) {
                return [
                    'success' => false,
                    'msg' => $response['message']
                ];
            }

            return [
                'success' => true,
                'data' => $response['data'],
                'listing_id' => $response['data']->id ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Discogs Listing Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Discogs listing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update a Discogs listing
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
                'msg' => 'Discogs API token not configured.'
            ];
        }

        try {
            $url = $this->baseUrl . 'marketplace/listings/' . $listingId;
            
            $listingData = [];
            if (isset($productData['price'])) {
                $listingData['price'] = $productData['price'];
            }
            if (isset($productData['status'])) {
                $listingData['status'] = $productData['status'];
            }
            if (isset($productData['condition'])) {
                $listingData['condition'] = $productData['condition'];
            }
            if (isset($productData['comments'])) {
                $listingData['comments'] = $productData['comments'];
            }

            $response = $this->callApi($url, 'POST', $listingData);

            if (!empty($response['error'])) {
                return [
                    'success' => false,
                    'msg' => $response['message']
                ];
            }

            return [
                'success' => true,
                'data' => $response['data']
            ];
        } catch (\Exception $e) {
            Log::error('Discogs Update Listing Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Discogs update listing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Pull a page of marketplace orders from Discogs.
     *
     * Discogs paginates with ?page=N, default per_page=50 (max 100). We
     * accept date bounds so the caller can resync a window without paging
     * through the entire history. `created_after` / `created_before`
     * accept ISO-8601 timestamps.
     *
     * Returns the decoded response. Empty array on auth/transport error.
     */
    public function fetchOrders($created_after = null, $created_before = null, $page = 1, $per_page = 100, $status = null)
    {
        if (empty($this->token)) {
            return ['error' => 'Discogs API token not configured'];
        }

        $params = [
            'token' => $this->token,
            'page' => max(1, (int)$page),
            'per_page' => min(100, max(1, (int)$per_page)),
            'sort' => 'created',
            'sort_order' => 'desc',
        ];
        if (!empty($status)) {
            $params['status'] = $status;
        }
        if (!empty($created_after)) {
            $params['created_after'] = $created_after;
        }
        if (!empty($created_before)) {
            $params['created_before'] = $created_before;
        }

        $url = $this->baseUrl . 'marketplace/orders?' . http_build_query($params);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: NivessaPlaylist/1.0 +https://playlist.nivessa.com',
                'Accept: application/json',
                'Authorization: Discogs token=' . $this->token,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                return ['error' => 'cURL: ' . $err];
            }
            if ($code !== 200) {
                return ['error' => 'Discogs HTTP ' . $code, 'body' => $body];
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : ['error' => 'Invalid JSON from Discogs'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

}
