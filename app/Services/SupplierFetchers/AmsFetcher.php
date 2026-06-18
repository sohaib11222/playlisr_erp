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

        // 4) Walk the vinyl + CD catalogs page by page and parse each block.
        // ipp=100 is AMS's proven page size (250 silently clamps to 100).
        $vinylPages = (int) env('AMS_VINYL_PAGES', 25);
        $cdPages = (int) env('AMS_CD_PAGES', 25);
        $ipp = (int) env('AMS_ITEMS_PER_PAGE', 100);

        $rows = [];
        foreach ([['Vinyl', $vinylPages, 'LP'], ['CD', $cdPages, 'CD']] as [$path, $pages, $defaultFormat]) {
            foreach ($this->walkCatalog($path, $defaultFormat, max(1, $pages), $ipp) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Page through one AMS catalog section (Vinyl / CD) collecting every
     * product row, deduped by EAN.
     *
     * Why this is more than a plain `for ($p=1..N)` loop: the page-number
     * query param AMS uses isn't fixed across portal versions — a wrong
     * param name is silently ignored and page 1 comes back again, which is
     * exactly the "400 fetched → 200 after dedupe" symptom Sarah hit (every
     * "page 2" was really page 1). So on the first advance we probe a few
     * known param spellings (pg / page / p) and lock onto whichever one
     * actually returns *new* EANs, then reuse it for the rest of the walk.
     * We stop as soon as a page yields no new EANs (end of catalog, or the
     * param stopped advancing) so we never spin re-fetching the same page.
     *
     * @return array<int, array<string,mixed>>
     */
    protected function walkCatalog(string $path, string $defaultFormat, int $maxPages, int $ipp): array
    {
        $base = 'https://www.allmediasupply.com/Search/' . $path . '?sort=SalesRank&ipp=' . $ipp;
        $rows = [];
        $seenEans = [];
        $pageParam = null; // locked once a spelling is proven to advance

        $absorb = function (array $parsed) use (&$rows, &$seenEans): int {
            $fresh = 0;
            foreach ($parsed as $r) {
                $ean = (string) ($r['upc'] ?? '');
                if ($ean !== '') {
                    if (isset($seenEans[$ean])) continue;
                    $seenEans[$ean] = true;
                }
                $rows[] = $r;
                $fresh++;
            }
            return $fresh;
        };

        // Page 1 — any unknown param is harmless here, it just gets ignored.
        try {
            $parsed = $this->parseProductListHtml($this->get($base . '&pg=1'), $defaultFormat);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AmsFetcher: page 1 fetch failed', ['path' => $path, 'err' => $e->getMessage()]);
            return [];
        }
        if (empty($parsed)) return [];
        $absorb($parsed);

        for ($p = 2; $p <= $maxPages; $p++) {
            $params = $pageParam !== null ? [$pageParam] : ['pg', 'page', 'p'];
            $advanced = false;
            foreach ($params as $param) {
                try {
                    $parsed = $this->parseProductListHtml($this->get($base . '&' . $param . '=' . $p), $defaultFormat);
                } catch (\Throwable $e) {
                    continue;
                }
                if (empty($parsed)) continue;
                $fresh = $absorb($parsed);
                // Treat the page as a real advance only if at least half its
                // rows are new — a repeat of page 1 brings ~0 fresh EANs.
                if ($fresh >= max(1, (int) floor(count($parsed) / 2))) {
                    $pageParam = $param;
                    $advanced = true;
                    break;
                }
            }
            if (!$advanced) break; // no spelling advanced → end of catalog
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
