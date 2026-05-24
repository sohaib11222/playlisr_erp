<?php

namespace App\Services\SupplierFetchers;

/**
 * Secretly Distribution portal fetcher (Dead Oceans, Jagjaguwar, etc.).
 *
 * STATUS: scaffolded — not yet functional. Add to .env (via Sohaib):
 *   SECRETLY_PORTAL_URL=https://secretlydistribution.com/login
 *   SECRETLY_PORTAL_USER=...
 *   SECRETLY_PORTAL_PASS=...
 *   SECRETLY_PRICES_URL=https://secretlydistribution.com/...
 *
 * Then update login() field names + the fetch() parser to match the
 * portal's actual markup.
 */
class SecretlyFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'secretly'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['SECRETLY_PORTAL_URL', 'SECRETLY_PORTAL_USER', 'SECRETLY_PORTAL_PASS', 'SECRETLY_PRICES_URL']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();
        $this->login($creds['SECRETLY_PORTAL_URL'], [
            'email' => $creds['SECRETLY_PORTAL_USER'],
            'password' => $creds['SECRETLY_PORTAL_PASS'],
        ]);
        $html = $this->get($creds['SECRETLY_PRICES_URL']);
        throw new \RuntimeException('SecretlyFetcher: parser not yet implemented — see file header.');
    }
}
