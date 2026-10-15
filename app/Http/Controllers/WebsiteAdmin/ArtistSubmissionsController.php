<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * Artist profiles people submit to nivessa.com, moved from the website's
 * /admin/artist-submissions (Sarah, 2026-10-15).
 */
class ArtistSubmissionsController extends DirectoryBaseController
{
    const LIMIT = 10;

    public function index(Request $request)
    {
        $this->guard();
        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected', 'all'], true) ? $request->input('status') : 'pending';
        $page = max(1, (int) $request->input('page', 1));
        $resp = self::call('GET', '/admin/artists/submissions?' . http_build_query(['status' => $status, 'page' => $page, 'limit' => self::LIMIT]));

        return view('website_admin.directory.artist_submissions', [
            'status'     => $status,
            'page'       => $page,
            'rows'       => self::rows($resp),
            'totalPages' => max(1, (int) ($resp['pagination']['totalPages'] ?? 1)),
            'total'      => (int) ($resp['pagination']['total'] ?? 0),
            'error'      => empty($resp['success']) ? ($resp['message'] ?? 'Could not load artist submissions.') : null,
        ]);
    }

    public function approve(string $id)
    {
        $this->guard();
        return $this->done(self::call('PUT', '/admin/artists/' . rawurlencode($id) . '/approve', []), 'Artist approved.');
    }

    public function reject(Request $request, string $id)
    {
        $this->guard();
        $reason = trim((string) $request->input('reason', ''));
        return $this->done(self::call('PUT', '/admin/artists/' . rawurlencode($id) . '/reject', ['rejection_reason' => $reason]), 'Artist rejected.');
    }
}
