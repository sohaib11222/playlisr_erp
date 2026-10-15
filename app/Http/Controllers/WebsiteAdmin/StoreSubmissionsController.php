<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * Review queue for record stores people submit to the directory, moved
 * from the website's /admin/store-submissions (Sarah, 2026-10-15).
 */
class StoreSubmissionsController extends DirectoryBaseController
{
    public function index(Request $request)
    {
        $this->guard();
        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected', 'all'], true) ? $request->input('status') : 'pending';
        $resp = self::call('GET', '/admin/record-stores/submissions' . ($status !== 'all' ? '?status=' . $status : ''));

        return view('website_admin.directory.submissions', [
            'status'  => $status,
            'stores'  => self::rows($resp),
            'error'   => empty($resp['success']) ? ($resp['message'] ?? 'Could not load submissions.') : null,
        ]);
    }

    public function approve(string $id)
    {
        $this->guard();
        return $this->done(self::call('PUT', '/admin/record-stores/' . rawurlencode($id) . '/approve', []), 'Store approved. It is now in the directory.');
    }

    public function reject(Request $request, string $id)
    {
        $this->guard();
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return back()->with('error', 'Please give a reason for rejecting this store.');
        }
        return $this->done(self::call('PUT', '/admin/record-stores/' . rawurlencode($id) . '/reject', ['reason' => $reason]), 'Store rejected.');
    }

    public function destroy(string $id)
    {
        $this->guard();
        return $this->done(self::call('DELETE', '/admin/record-stores/' . rawurlencode($id)), 'Store submission deleted.');
    }
}
