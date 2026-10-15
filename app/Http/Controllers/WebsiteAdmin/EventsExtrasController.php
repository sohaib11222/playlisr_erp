<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\EventsController;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * The few website /admin/events, /admin/rsvps and /admin/preorders actions
 * the ERP Events pages didn't have yet (Sarah, 2026-10-15): publish/hide,
 * image upload, delete an RSVP, add a named guest, RSVP CSV, preorder
 * paid/unpaid and internal note. Everything else already lives on the
 * ERP Events pages (EventsController), which stay the source of truth.
 */
class EventsExtrasController extends DirectoryBaseController
{
    /** Publish / hide an event on nivessa.com ($id = the ERP event id). */
    public function published(Request $request, string $id)
    {
        $this->guard();
        $published = filter_var($request->input('published'), FILTER_VALIDATE_BOOLEAN);
        return $this->done(
            self::call('PATCH', '/events/' . rawurlencode($id) . '/published', ['published' => $published]),
            $published ? 'Event published on nivessa.com.' : 'Event hidden from nivessa.com.'
        );
    }

    /** Uploads an event image to the website's image host; answers {success, url}. */
    public function uploadImage(Request $request)
    {
        $this->guard();
        $file = $request->file('image');
        if (!$file || !$file->isValid()) {
            return response()->json(['success' => false, 'message' => 'Pick an image file first.'], 422);
        }
        if (strpos((string) $file->getMimeType(), 'image/') !== 0) {
            return response()->json(['success' => false, 'message' => 'That file is not an image.'], 422);
        }
        $resp = WebsiteBridge::adminUpload('POST', '/upload/venue-image', [], ['image' => $file]);
        $url = (string) ($resp['data']['path'] ?? '');
        if (empty($resp['success']) || $url === '') {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Upload failed.'], 502);
        }
        return response()->json(['success' => true, 'url' => $url]);
    }

    public function rsvpDelete(string $rsvpId)
    {
        $this->guard();
        return $this->done(self::call('DELETE', '/events/rsvp/' . rawurlencode($rsvpId)), 'RSVP deleted.');
    }

    public function rsvpAddGuest(Request $request, string $rsvpId)
    {
        $this->guard();
        $email = strtolower(trim((string) $request->input('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'Enter a valid guest email.');
        }
        return $this->done(
            self::call('POST', '/events/rsvp/' . rawurlencode($rsvpId) . '/add-guest', [
                'email' => $email,
                'wantsFutureEvents' => filter_var($request->input('wantsFutureEvents'), FILTER_VALIDATE_BOOLEAN),
            ]),
            'Guest added.'
        );
    }

    /** Every RSVP (and named guest) for one event, same columns as the website's Export CSV. */
    public function rsvpsCsv(Request $request, string $id)
    {
        $this->guard();
        $ev = EventsController::load((int) $request->session()->get('user.business_id'))['items'][$id] ?? null;
        if (!$ev) {
            return redirect()->route('events.index')->with('error', 'Event not found.');
        }
        // Id and name both, like the event page: an older website ignores eventId.
        $params = ['limit' => 10000, 'eventId' => $id];
        if (trim((string) ($ev['name'] ?? '')) !== '') {
            $params['eventName'] = $ev['name'];
        }
        $resp = WebsiteBridge::erp('GET', '/rsvps?' . http_build_query($params), null, 30);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Could not load RSVPs from the website.');
        }
        $rsvps = $resp['data'] ?? $resp['rsvps'] ?? [];
        if (isset($rsvps['rsvps']) && is_array($rsvps['rsvps'])) {
            $rsvps = $rsvps['rsvps'];
        }

        $day = fn($d) => $d ? self::la($d, 'Y-m-d') : '';
        // Keep spreadsheet apps from running a cell as a formula.
        $put = function ($out, array $row) {
            fputcsv($out, array_map(fn($c) => (is_string($c) && preg_match('/^[=+\-@]/', $c)) ? "'" . $c : $c, $row));
        };
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Name', 'Email', 'Phone', 'Event', 'Attendance', 'Guests', 'Newsletter Opt-in', 'Listening Interest', 'Checked In', 'Date', 'Type']);
        foreach ((array) $rsvps as $r) {
            $name = trim((string) ($r['name'] ?? '')) ?: trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? ''));
            $put($out, [
                $name, $r['email'] ?? '', $r['phone'] ?? '', $r['eventName'] ?? '', $r['attendance'] ?? '',
                (int) ($r['guests'] ?? 0), !empty($r['subscribeToNewsletter']) ? 'yes' : 'no',
                is_array($r['interestedInPurchase'] ?? null) ? implode(', ', $r['interestedInPurchase']) : ($r['interestedInPurchase'] ?? ''),
                !empty($r['checkedIn']) ? 'yes' : 'no', $day($r['createdAt'] ?? ''), 'Primary',
            ]);
            foreach ((array) ($r['additionalGuests'] ?? []) as $g) {
                $put($out, [
                    trim(($g['firstName'] ?? '') . ' ' . ($g['lastName'] ?? '')) ?: 'Guest', $g['email'] ?? '', '',
                    $r['eventName'] ?? '', $r['attendance'] ?? '', '', !empty($g['wantsFutureEvents']) ? 'yes' : 'no',
                    '', !empty($g['checkedIn']) ? 'yes' : 'no', $day($r['createdAt'] ?? ''),
                    'Guest of ' . ($r['firstName'] ?? ($r['name'] ?? '')),
                ]);
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($ev['name'] ?? 'event'))), '-') ?: 'event';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rsvps-' . $slug . '-' . date('Y-m-d') . '.csv"',
        ]);
    }

    public function preorderPaid(Request $request, string $preorderId)
    {
        $this->guard();
        $paid = filter_var($request->input('paid'), FILTER_VALIDATE_BOOLEAN);
        return $this->done(
            WebsiteBridge::erp('PATCH', '/preorders/' . rawurlencode($preorderId) . '/paid', ['paid' => $paid, 'changedBy' => WebsiteBridge::actor()]),
            $paid ? 'Preorder marked paid.' : 'Preorder marked unpaid.'
        );
    }

    public function preorderNotes(Request $request, string $preorderId)
    {
        $this->guard();
        $note = mb_substr(trim((string) $request->input('adminNotes', '')), 0, 2000);
        return $this->done(
            WebsiteBridge::erp('PATCH', '/preorders/' . rawurlencode($preorderId) . '/admin-notes', ['adminNotes' => $note]),
            'Note saved.'
        );
    }
}
