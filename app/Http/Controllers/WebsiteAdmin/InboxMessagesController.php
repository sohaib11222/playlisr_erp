<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Contact form messages and Buy / Sell queries from nivessa.com, ported from
 * the website's /admin/contacts and /admin/buy-sell-queries (Sarah, 2026-10-15).
 */
class InboxMessagesController extends Controller
{
    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    /** The website answers 404 "No messages found." for an empty inbox. */
    protected static function listOrError(array $resp): array
    {
        if (!empty($resp['success'])) {
            return [is_array($resp['data'] ?? null) ? $resp['data'] : [], null];
        }
        if (($resp['_http'] ?? 0) === 404) {
            return [[], null];
        }
        return [[], $resp['message'] ?? 'Could not load messages.'];
    }

    protected static function sortByDate(array $rows, string $dir): array
    {
        usort($rows, function ($a, $b) use ($dir) {
            $x = strtotime($a['createdAt'] ?? '') ?: 0;
            $y = strtotime($b['createdAt'] ?? '') ?: 0;
            return $dir === 'asc' ? $x <=> $y : $y <=> $x;
        });
        return $rows;
    }

    public function messages(Request $request)
    {
        $this->guard();
        $sort = $request->query('sort') === 'asc' ? 'asc' : 'desc';
        list($rows, $error) = self::listOrError(WebsiteBridge::admin('GET', '/customer/messages'));

        return view('website_admin.inbox.messages', [
            'messages' => self::sortByDate($rows, $sort),
            'sort' => $sort,
            'bridgeError' => $error,
        ]);
    }

    /** Saves the reply and the website emails it to the customer, same as before. */
    public function reply(Request $request, $id)
    {
        $this->guard();
        $reply = trim((string) $request->input('reply', ''));
        if ($reply === '') {
            return back()->with('error', 'Please enter a reply message.');
        }
        $staffName = trim((string) (auth()->user()->first_name ?? ''));
        $resp = WebsiteBridge::admin('PATCH', '/contact/reply/' . rawurlencode($id), ['reply' => $reply, 'staffName' => $staffName], 30);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to send reply.');
        }
        return back()->with('status', 'Reply emailed to the customer.');
    }

    public function status(Request $request, $id)
    {
        $this->guard();
        $status = (string) $request->input('status');
        if (!in_array($status, ['new', 'replied', 'archived'], true)) {
            return back()->with('error', 'Unknown status.');
        }
        $resp = WebsiteBridge::admin('PATCH', '/contact/status/' . rawurlencode($id), ['status' => $status]);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to update status.');
        }
        return back()->with('status', 'Message marked as ' . $status . '.');
    }

    public function testEmail(Request $request)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('POST', '/contact/test-email', [], 30);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to send test email.');
        }
        return back()->with('status', $resp['message'] ?? 'Test email sent to hello@nivessa.com.');
    }

    public function buySell(Request $request)
    {
        $this->guard();
        list($rows, $error) = self::listOrError(WebsiteBridge::admin('GET', '/customer/categoryMessages'));

        return view('website_admin.inbox.buy_sell', [
            'messages' => self::sortByDate($rows, 'desc'),
            'bridgeError' => $error,
        ]);
    }
}
