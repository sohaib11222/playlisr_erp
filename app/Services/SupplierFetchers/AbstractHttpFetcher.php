<?php

namespace App\Services\SupplierFetchers;

use Illuminate\Support\Facades\Log;

/**
 * Common HTTP machinery for portal-based supplier fetchers — cookie jar,
 * form login, GET/POST helpers built on cURL (no Guzzle dep). Concrete
 * fetchers extend this and only implement supplierKey() / readCredentials()
 * / fetch().
 *
 * For portals that are heavily JS-driven (Cloudflare, React SPAs, hidden
 * CSRF tokens that mutate per request), this base class won't be enough
 * and that supplier will need a headless-browser fetcher instead (added
 * later when we know which portals are which).
 */
abstract class AbstractHttpFetcher implements SupplierFetcherContract
{
    /** Path to the per-session cookie jar — auto-created per fetcher key. */
    protected string $cookieJar;

    /** Standard browser UA string — some portals block "curl/*". */
    protected string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct()
    {
        $this->cookieJar = storage_path('app/supplier-fetch-cookies-' . $this->supplierKey() . '.txt');
        // Ensure storage/app exists
        $dir = dirname($this->cookieJar);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    }

    /**
     * Convenience: read env vars; throw if any are blank.
     *
     * Sarah 2026-05-21: also looks up the per-business encrypted creds
     * file (storage/app/supplier-creds-{biz}-{key}.enc) when an env var
     * is blank — so she can manage portal logins from the ICA UI
     * without SSHing into the box to edit .env. Env still wins when set.
     */
    protected function requireEnv(array $keys): array
    {
        $out = [];
        $missing = [];

        // Lazy-load the encrypted creds file for this supplier if any
        // env key is missing. Cheap (one file read per supplier per
        // fetch run).
        $fileCreds = null;
        $loadFileCreds = function () use (&$fileCreds) {
            if ($fileCreds !== null) return $fileCreds;
            try {
                $svc = app(\App\Services\InventoryCheckService::class);
                $businessId = $this->resolveBusinessId();
                $fileCreds = $businessId ? $svc->loadSupplierCredentials($businessId, $this->supplierKey()) : [];
            } catch (\Throwable $e) {
                $fileCreds = [];
            }
            return $fileCreds;
        };

        foreach ($keys as $k) {
            $v = env($k);
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
                continue;
            }
            // Map .env key to the credentials-file field by stripping
            // the supplier prefix (e.g. AMS_PORTAL_PASS → PORTAL_PASS).
            $upperKey = strtoupper($this->supplierKey()) . '_';
            $shortKey = strpos($k, $upperKey) === 0 ? substr($k, strlen($upperKey)) : $k;
            $stash = $loadFileCreds();
            if (!empty($stash[$shortKey])) {
                $out[$k] = $stash[$shortKey];
                continue;
            }
            $missing[] = $k;
        }
        if (!empty($missing)) {
            throw new \RuntimeException(static::class . ': missing credential keys ' . implode(', ', $missing)
                . ' — set them in .env OR via the supplier panel "Credentials" form on the Inventory Check Assistant page.');
        }
        return $out;
    }

    /**
     * Best-effort: pick a business_id for credential lookup. Single-
     * business installs (Nivessa's case) have exactly one row.
     */
    protected function resolveBusinessId(): ?int
    {
        try {
            $b = \App\Business::orderBy('id')->first();
            return $b ? (int) $b->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Form POST login. Returns the response body for debugging; cookie
     * jar persists session for subsequent get() calls.
     */
    protected function login(string $url, array $fields, array $extraHeaders = []): string
    {
        return $this->request('POST', $url, [
            'body' => http_build_query($fields),
            'headers' => array_merge(['Content-Type: application/x-www-form-urlencoded'], $extraHeaders),
        ]);
    }

    /** GET a URL using the established cookie jar. */
    protected function get(string $url, array $extraHeaders = []): string
    {
        return $this->request('GET', $url, ['headers' => $extraHeaders]);
    }

    protected function request(string $method, string $url, array $opts = []): string
    {
        $ch = curl_init($url);
        $base = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ];
        // 2026-05-28 Sarah: AMS login flipped from HTTP 411 (Length
        // Required) → HTTP 400 (Bad Request) because we set
        // Content-Length manually AND curl auto-set it via CURLOPT_POST,
        // so .NET saw duplicate headers and rejected. Now: use
        // CURLOPT_POST for POSTs (curl handles Content-Length on its own,
        // no manual stamp), CURLOPT_CUSTOMREQUEST for other verbs.
        $isPost = strtoupper($method) === 'POST';
        if ($isPost) {
            $base[CURLOPT_POST] = true;
        } else {
            $base[CURLOPT_CUSTOMREQUEST] = $method;
        }
        curl_setopt_array($ch, $base);
        $headers = !empty($opts['headers']) ? $opts['headers'] : [];
        if (!empty($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            Log::warning(static::class . ' http ' . $status . ' on ' . $url, ['curl_error' => $err]);
            throw new \RuntimeException(static::class . ": HTTP $status on $url ($err)");
        }
        return (string) $body;
    }
}
