<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\EventsController;
use App\Services\WebsiteBridge;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Listening party prep board: every upcoming party's checklist on one page,
 * the ERP version of the website's /admin/listening-party-prep (Sarah,
 * 2026-10-15). Reads and writes the ERP events store (EventsController's
 * JSON, the source of truth the website reads), same fields and merge
 * rules as the per-event prep box on the event page.
 */
class ListeningPartyPrepController extends DirectoryBaseController
{
    /** Guidance bullets from the website's lib/listeningPartyPrep.js, keyed by item id. */
    const SUB = [
        'social_post_scheduled' => [
            'Timing matters. We cannot announce it earlier than RSD does.',
            'Schedule posts in Pacific time.',
        ],
        'box_tracked_confirmed' => [
            'Verify receipt with one employee at each participating store.',
            'Ask them to label each box with the event name and event date.',
        ],
        'rules_confirmed_with_host' => [
            'Giveaway procedures',
            'Preorder procedures if applicable',
            'Purchase and release-day rules',
            'Any special instructions from the label or Carrie',
        ],
        'link_confirmed_working' => [
            'Confirm audio works properly.',
            'Do not wait until guests have arrived to test the link.',
        ],
    ];
    const EMPHASIS = ['link_shared_with_host'];

    public function index(Request $request)
    {
        $this->guard();
        $businessId = (int) $request->session()->get('user.business_id');
        $showPast = $request->boolean('past');
        $today = Carbon::now('America/Los_Angeles')->startOfDay();
        $todayStr = $today->format('Y-m-d');

        $parties = array_values(array_filter(
            EventsController::load($businessId)['items'],
            function ($e) use ($showPast, $todayStr) {
                if (($e['eventType'] ?? 'listening_party') !== 'listening_party') {
                    return false;
                }
                $d = (string) ($e['date'] ?? '');
                return $showPast || $d === '' || $d >= $todayStr;
            }
        ));
        // Soonest first; undated last.
        usort($parties, function ($a, $b) {
            $da = ($a['date'] ?? '') ?: '9999-99-99';
            $db = ($b['date'] ?? '') ?: '9999-99-99';
            return strcmp($da . ($a['time'] ?? ''), $db . ($b['time'] ?? ''));
        });

        // Headcount: the event's own RSVP list for parties in the next two
        // weeks (the bulk stats can lag, as on the Events page); the bulk
        // stats for the rest, which keeps the page to a few website calls.
        $attending = [];
        $events = app(EventsController::class);
        $bulk = null;
        $soonStr = $today->copy()->addDays(14)->format('Y-m-d');
        foreach ($parties as $p) {
            $pid = (string) ($p['id'] ?? '');
            $d = (string) ($p['date'] ?? '');
            if ($d !== '' && $d >= $todayStr && $d <= $soonStr) {
                $live = $events->liveEventCounts((string) ($p['name'] ?? ''), $pid ?: null);
                if ($live !== null) {
                    $attending[$pid] = (int) $live['attending'];
                    continue;
                }
            }
            if ($bulk === null) {
                $bulk = [];
                $stats = WebsiteBridge::erp('GET', '/rsvps/stats');
                foreach ((array) ($stats['data'] ?? $stats['stats'] ?? []) as $row) {
                    $k = EventsController::normName($row['eventName'] ?? '');
                    if ($k !== '') {
                        $bulk[$k] = ($bulk[$k] ?? 0) + (int) ($row['attendingCount'] ?? $row['totalAttendees'] ?? 0);
                    }
                }
            }
            $k = EventsController::normName($p['name'] ?? '');
            if (isset($bulk[$k])) {
                $attending[$pid] = $bulk[$k];
            }
        }

        return view('website_admin.directory.listening_party_prep', [
            'parties'   => $parties,
            'showPast'  => $showPast,
            'today'     => $today,
            'attending' => $attending,
            'sub'       => self::SUB,
            'emphasis'  => self::EMPHASIS,
        ]);
    }

    /** Merge checklist items and/or detail fields into one event (same rules as EventsController@updatePrep). */
    public function save(Request $request, string $id)
    {
        $this->guard();
        $businessId = (int) $request->session()->get('user.business_id');
        $data = EventsController::load($businessId);
        if (!isset($data['items'][$id])) {
            return $this->reply($request, false, 'Event not found.');
        }

        $ev = $data['items'][$id];
        $checklist = (array) ($ev['prepChecklist'] ?? []);
        $details = (array) ($ev['prepDetails'] ?? []);
        $now = date('c');
        $actor = WebsiteBridge::actor() ?: 'staff';

        $validIds = array_column(EventsController::prepItems(), 'id');
        foreach ((array) $request->input('checklist', []) as $itemId => $val) {
            if (!in_array($itemId, $validIds, true) || !is_array($val)) {
                continue;
            }
            $prev = (array) ($checklist[$itemId] ?? []);
            $checklist[$itemId] = [
                'done'      => filter_var($val['done'] ?? ($prev['done'] ?? false), FILTER_VALIDATE_BOOLEAN),
                'note'      => mb_substr(trim((string) ($val['note'] ?? ($prev['note'] ?? ''))), 0, 2000),
                'updatedBy' => $actor,
                'updatedAt' => $now,
            ];
        }

        $incomingDetails = (array) $request->input('details', []);
        foreach (['eventHost', 'eventHostHollywood', 'eventHostPico', 'eventLink', 'boxTracking', 'boxLocation'] as $k) {
            if (array_key_exists($k, $incomingDetails)) {
                $details[$k] = mb_substr(trim((string) $incomingDetails[$k]), 0, 2000);
            }
        }
        if (!empty($incomingDetails)) {
            $details['updatedBy'] = $actor;
            $details['updatedAt'] = $now;
        }

        $data['items'][$id]['prepChecklist'] = $checklist ?: new \stdClass();
        $data['items'][$id]['prepDetails'] = $details ?: new \stdClass();
        $data['items'][$id]['updatedAt'] = $now;
        EventsController::save($businessId, $data);

        return $this->reply($request, true, 'Prep saved.', [
            'by' => $actor,
            'at' => Carbon::parse($now)->setTimezone('America/Los_Angeles')->format('M j, g:ia'),
        ]);
    }

    protected function reply(Request $request, bool $ok, string $message, array $extra = [])
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => $ok, 'message' => $message] + $extra, $ok ? 200 : 404);
        }
        return back()->with($ok ? 'status' : 'error', $message);
    }
}
