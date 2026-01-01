<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DiscogsService
{
    private string $baseUrl = 'https://api.discogs.com/';

    private string $token = 'tsaPkOArKRJoYaEdiUgRtlVriVMtAnswAdTaNknw';

    private string $databaseSearchUrl = 'database/search';

    private string $priceSuggestionUrl = 'marketplace/price_suggestions';

    public function __construct()
    {}

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

    public function callApi($url)
    {
        try {
            $ch = curl_init();
            
            // Set basic cURL options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: NivessaPlaylist/1.0 +https://playlist.nivessa.com',
                'Accept: application/vnd.discogs.v2.plaintext+json',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
                'Cache-Control: no-cache'
            ]);

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
}
