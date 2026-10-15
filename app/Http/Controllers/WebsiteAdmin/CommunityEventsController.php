<?php

namespace App\Http\Controllers\WebsiteAdmin;

use Illuminate\Http\Request;

/**
 * Community-submitted events (the public "submit your event" form), moved
 * from the website's /admin/community-events (Sarah, 2026-10-15). These are
 * separate from Nivessa's own events, which live on the ERP Events page.
 */
class CommunityEventsController extends DirectoryBaseController
{
    const LIMIT = 20;

    public function index(Request $request)
    {
        $this->guard();
        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected'], true) ? $request->input('status') : '';
        $page = max(1, (int) $request->input('page', 1));
        $resp = self::call('GET', '/community-events/all?' . http_build_query(array_filter(['page' => $page, 'limit' => self::LIMIT, 'status' => $status])));

        return view('website_admin.directory.community_events', [
            'status' => $status,
            'page'   => $page,
            'limit'  => self::LIMIT,
            'rows'   => self::rows($resp),
            'pages'  => (int) ceil(((int) ($resp['totalCount'] ?? 0)) / self::LIMIT),
            'counts' => (array) ($resp['counts'] ?? []) + ['pending' => 0, 'approved' => 0, 'rejected' => 0],
            'error'  => empty($resp['success']) ? ($resp['message'] ?? 'Could not load community events.') : null,
        ]);
    }

    public function approve(string $id)
    {
        $this->guard();
        return $this->done(self::call('PATCH', '/community-events/approve/' . rawurlencode($id), []), 'Event approved. It now shows on the site.');
    }

    public function reject(Request $request, string $id)
    {
        $this->guard();
        $reason = trim((string) $request->input('reason', ''));
        return $this->done(self::call('PATCH', '/community-events/reject/' . rawurlencode($id), ['reason' => $reason]), 'Event rejected.');
    }

    public function destroy(string $id)
    {
        $this->guard();
        return $this->done(self::call('DELETE', '/community-events/delete/' . rawurlencode($id)), 'Event deleted.');
    }
}
