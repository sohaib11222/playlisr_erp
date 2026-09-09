<?php

namespace App\Services;

/**
 * Minimal IMAP4rev1 client over a raw TLS socket — no `ext-imap` needed.
 *
 * Built because the production server has no path to install ext-imap
 * (confirmed via diagnostic: no sudo at all for the deploy user, no
 * control panel, root-owned php.ini/conf.d/extension_dir, no phpize/
 * pecl — see .github/workflows/diag-php-install.yml) and the deploy
 * pipeline doesn't run `composer install` either (git sync only), so a
 * Composer package wouldn't actually land on the server. Plain PHP over
 * `stream_socket_client` has no such dependency — it just needs the
 * `openssl` extension, which is already required by half of Laravel.
 *
 * Deliberately narrow: only what EmailInboxService needs (login, select
 * a folder, search by date, fetch a whole raw message body). Not a
 * general-purpose IMAP library — no IDLE, no flags, no attachments.
 */
class RawImapClient
{
    /** @var resource|null */
    private $conn;
    private int $tagCounter = 0;

    public function connect(string $host, int $port, int $timeoutSeconds = 15): void
    {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $conn = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$conn) {
            throw new \RuntimeException("IMAP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($conn, $timeoutSeconds);
        $this->conn = $conn;
        $this->readLine(); // server greeting, e.g. "* OK Gimap ready..."
    }

    public function login(string $username, string $password): void
    {
        $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
    }

    public function selectFolder(string $folder): void
    {
        $this->command('SELECT ' . $this->quote($folder));
    }

    /** @return int[] UIDs matching, ascending order (IMAP's natural order) */
    public function uidSearchSince(\DateTimeInterface $since): array
    {
        $dateStr = strtoupper($since->format('d-M-Y'));
        $lines = $this->command('UID SEARCH SINCE ' . $dateStr);
        $uids = [];
        foreach ($lines as $line) {
            if (preg_match('/^\* SEARCH(.*)$/i', trim($line), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $tok) {
                    if ($tok !== '' && ctype_digit($tok)) {
                        $uids[] = (int) $tok;
                    }
                }
            }
        }
        return $uids;
    }

    /** Fetch one message's full raw RFC822 source (headers + body) by UID, without marking it read. */
    public function uidFetchRaw(int $uid): ?string
    {
        $tag = $this->nextTag();
        $this->write("{$tag} UID FETCH {$uid} (BODY.PEEK[])\r\n");
        $raw = null;

        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $len = (int) $m[1];
                $raw = $this->readExact($len);
                // Consume the rest of this FETCH response (closing ")" and
                // the tagged completion line that follows).
                $this->readLine(); // trailing ")\r\n" after the literal
                continue;
            }
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $line)) {
                break;
            }
        }

        return $raw;
    }

    public function logout(): void
    {
        try {
            $this->write($this->nextTag() . " LOGOUT\r\n");
        } catch (\Throwable $e) {
        }
        if ($this->conn) {
            fclose($this->conn);
            $this->conn = null;
        }
    }

    private function command(string $cmd): array
    {
        $tag = $this->nextTag();
        $this->write("{$tag} {$cmd}\r\n");
        $lines = [];
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new \RuntimeException("IMAP connection closed while waiting for response to: {$cmd}");
            }
            $lines[] = $line;
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)(.*)$/i', trim($line), $m)) {
                if (strtoupper($m[1]) !== 'OK') {
                    throw new \RuntimeException("IMAP command failed [{$cmd}]: " . trim($line));
                }
                break;
            }
        }
        return $lines;
    }

    private function nextTag(): string
    {
        return 'A' . (++$this->tagCounter);
    }

    private function write(string $data): void
    {
        if (!$this->conn || fwrite($this->conn, $data) === false) {
            throw new \RuntimeException('IMAP write failed — connection lost.');
        }
    }

    private function readLine(): ?string
    {
        if (!$this->conn || feof($this->conn)) {
            return null;
        }
        $line = fgets($this->conn, 65536);
        return $line === false ? null : $line;
    }

    private function readExact(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            if (!$this->conn || feof($this->conn)) {
                break;
            }
            $chunk = fread($this->conn, min(65536, $bytes - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
