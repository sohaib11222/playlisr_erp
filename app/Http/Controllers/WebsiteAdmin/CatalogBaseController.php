<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;

/**
 * Shared helpers for the website catalog pages moved into the ERP
 * (products, categories, collections, featured lists, gift cards).
 * (Sarah, 2026-10-15)
 */
abstract class CatalogBaseController extends Controller
{
    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Some website handlers answer without a success flag, so the bridge
     * wraps them as {success, data: <raw>}. This digs the real payload out.
     */
    protected function payload(array $resp, string $key = 'data')
    {
        $v = $resp[$key] ?? null;
        if (is_array($v) && !array_key_exists(0, $v) && array_key_exists($key, $v)) {
            return $v[$key];
        }
        return $v;
    }

    protected function failMessage(array $resp, string $fallback): string
    {
        $m = trim((string) ($resp['message'] ?? ''));
        return $m !== '' ? $m : $fallback;
    }

    protected function bridge(string $method, string $path, array $body = null, int $timeout = 20): array
    {
        return WebsiteBridge::admin($method, $path, $body, $timeout);
    }

    /** JSON reply for the inline fetch() toggles. */
    protected function jsonResult(array $resp, string $okMessage, string $failMessage)
    {
        if (!empty($resp['success'])) {
            return response()->json(['success' => true, 'message' => $okMessage, 'data' => $resp['data'] ?? null]);
        }
        return response()->json(['success' => false, 'message' => $this->failMessage($resp, $failMessage)], 422);
    }

    /** Website image paths are often relative or on old hosts; make them loadable from the ERP. */
    public static function img($url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?(/.*)$#i', $url, $m)) {
            return 'https://nivessa.com' . $m[3];
        }
        if (stripos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }
        if (stripos($url, 'https://') === 0 || stripos($url, 'data:') === 0) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return 'https://nivessa.com/' . ltrim($url, '/');
    }

    /** Upload one image through the website's /upload/image route; returns the URL or null. */
    protected function uploadImage($file, &$error = null)
    {
        if (!$file) {
            return null;
        }
        $resp = WebsiteBridge::adminUpload('POST', '/upload/image', [], ['image' => $file]);
        if (!empty($resp['success'])) {
            $url = $resp['data']['url'] ?? ($resp['url'] ?? null);
            if ($url) {
                return $url;
            }
        }
        $error = $this->failMessage($resp, 'The image did not upload.');
        return null;
    }
}
