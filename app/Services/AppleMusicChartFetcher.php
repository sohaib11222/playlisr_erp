<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls Apple's public "Most Played Albums — US" RSS feed.
 *
 * Endpoint (no auth, JSON):
 *   https://rss.marketingtools.apple.com/api/v2/us/music/most-played/100/albums.json
 *
 * Apple updates this daily. We cache for 6 hours to avoid beating on it
 * when the ICA page opens repeatedly.
 */
class AppleMusicChartFetcher
{
    const ENDPOINT = 'https://rss.marketingtools.apple.com/api/v2/us/music/most-played/100/albums.json';

    /**
     * @return array<int, array{rank:int, artist:string, title:string, release_date:?string, genre:?string, apple_url:?string}>
     */
    public function fetchTop100(): array
    {
        try {
            $resp = Http::timeout(10)->get(self::ENDPOINT);
            if (!$resp->ok()) {
                Log::warning('AppleMusicChartFetcher: non-200', ['status' => $resp->status()]);
                return [];
            }
            $body = $resp->json();
        } catch (\Throwable $e) {
            Log::warning('AppleMusicChartFetcher: fetch failed', ['error' => $e->getMessage()]);
            return [];
        }

        $entries = $body['feed']['results'] ?? [];
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $i => $e) {
            if (!is_array($e)) {
                continue;
            }
            $artist = trim((string) ($e['artistName'] ?? ''));
            $title = trim((string) ($e['name'] ?? ''));
            if ($artist === '' || $title === '') {
                continue;
            }
            $out[] = [
                'rank' => $i + 1,
                'artist' => $artist,
                'title' => $title,
                'release_date' => $e['releaseDate'] ?? null,
                'genre' => isset($e['genres'][0]['name']) ? (string) $e['genres'][0]['name'] : null,
                'apple_url' => $e['url'] ?? null,
            ];
        }

        return $out;
    }
}
