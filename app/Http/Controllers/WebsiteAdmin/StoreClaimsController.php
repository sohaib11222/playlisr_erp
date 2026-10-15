<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * Store ownership claims + every claim submit attempt, moved from the
 * website's /admin/store-claims (Sarah, 2026-10-15).
 */
class StoreClaimsController extends DirectoryBaseController
{
    const LIMIT = 20;

    public function index(Request $request)
    {
        $this->guard();
        $view = $request->input('view') === 'attempts' ? 'attempts' : 'claims';
        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected'], true) ? $request->input('status') : '';
        $page = max(1, (int) $request->input('page', 1));

        // Claims always load: their counts drive the filter pills.
        $claimsPage = $view === 'claims' ? $page : 1;
        $claims = self::call('GET', '/store-claims/admin/all?' . http_build_query(array_filter([
            'page' => $claimsPage, 'limit' => self::LIMIT, 'status' => $status,
        ])));
        $attempts = null;
        if ($view === 'attempts') {
            $attempts = self::call('GET', '/store-claims/admin/attempts?' . http_build_query(['page' => $page, 'limit' => self::LIMIT]));
        }
        $counts = (array) ($claims['counts'] ?? []) + ['pending' => 0, 'approved' => 0, 'rejected' => 0];

        return view('website_admin.directory.claims', [
            'view'          => $view,
            'status'        => $status,
            'page'          => $page,
            'limit'         => self::LIMIT,
            'claims'        => self::rows($claims),
            'claimsTotal'   => (int) ($claims['totalCount'] ?? 0),
            'claimsError'   => empty($claims['success']) ? ($claims['message'] ?? 'Could not load claims.') : null,
            'counts'        => $counts,
            'attempts'      => $attempts ? self::rows($attempts) : [],
            'attemptsTotal' => $attempts ? (int) ($attempts['totalCount'] ?? 0) : 0,
            'attemptsError' => ($attempts && empty($attempts['success'])) ? ($attempts['message'] ?? 'Could not load attempts.') : null,
            'siteRoot'      => self::siteRoot(),
        ]);
    }

    public function approve(string $id)
    {
        $this->guard();
        return $this->done(self::call('PATCH', '/store-claims/admin/approve/' . rawurlencode($id), []), 'Claim approved. The store is now verified.');
    }

    public function reject(Request $request, string $id)
    {
        $this->guard();
        $reason = trim((string) $request->input('reason', ''));
        return $this->done(self::call('PATCH', '/store-claims/admin/reject/' . rawurlencode($id), ['reason' => $reason]), 'Claim rejected.');
    }

    public function destroy(string $id)
    {
        $this->guard();
        return $this->done(self::call('DELETE', '/store-claims/admin/' . rawurlencode($id)), 'Claim deleted.');
    }
}
