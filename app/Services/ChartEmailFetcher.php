<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pulls weekly chart emails from sarah@nivessa.com via Gmail IMAP and
 * extracts their attachments + bodies for downstream parsing.
 *
 * Uses PHP's built-in `ext-imap`. If the extension isn't installed the
 * fetcher returns an empty list with a logged warning — the rest of the
 * ICA keeps working, Clyde just won't see auto-imported chart rows.
 *
 * Expected env vars (already documented in config/inventory_check.php):
 *   INVENTORY_CHECK_IMAP_HOST
 *   INVENTORY_CHECK_IMAP_PORT
 *   INVENTORY_CHECK_IMAP_USERNAME
 *   INVENTORY_CHECK_IMAP_PASSWORD
 *   INVENTORY_CHECK_IMAP_ENCRYPTION  (ssl|tls, default ssl)
 */
class ChartEmailFetcher
{
    /**
     * Fetch matching emails and save attachments to storage.
     *
     * @param  int  $sinceDays  how far back to look (default 7)
     * @return array<int, array{
     *     source: string,
     *     subject: string,
     *     from: string,
     *     date: string,
     *     body: string,
     *     attachments: array<int, array{filename: string, storage_path: string, mime: string}>,
     *     uid: int
     * }>
     */
    public function fetchRecent(int $sinceDays = 7): array
    {
        if (!function_exists('imap_open')) {
            Log::warning('ChartEmailFetcher: ext-imap not installed — skipping auto-fetch. Install php-imap on the server.');
            return [];
        }

        $host = config('inventory_check.email.host');
        $port = config('inventory_check.email.port', 993);
        $user = config('inventory_check.email.username');
        $pass = config('inventory_check.email.password');
        $enc = config('inventory_check.email.encryption', 'ssl');

        if (!$host || !$user || !$pass) {
            Log::info('ChartEmailFetcher: email credentials not set — skipping.');
            return [];
        }

        $sources = config('inventory_check.email.sources', []);
        if (empty($sources)) {
            return [];
        }

        $mailboxStr = sprintf('{%s:%d/imap/%s}INBOX', $host, $port, $enc);
        $conn = @imap_open($mailboxStr, $user, $pass, 0, 1);
        if (!$conn) {
            Log::error('ChartEmailFetcher: imap_open failed: ' . imap_last_error());
            return [];
        }

        $results = [];
        $sinceStr = date('d-M-Y', strtotime("-{$sinceDays} days"));

        try {
            foreach ($sources as $sourceKey => $meta) {
                $fromPattern = $meta['from'] ?? '';
                if (!$fromPattern) {
                    continue;
                }

                $search = sprintf('FROM "%s" SINCE "%s"', $fromPattern, $sinceStr);
                $uids = imap_search($conn, $search, SE_UID);
                if (!$uids) {
                    continue;
                }

                foreach ($uids as $uid) {
                    $header = imap_rfc822_parse_headers(imap_fetchheader($conn, $uid, FT_UID));
                    $subject = $this->decodeMime($header->subject ?? '');
                    $from = isset($header->from[0])
                        ? ($header->from[0]->mailbox . '@' . $header->from[0]->host)
                        : '';
                    $date = isset($header->date) ? date('Y-m-d', strtotime($header->date)) : date('Y-m-d');

                    $structure = imap_fetchstructure($conn, $uid, FT_UID);
                    $body = $this->extractBody($conn, $uid, $structure);
                    $attachments = $this->extractAttachments($conn, $uid, $structure, $sourceKey, $date);

                    $results[] = [
                        'source' => $sourceKey,
                        'subject' => $subject,
                        'from' => $from,
                        'date' => $date,
                        'body' => $body,
                        'attachments' => $attachments,
                        'uid' => (int) $uid,
                    ];
                }
            }
        } finally {
            imap_close($conn);
        }

        return $results;
    }

    protected function decodeMime(string $s): string
    {
        $parts = imap_mime_header_decode($s);
        $out = '';
        foreach ($parts as $p) {
            $out .= $p->text;
        }
        return $out;
    }

    protected function extractBody($conn, int $uid, $structure): string
    {
        // Prefer text/plain; fall back to text/html (strip tags later at parse time)
        $plain = $this->findPart($conn, $uid, $structure, 'TEXT', 'PLAIN');
        if ($plain) {
            return $plain;
        }
        $html = $this->findPart($conn, $uid, $structure, 'TEXT', 'HTML');
        return $html ?: (string) imap_body($conn, $uid, FT_UID);
    }

    protected function findPart($conn, int $uid, $structure, string $type, string $subtype, string $section = ''): ?string
    {
        if (!isset($structure->parts) || empty($structure->parts)) {
            if ($this->matchesType($structure, $type, $subtype)) {
                $body = imap_fetchbody($conn, $uid, $section ?: '1', FT_UID);
                return $this->decodeBody($body, $structure->encoding ?? 0);
            }
            return null;
        }

        foreach ($structure->parts as $idx => $part) {
            $partSection = $section === '' ? (string) ($idx + 1) : $section . '.' . ($idx + 1);
            if ($this->matchesType($part, $type, $subtype)) {
                $body = imap_fetchbody($conn, $uid, $partSection, FT_UID);
                return $this->decodeBody($body, $part->encoding ?? 0);
            }
            if (isset($part->parts)) {
                $nested = $this->findPart($conn, $uid, $part, $type, $subtype, $partSection);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    protected function matchesType($part, string $type, string $subtype): bool
    {
        $typeMap = [0 => 'TEXT', 1 => 'MULTIPART', 2 => 'MESSAGE', 3 => 'APPLICATION', 4 => 'AUDIO', 5 => 'IMAGE', 6 => 'VIDEO', 7 => 'OTHER'];
        $partType = $typeMap[$part->type ?? 0] ?? 'TEXT';
        $partSubtype = strtoupper((string) ($part->subtype ?? ''));
        return $partType === $type && $partSubtype === $subtype;
    }

    protected function decodeBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case 3: return base64_decode($body);
            case 4: return quoted_printable_decode($body);
            default: return $body;
        }
    }

    /**
     * @return array<int, array{filename: string, storage_path: string, mime: string}>
     */
    protected function extractAttachments($conn, int $uid, $structure, string $sourceKey, string $date): array
    {
        $found = [];
        $this->walkForAttachments($conn, $uid, $structure, '', $found, $sourceKey, $date);
        return $found;
    }

    protected function walkForAttachments($conn, int $uid, $part, string $section, array &$found, string $sourceKey, string $date)
    {
        $partSection = $section === '' ? '1' : $section;

        // Detect attachment by disposition or filename parameters
        $filename = $this->attachmentFilename($part);
        if ($filename && $section !== '') {
            $raw = imap_fetchbody($conn, $uid, $partSection, FT_UID);
            $decoded = $this->decodeBody($raw, $part->encoding ?? 0);
            $slug = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
            $path = sprintf('chart-imports/%s/%s_uid%d_%s', $sourceKey, $date, $uid, $slug);
            Storage::disk('local')->put($path, $decoded);
            $mime = $this->mimeFor($part);
            $found[] = [
                'filename' => $filename,
                'storage_path' => Storage::disk('local')->path($path),
                'mime' => $mime,
            ];
        }

        if (!isset($part->parts)) {
            return;
        }

        foreach ($part->parts as $idx => $sub) {
            $subSection = $section === '' ? (string) ($idx + 1) : $section . '.' . ($idx + 1);
            $this->walkForAttachments($conn, $uid, $sub, $subSection, $found, $sourceKey, $date);
        }
    }

    protected function attachmentFilename($part): ?string
    {
        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $p) {
                if (strtolower($p->attribute) === 'filename') {
                    return $this->decodeMime($p->value);
                }
            }
        }
        if (!empty($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (strtolower($p->attribute) === 'name') {
                    return $this->decodeMime($p->value);
                }
            }
        }
        return null;
    }

    protected function mimeFor($part): string
    {
        $typeMap = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'];
        $type = $typeMap[$part->type ?? 3] ?? 'application';
        $sub = strtolower((string) ($part->subtype ?? 'octet-stream'));
        return "$type/$sub";
    }
}
