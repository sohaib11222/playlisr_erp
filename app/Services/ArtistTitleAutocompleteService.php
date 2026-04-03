<?php

namespace App\Services;

use App\Product;

class ArtistTitleAutocompleteService
{
    public function search($businessId, $type, $query, $limit = 20)
    {
        $type = $type === 'title' ? 'title' : 'artist';
        $query = trim((string) $query);
        $limit = max(1, min((int) $limit, 50));

        if ($query === '') {
            return [];
        }

        $internal = $this->searchInternal($businessId, $type, $query, $limit);
        $merged = $internal;

        if ($type === 'artist' && count($internal) < $limit) {
            $external = $this->searchNivessaArtists($query, $limit);
            $merged = $this->mergeUnique($internal, $external, $limit);
        }

        return $merged;
    }

    public function exportDistinctValues($businessId)
    {
        $artists = Product::where('business_id', $businessId)
            ->whereNotNull('artist')
            ->where('artist', '!=', '')
            ->distinct()
            ->orderBy('artist')
            ->pluck('artist')
            ->map(function ($v) { return trim((string) $v); })
            ->filter()
            ->values()
            ->all();

        $titles = Product::where('business_id', $businessId)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->map(function ($v) { return trim((string) $v); })
            ->filter()
            ->values()
            ->all();

        return compact('artists', 'titles');
    }

    protected function searchInternal($businessId, $type, $query, $limit)
    {
        $column = $type === 'title' ? 'name' : 'artist';
        $startsWith = $query . '%';
        $contains = '%' . $query . '%';

        $starts = Product::where('business_id', $businessId)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'like', $startsWith)
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->toArray();

        if (count($starts) >= $limit) {
            return array_values(array_filter(array_map('trim', $starts)));
        }

        $remaining = $limit - count($starts);
        $containsRows = Product::where('business_id', $businessId)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'like', $contains)
            ->distinct()
            ->orderBy($column)
            ->limit($remaining * 2)
            ->pluck($column)
            ->toArray();

        return $this->mergeUnique(
            array_values(array_filter(array_map('trim', $starts))),
            array_values(array_filter(array_map('trim', $containsRows))),
            $limit
        );
    }

    protected function searchNivessaArtists($query, $limit)
    {
        try {
            $url = 'https://nivessa.com/api/v1/artists?page=1&limit=' . (int) $limit
                . '&search=' . urlencode($query) . '&sort=artist_name';
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 3,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                return [];
            }

            $payload = json_decode($raw, true);
            $rows = [];

            if (is_array($payload)) {
                if (isset($payload['data']) && is_array($payload['data'])) {
                    $rows = $payload['data'];
                } elseif (isset($payload['artists']) && is_array($payload['artists'])) {
                    $rows = $payload['artists'];
                } elseif (array_values($payload) === $payload) {
                    $rows = $payload;
                }
            }

            $names = [];
            foreach ($rows as $row) {
                $name = isset($row['artist_name']) ? trim((string) $row['artist_name']) : '';
                if ($name !== '') {
                    $names[] = $name;
                }
            }

            return array_values(array_unique($names));
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function mergeUnique(array $primary, array $secondary, $limit)
    {
        $out = [];
        $seen = [];

        foreach ([$primary, $secondary] as $source) {
            foreach ($source as $item) {
                $value = trim((string) $item);
                if ($value === '') {
                    continue;
                }
                $key = mb_strtolower($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $value;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }
}

