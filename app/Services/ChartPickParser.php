<?php

namespace App\Services;

/**
 * Parses pasted Street Pulse / Universal weekly chart emails into
 * structured rows. The two sources use slightly different layouts but
 * both fundamentally list "rank? — artist — title — format?" per line.
 *
 * This parser is intentionally forgiving: it tolerates tab, comma, or
 * multi-space separators, HTML remnants, and stray chart headers. If a
 * line can't be parsed cleanly, it's skipped rather than failing the
 * whole import — we log the skip count in the response so the operator
 * can see if something went sideways.
 */
class ChartPickParser
{
    /**
     * @return array<int, array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}>
     */
    public function parse(string $body, string $source): array
    {
        $body = $this->stripHtml($body);

        $lines = preg_split("/\r?\n/", $body);
        $out = [];
        $rank = 0;

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }

            // Skip obvious header / footer / marketing lines
            if ($this->isHeaderLine($line)) {
                continue;
            }

            $row = $this->parseLine($line);
            if ($row === null) {
                continue;
            }

            if ($row['rank'] === null) {
                $rank++;
                $row['rank'] = $rank;
            } else {
                $rank = (int) $row['rank'];
            }

            $out[] = $row;
        }

        return $out;
    }

    protected function stripHtml(string $body): string
    {
        // Decode HTML entities, drop tags — emails often forward as HTML
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        $body = preg_replace('/<br\s*\/?>/i', "\n", $body);
        $body = preg_replace('/<\/(tr|p|div|li)>/i', "\n", $body);
        $body = strip_tags($body);

        return $body;
    }

    protected function isHeaderLine(string $line): bool
    {
        $lower = mb_strtolower($line);
        $skips = [
            'street pulse', 'universal music', 'top 100', 'top 200', 'top 50',
            'week ending', 'week of', 'chart position', 'rank artist title',
            'this week', 'last week', 'unsubscribe', 'view in browser',
            'copyright', 'all rights reserved',
        ];
        foreach ($skips as $s) {
            if (mb_strpos($lower, $s) !== false && mb_strlen($line) < 80) {
                return true;
            }
        }
        // Pure numbers or dividers
        if (preg_match('/^[-=_*]{3,}$/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}|null
     */
    protected function parseLine(string $line): ?array
    {
        $isNew = false;
        // New-release markers: "*NEW*", "(NEW)", "NEW — ", leading "★"
        if (preg_match('/(^|\s)(\*NEW\*|\(NEW\)|NEW\b|★)/i', $line)) {
            $isNew = true;
            $line = preg_replace('/(\*NEW\*|\(NEW\)|★)/i', '', $line);
            $line = trim($line);
        }

        // Tab-separated (common in CSV/TSV forwards)
        if (mb_strpos($line, "\t") !== false) {
            $parts = array_map('trim', explode("\t", $line));
            return $this->fromParts($parts, $isNew);
        }

        // Pipe-separated
        if (mb_strpos($line, '|') !== false) {
            $parts = array_map('trim', explode('|', $line));
            return $this->fromParts($parts, $isNew);
        }

        // Comma-separated — only trust if exactly 2-4 comma groups AND no
        // comma inside quoted spans. Otherwise fall through to dash pattern.
        if (substr_count($line, ',') >= 1 && substr_count($line, ',') <= 3
            && mb_strpos($line, '"') === false) {
            $parts = array_map('trim', explode(',', $line));
            $guess = $this->fromParts($parts, $isNew);
            if ($guess && $guess['artist'] && $guess['title']) {
                return $guess;
            }
        }

        // Dash / em-dash separated: "1. Artist — Title — Format"
        $normalized = preg_replace('/[–—]/u', '-', $line);
        if (preg_match('/^(\d+)\.?\s+(.+)$/', $normalized, $m)) {
            $rank = (int) $m[1];
            $rest = $m[2];
        } else {
            $rank = null;
            $rest = $normalized;
        }

        $parts = array_map('trim', preg_split('/\s+-\s+/', $rest, 3));
        if (count($parts) < 2) {
            return null;
        }

        return [
            'rank' => $rank,
            'artist' => $parts[0] ?: null,
            'title' => $parts[1] ?: null,
            'format' => $parts[2] ?? null,
            'is_new_release' => $isNew,
        ];
    }

    /**
     * @param  array<int,string>  $parts
     * @return array{rank:?int, artist:?string, title:?string, format:?string, is_new_release:bool}|null
     */
    protected function fromParts(array $parts, bool $isNew): ?array
    {
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            return null;
        }

        $rank = null;
        if (ctype_digit($parts[0])) {
            $rank = (int) array_shift($parts);
        }

        return [
            'rank' => $rank,
            'artist' => $parts[0] ?? null,
            'title' => $parts[1] ?? null,
            'format' => $parts[2] ?? null,
            'is_new_release' => $isNew,
        ];
    }
}
