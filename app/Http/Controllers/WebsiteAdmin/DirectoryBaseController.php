<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Carbon\Carbon;

/**
 * Shared helpers for the directory / community pages moved from the
 * nivessa.com admin into the ERP (Sarah, 2026-10-15).
 */
abstract class DirectoryBaseController extends Controller
{
    protected function guard(): void
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    /** Redirect back with the website's answer as a flash message. */
    protected function done(array $resp, string $ok)
    {
        if (!empty($resp['success'])) {
            return back()->with('status', $ok);
        }
        return back()->with('error', $resp['message'] ?? 'The website did not accept that change.');
    }

    /** nivessa.com root (no /api/v1), for files the API serves by path. */
    public static function siteRoot(): string
    {
        $base = trim((string) config('constants.nivessa_api')) ?: 'https://nivessa.com/api/v1';
        return preg_replace('#/api/v1/?$#', '', rtrim($base, '/'));
    }

    /** ISO timestamp shown in Pacific time, '' when missing. */
    public static function la($iso, string $fmt = 'M j, Y g:ia'): string
    {
        if (empty($iso)) {
            return '';
        }
        try {
            return Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format($fmt);
        } catch (\Throwable $e) {
            return (string) $iso;
        }
    }

    /** Website list payloads come back as {data:[...]} or a bare list. */
    protected static function rows(array $resp): array
    {
        $d = $resp['data'] ?? [];
        return is_array($d) ? array_values($d) : [];
    }

    protected static function call(string $method, string $path, array $body = null): array
    {
        return WebsiteBridge::admin($method, $path, $body);
    }
}
