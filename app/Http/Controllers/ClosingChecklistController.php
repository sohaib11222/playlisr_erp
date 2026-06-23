<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Evening closing checklist. Mirrors the opening checklist: pick the store, work
 * the list, check each step off, submit. We log who closed, when, which store,
 * and anything left undone.
 *
 * Each store's list is split into two sections so a late closer knows what is
 * non-negotiable: "Before you leave" is security + shutdown + garbage (only the
 * closer can do these), and "If you have time" is presentation prep the morning
 * opener already does, so it can slide to the morning when it's running late.
 *
 * No migration: storage/app/closing_checklist.json. Renders the shared view.
 */
class ClosingChecklistController extends Controller
{
    const STORE_PATH = 'closing_checklist.json';

    const STORE_LABELS = [
        'hollywood' => 'Hollywood',
        'pico'      => 'Pico',
    ];

    const STORES = [
        'hollywood' => [
            'Before you leave (required)' => [
                'trash_all'    => 'Empty all the trash bins and take the trash out to the back dumpster.',
                'aframe'       => 'Bring in the A-frame if it is outside.',
                'computer'     => 'Shut down the computer at the front desk.',
                'receiver'     => 'Put the receiver on its lowest volume and click "Mute."',
                'ac'           => 'Make sure the AC is off.',
                'scent'        => 'Make sure the scent purifier is off.',
                'sign_diggers' => 'Unplug the "Welcome to Digger\'s Paradise" sign by pulling the plug behind the listening station.',
                'sign_vinyl'   => 'Unplug the "Have you heard it on vinyl" sign from behind the rock bins.',
                'sign_disco'   => 'Unplug the "El disco es la cultura" sign from on stage.',
                'lights'       => 'Turn off all the lights in the store, and make sure the bathroom light is off.',
                'front_door'   => 'Close and lock the front door with the two locks at the bottom, then let down the gate using the buttons on the right wall (hold the arrow button down to lower it).',
                'lockbox'      => 'If you used the lockbox key, put it back in the lockbox at the front of the gate and scramble the code.',
                'back_door'    => 'Exit through the back door. Make sure it is locked before you leave, since people in the neighborhood will come in looking for a place to crash if it is left open.',
            ],
            'If you have time (otherwise the morning opener will finish these)' => [
                'tidy'       => 'Tidy up the sales floor and make sure all bins look organized.',
                'endcaps'    => 'Refill any records that sold from the end caps so the featured albums stay visible.',
                'front_desk' => 'Remove any clutter from the front desk so it stays inviting.',
                'floor'      => 'Sweep or vacuum the floor so the store is clean for the next day.',
                'bathroom'   => 'Make sure the bathroom is tidy.',
            ],
        ],
        'pico' => [
            'Before you leave (required)' => [
                'trash_all'   => 'Empty all the trash bins and throw out any trash around the store.',
                'aframe'      => 'Bring the A-frame in from the curb.',
                'computer'    => 'Shut down the computer (click Start, then Shut down).',
                'fan'         => 'Turn off the fan.',
                'lights'      => 'Turn off all the lights in the main room, back room, and bathroom, and turn off the light near the back room door post to shut off the sign.',
                'window_gate' => 'Lock the window gate with the lock hanging on the hook behind the desk, then join the metal gates together and lock them in place.',
                'front_gate'  => 'Close the front gate: pull the brown gate flush with the door and push it to the right.',
                'front_door'  => 'Close the glass door and lock it with the key, turning to the right until it clicks, then double-check that the front door is locked.',
            ],
            'If you have time (otherwise the morning opener will finish these)' => [
                'tidy'       => 'Make sure the floor is tidy and the bins look organized.',
                'endcaps'    => 'Refill any records that sold from the end caps so the featured albums stay visible.',
                'front_desk' => 'Remove any clutter from the front desk so it stays clean and inviting.',
                'floor'      => 'Sweep or vacuum the floor for the next day.',
                'bathroom'   => 'Make sure the bathroom looks tidy.',
            ],
        ],
    ];

    const LINKS = [
        'endcaps' => ['url' => '/reports/abc-full-report?class=A', 'text' => 'View A products'],
    ];

    const INTROS = [
        'hollywood' => 'Hollywood. The first section is required before you leave (locking up, shutting down, and trash). The second section is the next-day tidy: do it if you have time, but if it is late, the morning opener will finish it. Lock up tight before you go. Thank you!',
        'pico'      => 'Pico (5770 W Pico Blvd). The first section is required before you leave (locking up, shutting down, and trash). The second section is the next-day tidy: do it if you have time, but if it is late, the morning opener will finish it. Lock up tight before you go. Thank you!',
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

    public static function groupsFor($store)
    {
        return self::STORES[$store] ?? self::STORES['hollywood'];
    }

    public static function allItems($store)
    {
        $flat = [];
        foreach (self::groupsFor($store) as $items) {
            foreach ($items as $key => $label) {
                $flat[$key] = $label;
            }
        }
        return $flat;
    }

    private function resolveStore($requested)
    {
        $available = OpeningChecklistController::storesForUser();
        $requested = strtolower(trim((string) $requested));
        if (isset($available[$requested])) {
            return $requested;
        }
        return array_key_first($available);
    }

    /* ---------- page ---------- */

    public function index(Request $request)
    {
        $store = $this->resolveStore($request->input('store'));
        $allKeys = array_keys(self::allItems($store));

        $all = self::readAll();
        $forStore = array_values(array_filter($all, function ($r) use ($store) {
            return ($r['store'] ?? 'hollywood') === $store;
        }));
        usort($forStore, function ($a, $b) {
            return strcmp($b['completed_at'] ?? '', $a['completed_at'] ?? '');
        });
        $recent = array_slice($forStore, 0, 20);

        $today = date('Y-m-d');
        $doneToday = array_values(array_filter($forStore, function ($r) use ($today) {
            return ($r['date'] ?? '') === $today;
        }));

        return view('checklist.index', [
            'groups'       => self::groupsFor($store),
            'links'        => self::LINKS,
            'totalItems'   => count($allKeys),
            'recent'       => $recent,
            'doneToday'    => $doneToday,
            'store'        => $store,
            'storeOptions' => OpeningChecklistController::storesForUser(),
            'baseUrl'      => url('/closing-checklist'),
            'pageTitle'    => 'Closing Checklist',
            'heading'      => 'Closing Up Checklist',
            'intro'        => self::INTROS[$store] ?? '',
            'formAction'   => 'ClosingChecklistController@complete',
            'noun'         => 'closing',
            'byLabel'      => 'Closed by',
            'submitLabel'  => 'Complete closing',
            'recentLabel'  => 'Recent closings',
            'doneMsg'      => 'You rock! Thanks for closing up. Have a good night!',
        ]);
    }

    public function complete(Request $request)
    {
        $store = $this->resolveStore($request->input('store'));
        $allKeys = array_keys(self::allItems($store));

        $checked = (array) $request->input('items', []);
        $checked = array_values(array_filter($allKeys, function ($k) use ($checked) {
            return in_array($k, $checked, true);
        }));
        $missed = array_values(array_diff($allKeys, $checked));

        $all = self::readAll();
        $all[] = [
            'id'             => round(microtime(true) * 1000),
            'date'           => date('Y-m-d'),
            'store'          => $store,
            'location_name'  => self::STORE_LABELS[$store] ?? '',
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

        return redirect()->action('ClosingChecklistController@index', ['store' => $store])
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
