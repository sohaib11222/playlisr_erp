<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls Apple's public "Most Played Albums — US" RSS feed.
 *
 * Apple has shuffled this feed's hostname twice:
 *   - rss.itunes.apple.com           (retired Aug 2024)
 *   - rss.marketingtools.apple.com   (alias, intermittent)
 *   - rss.applemarketingtools.com    (current canonical, per developer.apple.com)
 *
 * We try the canonical host first and fall back, so the "feed not found"
 * symptom Sarah saw doesn't recur if Apple flips the alias again.
 *
 * Apple updates this daily. Caller is expected to throttle (the ICA cron
 * runs once a day; the manual button is rare).
 */
class AppleMusicChartFetcher
{
    const ENDPOINTS = [
        'https://rss.applemarketingtools.com/api/v2/us/music/most-played/100/albums.json',
        'https://rss.marketingtools.apple.com/api/v2/us/music/most-played/100/albums.json',
    ];

    /** @var string|null populated on the last fetch attempt for diagnostics */
    public $lastError;

    /** @var string|null populated on the last successful fetch */
    public $lastEndpoint;

    /**
     * @return array<int, array{rank:int, artist:string, title:string, release_date:?string, genre:?string, apple_url:?string}>
     */
    public function fetchTop100(): array
    {
        $this->lastError = null;
        $this->lastEndpoint = null;

        $body = null;
        $errors = [];
        foreach (self::ENDPOINTS as $url) {
            try {
                $resp = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'NivessaERP/1.0 (+playlist.nivessa.com)'])
                    ->get($url);
                if ($resp->ok()) {
                    $body = $resp->json();
                    $this->lastEndpoint = $url;
                    break;
                }
                $errors[] = $url . ' → HTTP ' . $resp->status();
            } catch (\Throwable $e) {
                $errors[] = $url . ' → ' . $e->getMessage();
            }
        }

        if ($body === null) {
            $this->lastError = implode(' | ', $errors) ?: 'no endpoints responded';
            Log::warning('AppleMusicChartFetcher: all endpoints failed', ['errors' => $errors]);
            return [];
        }

        $entries = $body['feed']['results'] ?? [];
        if (!is_array($entries)) {
            $this->lastError = 'unexpected payload shape (no feed.results array)';
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

        if (empty($out)) {
            $this->lastError = 'feed responded but contained 0 album entries';
        }

        return $out;
    }
}
