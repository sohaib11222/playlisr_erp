<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Evening closing checklist for Hollywood. Mirrors the opening checklist: the
 * closer works the list in order, checks each step off, and submits it. We log
 * who closed, when, and anything left undone so a manager can confirm the store
 * was shut down and locked up properly.
 *
 * No migration: stored in storage/app/closing_checklist.json (same JSON sidecar
 * pattern as the opening checklist). Renders the shared checklist view.
 *
 * To add or reword a step, edit the GROUPS array below. Keys are stable.
 */
class ClosingChecklistController extends Controller
{
    const STORE_PATH = 'closing_checklist.json';

    /** The Hollywood closing procedure, grouped in the order you close up. */
    const GROUPS = [
        '1. Tidy the floor' => [
            'tidy'       => 'Tidy up the sales floor and make sure all bins look organized.',
            'endcaps'    => 'Refill any records that sold from the end caps so the featured albums stay visible.',
            'front_desk' => 'Remove any clutter from the front desk so it stays inviting.',
            'floor'      => 'Sweep or vacuum the floor so the store is clean for the next day.',
        ],
        '2. Bathroom & trash' => [
            'bathroom'  => 'Check the bathroom: make sure it is tidy, the light is off, and the trash is emptied.',
            'trash_all' => 'Empty all the trash bins and dispose of any trash around the store.',
        ],
        '3. Power down' => [
            'computer' => 'Shut down the computer at the front desk.',
            'receiver' => 'Put the receiver on its lowest volume and click "Mute."',
            'ac'       => 'Make sure the AC is off.',
            'scent'    => 'Make sure the scent purifier is off.',
        ],
        '4. Unplug the neon signs' => [
            'sign_diggers' => 'Unplug the "Welcome to Digger\'s Paradise" sign by pulling the plug behind the listening station.',
            'sign_vinyl'   => 'Unplug the "Have you heard it on vinyl" sign from behind the rock bins.',
            'sign_disco'   => 'Unplug the "El disco es la cultura" sign from on stage.',
        ],
        '5. Lock up and leave' => [
            'aframe'     => 'Bring in the A-frame if it is outside.',
            'lights'     => 'Turn off all the lights in the store, and make sure the bathroom light is off.',
            'front_door' => 'Close and lock the front door with the two locks at the bottom, then let down the gate using the buttons on the right wall of the store (hold the arrow button down to lower it).',
            'lockbox'    => 'If you used the lockbox key, put it back in the lockbox at the front of the gate and scramble the code.',
            'back_door'  => 'Exit through the back door. Make sure it is locked before you leave, since people in the neighborhood will come in looking for a place to crash if it is left open.',
        ],
    ];

    /** Action link shown next to specific items (keyed by item key). */
    const LINKS = [
        'endcaps' => ['url' => '/reports/abc-full-report?class=A', 'text' => 'View A products'],
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

    public static function allItems()
    {
        $flat = [];
        foreach (self::GROUPS as $items) {
            foreach ($items as $key => $label) {
                $flat[$key] = $label;
            }
        }
        return $flat;
    }

    private function locations()
    {
        $business_id = request()->session()->get('user.business_id');
        return BusinessLocation::forDropdown($business_id);
    }

    /* ---------- page ---------- */

    public function index()
    {
        $all = self::readAll();

        usort($all, function ($a, $b) {
            return strcmp($b['completed_at'] ?? '', $a['completed_at'] ?? '');
        });
        $recent = array_slice($all, 0, 20);

        $today = date('Y-m-d');
        $doneToday = array_values(array_filter($all, function ($r) use ($today) {
            return ($r['date'] ?? '') === $today;
        }));

        return view('checklist.index', [
            'groups'      => self::GROUPS,
            'links'       => self::LINKS,
            'totalItems'  => count(self::allItems()),
            'locations'   => $this->locations(),
            'recent'      => $recent,
            'doneToday'   => $doneToday,
            'pageTitle'   => 'Closing Checklist',
            'heading'     => 'Closing Up Checklist',
            'intro'       => "Hollywood. Work through it in order at the end of the night and check each box as you go. Make sure the store is locked up tight before you leave. Thank you!",
            'formAction'  => 'ClosingChecklistController@complete',
            'noun'        => 'closing',
            'byLabel'     => 'Closed by',
            'submitLabel' => 'Complete closing',
            'recentLabel' => 'Recent closings',
            'doneMsg'     => 'You rock! Thanks for closing up. Have a good night!',
        ]);
    }

    public function complete(Request $request)
    {
        $allKeys = array_keys(self::allItems());

        $checked = (array) $request->input('items', []);
        $checked = array_values(array_filter($allKeys, function ($k) use ($checked) {
            return in_array($k, $checked, true);
        }));
        $missed = array_values(array_diff($allKeys, $checked));

        $locationId = $request->input('location_id') ?: null;
        $locationName = '';
        if ($locationId) {
            $locationName = (string) optional(BusinessLocation::find($locationId))->name;
        }

        $all = self::readAll();
        $all[] = [
            'id'             => round(microtime(true) * 1000),
            'date'           => date('Y-m-d'),
            'location_id'    => $locationId,
            'location_name'  => $locationName,
            'user_id'        => auth()->id(),
            'user_name'      => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            'checked'        => $checked,
            'missed'         => $missed,
            'checked_count'  => count($checked),
            'total'          => count($allKeys),
            'note'           => mb_substr(trim((string) $request->input('note', '')), 0, 500),
            'completed_at'   => date('Y-m-d H:i'),
        ];
        self::writeAll($all);

        $msg = count($missed) === 0
            ? 'You rock! Thanks for closing up. Have a good night!'
            : 'Closing logged. ' . count($missed) . ' item(s) still need doing. Please finish them before you leave.';

        return redirect()->action('ClosingChecklistController@index')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
