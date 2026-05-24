<?php

namespace App\Services\SupplierFetchers;

/**
 * AMS / Alliance Music Supply portal fetcher.
 *
 * STATUS: scaffolded — not yet functional. To enable:
 *   1. Add to .env on the production box (via Sohaib):
 *        AMS_PORTAL_URL=https://ams.com/login   (real login URL)
 *        AMS_PORTAL_USER=...
 *        AMS_PORTAL_PASS=...
 *        AMS_PRICES_URL=https://ams.com/...     (where the prices list lives)
 *   2. Inspect the login form (Chrome devtools → Network → submit login →
 *      copy the POST URL + form field names) and update login() below.
 *   3. Inspect the prices page (Chrome devtools → copy the HTML or check
 *      for a CSV download link) and replace the TODO parser with real
 *      column extraction.
 *
 * The cron command (supplier-prices:fetch ams) will throw a clear
 * "not yet configured" message until the .env keys exist + the TODOs are
 * filled in. Output is displayed on the supplier panel as "Auto-fetch:
 * not configured" so Sarah knows it's pending vs broken.
 */
class AmsFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'ams'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['AMS_PORTAL_URL', 'AMS_PORTAL_USER', 'AMS_PORTAL_PASS', 'AMS_PRICES_URL']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();

        // TODO: replace with the real login form fields once we have
        // the portal URL + a screenshot of the login page. Most B2B
        // portals POST { username, password } or { email, password }.
        $this->login($creds['AMS_PORTAL_URL'], [
            'username' => $creds['AMS_PORTAL_USER'],
            'password' => $creds['AMS_PORTAL_PASS'],
        ]);

        $html = $this->get($creds['AMS_PRICES_URL']);

        // TODO: parse $html into rows. Two common shapes:
        //   a) HTML table — use DOMDocument + DOMXPath, look for the
        //      prices <table>, iterate <tr> and pull artist/title/cost
        //      from the right <td> indexes.
        //   b) Download link to CSV — fetch the CSV URL and parse with
        //      str_getcsv() per row.
        // Once we know which, this method returns the flat rows array.
        throw new \RuntimeException('AmsFetcher: parser not yet implemented — see file header.');
    }
}
