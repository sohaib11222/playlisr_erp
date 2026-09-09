<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Live IMAP sync for the Communications Hub's email channel — hello@ and
 * orders@nivessa.com. Same `ext-imap` mechanism already proven by
 * ChartEmailFetcher (weekly chart-pick import), just pointed at the two
 * shared support mailboxes instead of Sarah's own inbox, and reading both
 * INBOX (customer inquiries) and Sent Mail (staff replies, so a reply
 * typed directly in Gmail — not through the Hub — still shows up here).
 *
 * Credentials (an address + a Google "App Password", not the real account
 * password) are pasted through the Hub's Email Setup screen and stored in
 * a gitignored file, same reason and pattern as Quo's webhook key/API key
 * — no SSH to hand-edit .env on this box.
 */
class EmailInboxService
{
    private function credsFile(): string
    {
        return storage_path('app/email-inbox-accounts.json');
    }

    /** @return array<string, array{username: string, password: string, host: string, port: int, encryption: string}> */
    public function mailboxes(): array
    {
        $file = $this->credsFile();
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    public function saveMailbox(string $key, string $username, string $appPassword): void
    {
        $mailboxes = $this->mailboxes();
        $mailboxes[$key] = [
            'username' => $username,
            'password' => $appPassword,
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
        ];
        $file = $this->credsFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode($mailboxes, JSON_PRETTY_PRINT));
    }

    public function isConfigured(): bool
    {
        return !empty($this->mailboxes()) && function_exists('imap_open');
    }

    /**
     * Fetch messages from one mailbox's INBOX or Sent folder, most recent
     * first. Returns an empty array (never throws) so one bad mailbox
     * doesn't break the others.
     *
     * @return array<int, array{message_id: string, subject: string, from: string, to: string, date: string, body: string}>
     */
    public function fetchRecent(array $account, string $folder = 'INBOX', int $sinceDays = 5): array
    {
        if (!function_exists('imap_open')) {
            Log::warning('EmailInboxService: ext-imap not installed.');
            return [];
        }

        $host = $account['host'] ?? 'imap.gmail.com';
        $port = $account['port'] ?? 993;
        $enc = $account['encryption'] ?? 'ssl';
        $user = $account['username'] ?? '';
        $pass = $account['password'] ?? '';
        if (!$user || !$pass) {
            return [];
        }

        $folderPath = $folder === 'INBOX' ? 'INBOX' : '[Gmail]/Sent Mail';
        $mailboxStr = sprintf('{%s:%d/imap/%s}%s', $host, $port, $enc, imap_utf7_encode($folderPath));
        $conn = @imap_open($mailboxStr, $user, $pass, 0, 1);
        if (!$conn) {
            Log::error('EmailInboxService: imap_open failed for ' . $user . ' (' . $folder . '): ' . imap_last_error());
            return [];
        }

        $results = [];
        try {
            $sinceStr = date('d-M-Y', strtotime("-{$sinceDays} days"));
            $uids = imap_search($conn, 'SINCE "' . $sinceStr . '"', SE_UID);
            if (!$uids) {
                return [];
            }
            // Most recent first, cap to a sane batch per run.
            rsort($uids);
            $uids = array_slice($uids, 0, 100);

            foreach ($uids as $uid) {
                $headerInfo = imap_headerinfo($conn, imap_msgno($conn, $uid));
                $rawHeader = imap_fetchheader($conn, $uid, FT_UID);
                $parsedHeader = imap_rfc822_parse_headers($rawHeader);

                $messageId = trim((string) ($headerInfo->message_id ?? ''));
                if ($messageId === '') {
                    // No stable id to dedupe on — skip rather than risk duplicates.
                    continue;
                }

                $subject = $this->decodeMime($parsedHeader->subject ?? '');
                $from = isset($parsedHeader->from[0])
                    ? strtolower($parsedHeader->from[0]->mailbox . '@' . $parsedHeader->from[0]->host)
                    : '';
                $to = isset($parsedHeader->to[0])
                    ? strtolower($parsedHeader->to[0]->mailbox . '@' . $parsedHeader->to[0]->host)
                    : '';
                $date = isset($headerInfo->date) ? (string) $headerInfo->date : '';

                $structure = imap_fetchstructure($conn, $uid, FT_UID);
                $body = $this->extractPlainBody($conn, $uid, $structure);

                $results[] = [
                    'message_id' => $messageId,
                    'subject' => $subject,
                    'from' => $from,
                    'to' => $to,
                    'date' => $date,
                    'body' => trim($body),
                ];
            }
        } finally {
            imap_close($conn);
        }

        return $results;
    }

    private function decodeMime(string $s): string
    {
        $parts = imap_mime_header_decode($s);
        $out = '';
        foreach ($parts as $p) {
            $out .= $p->text;
        }
        return $out;
    }

    private function extractPlainBody($conn, int $uid, $structure): string
    {
        $plain = $this->findPart($conn, $uid, $structure, 'TEXT', 'PLAIN');
        if ($plain) {
            return $this->stripQuotedReply($plain);
        }
        $html = $this->findPart($conn, $uid, $structure, 'TEXT', 'HTML');
        $text = $html ? trim(strip_tags($html)) : (string) imap_body($conn, $uid, FT_UID);
        return $this->stripQuotedReply($text);
    }

    private function findPart($conn, int $uid, $structure, string $type, string $subtype, string $section = ''): ?string
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

    private function matchesType($part, string $type, string $subtype): bool
    {
        $typeMap = [0 => 'TEXT', 1 => 'MULTIPART', 2 => 'MESSAGE', 3 => 'APPLICATION', 4 => 'AUDIO', 5 => 'IMAGE', 6 => 'VIDEO', 7 => 'OTHER'];
        $partType = $typeMap[$part->type ?? 0] ?? 'TEXT';
        $partSubtype = strtoupper((string) ($part->subtype ?? ''));
        return $partType === $type && $partSubtype === $subtype;
    }

    private function decodeBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case 3: return base64_decode($body);
            case 4: return quoted_printable_decode($body);
            default: return $body;
        }
    }

    /** Cut a reply off at the quoted "On ... wrote:" block so only the new text is stored. */
    private function stripQuotedReply(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^On .{5,80} wrote:\s*$/', trim($line)) || strpos(trim($line), '>') === 0) {
                break;
            }
            $out[] = $line;
        }
        return trim(implode("\n", $out));
    }
}
