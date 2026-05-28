<?php

namespace App\Services\SupplierFetchers;

/**
 * AMS / Alliance Music Supply portal fetcher
 * (allmediasupply.com).
 *
 * Wired up Sarah 2026-05-21 after a portal walkthrough — login form is
 * a plain ASP.NET POST (no CSRF / no Cloudflare); product pages render
 * server-side so plain HTTP + regex is enough (no headless browser).
 *
 * REQUIRED .env keys (production box, via Sohaib):
 *   AMS_PORTAL_USER=131715         # Account number / username (same on AMS)
 *   AMS_PORTAL_ACCOUNT=131715      # Optional; defaults to USER if blank
 *   AMS_PORTAL_PASS=********       # ROTATE the original password; the one
 *                                  # Sarah pasted in chat 2026-05-21 should
 *                                  # be changed
 *
 * Catalog scope: top-N-per-format ordered by SalesRank. Default 500
 * vinyl + 500 CD (2 pages × 250 each). Bump via env if needed.
 *
 *   AMS_VINYL_PAGES=2  AMS_CD_PAGES=2  AMS_ITEMS_PER_PAGE=250
 */
class AmsFetcher extends AbstractHttpFetcher
{
    public function supplierKey(): string { return 'ams'; }

    public function readCredentials(): array
    {
        $creds = $this->requireEnv(['AMS_PORTAL_USER', 'AMS_PORTAL_PASS']);
        // AccountNumber defaults to username if not separately set.
        $creds['AMS_PORTAL_ACCOUNT'] = env('AMS_PORTAL_ACCOUNT', $creds['AMS_PORTAL_USER']);
        return $creds;
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();

        // 1) Get the login page first so the cookie jar has any
        // pre-session cookies (.NET sometimes seeds these). Throwaway.
        $this->get('https://www.allmediasupply.com/Account/LogOn');

        // 2) POST credentials — AMS expects {AccountNumber, UserName,
        // Password, RememberMe, ReturnUrl}.
        $this->login('https://www.allmediasupply.com/Account/LogOn', [
            'AccountNumber' => $creds['AMS_PORTAL_ACCOUNT'],
            'UserName' => $creds['AMS_PORTAL_USER'],
            'Password' => $creds['AMS_PORTAL_PASS'],
            'RememberMe' => 'false',
            'ReturnUrl' => '',
        ]);

        // 3) Verify session — if the logged-out homepage came back instead
        // of "Sign out", credentials are wrong / account locked / portal
        // changed, and the caller should see a clear error.
        $home = $this->get('https://www.allmediasupply.com/');
        if (stripos($home, 'Sign out') === false && stripos($home, 'logout') === false) {
            throw new \RuntimeException('AMS: login appears to have failed — no "Sign out" link on home. Check AMS_PORTAL_* env values + that the account is unlocked.');
        }

        // 4) Walk the vinyl + CD top-sellers and parse each product block.
        $vinylPages = (int) env('AMS_VINYL_PAGES', 2);
        $cdPages = (int) env('AMS_CD_PAGES', 2);
        $ipp = (int) env('AMS_ITEMS_PER_PAGE', 250);

        $rows = [];
        foreach ([['Vinyl', $vinylPages, 'LP'], ['CD', $cdPages, 'CD']] as [$path, $pages, $defaultFormat]) {
            for ($p = 1; $p <= max(1, $pages); $p++) {
                $url = 'https://www.allmediasupply.com/Search/' . $path . '?sort=SalesRank&pg=' . $p . '&ipp=' . $ipp;
                try {
                    $html = $this->get($url);
                } catch (\Throwable $e) {
                    // One bad page shouldn't kill the whole fetch.
                    \Illuminate\Support\Facades\Log::warning('AmsFetcher: page fetch failed', ['url' => $url, 'err' => $e->getMessage()]);
                    continue;
                }
                $parsed = $this->parseProductListHtml($html, $defaultFormat);
                foreach ($parsed as $row) {
                    $rows[] = $row;
                }
                // If a page came back with no products, no point paging
                // further — we've fallen off the end of the catalog.
                if (empty($parsed)) {
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Pull every <li class="resultItem music" data-ean="..."> block out
     * of the HTML and normalize to the supplier-feed row shape.
     *
     * Format clues are in two places: a `<li class="format">LP Vinyl</li>`
     * sub-row, and the path slug of the product link (.../TEN-150G-VINYL/...).
     * We trust the explicit format tag when present, else fall back to the
     * sheet-name default.
     *
     * @return array<int, array{artist:?string, title:?string, format:?string, cost:?float, upc:?string}>
     */
    protected function parseProductListHtml(string $html, string $defaultFormat): array
    {
        $out = [];
        // Each product is anchored by `<li class="resultItem music"
        // data-ean="N">` — body is everything between this start tag
        // and the NEXT product start (or end of HTML). A naive regex
        // on the closing </li> tripped on inner </ul>/</li> tags
        // inside the .biblioData block; positional slicing is bulletproof.
        $count = preg_match_all(
            '#<li\s+class="resultItem\s+music"\s+data-ean="(?P<ean>[0-9]+)"#',
            $html,
            $starts,
            PREG_OFFSET_CAPTURE
        );
        if ($count === false || $count === 0) {
            return [];
        }

        $positions = [];
        foreach ($starts[0] as $i => $hit) {
            $positions[] = ['start' => $hit[1], 'ean' => $starts['ean'][$i][0]];
        }
        $htmlLen = strlen($html);

        foreach ($positions as $i => $p) {
            $end = $i + 1 < count($positions) ? $positions[$i + 1]['start'] : $htmlLen;
            $body = substr($html, $p['start'], $end - $p['start']);
            $ean = $p['ean'];

            // Title: <h2><a href="/Product/<ARTIST>/<TITLE>/<EAN>">TITLE</a></h2>
            $title = null;
            $artist = null;
            $url = null;
            if (preg_match('#<h2[^>]*>\s*<a\s+href="(/Product/[^"]+)"[^>]*>([^<]+)</a>#', $body, $hm)) {
                $title = trim(html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $url = 'https://www.allmediasupply.com' . $hm[1];
                $segs = explode('/', trim($hm[1], '/'));
                if (count($segs) >= 3) {
                    $artist = str_replace('-', ' ', $segs[1]);
                }
            }
            if (preg_match('#<h3[^>]*>\s*by\s*<a[^>]*>([^<]+)</a>#i', $body, $am)) {
                $artist = trim(html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            $format = $defaultFormat;
            if (preg_match('#<li\s+class="format"[^>]*>([^<]+)</li>#', $body, $fm)) {
                $raw = strtolower(trim($fm[1]));
                if (strpos($raw, 'vinyl') !== false || strpos($raw, 'lp') !== false) {
                    $format = 'LP';
                } elseif (strpos($raw, 'cd') !== false) {
                    $format = 'CD';
                } elseif (strpos($raw, 'cassette') !== false || strpos($raw, 'tape') !== false) {
                    $format = 'Cassette';
                } else {
                    $format = trim($fm[1]);
                }
            }

            // Wholesale "Your Price" — NOT MSRP. The <p class="youPay …">
            // wraps a <label>Your Price:</label><span>$NN.NN</span>.
            $cost = null;
            if (preg_match('#<p[^>]*class="[^"]*youPay[^"]*"[^>]*>.*?<span>\s*\$?([0-9]+(?:\.[0-9]{1,2})?)\s*</span>#s', $body, $cm)) {
                $cost = (float) $cm[1];
            }

            if ($title === null && $artist === null) continue;
            $out[] = [
                'artist' => $artist,
                'title' => $title,
                'format' => $format,
                'cost' => $cost,
                'upc' => $ean,
                'url' => $url,
            ];
        }
        return $out;
    }
}
