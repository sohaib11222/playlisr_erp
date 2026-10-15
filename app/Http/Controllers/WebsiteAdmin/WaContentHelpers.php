<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Services\WebsiteBridge;

/**
 * Shared bits for the website content/settings/users pages moved into the
 * ERP (Sarah, 2026-10-15).
 */
trait WaContentHelpers
{
    protected function guardStaff()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected function guardAdmin()
    {
        if (!auth()->user()->hasRole('Admin#' . session('business.id'))) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * A key from a bridge response. Handlers that answer without a success
     * flag get wrapped as {success, data: <raw>}, so look in both places.
     */
    protected function pick(array $resp, string $key, $default = null)
    {
        if (array_key_exists($key, $resp)) {
            return $resp[$key];
        }
        if (isset($resp['data']) && is_array($resp['data']) && array_key_exists($key, $resp['data'])) {
            return $resp['data'][$key];
        }
        return $default;
    }

    /** The list a bridge response carries, whatever key it uses. */
    protected function listFrom(array $resp, array $keys = ['data', 'items', 'results', 'rows'])
    {
        foreach ($keys as $k) {
            $v = $this->pick($resp, $k);
            if (is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1))) {
                return $v;
            }
        }
        return [];
    }

    protected function bridgeBack(array $resp, string $ok, string $fallbackError = 'The website did not accept that.')
    {
        if (!empty($resp['success'])) {
            return back()->with('status', $ok);
        }
        return back()->withInput()->with('error', $resp['message'] ?? $fallbackError);
    }

    protected function jsonFrom(array $resp, int $okCode = 200)
    {
        unset($resp['_http']);
        return response()->json($resp, !empty($resp['success']) ? $okCode : 422);
    }

    /** "a, b , ,c" -> ["a","b","c"] */
    protected function csvList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
    }

    /** Uploads one image to the website and returns its URL (or null + message). */
    protected function uploadImageTo(string $path, $file): array
    {
        if (!$file || !$file->isValid()) {
            return ['success' => false, 'message' => 'Pick an image file.'];
        }
        if (strpos((string) $file->getMimeType(), 'image/') !== 0) {
            return ['success' => false, 'message' => 'That file is not an image.'];
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Images must be under 5 MB.'];
        }
        $resp = WebsiteBridge::adminUpload('POST', $path, [], ['image' => $file]);
        if (empty($resp['success'])) {
            return ['success' => false, 'message' => $resp['message'] ?? 'Upload failed.'];
        }
        $url = $resp['url'] ?? ($resp['data']['url'] ?? null);
        return $url ? ['success' => true, 'url' => $url] : ['success' => false, 'message' => 'The website did not return an image URL.'];
    }

    /** Website-relative image paths, made viewable from the ERP. */
    public static function siteImage($path): string
    {
        $path = (string) $path;
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (strpos($path, '/imageNiv/blogs/') === 0) {
            return 'https://nivessa.com/blog-img/' . substr($path, strlen('/imageNiv/blogs/'));
        }
        return 'https://nivessa.com' . ($path[0] === '/' ? '' : '/') . $path;
    }

    /** Date for display in Pacific time. */
    public static function fmtDate($iso, string $format = 'M j, Y'): string
    {
        if (!$iso) {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format($format);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
