<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses UMe's weekly "Back-in-Stock + Active LPs and CDs" xlsx into
 * structured chart rows + event/anniversary rows.
 *
 * The xlsx has a stable 10-tab layout:
 *   Vinyl on-hand              — full catalog in stock at UMe
 *   Vinyl Top 200              — ranked 1..200 for indie vinyl sales
 *   This Week Vinyl-Deliveries — new releases shipping this week
 *   CD on-hand                 — full CD catalog
 *   CD Top 200
 *   This Week CD-Deliveries
 *   Holiday                    — seasonal (has extra Config column)
 *   Cassette on-hand
 *   Index                      — genre code legend
 *   Key Anniversaries + Birthdays — upcoming artist moments
 *
 * Header row (row 1) on all music tabs:
 *   UPC, MATERIAL #, ARTIST NAME, Title, GENRE, LABEL, RELEASE DATE,
 *   PRICE CODE, BOX LOT, UNITS PER SET, ON HAND, explicit
 *
 * We only pull what matters for reorder decisions: Top 200 rankings
 * (→ chart_picks) and this-week deliveries (→ chart_picks with
 * is_new_release=true). On-hand tabs stay in the xlsx for Clyde to
 * reference if he wants to look up specific UPCs.
 *
 * Anniversaries feed into the "events_upcoming" bucket indirectly via a
 * separate method; caller decides what to do with them.
 */
class UniversalChartParser
{
    /**
     * @return array{
     *     top_200_vinyl: array<int, array>,
     *     top_200_cd: array<int, array>,
     *     deliveries_vinyl: array<int, array>,
     *     deliveries_cd: array<int, array>,
     *     anniversaries: array<int, array>,
     *     meta: array<string, mixed>
     * }
     */
    public function parse(string $xlsxPath): array
    {
        if (!is_readable($xlsxPath)) {
            Log::warning('UniversalChartParser: file not readable: ' . $xlsxPath);
            return $this->emptyResult('file_not_readable');
        }

        try {
            $spreadsheet = IOFactory::load($xlsxPath);
        } catch (\Throwable $e) {
            Log::warning('UniversalChartParser: IOFactory::load failed: ' . $e->getMessage());
            return $this->emptyResult('load_failed');
        }

        $out = [
            'top_200_vinyl' => [],
            'top_200_cd' => [],
            'deliveries_vinyl' => [],
            'deliveries_cd' => [],
            'anniversaries' => [],
            'meta' => [
                'sheets' => [],
                'source_file' => basename($xlsxPath),
            ],
        ];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $out['meta']['sheets'][] = $sheetName;
            $lower = mb_strtolower($sheetName);

            if ((mb_strpos($lower, 'vinyl top 200') !== false)) {
                $out['top_200_vinyl'] = $this->parseMusicRows($spreadsheet->getSheetByName($sheetName), 'LP', true);
            } elseif ((mb_strpos($lower, 'cd top 200') !== false)) {
                $out['top_200_cd'] = $this->parseMusicRows($spreadsheet->getSheetByName($sheetName), 'CD', true);
            } elseif ((mb_strpos($lower, 'vinyl-deliveries') !== false) || (mb_strpos($lower, 'vinyl-delivery') !== false)) {
                $out['deliveries_vinyl'] = $this->parseMusicRows($spreadsheet->getSheetByName($sheetName), 'LP', false, true);
            } elseif ((mb_strpos($lower, 'cd-deliveries') !== false) || (mb_strpos($lower, 'cd-delivery') !== false)) {
                $out['deliveries_cd'] = $this->parseMusicRows($spreadsheet->getSheetByName($sheetName), 'CD', false, true);
            } elseif ((mb_strpos($lower, 'anniversar') !== false) || (mb_strpos($lower, 'birthday') !== false)) {
                $out['anniversaries'] = $this->parseAnniversaries($spreadsheet->getSheetByName($sheetName));
            }
        }

        return $out;
    }

    /**
     * @param  bool  $ranked  true when the tab has meaningful 1..N rank ordering
     * @param  bool  $isNewRelease  true when the tab represents "this week's deliveries"
     */
    protected function parseMusicRows($sheet, string $formatLabel, bool $ranked, bool $isNewRelease = false): array
    {
        if (!$sheet) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, false);
        if (empty($rows)) {
            return [];
        }

        // Row 0 is the header; find column indexes by name to be resilient to re-ordering
        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $col = function (string $needle) use ($headers) {
            foreach ($headers as $i => $h) {
                if ($h === $needle || (mb_strpos($h, $needle) !== false)) {
                    return $i;
                }
            }
            return null;
        };

        $cUpc = $col('upc');
        $cMat = $col('material');
        $cArtist = $col('artist');
        $cTitle = $col('title');
        $cGenre = $col('genre');
        $cLabel = $col('label');
        $cRelease = $col('release date');
        $cOnHand = $col('on hand');

        $out = [];
        $rank = 0;
        $count = count($rows);
        for ($r = 1; $r < $count; $r++) {
            $row = $rows[$r];
            $artist = trim((string) ($row[$cArtist] ?? ''));
            $title = trim((string) ($row[$cTitle] ?? ''));
            if ($artist === '' && $title === '') {
                continue;
            }
            $rank++;

            $upc = trim((string) ($row[$cUpc] ?? ''));
            $out[] = [
                'rank' => $ranked ? $rank : null,
                'upc' => $upc,
                'material_number' => trim((string) ($row[$cMat] ?? '')),
                'artist' => $artist,
                'title' => $title,
                'genre' => trim((string) ($row[$cGenre] ?? '')),
                'label' => trim((string) ($row[$cLabel] ?? '')),
                'release_date' => $this->normalizeDate($row[$cRelease] ?? null),
                'ume_on_hand' => is_numeric($row[$cOnHand] ?? null) ? (int) $row[$cOnHand] : null,
                'format' => $formatLabel,
                'is_new_release' => $isNewRelease,
            ];
        }

        return $out;
    }

    protected function parseAnniversaries($sheet): array
    {
        if (!$sheet) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, false);
        if (count($rows) < 2) {
            return [];
        }

        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $col = function (string $needle) use ($headers) {
            foreach ($headers as $i => $h) {
                if ($h === $needle || (mb_strpos($h, $needle) !== false)) {
                    return $i;
                }
            }
            return null;
        };

        $cArtist = $col('artist');
        $cAlbum = $col('album');
        $cOrigDate = $col('original release');
        $cYears = $col('# of years');
        $cSortDate = $col('sortable date');
        $cMoment = $col('moment');
        $cLabel = $col('label');

        $out = [];
        $today = date('Y-m-d');
        $lookahead = date('Y-m-d', strtotime('+90 days'));

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $artist = trim((string) ($row[$cArtist] ?? ''));
            if ($artist === '') {
                continue;
            }

            $date = $this->normalizeDate($row[$cSortDate] ?? null);
            if (!$date || $date < $today || $date > $lookahead) {
                continue;
            }

            $out[] = [
                'artist' => $artist,
                'album_or_track' => trim((string) ($row[$cAlbum] ?? '')),
                'original_release_date' => $this->normalizeDate($row[$cOrigDate] ?? null),
                'years' => is_numeric($row[$cYears] ?? null) ? (int) $row[$cYears] : null,
                'event_date' => $date,
                'moment' => trim((string) ($row[$cMoment] ?? '')),
                'label' => trim((string) ($row[$cLabel] ?? '')),
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['event_date'], $b['event_date']));

        return $out;
    }

    protected function normalizeDate($cell): ?string
    {
        if ($cell === null || $cell === '') {
            return null;
        }
        if (is_numeric($cell)) {
            // Excel serial date
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $cell);
                return $dt->format('Y-m-d');
            } catch (\Throwable $ignore) {
                return null;
            }
        }
        $ts = strtotime((string) $cell);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    protected function emptyResult(string $reason): array
    {
        return [
            'top_200_vinyl' => [],
            'top_200_cd' => [],
            'deliveries_vinyl' => [],
            'deliveries_cd' => [],
            'anniversaries' => [],
            'meta' => ['error' => $reason],
        ];
    }
}
