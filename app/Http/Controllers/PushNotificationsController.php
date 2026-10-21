<?php

namespace App\Http\Controllers;

use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * App Notifications (Sarah, 2026-10-20): send a phone notification to every
 * customer who turned on notifications in the nivessa.com app. Sending and
 * the device list live on the website (/api/v1/erp/push/*); order updates
 * (shipped, ready for pickup, delivered) go out automatically from there.
 */
class PushNotificationsController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }
        $resp = WebsiteBridge::erp('GET', '/push/stats');
        return view('push_notifications.index', [
            'error' => empty($resp['success']) ? ($resp['message'] ?? 'Could not reach the website.') : null,
            'devices' => (int) ($resp['devices'] ?? 0),
            'loggedIn' => (int) ($resp['loggedIn'] ?? 0),
            'history' => $resp['history'] ?? [],
        ]);
    }

    public function send(Request $request)
    {
        if (!auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }
        $data = $request->validate([
            'title' => 'required|string|max:80',
            'body' => 'required|string|max:200',
            'url' => 'nullable|string|max:300',
            'confirm' => 'required|in:SEND',
        ], [
            'confirm.in' => 'Type SEND to confirm.',
            'confirm.required' => 'Type SEND to confirm.',
        ]);
        $resp = WebsiteBridge::erp('POST', '/push/send', [
            'title' => $data['title'],
            'body' => $data['body'],
            'url' => $data['url'] ?: '/',
            'sentBy' => WebsiteBridge::actor(),
        ], 120);
        if (empty($resp['success'])) {
            return back()->withInput()->with('status', ['success' => 0, 'msg' => $resp['message'] ?? 'Send failed.']);
        }
        return redirect('/push-notifications')->with('status', [
            'success' => 1,
            'msg' => 'Sent to ' . (int) ($resp['delivered'] ?? 0) . ' of ' . (int) ($resp['targeted'] ?? 0) . ' devices.',
        ]);
    }
}
