<?php

namespace App\Services\SupplierFetchers;

/**
 * Redeye Worldwide portal fetcher (indie / world / reggae catalog).
 *
 * STATUS: scaffolded — not yet functional. Add to .env (via Sohaib):
 *   REDEYE_PORTAL_URL=https://www.redeyerecords.co.uk/login
 *   REDEYE_PORTAL_USER=...
 *   REDEYE_PORTAL_PASS=...
 *   REDEYE_PRICES_URL=https://www.redeyerecords.co.uk/...
 */
class RedeyeFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'redeye'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['REDEYE_PORTAL_URL', 'REDEYE_PORTAL_USER', 'REDEYE_PORTAL_PASS', 'REDEYE_PRICES_URL']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();
        $this->login($creds['REDEYE_PORTAL_URL'], [
            'email' => $creds['REDEYE_PORTAL_USER'],
            'password' => $creds['REDEYE_PORTAL_PASS'],
        ]);
        $html = $this->get($creds['REDEYE_PRICES_URL']);
        throw new \RuntimeException('RedeyeFetcher: parser not yet implemented — see file header.');
    }
}
