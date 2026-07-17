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
        $startedAt = microtime(true);
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
            foreach ($this->walkCatalog($path, $defaultFormat, max(1, $pages), $ipp, $startedAt) as $row) {
                $rows[] = $row;
            }
        }

        // 5) Barcode lookups for reorder candidates AMS doesn't surface in its
        // SalesRank lists (deep catalog — older titles that still sell for us
        // but aren't current best-sellers). Every product page is keyed purely
        // by the trailing barcode segment (the artist/title slugs are ignored),
        // so /Product/x/x/<ean> resolves straight to the item + "Your Price".
        // We feed it the barcodes of our own low-stock movers and merge the
        // hits in. Bounded by a wall-clock budget so the synchronous
        // "Fetch AMS now" button finishes before the JS client aborts.
        $have = [];
        foreach ($rows as $r) {
            $n = $this->normalizeBarcode((string) ($r['upc'] ?? ''));
            if ($n !== '') $have[$n] = true;
        }
        // Also skip barcodes already priced in a previous run's feed so each
        // run advances into new territory instead of re-pulling the same items.
        try {
            $svc = app(\App\Services\InventoryCheckService::class);
            $bizId = $this->resolveBusinessId();
            $feed = $bizId ? $svc->loadSupplierFeed($bizId, $this->supplierKey()) : [];
            foreach (($feed['rows'] ?? []) as $r) {
                if (!is_array($r)) continue;
                $n = $this->normalizeBarcode((string) ($r['upc'] ?? ''));
                if ($n !== '' && isset($r['cost']) && (float) $r['cost'] > 0) $have[$n] = true;
            }
        } catch (\Throwable $e) {
            // Non-fatal — worst case we re-look-up a few we already had.
        }

        foreach ($this->lookupByBarcodes($this->candidateBarcodes($have), $startedAt) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Pull the barcodes of our own low-stock reorder candidates, most-sold
     * first, so the barcode lookup spends its budget on the items most likely
     * to need restocking. Numeric UPC/EAN SKUs only (legacy internal SKUs like
     * "0119" or "RXT 09" can't be looked up on AMS). $skip holds normalized
     * barcodes we already have a price for.
     *
     * @return array<int, string> normalized (leading-zeros-stripped) barcodes
     */
    protected function candidateBarcodes(array $skip): array
    {
        $cap = max(0, (int) env('AMS_BARCODE_LOOKUPS', 400));
        if ($cap === 0) return [];
        $bizId = $this->resolveBusinessId();
        if (!$bizId) return [];
        $maxStock = (int) env('AMS_BARCODE_MAX_STOCK', 3);

        try {
            $rows = \Illuminate\Support\Facades\DB::table('product_stock_cache')
                ->where('business_id', $bizId)
                ->where('stock', '<=', $maxStock)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->orderByDesc('total_sold')
                ->limit(20000)
                ->pluck('sku');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AmsFetcher: candidateBarcodes query failed', ['err' => $e->getMessage()]);
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $sku) {
            $digits = preg_replace('/\D+/', '', (string) $sku);
            if (strlen($digits) < 11 || strlen($digits) > 13) continue; // not a UPC/EAN
            $norm = ltrim($digits, '0');
            if ($norm === '' || isset($seen[$norm]) || isset($skip[$norm])) continue;
            $seen[$norm] = true;
            $out[] = $norm;
            if (count($out) >= $cap) break;
        }
        return $out;
    }

    /**
     * Look each barcode up on its AMS product page and parse the wholesale
     * price. Runs in small concurrent batches (curl_multi) and stops once the
     * overall fetch wall-clock budget is hit, so a deep candidate list never
     * runs the synchronous request past the client abort.
     *
     * @param array<int, string> $eans normalized barcodes
     * @return array<int, array<string,mixed>>
     */
    protected function lookupByBarcodes(array $eans, float $startedAt): array
    {
        if (empty($eans)) return [];
        $budget = (float) env('AMS_FETCH_BUDGET_SEC', 45);
        $concurrency = max(1, (int) env('AMS_BARCODE_CONCURRENCY', 12));

        $out = [];
        foreach (array_chunk($eans, $concurrency) as $chunk) {
            if ((microtime(true) - $startedAt) > $budget) break;
            $urls = [];
            foreach ($chunk as $ean) {
                $urls[$ean] = 'https://www.allmediasupply.com/Product/x/x/' . $ean;
            }
            foreach ($this->multiGet($urls) as $ean => $html) {
                if ($html === null || $html === '') continue;
                $row = $this->parseProductPageHtml($html, (string) $ean);
                if ($row !== null) $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Fetch several URLs concurrently against the established cookie jar.
     * Read-only: we set COOKIEFILE (read) but NOT COOKIEJAR (write) so the
     * parallel handles never race to rewrite the session cookie file.
     *
     * @param array<string,string> $urls keyed by an arbitrary id (the EAN)
     * @return array<string, ?string> same keys → body (or null on HTTP error)
     */
    protected function multiGet(array $urls): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_COOKIEFILE => $this->cookieJar,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 1.0);
        } while ($running > 0);

        $out = [];
        foreach ($handles as $key => $ch) {
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $out[$key] = ($status >= 200 && $status < 400 && $body !== false) ? (string) $body : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * Parse a single AMS product page into a feed row. Only the wholesale
     * "Your Price" is required — if it's absent (barcode not carried, or not
     * logged in) we return null and the caller skips it. Title/artist/format
     * are best-effort for display; matching downstream keys on the barcode.
     *
     * @return array<string,mixed>|null
     */
    protected function parseProductPageHtml(string $html, string $ean): ?array
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        // "Your Price: $7.40" (NOT "MSRP: $11.99" — that's list price).
        $cost = null;
        if (preg_match('#Your\s*Price\s*:?\s*\$?\s*([0-9]+(?:\.[0-9]{1,2})?)#i', (string) $text, $m)) {
            $cost = (float) $m[1];
        }
        if ($cost === null || $cost <= 0) return null;

        $upc = $ean;
        if (preg_match('#BARCODE\s*:?\s*([0-9]{8,14})#i', (string) $text, $bm)) {
            $upc = $bm[1];
        }

        $format = null;
        if (preg_match('#\bFORMAT\s*:?\s*([A-Za-z][A-Za-z0-9 /\-]{0,30}?)\s+(?:LABEL|GENRE|CATALOG|NO OF|BARCODE|RELEASE)\b#i', (string) $text, $fm)) {
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

        $title = null;
        if (preg_match('#<h1[^>]*>\s*(.+?)\s*</h1>#is', $html, $tm)) {
            $title = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $artist = null;
        if (preg_match('#Artist\b\s*(?:</[a-z0-9]+>\s*)*<a\b[^>]*>\s*(.+?)\s*</a>#is', $html, $am)) {
            $artist = trim(html_entity_decode(strip_tags($am[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return [
            'artist' => $artist !== '' ? $artist : null,
            'title' => $title !== '' ? $title : null,
            'format' => $format,
            'cost' => $cost,
            'upc' => $upc,
            'url' => 'https://www.allmediasupply.com/Product/x/x/' . $ean,
        ];
    }

    /** Digits-only, leading zeros stripped — matches AMS's product-URL key. */
    protected function normalizeBarcode(string $raw): string
    {
        return ltrim((string) preg_replace('/\D+/', '', $raw), '0');
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
    protected function walkCatalog(string $path, string $defaultFormat, int $maxPages, int $ipp, float $startedAt = 0.0): array
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

        $budget = (float) env('AMS_FETCH_BUDGET_SEC', 45);
        for ($p = 2; $p <= $maxPages; $p++) {
            // Bound the page walk by the same wall-clock budget as the
            // barcode lookups — without this the walk could run dozens of
            // sequential pages for minutes and blow past the request window
            // (the "never fetches" bug). Whatever we have so far is saved;
            // the next run's "skip already priced" logic advances further.
            if ($startedAt > 0.0 && (microtime(true) - $startedAt) > $budget) break;
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
