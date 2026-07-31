<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Supply requests. Any staff member can submit a request ("we're out of waters
 * at Pico"); managers see all requests, mark them Ordered and leave an ETA +
 * tracking, then mark Received. The employee sees the status, the date it was
 * ordered, and when it's coming right in the system.
 *
 * No migration: stored in storage/app/supply_requests.json (same pattern as the
 * supplies tracker and help-assistant config).
 */
class SupplyRequestController extends Controller
{
    const STORE_PATH = 'supply_requests.json';

    const STATUSES = [
        'pending'  => 'Requested',
        'ordered'  => 'Ordered',
        'received' => 'Received',
        'declined' => 'Declined',
    ];

    /* ---------- storage helpers ---------- */

    public static function readAll()
    {
        if (!Storage::exists(self::STORE_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::STORE_PATH), true);
        return is_array($data) ? $data : [];
    }

    private static function writeAll(array $items)
    {
        Storage::put(self::STORE_PATH, json_encode(array_values($items), JSON_PRETTY_PRINT));
    }

    private function managerGuard()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function locationsForUser()
    {
        $business_id = request()->session()->get('user.business_id');
        // Respect each user's permitted locations for the request form.
        return BusinessLocation::forDropdown($business_id);
    }

    private function allLocations()
    {
        $business_id = request()->session()->get('user.business_id');
        return BusinessLocation::forDropdown($business_id, false, false, false, false);
    }

    /* ---------- employee side ---------- */

    public function myRequests()
    {
        $userId = auth()->id();
        $all = self::readAll();

        $mine = array_values(array_filter($all, function ($r) use ($userId) {
            return ($r['requested_by_id'] ?? null) == $userId;
        }));
        // Newest first.
        usort($mine, function ($a, $b) {
            return strcmp($b['requested_at'] ?? '', $a['requested_at'] ?? '');
        });

        // Mark the user's updated requests as seen now that they're looking.
        $changed = false;
        foreach ($all as &$r) {
            if (($r['requested_by_id'] ?? null) == $userId && empty($r['seen_by_requester'])) {
                $r['seen_by_requester'] = true;
                $changed = true;
            }
        }
        unset($r);
        if ($changed) {
            self::writeAll($all);
        }

        return view('supply_requests.my', [
            'requests'  => $mine,
            'statuses'  => self::STATUSES,
            'locations' => $this->locationsForUser(),
        ]);
    }

    public function submit(Request $request)
    {
        $item = trim((string) $request->input('item', ''));
        if ($item === '') {
            return redirect()->action('SupplyRequestController@myRequests')
                ->with('status', ['success' => 0, 'msg' => 'Please enter what you need.']);
        }

        $locationId = $request->input('location_id') ?: null;
        $locationName = '';
        if ($locationId) {
            $locationName = (string) optional(BusinessLocation::find($locationId))->name;
        }

        $all = self::readAll();
        $all[] = [
            'id'                => round(microtime(true) * 1000),
            'item'              => mb_substr($item, 0, 200),
            'qty'               => mb_substr(trim((string) $request->input('qty', '')), 0, 100),
            'location_id'       => $locationId,
            'location_name'     => $locationName,
            'note'              => mb_substr(trim((string) $request->input('note', '')), 0, 500),
            'requested_by_id'   => auth()->id(),
            'requested_by_name' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            'status'            => 'pending',
            'requested_at'      => date('Y-m-d H:i'),
            'ordered_at'        => null,
            'eta'               => '',
            'tracking'          => '',
            'manager_note'      => '',
            'seen_by_requester' => true, // requester just submitted it
            'updated_at'        => date('Y-m-d H:i'),
        ];
        self::writeAll($all);

        return redirect()->action('SupplyRequestController@myRequests')
            ->with('status', ['success' => 1, 'msg' => 'Request submitted. A manager will order it.']);
    }

    /* ---------- manager side ---------- */

    public function admin(Request $request)
    {
        $this->managerGuard();

        $all = self::readAll();
        // Newest first within each group.
        usort($all, function ($a, $b) {
            return strcmp($b['requested_at'] ?? '', $a['requested_at'] ?? '');
        });

        // The active queue is only what still needs ordering (pending). Once a
        // manager marks something Ordered it drops out of the queue into the
        // collapsed "Ordered & done" list below - still editable there so they
        // can add tracking or mark it Received, and the requester still sees it.
        $open = array_values(array_filter($all, function ($r) {
            return ($r['status'] ?? 'pending') === 'pending';
        }));
        $done = array_values(array_filter($all, function ($r) {
            return ($r['status'] ?? 'pending') !== 'pending';
        }));

        return view('admin.supply_requests', [
            'requests' => $open,
            'done'     => $done,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request)
    {
        $this->managerGuard();

        $id = $request->input('id');
        $status = $request->input('status', 'pending');
        if (!array_key_exists($status, self::STATUSES)) {
            $status = 'pending';
        }

        $all = self::readAll();
        $found = false;
        foreach ($all as &$r) {
            if ((string) ($r['id'] ?? '') === (string) $id) {
                $found = true;
                $wasStatus = $r['status'] ?? 'pending';
                $r['status'] = $status;
                $r['eta'] = mb_substr(trim((string) $request->input('eta', '')), 0, 120);
                $r['tracking'] = mb_substr(trim((string) $request->input('tracking', '')), 0, 300);
                $r['manager_note'] = mb_substr(trim((string) $request->input('manager_note', '')), 0, 500);

                // Stamp the order date the first time it flips to Ordered.
                if ($status === 'ordered' && empty($r['ordered_at'])) {
                    $r['ordered_at'] = date('Y-m-d H:i');
                }
                // Any status/detail change is a new update the requester should see.
                if ($wasStatus !== $status || $request->filled('eta') || $request->filled('tracking')) {
                    $r['seen_by_requester'] = false;
                }
                $r['updated_at'] = date('Y-m-d H:i');
                break;
            }
        }
        unset($r);

        if (!$found) {
            return redirect()->action('SupplyRequestController@admin')
                ->with('status', ['success' => 0, 'msg' => 'Request not found.']);
        }

        self::writeAll($all);

        return redirect()->action('SupplyRequestController@admin')
            ->with('status', ['success' => 1, 'msg' => 'Request updated. The employee will see the change.']);
    }

    /* ---------- shared helpers ---------- */

    /**
     * Count of a user's requests that were updated by a manager (ordered /
     * received / etc.) and not yet seen. Drives the sidebar badge.
     */
    public static function unseenCountFor($userId)
    {
        $count = 0;
        foreach (self::readAll() as $r) {
            if (($r['requested_by_id'] ?? null) == $userId && empty($r['seen_by_requester'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Open requests (pending/ordered) summarized for the help assistant, so
     * staff can ask "did my water request get ordered yet?".
     */
    public static function formatForBot()
    {
        $open = array_filter(self::readAll(), function ($r) {
            return in_array($r['status'] ?? 'pending', ['pending', 'ordered']);
        });
        if (empty($open)) {
            return "(No open supply requests right now.)";
        }
        $lines = [];
        foreach ($open as $r) {
            $label = self::STATUSES[$r['status'] ?? 'pending'] ?? 'Requested';
            $line = '- ' . ($r['item'] ?? 'Item');
            if (!empty($r['location_name'])) {
                $line .= ' (' . $r['location_name'] . ')';
            }
            $line .= ': ' . $label;
            if (($r['status'] ?? '') === 'ordered') {
                if (!empty($r['ordered_at'])) {
                    $line .= ', ordered ' . $r['ordered_at'];
                }
                if (!empty($r['eta'])) {
                    $line .= ', arriving ' . $r['eta'];
                }
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
