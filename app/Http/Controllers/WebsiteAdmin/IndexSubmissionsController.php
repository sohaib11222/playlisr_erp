<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * Items submitted to The Nivessa Index, moved from the website's
 * /admin/index-submissions (Sarah, 2026-10-15).
 */
class IndexSubmissionsController extends DirectoryBaseController
{
    const LIMIT = 10;

    public function index(Request $request)
    {
        $this->guard();
        $status = in_array($request->input('status'), ['pending', 'published', 'rejected', 'all'], true) ? $request->input('status') : 'pending';
        $page = max(1, (int) $request->input('page', 1));
        $resp = self::call('GET', '/admin/index/submissions?' . http_build_query(['status' => $status, 'page' => $page, 'limit' => self::LIMIT]));

        return view('website_admin.directory.index_submissions', [
            'status'     => $status,
            'page'       => $page,
            'rows'       => self::rows($resp),
            'totalPages' => max(1, (int) ($resp['pagination']['totalPages'] ?? 1)),
            'total'      => (int) ($resp['pagination']['total'] ?? 0),
            'error'      => empty($resp['success']) ? ($resp['message'] ?? 'Could not load Index submissions.') : null,
        ]);
    }

    public function approve(string $id)
    {
        $this->guard();
        return $this->done(self::call('PUT', '/admin/index/' . rawurlencode($id) . '/approve', []), 'Published to The Nivessa Index.');
    }

    public function reject(Request $request, string $id)
    {
        $this->guard();
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return back()->with('error', 'A reason is required to reject.');
        }
        return $this->done(self::call('PUT', '/admin/index/' . rawurlencode($id) . '/reject', ['reason' => $reason]), 'Submission rejected.');
    }
}
