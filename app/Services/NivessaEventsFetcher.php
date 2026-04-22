<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads upcoming events from the Nivessa website's public API (same
 * endpoint powering /events) and returns them as a clean array.
 *
 * Cached for 15 minutes to avoid hammering the upstream every time a
 * user opens the Inventory Check Assistant.
 */
class NivessaEventsFetcher
{
    /**
     * @return array<int, array{name:string, date:string, location:?string, artists:array<int,string>, raw:array}>
     */
    public function upcoming(int $lookaheadDays = 30): array
    {
        $url = config('inventory_check.events_api_url');
        if (empty($url)) {
            return [];
        }

        $cacheKey = 'nivessa_events_upcoming_' . md5($url) . '_' . $lookaheadDays;

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($url, $lookaheadDays) {
            try {
                $resp = Http::timeout(10)->get($url);
                if (!$resp->ok()) {
                    Log::warning('NivessaEventsFetcher: non-200 from events API', [
                        'status' => $resp->status(),
                        'url' => $url,
                    ]);
                    return [];
                }
                $payload = $resp->json();
            } catch (\Throwable $e) {
                Log::warning('NivessaEventsFetcher: fetch failed', ['error' => $e->getMessage()]);
                return [];
            }

            $rows = $payload['data'] ?? $payload['events'] ?? $payload ?? [];
            if (!is_array($rows)) {
                return [];
            }

            $today = Carbon::today();
            $cutoff = $today->copy()->addDays($lookaheadDays);
            $out = [];

            foreach ($rows as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $date = $e['date'] ?? $e['eventDate'] ?? $e['start_date'] ?? null;
                if (!$date) {
                    continue;
                }
                try {
                    $d = Carbon::parse($date);
                } catch (\Throwable $ignore) {
                    continue;
                }
                if ($d->lt($today) || $d->gt($cutoff)) {
                    continue;
                }

                $name = $e['name'] ?? $e['title'] ?? '';
                $description = $e['description'] ?? '';
                $location = $e['location']['name'] ?? $e['location'] ?? $e['venue'] ?? null;
                if (is_array($location)) {
                    $location = $location['name'] ?? null;
                }

                // Prefer structured artists if backend ever adds one; otherwise
                // fall back to extracting artist names from the title.
                $artists = [];
                if (!empty($e['artists']) && is_array($e['artists'])) {
                    foreach ($e['artists'] as $a) {
                        if (is_string($a)) {
                            $artists[] = trim($a);
                        } elseif (is_array($a) && !empty($a['name'])) {
                            $artists[] = trim($a['name']);
                        }
                    }
                }
                if (empty($artists)) {
                    $artists = $this->extractArtistsFromText($name . ' ' . $description);
                }

                $out[] = [
                    'name' => (string) $name,
                    'date' => $d->format('Y-m-d'),
                    'location' => $location ? (string) $location : null,
                    'artists' => array_values(array_unique(array_filter($artists))),
                    'raw' => $e,
                ];
            }

            usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

            return $out;
        });
    }

    /**
     * Cheap heuristic pass over an event title/description to pull
     * likely-artist strings. Not perfect — backend should eventually add
     * a structured `artists` field and this fallback goes away.
     */
    protected function extractArtistsFromText(string $text): array
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        if ($text === '') {
            return [];
        }

        $patterns = [
            '/listening party[:\-]?\s*(.+?)\s*[-–—]\s*/i',
            '/album release[:\-]?\s*(.+?)\s*[-–—]\s*/i',
            '/live at nivessa[:\-]?\s*(.+?)(?:\s*\(|$)/i',
            '/in[- ]store performance[:\-]?\s*(.+?)(?:\s*\(|$)/i',
            '/featuring\s+(.+?)(?:\s+and\s+|,|\.|$)/i',
            '/with\s+(.+?)(?:\s+and\s+|,|\.|$)/i',
        ];

        $found = [];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m)) {
                foreach ($m[1] as $hit) {
                    $hit = trim($hit, " \t.,-–—:");
                    if ($hit !== '' && mb_strlen($hit) < 80) {
                        $found[] = $hit;
                    }
                }
            }
        }

        return $found;
    }
}
