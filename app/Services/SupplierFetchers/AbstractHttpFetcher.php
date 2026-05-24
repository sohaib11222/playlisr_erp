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

    /** Convenience: read env vars; throw if any are blank. */
    protected function requireEnv(array $keys): array
    {
        $out = [];
        $missing = [];
        foreach ($keys as $k) {
            $v = env($k);
            if ($v === null || $v === '') {
                $missing[] = $k;
                continue;
            }
            $out[$k] = $v;
        }
        if (!empty($missing)) {
            throw new \RuntimeException(static::class . ': missing .env keys ' . implode(', ', $missing));
        }
        return $out;
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if (!empty($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        if (!empty($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
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
