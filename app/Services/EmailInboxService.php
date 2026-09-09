<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Live IMAP sync for the Communications Hub's email channel — hello@ and
 * orders@nivessa.com. Uses RawImapClient (a hand-rolled IMAP client over
 * a plain TLS socket) rather than the `ext-imap` PHP extension: the
 * production server has no path to install that extension (confirmed —
 * see .github/workflows/diag-php-install.yml: no sudo, no control panel,
 * root-owned everything, no phpize/pecl) and the deploy pipeline doesn't
 * run `composer install` either, so a Composer package wouldn't land on
 * the server. Plain sockets need nothing beyond openssl, which Laravel
 * already requires.
 *
 * Credentials (an address + a Google "App Password", not the real
 * account password) are pasted through the Hub's Email Setup screen and
 * stored in a gitignored file, same reason and pattern as Quo's webhook
 * key/API key — no SSH to hand-edit .env on this box.
 */
class EmailInboxService
{
    private function credsFile(): string
    {
        return storage_path('app/email-inbox-accounts.json');
    }

    /** @return array<string, array{username: string, password: string, host: string, port: int}> */
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
        ];
        $file = $this->credsFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode($mailboxes, JSON_PRETTY_PRINT));
    }

    public function isConfigured(): bool
    {
        return !empty($this->mailboxes());
    }

    /**
     * Fetch messages from one mailbox's INBOX or Sent folder, newest
     * first. Returns an empty array (never throws) so one bad mailbox
     * doesn't break the others — errors are logged instead.
     *
     * @return array<int, array{message_id: string, subject: string, from: string, to: string, date: string, body: string}>
     */
    public function fetchRecent(array $account, string $folder = 'INBOX', int $sinceDays = 5): array
    {
        $host = $account['host'] ?? 'imap.gmail.com';
        $port = $account['port'] ?? 993;
        $user = $account['username'] ?? '';
        $pass = $account['password'] ?? '';
        if (!$user || !$pass) {
            return [];
        }

        $folderPath = $folder === 'INBOX' ? 'INBOX' : '[Gmail]/Sent Mail';
        $client = new RawImapClient();
        $parser = new RawMimeParser();
        $results = [];

        try {
            $client->connect($host, (int) $port);
            $client->login($user, $pass);
            $client->selectFolder($folderPath);

            $uids = $client->uidSearchSince(now()->subDays($sinceDays));
            // Newest first, cap to a sane batch per run.
            $uids = array_reverse($uids);
            $uids = array_slice($uids, 0, 100);

            foreach ($uids as $uid) {
                $raw = $client->uidFetchRaw($uid);
                if (!$raw) {
                    continue;
                }
                $parsed = $parser->parse($raw);
                if ($parsed['message_id'] === '') {
                    continue; // no stable id to dedupe on — skip rather than risk duplicates
                }
                $results[] = $parsed;
            }

            $client->logout();
        } catch (\Throwable $e) {
            Log::error('EmailInboxService: IMAP fetch failed for ' . $user . ' (' . $folder . '): ' . $e->getMessage());
            return $results;
        }

        return $results;
    }
}
