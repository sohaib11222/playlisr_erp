<?php

namespace App\Services;

/**
 * Calls the nivessa.com API with the shared ERP key (Sarah, 2026-10-15).
 *
 * - erp():   the ERP bridge routes, /api/v1/erp/<path>
 * - admin(): any website admin route, run as an admin through
 *            /api/v1/erp/admin-proxy/<path> (path is what the website's
 *            /admin pages call, minus /api/v1), so ERP pages reuse the
 *            website's own handlers.
 *
 * Always returns an array; on failure {success:false, message:<reason>}.
 * Key/base URL resolution is the same as WebsiteOrdersController's.
 */
class WebsiteBridge
{
    public static function erp(string $method, string $path, array $body = null, int $timeout = 15): array
    {
        return self::call($method, '/erp' . $path, $body, $timeout);
    }

    public static function admin(string $method, string $path, array $body = null, int $timeout = 20): array
    {
        return self::call($method, '/erp/admin-proxy' . $path, $body, $timeout);
    }

    /** Multipart upload through the admin proxy ($files: field => UploadedFile). */
    public static function adminUpload(string $method, string $path, array $fields, array $files, int $timeout = 60): array
    {
        $post = [];
        foreach ($fields as $k => $v) {
            $post[$k] = is_array($v) ? json_encode($v) : (string) $v;
        }
        foreach ($files as $k => $file) {
            if ($file) {
                $post[$k] = new \CURLFile($file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName());
            }
        }
        return self::call($method, '/erp/admin-proxy' . $path, $post, $timeout, true);
    }

    public static function actor(): string
    {
        $u = auth()->user();
        if (!$u) {
            return '';
        }
        return trim($u->first_name . ' ' . $u->last_name) ?: (string) $u->username;
    }

    protected static function call(string $method, string $path, $body, int $timeout, bool $multipart = false): array
    {
        $key = self::apiKey();
        if ($key === '') {
            return ['success' => false, 'message' => 'No ERP bridge key configured (set one on the Events page).'];
        }
        try {
            $ch = curl_init(self::baseUrl() . $path);
            $headers = ['Accept: application/json', 'x-erp-key: ' . $key, 'x-erp-user: ' . self::actor()];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            ]);
            if ($body !== null) {
                if ($multipart) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                } else {
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errNo = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false && $errNo === CURLE_OPERATION_TIMEDOUT) {
                return ['success' => false, 'timedOut' => true, 'message' => 'The website took too long to answer.'];
            }
            if ($raw === false) {
                return ['success' => false, 'message' => 'Could not reach the website' . ($err !== '' ? " ({$err})" : '') . '.'];
            }
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                $snippet = substr(trim(strip_tags((string) $raw)), 0, 200);
                return ['success' => false, 'message' => "Website returned HTTP {$code}" . ($snippet !== '' ? ": {$snippet}" : '.')];
            }
            if ($code >= 400 && !array_key_exists('success', $decoded)) {
                $decoded['success'] = false;
            }
            // Some website handlers answer a bare list or {data} with no
            // success flag; treat a 2xx as success.
            if ($code < 400 && !array_key_exists('success', $decoded)) {
                $decoded = ['success' => true, 'data' => $decoded];
            }
            $decoded['_http'] = $code;
            return $decoded;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Bridge error: ' . $e->getMessage()];
        }
    }

    protected static function apiKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            $key = self::envFromDisk('ERP_API_KEY');
        }
        if ($key === '') {
            try {
                $path = storage_path('app/events-bridge.json');
                if (is_file($path)) {
                    $j = json_decode((string) file_get_contents($path), true);
                    $key = is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
                }
            } catch (\Throwable $e) {
                $key = '';
            }
        }
        return $key;
    }

    protected static function envFromDisk(string $name): string
    {
        try {
            $path = base_path('.env');
            if (!is_readable($path)) {
                return '';
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), $name . '=') === 0) {
                    return trim(trim(substr(ltrim($line), strlen($name) + 1)), "\"'");
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    protected static function baseUrl(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        return rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
    }
}
