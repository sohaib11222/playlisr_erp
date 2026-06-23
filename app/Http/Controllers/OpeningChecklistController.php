<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Morning opening checklist. The opening shift person picks their store, works
 * the list top to bottom, checks each item off, and submits. We log who opened,
 * when, which store, and which items were left unchecked so a manager can see
 * the store was set up right.
 *
 * No migration: stored in storage/app/opening_checklist.json (JSON sidecar).
 * Renders the shared checklist view. Per-store lists live in STORES below;
 * to add or reword a step, edit that array (keys are stable).
 */
class OpeningChecklistController extends Controller
{
    const STORE_PATH = 'opening_checklist.json';

    const STORE_LABELS = [
        'hollywood' => 'Hollywood',
        'pico'      => 'Pico',
    ];

    /** Per-store opening lists, grouped in the order you walk the store. */
    const STORES = [
        'hollywood' => [
            '1. Turn the store on' => [
                'lights'   => 'Turn on all the lights in the main room.',
                'music'    => 'Put on upbeat shopping music and turn it up loud enough to hear outside.',
                'computer' => 'Turn on the computer.',
                'scent'    => 'Turn on the store scent purifier.',
            ],
            '2. Windows & entrance' => [
                'entrance'        => 'Keep the front entrance totally clear, with no boxes, trash, or obstacles in sight, so it feels welcoming to customers.',
                'windows_clean'   => 'Wipe down all the windows with glass cleaner until they are clean.',
                'windows_clear'   => 'Clear the windows of any random signs so nothing blocks the view inside.',
                'window_displays' => 'Update both window displays with A products.',
            ],
            '3. Neon signs' => [
                'sign_diggers' => 'Plug in the "Welcome to Digger\'s Paradise" sign behind the listening station.',
                'sign_vinyl'   => 'Plug in the "Have you heard it on vinyl" sign at the outlet behind the rock bins.',
                'sign_disco'   => 'Plug in the "Disco es la cultura" sign on the stage.',
            ],
            '4. Records: walls & bins' => [
                'walls_full'    => 'Fill the walls with records so there is no blank space showing.',
                'bins_full'     => 'Fill in any bin that looks thin so none of them look empty.',
                'bins_neat'     => 'Straighten the bins so everything is neat and nothing is out of place.',
                'trading_cards' => 'Organize the trading card bin.',
                'endcaps'       => 'Fill the end caps with A products and new releases, leaving no blank space.',
            ],
            '5. Tidy the floor' => [
                'stray'          => 'Put any stray records or products back where they belong.',
                'surfaces_clear' => 'Clear the tops of the bins and tables so no cassettes, DVDs, or drinks are left out.',
                'clothes_hung'   => 'Hang up all clothing by the clothing rack so there is nothing on the floor.',
                'stage_neat'     => 'Tidy the stage so it has no trash or stray items and looks like a cozy living room.',
            ],
            '6. Fridge & snacks' => [
                'drink_fridge' => 'Make sure the drink fridge is full, and send a supply request if it is running low.',
                'snack_rack'   => 'Make sure the snack rack is full, and send a supply request if it is running low.',
            ],
            '7. Front desk & bathroom' => [
                'front_desk' => 'Clear the front desk so it is tidy and free of any trash or boxes.',
                'bathroom'   => 'Make sure the bathroom is tidy and clean.',
            ],
            '8. Last: floor & trash' => [
                'floor'     => 'Sweep and mop the floor.',
                'trash_all' => 'Empty all the trash bins and take the trash out to the back.',
            ],
        ],
        'pico' => [
            '1. Unlock and open up' => [
                'front_door'  => 'Unlock the front door: use your key to turn the top lock until the latch opens.',
                'metal_gate'  => 'Open the metal gate: turn the same key to the right to release it, pull the gate to the left, and move the bottom metal plate against the left wall.',
                'window_gate' => 'Open the gate by the window using the key hanging on the hook at the desk post, below the monitor under the desktop.',
            ],
            '2. Turn the store on' => [
                'lights'     => 'Turn on all three lights on the left side as you walk in.',
                'computer'   => 'Turn on the power to the computer tower on top of the desk.',
                'music'      => 'Put on good music and turn it up.',
                'sign_light' => 'Turn on the light on the right side of the back room door opening, which powers the listening station and the "Diggers Paradise" neon sign.',
            ],
            '3. Out front' => [
                'aframe' => 'Put out the A-frame in an easy-to-see spot by the curb.',
            ],
            '4. Records & front desk' => [
                'stock'      => 'Check that the walls and bins are fully stocked with records and the bins are organized.',
                'endcaps'    => 'Fill in any missing records on the end caps to highlight the featured albums.',
                'front_desk' => 'Keep the front desk clutter-free for our customers.',
            ],
            '5. Clean' => [
                'floor'    => 'Sweep or vacuum the floor for a clean shopping experience.',
                'bathroom' => 'Check the bathroom and the front trash: make sure everything is tidy and take all the trash out, including any trash around the store.',
            ],
        ],
    ];

    /** Action links shown next to specific items (keyed by item key, any store). */
    const LINKS = [
        'endcaps'         => ['url' => '/reports/abc-full-report?class=A', 'text' => 'View A products'],
        'window_displays' => ['url' => '/reports/abc-full-report?class=A', 'text' => 'View A products'],
        'drink_fridge'    => ['url' => '/supply-requests', 'text' => 'Request a supply'],
        'snack_rack'      => ['url' => '/supply-requests', 'text' => 'Request a supply'],
    ];

    const INTROS = [
        'hollywood' => 'Hollywood. This follows the store front to back, so just walk it in order and check each box as you go. Whatever you can\'t get to, leave it unchecked and let a manager know. Thank you!',
        'pico'      => 'Pico (5770 W Pico Blvd). Try to arrive at least 15 minutes before opening. Walk the list in order and check each box as you go. Thank you!',
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

    /** Flat key => label map for one store. */
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

    /** Normalize a requested store, falling back to the user's own store. */
    private function resolveStore($requested)
    {
        $requested = strtolower(trim((string) $requested));
        if (isset(self::STORES[$requested])) {
            return $requested;
        }
        return self::defaultStoreForUser();
    }

    /** Best guess of the logged-in user's store from their permitted locations. */
    public static function defaultStoreForUser()
    {
        try {
            foreach (BusinessLocation::forDropdown(session('user.business_id')) as $name) {
                if (stripos($name, 'pico') !== false) {
                    return 'pico';
                }
                if (stripos($name, 'holly') !== false) {
                    return 'hollywood';
                }
            }
        } catch (\Exception $e) {
            // fall through
        }
        return 'hollywood';
    }

    /* ---------- "has the store been opened today?" helpers ---------- */

    /** Has anyone logged today's opening for this store yet? */
    public static function openedToday($store)
    {
        $today = date('Y-m-d');
        foreach (self::readAll() as $r) {
            if (($r['date'] ?? '') === $today && (($r['store'] ?? 'hollywood') === $store)) {
                return true;
            }
        }
        return false;
    }

    /**
     * If the current user's store hasn't been opened today, return that store
     * key; otherwise null. Drives the dashboard banner + red sidebar badge.
     * Only fires for staff who actually work a recognized store.
     */
    public static function promptStore()
    {
        if (!auth()->check()) {
            return null;
        }
        $store = null;
        try {
            foreach (BusinessLocation::forDropdown(session('user.business_id')) as $name) {
                if (stripos($name, 'pico') !== false) {
                    $store = 'pico';
                    break;
                }
                if (stripos($name, 'holly') !== false) {
                    $store = 'hollywood';
                    break;
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        if (!$store) {
            return null;
        }
        return self::openedToday($store) ? null : $store;
    }

    public static function shouldPrompt()
    {
        return self::promptStore() !== null;
    }

    /* ---------- page ---------- */

    public function index(Request $request)
    {
        $store = $this->resolveStore($request->input('store'));
        $allKeys = array_keys(self::allItems($store));

        $all = self::readAll();
        // This store's records, newest first.
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
            'storeOptions' => self::STORE_LABELS,
            'baseUrl'      => url('/opening-checklist'),
            'pageTitle'    => 'Opening Checklist',
            'heading'      => 'Morning Opening Checklist',
            'intro'        => self::INTROS[$store] ?? '',
            'formAction'   => 'OpeningChecklistController@complete',
            'noun'         => 'opening',
            'byLabel'      => 'Opened by',
            'submitLabel'  => 'Complete opening',
            'recentLabel'  => 'Recent openings',
            'doneMsg'      => 'You rock! Thank you, and have a great day!',
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
            ? 'You rock! Thank you, and have a great day!'
            : 'Opening logged. ' . count($missed) . ' item(s) still need doing. Please finish them.';

        return redirect()->action('OpeningChecklistController@index', ['store' => $store])
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
