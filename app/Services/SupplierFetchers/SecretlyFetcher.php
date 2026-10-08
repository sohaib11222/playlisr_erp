<?php

namespace App\Services\SupplierFetchers;

/**
 * Secretly Distribution (b2b.secretlydistribution.com) — Dead Oceans,
 * Jagjaguwar, Saddle Creek, etc.
 *
 * Built 2026-10-08. The B2B site is an Angular app over a JSON API:
 *   1. POST /api/sign_in {username, password} -> {status:"success", data:<JWT>}
 *   2. GET  /api/catalog_download?auth_jwt=<JWT> -> the whole catalog as
 *      tab-separated text (Artist, Title, Format, ..., UPC, ..., Price).
 * Price is the dealer price for the account. No captcha, so this runs from
 * the server on the Sunday-night cron with the login saved in the ICA
 * Credentials form (PORTAL_USER / PORTAL_PASS).
 */
class SecretlyFetcher extends AbstractHttpFetcher
{
    protected string $base = 'https://b2b.secretlydistribution.com';

    public function supplierKey(): string { return 'secretly'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['SECRETLY_PORTAL_USER', 'SECRETLY_PORTAL_PASS']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();
        $resp = $this->request('POST', $this->base . '/api/sign_in', [
            'body' => json_encode(['username' => $creds['SECRETLY_PORTAL_USER'], 'password' => $creds['SECRETLY_PORTAL_PASS']]),
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $j = json_decode($resp, true);
        $jwt = is_array($j) && ($j['status'] ?? '') === 'success' ? (string) ($j['data'] ?? '') : '';
        if ($jwt === '') {
            throw new \RuntimeException('Secretly: login failed (' . (is_array($j) ? ($j['message'] ?? 'no message') : 'bad response')
                . '). Check the Secretly login saved in the ICA Credentials form (it is the B2B username, not always the email).');
        }

        $tsv = $this->request('GET', $this->base . '/api/catalog_download?auth_jwt=' . urlencode($jwt), [
            'headers' => ['Accept: text/csv, text/plain, */*'],
        ]);
        $lines = preg_split('/\r?\n/', $tsv);
        $head = str_getcsv((string) array_shift($lines), "\t");
        $ix = array_flip(array_map('trim', $head));
        foreach (['UPC', 'Price'] as $need) {
            if (!isset($ix[$need])) {
                throw new \RuntimeException('Secretly: catalog download changed shape (no ' . $need . ' column).');
            }
        }
        $now = date('c');
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $c = explode("\t", $line);
            $upc = preg_replace('/\D+/', '', (string) ($c[$ix['UPC']] ?? ''));
            $cost = (float) ($c[$ix['Price']] ?? 0);
            if (strlen($upc) < 8 || $cost <= 0) continue;
            $fmt = strtolower((string) ($c[$ix['Format']] ?? ''));
            $format = (strpos($fmt, 'vinyl') !== false || strpos($fmt, 'lp') !== false) ? 'LP'
                : (strpos($fmt, 'cd') !== false ? 'CD' : ((strpos($fmt, 'cassette') !== false || strpos($fmt, 'tape') !== false) ? 'Cassette' : ($c[$ix['Format']] ?? null)));
            $val = function ($k) use ($c, $ix) { $v = isset($ix[$k]) ? trim((string) ($c[$ix[$k]] ?? '')) : ''; return ($v === '' || $v === 'null') ? null : $v; };
            $out[] = [
                'artist' => $val('Artist'),
                'title' => $val('Title'),
                'format' => $format,
                'cost' => round($cost, 2),
                'upc' => $upc,
                'url' => null,
                'checked_at' => $now,
            ];
        }
        return $out;
    }
}
