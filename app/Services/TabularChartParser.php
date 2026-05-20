<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses tabular chart files (xlsx / csv) where the layout is "one row
 * per chart entry with named columns". Used by the browser-side import
 * for both Luminate / Street Pulse exports and any tabular file Sarah or
 * Clyde drags in. Header detection is forgiving — it accepts column
 * names like "Artist" / "ARTIST NAME" / "Performer" interchangeably.
 *
 * Columns we look for:
 *   - rank        : "rank" / "chart position" / "#"
 *   - artist      : "artist" / "performer"
 *   - title       : "title" / "album" / "name"
 *   - format      : "format" / "configuration"
 *   - release     : "release date"
 *
 * If the file has multiple sheets, every sheet that has Artist+Title
 * headers is concatenated. (Luminate's "Top 200 Physical Album Chart"
 * and "Top 200 Vinyl Album Chart" tabs both apply.)
 */
class TabularChartParser
{
    /**
     * @return array<int, array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}>
     */
    public function parseFile(string $path, string $originalName = ''): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'tsv' || $ext === 'txt') {
            return $this->parseCsv($path, $ext === 'tsv' ? "\t" : null);
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            Log::warning('TabularChartParser: load failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, true, false);
            // Sheet name often encodes the format on Luminate / Street Pulse
            // weekly files ("Top 200 Overall Vinyl Albums", "Indie Stores
            // Top 200 CD Albums"). Pass that as a default since these
            // sheets don't have a Format column. Sarah 2026-05-20.
            $defaultFormat = $this->formatFromSheetName($sheetName);
            $parsed = $this->rowsToChartEntries($rows, $defaultFormat);
            foreach ($parsed as $r) {
                $out[] = $r;
            }
        }

        // Dedupe across tabs by (artist, title, format) — the Indie file
        // has 5 overlapping tabs (Overall Vinyl + Indie Vinyl + Indie
        // Physical, etc.); we don't want the same title appearing 3×.
        return $this->dedupeRows($out);
    }

    protected function formatFromSheetName(string $sheetName): ?string
    {
        $lower = mb_strtolower($sheetName);
        if (mb_strpos($lower, 'vinyl') !== false) return 'LP';
        if (mb_strpos($lower, 'cd') !== false) return 'CD';
        if (mb_strpos($lower, 'cassette') !== false) return 'Cassette';
        return null;
    }

    /**
     * @param  array<int, array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}>  $rows
     * @return array<int, array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}>
     */
    protected function dedupeRows(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(($r['artist'] ?? '') . '|' . ($r['title'] ?? '') . '|' . ($r['format'] ?? ''));
            if (isset($seen[$key])) {
                // Keep the smaller (= higher) rank if both have one
                $existing = $out[$seen[$key]];
                if (
                    ($r['rank'] ?? null) !== null
                    && ($existing['rank'] ?? null) !== null
                    && (int) $r['rank'] < (int) $existing['rank']
                ) {
                    $out[$seen[$key]] = $r;
                }
                continue;
            }
            $seen[$key] = count($out);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @return array<int, array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}>
     */
    public function parseCsv(string $path, ?string $delim = null): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            return [];
        }

        if ($delim === null) {
            // Sniff: tab beats comma beats semicolon
            $sample = fread($fh, 4096);
            rewind($fh);
            if (substr_count($sample, "\t") > substr_count($sample, ',')) {
                $delim = "\t";
            } elseif (substr_count($sample, ';') > substr_count($sample, ',')) {
                $delim = ';';
            } else {
                $delim = ',';
            }
        }

        $rows = [];
        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        return $this->rowsToChartEntries($rows);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  string|null  $defaultFormat  fallback when the sheet has no Format column
     * @return array<int, array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}>
     */
    public function rowsToChartEntries(array $rows, ?string $defaultFormat = null): array
    {
        $headerIdx = $this->findHeaderRow($rows);
        if ($headerIdx === null) {
            return [];
        }

        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[$headerIdx]);

        $col = function (array $needles) use ($headers) {
            foreach ($headers as $i => $h) {
                foreach ($needles as $n) {
                    if ($h === $n || (mb_strlen($n) >= 3 && mb_strpos($h, $n) !== false)) {
                        return $i;
                    }
                }
            }
            return null;
        };

        $cRank = $col(['rank', 'chart position', '#', 'position']);
        $cArtist = $col(['artist name', 'artist', 'performer']);
        $cTitle = $col(['title', 'album', 'name']);
        $cFormat = $col(['format', 'configuration']);
        $cRelease = $col(['release date', 'release', 'street date']);

        if ($cArtist === null || $cTitle === null) {
            return [];
        }

        $out = [];
        $autoRank = 0;
        $count = count($rows);
        for ($r = $headerIdx + 1; $r < $count; $r++) {
            $row = $rows[$r];
            if (!is_array($row)) {
                continue;
            }
            $artist = trim((string) ($row[$cArtist] ?? ''));
            $title = trim((string) ($row[$cTitle] ?? ''));
            if ($artist === '' && $title === '') {
                continue;
            }
            // Skip the obvious "© / copyright" footers Luminate appends
            if ($cArtist !== null && stripos($artist, 'copyright') !== false) {
                continue;
            }

            $rank = null;
            if ($cRank !== null && isset($row[$cRank]) && is_numeric($row[$cRank])) {
                $rank = (int) $row[$cRank];
            } else {
                $autoRank++;
                $rank = $autoRank;
            }

            $isNew = false;
            if ($cRelease !== null) {
                $rel = $row[$cRelease] ?? null;
                if ($rel !== null && $rel !== '') {
                    $relTs = is_numeric($rel)
                        ? $this->excelSerialToTs((float) $rel)
                        : strtotime((string) $rel);
                    if ($relTs && $relTs > strtotime('-60 days')) {
                        $isNew = true;
                    }
                }
            }

            $fmt = $cFormat !== null ? trim((string) ($row[$cFormat] ?? '')) ?: null : null;
            if ($fmt === null && $defaultFormat !== null) {
                $fmt = $defaultFormat;
            }
            $out[] = [
                'rank' => $rank,
                'artist' => $artist !== '' ? $artist : null,
                'title' => $title !== '' ? $title : null,
                'format' => $fmt,
                'is_new_release' => $isNew,
            ];
        }

        return $out;
    }

    /**
     * Find the first row that looks like a header. We accept the first row
     * containing both "artist" / "performer" and "title" / "album" tokens.
     * Some Luminate exports have a banner row above headers.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function findHeaderRow(array $rows): ?int
    {
        $limit = min(count($rows), 10);
        for ($r = 0; $r < $limit; $r++) {
            $row = $rows[$r] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $joined = mb_strtolower(implode('|', array_map(fn ($c) => (string) $c, $row)));
            $hasArtist = mb_strpos($joined, 'artist') !== false || mb_strpos($joined, 'performer') !== false;
            $hasTitle = mb_strpos($joined, 'title') !== false || mb_strpos($joined, 'album') !== false;
            if ($hasArtist && $hasTitle) {
                return $r;
            }
        }
        return null;
    }

    protected function excelSerialToTs(float $serial): ?int
    {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($serial);
            return $dt->getTimestamp();
        } catch (\Throwable $ignore) {
            return null;
        }
    }
}
