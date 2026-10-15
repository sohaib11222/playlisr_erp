<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * "Ask Nivessa" chat conversations from the on-site helper, ported from the
 * website's /admin/conversations (Sarah, 2026-10-15).
 */
class ChatsController extends Controller
{
    const STATUSES = ['new', 'reviewed', 'archived'];

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->guard();
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : '';
        $page = max(1, (int) $request->query('page', 1));
        $limit = 20;

        $q = 'page=' . $page . '&limit=' . $limit . ($status !== '' ? '&status=' . $status : '');
        $resp = WebsiteBridge::admin('GET', '/chat-conversations/admin/all?' . $q);
        $totalCount = (int) ($resp['totalCount'] ?? 0);

        return view('website_admin.inbox.chats', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load conversations.') : null,
            'conversations' => $resp['data'] ?? [],
            'counts' => $resp['counts'] ?? [],
            'totalCount' => $totalCount,
            'totalPages' => max(1, (int) ceil($totalCount / $limit)),
            'status' => $status,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** Form post, or JSON when opening a New chat marks it Reviewed. */
    public function status(Request $request, $id)
    {
        $this->guard();
        $status = (string) $request->input('status');
        if (!in_array($status, self::STATUSES, true)) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Unknown status.'])
                : back()->with('error', 'Unknown status.');
        }
        $resp = WebsiteBridge::admin('PATCH', '/chat-conversations/admin/status/' . rawurlencode($id), ['status' => $status]);
        $ok = !empty($resp['success']);
        if ($request->expectsJson()) {
            return response()->json(['success' => $ok, 'message' => $ok ? null : ($resp['message'] ?? 'Failed to update status.')]);
        }
        return $ok
            ? back()->with('status', 'Marked ' . $status . '.')
            : back()->with('error', $resp['message'] ?? 'Failed to update status.');
    }

    public function destroy(Request $request, $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('DELETE', '/chat-conversations/admin/' . rawurlencode($id));
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to delete.');
        }
        return back()->with('status', 'Conversation deleted.');
    }
}
