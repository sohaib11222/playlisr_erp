<?php

namespace App\Services\SupplierFetchers;

/**
 * Redeye Worldwide B2B portal fetcher (indie / world / reggae catalog).
 *
 * Wired up Sarah 2026-07-17 after a portal walkthrough (saved catalog +
 * product pages). The portal is a plain server-rendered Laravel app at
 * b2b.redeyeworldwide.com — login is a standard email/password form with
 * a CSRF `_token`, and product pages render server-side, so plain HTTP +
 * regex is enough (no headless browser).
 *
 * Flow:
 *   1. GET  /login            → grab the CSRF `_token` (+ session cookie)
 *   2. POST /login            → {_token, email, password, remember}
 *   3. GET  a set of listing pages (best-sellers / catalog / new / preorder)
 *      → collect every /products/details/<id> link
 *   4. GET  each product page → parse each format variant's "Your cost: $X"
 *      block (has its own Item # / UPC / EAN / MSRP)
 *
 * REQUIRED credentials — set via the ICA "Credentials" form (encrypted on
 * disk) OR .env:
 *   REDEYE_PORTAL_USER=...    # portal e-mail (login field `email`)
 *   REDEYE_PORTAL_PASS=...    # portal password
 *
 * Tunables (env, all optional):
 *   REDEYE_LIST_PAGES=8       # max ?page=N per listing section to walk
 *   REDEYE_DETAIL_CONCURRENCY=12
 *   REDEYE_FETCH_BUDGET_SEC=95  # wall-clock cap so the sync button returns
 */
class RedeyeFetcher extends AbstractHttpFetcher
{
    protected string $base = 'https://b2b.redeyeworldwide.com';

    /** Listing sections that render the product-card grid. */
    protected array $listPaths = [
        '/best-sellers/catalog',
        '/best-sellers',
        '/best-sellers/new-releases',
        '/best-sellers/preorders',
    ];

    public function supplierKey(): string { return 'redeye'; }

    public function readCredentials(): array
    {
        // Only user + pass are needed — the login and catalog URLs are
        // fixed on b2b.redeyeworldwide.com (mirrors AmsFetcher).
        return $this->requireEnv(['REDEYE_PORTAL_USER', 'REDEYE_PORTAL_PASS']);
    }

    public function fetch(): array
    {
        $startedAt = microtime(true);
        $creds = $this->readCredentials();

        // 1) GET the login page to seed the session cookie + read the CSRF
        // token Laravel requires on the POST.
        $loginHtml = $this->get($this->base . '/login');
        $token = $this->extractCsrfToken($loginHtml);
        if ($token === null) {
            throw new \RuntimeException('Redeye: could not find CSRF _token on the login page — portal markup may have changed.');
        }

        // 2) POST credentials.
        $this->login($this->base . '/login', [
            '_token' => $token,
            'return_to_referrer' => '',
            'email' => $creds['REDEYE_PORTAL_USER'],
            'password' => $creds['REDEYE_PORTAL_PASS'],
            'remember' => 'on',
        ]);

        // 3) Verify the session — a logged-in page carries a /logout link;
        // the logged-out login page does not.
        $home = $this->get($this->base . '/best-sellers');
        if (stripos($home, '/logout') === false) {
            throw new \RuntimeException('Redeye: login appears to have failed — no logout link after POST. Check REDEYE_PORTAL_USER / REDEYE_PORTAL_PASS.');
        }

        // 4) Collect product-detail ids across the listing sections. The
        // first listing page we already have in $home; reuse it.
        $ids = $this->collectDetailIds($home);
        foreach ($this->listPaths as $path) {
            if ((microtime(true) - $startedAt) > $this->budget()) break;
            foreach ($this->walkListing($path, $startedAt) as $id) {
                $ids[$id] = true;
            }
        }

        // 5) Skip ids we already priced in a previous run so each run
        // advances into new territory instead of re-pulling the same pages.
        $ids = $this->dropAlreadyPriced(array_keys($ids));

        // 6) Fetch each product page concurrently (budgeted) and parse.
        $rows = [];
        foreach ($this->fetchDetails($ids, $startedAt) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Pull the Laravel CSRF token out of the login form. */
    protected function extractCsrfToken(string $html): ?string
    {
        if (preg_match('#name="_token"[^>]*value="([^"]+)"#', $html, $m)) {
            return $m[1];
        }
        if (preg_match('#<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"#i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Extract every /products/details/<id> id from a listing page. */
    protected function collectDetailIds(string $html): array
    {
        $out = [];
        if (preg_match_all('#/products/details/(\d+)#', $html, $m)) {
            foreach ($m[1] as $id) $out[$id] = true;
        }
        return $out;
    }

    /**
     * Walk one listing section, page by page, collecting detail ids. Stops
     * as soon as a page adds no new ids (end of section, or the section
     * doesn't paginate) so we never spin re-fetching page 1.
     *
     * @return array<int, string> detail ids
     */
    protected function walkListing(string $path, float $startedAt): array
    {
        $seen = [];
        $maxPages = max(1, (int) env('REDEYE_LIST_PAGES', 8));
        for ($p = 1; $p <= $maxPages; $p++) {
            if ((microtime(true) - $startedAt) > $this->budget()) break;
            $url = $this->base . $path . ($p > 1 ? ('?page=' . $p) : '');
            try {
                $html = $this->get($url);
            } catch (\Throwable $e) {
                break;
            }
            $before = count($seen);
            foreach (array_keys($this->collectDetailIds($html)) as $id) {
                $seen[$id] = true;
            }
            if (count($seen) === $before) break; // no new ids → done
        }
        return array_keys($seen);
    }

    /**
     * Drop detail ids whose product we already have a priced row for in a
     * prior feed. Keyed by detail id embedded in each stored row's url.
     *
     * @param array<int,string> $ids
     * @return array<int,string>
     */
    protected function dropAlreadyPriced(array $ids): array
    {
        try {
            $svc = app(\App\Services\InventoryCheckService::class);
            $bizId = $this->resolveBusinessId();
            $feed = $bizId ? $svc->loadSupplierFeed($bizId, $this->supplierKey()) : [];
            $priced = [];
            foreach (($feed['rows'] ?? []) as $r) {
                if (!is_array($r)) continue;
                if (isset($r['cost']) && (float) $r['cost'] > 0
                    && preg_match('#/products/details/(\d+)#', (string) ($r['url'] ?? ''), $m)) {
                    $priced[$m[1]] = true;
                }
            }
            if (!empty($priced)) {
                $ids = array_values(array_filter($ids, fn ($id) => !isset($priced[$id])));
            }
        } catch (\Throwable $e) {
            // Non-fatal — worst case we re-price a few we already had.
        }
        return $ids;
    }

    /**
     * Concurrently fetch product pages (bounded by a wall-clock budget) and
     * parse every format variant into a feed row.
     *
     * @param array<int,string> $ids
     * @return array<int, array<string,mixed>>
     */
    protected function fetchDetails(array $ids, float $startedAt): array
    {
        if (empty($ids)) return [];
        $concurrency = max(1, (int) env('REDEYE_DETAIL_CONCURRENCY', 12));
        $out = [];
        foreach (array_chunk($ids, $concurrency) as $chunk) {
            if ((microtime(true) - $startedAt) > $this->budget()) break;
            $urls = [];
            foreach ($chunk as $id) {
                $urls[$id] = $this->base . '/products/details/' . $id;
            }
            foreach ($this->multiGet($urls) as $id => $html) {
                if ($html === null || $html === '') continue;
                foreach ($this->parseDetailPage($html, (string) $id) as $row) {
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Parse one product page into one row per format variant. Each variant
     * lives in a `<div class="cart-buy-box …">` block carrying its own
     * Item # / UPC / EAN / "Your cost: $X" / MSRP.
     *
     * @return array<int, array<string,mixed>>
     */
    protected function parseDetailPage(string $html, string $id): array
    {
        // Header: "<h1>Artist / Title</h1>" — split on the first " / ".
        $artist = null; $title = null;
        if (preg_match('#<h1[^>]*>\s*(.+?)\s*</h1>#is', $html, $hm)) {
            $head = trim(html_entity_decode(strip_tags($hm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strpos($head, ' / ') !== false) {
                [$a, $t] = array_map('trim', explode(' / ', $head, 2));
                $artist = $a !== '' ? $a : null;
                $title = $t !== '' ? $t : null;
            } else {
                $title = $head !== '' ? $head : null;
            }
        }

        $url = $this->base . '/products/details/' . $id;

        // Split into cart-buy-box blocks by positional slicing (bulletproof
        // vs a greedy close-tag regex).
        $count = preg_match_all('#<div\s+class="cart-buy-box[^"]*"#', $html, $starts, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) return [];
        $offsets = array_map(fn ($h) => $h[1], $starts[0]);
        $htmlLen = strlen($html);

        $out = [];
        foreach ($offsets as $i => $start) {
            $end = $i + 1 < count($offsets) ? $offsets[$i + 1] : $htmlLen;
            $block = substr($html, $start, $end - $start);

            // "Your cost: $23.30" — the wholesale price (NOT MSRP).
            if (!preg_match('#Your\s*cost\s*:?\s*\$?\s*([0-9]+(?:\.[0-9]{1,2})?)#i', $block, $cm)) {
                continue;
            }
            $cost = (float) $cm[1];
            if ($cost <= 0) continue;

            // UPC (prefer) or EAN → matcher normalizes leading zeros anyway.
            $upc = null;
            if (preg_match('#UPC\s*:?\s*([0-9]{8,14})#i', $block, $um)) {
                $upc = $um[1];
            } elseif (preg_match('#EAN\s*:?\s*([0-9]{8,14})#i', $block, $em)) {
                $upc = $em[1];
            }

            // Format: the block's leading "<strong>Vinyl LP standard Jacket</strong>".
            $format = null;
            if (preg_match('#<strong>\s*(.+?)\s*</strong>#is', $block, $fm)) {
                $raw = strtolower(trim(strip_tags($fm[1])));
                if (strpos($raw, 'vinyl') !== false || preg_match('#\blp\b#', $raw)) {
                    $format = 'LP';
                } elseif (strpos($raw, 'cd') !== false) {
                    $format = 'CD';
                } elseif (strpos($raw, 'cassette') !== false || strpos($raw, 'tape') !== false) {
                    $format = 'Cassette';
                } else {
                    $format = trim(strip_tags($fm[1])) ?: null;
                }
            }

            $out[] = [
                'artist' => $artist,
                'title' => $title,
                'format' => $format,
                'cost' => $cost,
                'upc' => $upc,
                'url' => $url,
            ];
        }
        return $out;
    }

    /** Wall-clock budget for the whole synchronous fetch. */
    protected function budget(): float
    {
        return (float) env('REDEYE_FETCH_BUDGET_SEC', 95);
    }

    /**
     * Fetch several URLs concurrently against the established cookie jar.
     * Read-only: sets COOKIEFILE (read) but NOT COOKIEJAR (write) so the
     * parallel handles never race to rewrite the session cookie file.
     * (Mirrors AmsFetcher::multiGet.)
     *
     * @param array<string,string> $urls keyed by an arbitrary id
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
}
