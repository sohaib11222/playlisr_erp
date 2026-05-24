<?php

namespace App\Services\SupplierFetchers;

/**
 * VP Records portal fetcher (reggae specialist).
 *
 * STATUS: scaffolded — not yet functional. Add to .env (via Sohaib):
 *   VP_PORTAL_URL=https://vprecords.com/login
 *   VP_PORTAL_USER=...
 *   VP_PORTAL_PASS=...
 *   VP_PRICES_URL=https://vprecords.com/...
 */
class VpFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'vp'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['VP_PORTAL_URL', 'VP_PORTAL_USER', 'VP_PORTAL_PASS', 'VP_PRICES_URL']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();
        $this->login($creds['VP_PORTAL_URL'], [
            'email' => $creds['VP_PORTAL_USER'],
            'password' => $creds['VP_PORTAL_PASS'],
        ]);
        $html = $this->get($creds['VP_PRICES_URL']);
        throw new \RuntimeException('VpFetcher: parser not yet implemented — see file header.');
    }
}
