<?php

namespace App\Services\SupplierFetchers;

/**
 * deejay.de (Germany; electronic / dance vinyl). Built 2026-10-08.
 *
 * Plain form login (POST /ajaxHelper/handleLogin.php: loginFeld, passwortFeld),
 * so this runs from the server on the Sunday/Wednesday cron with the login
 * saved in the ICA Credentials form. Search takes a barcode: /?param=<UPC>.
 * A search that returns exactly one item is taken as that barcode's price.
 * Prices are euros; converted to dollars at that day's rate (frankfurter.app,
 * ECB rates) so they compare with the US distributors.
 */
class DeejayFetcher extends AbstractHttpFetcher
{
    protected string $base = 'https://www.deejay.de';

    public function supplierKey(): string { return 'deejay'; }

    public function readCredentials(): array
    {
        return $this->requireEnv(['DEEJAY_PORTAL_USER', 'DEEJAY_PORTAL_PASS']);
    }

    public function fetch(): array
    {
        $creds = $this->readCredentials();
        @unlink($this->cookieJar);
        $page = $this->get($this->base . '/');
        // The login form carries a hidden per-session token named "deejay".
        $token = preg_match('#name="deejay"\s+value="([^"]+)"#', $page, $tm) ? $tm[1] : '';
        $this->login($this->base . '/ajaxHelper/handleLogin.php', [
            'loginFeld' => $creds['DEEJAY_PORTAL_USER'],
            'passwortFeld' => $creds['DEEJAY_PORTAL_PASS'],
            'deejay' => $token,
            'longSession' => '1',
            'loginSubmit' => '1',
        ], ['Referer: ' . $this->base . '/']);
        $home = $this->get($this->base . '/');
        // Logged out pages show the header login button; logged in ones don't.
        if (strpos($home, 'id="loginModalBtn"') !== false) {
            throw new \RuntimeException('Deejay: login failed. Check the deejay.de login saved in the ICA Credentials form.');
        }

        $rate = 1.08;
        try {
            $fx = json_decode($this->get('https://api.frankfurter.app/latest?from=EUR&to=USD'), true);
            if (!empty($fx['rates']['USD'])) $rate = (float) $fx['rates']['USD'];
        } catch (\Throwable $e) {}

        $started = microtime(true);
        $budget = (float) env('DEEJAY_FETCH_BUDGET_SEC', 45);
        $out = [];
        foreach ($this->candidateBarcodes() as $upc) {
            if ((microtime(true) - $started) > $budget) break;
            usleep(400000); // be polite
            try { $html = $this->get($this->base . '/?param=' . urlencode($upc)); } catch (\Throwable $e) { continue; }
            if (stripos($html, 'didn´t find a matching') !== false || stripos($html, "didn't find a matching") !== false) continue;
            // <span class="price">12,56 <b>&euro;</b></span>
            if (!preg_match_all('#class="price"[^>]*>\s*([0-9]+,[0-9]{2})\s*(?:<b>)?\s*(?:&euro;|€)#u', $html, $pm) || count($pm[1]) !== 1) continue;
            $eur = (float) str_replace(',', '.', $pm[1][0]);
            if ($eur <= 0) continue;
            $out[] = [
                'artist' => null, 'title' => null, 'format' => 'LP',
                'cost' => round($eur * $rate, 2),
                'cost_eur' => $eur,
                'upc' => $upc,
                'url' => $this->base . '/?param=' . $upc,
                'checked_at' => date('c'),
            ];
        }
        return $out;
    }

    /** Our sealed vinyl barcodes in electronic/dance genres first, then the rest. */
    protected function candidateBarcodes(): array
    {
        $biz = $this->resolveBusinessId();
        if (!$biz) return [];
        $rows = \DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)
            ->whereRaw("p.sku REGEXP '^[0-9 -]{11,16}$'")
            ->whereRaw("LOWER(c.name) LIKE '%vinyl%' AND LOWER(c.name) NOT LIKE '%used%'")
            ->orderByRaw("CASE WHEN LOWER(COALESCE(sc.name,'')) REGEXP 'electronic|dance|house|techno' THEN 0 ELSE 1 END")
            ->orderByDesc('p.id')
            ->limit(20000)->pluck('p.sku');
        $out = [];
        foreach ($rows as $s) {
            $d = preg_replace('/\D+/', '', (string) $s);
            if (strlen($d) >= 11 && strlen($d) <= 14) $out[$d] = true;
        }
        return array_keys($out);
    }
}
