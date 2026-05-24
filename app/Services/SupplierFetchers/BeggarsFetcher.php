<?php

namespace App\Services\SupplierFetchers;

/**
 * Beggars Group portal fetcher (XL, Matador, 4AD, Rough Trade, Young).
 *
 * STATUS: scaffolded — not yet functional. Add to .env (via Sohaib):
 *   BEGGARS_PORTAL_URL=https://beggars.com/login
 *   BEGGARS_PORTAL_USER=...
 *   BEGGARS_PORTAL_PASS=...
 *   BEGGARS_PRICES_URL=https://beggars.com/...
 */
class BeggarsFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'beggars'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['BEGGARS_PORTAL_URL', 'BEGGARS_PORTAL_USER', 'BEGGARS_PORTAL_PASS', 'BEGGARS_PRICES_URL']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();
        $this->login($creds['BEGGARS_PORTAL_URL'], [
            'email' => $creds['BEGGARS_PORTAL_USER'],
            'password' => $creds['BEGGARS_PORTAL_PASS'],
        ]);
        $html = $this->get($creds['BEGGARS_PRICES_URL']);
        throw new \RuntimeException('BeggarsFetcher: parser not yet implemented — see file header.');
    }
}
