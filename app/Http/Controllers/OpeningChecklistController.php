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
                'lights' => 'Turn on all the lights and the computer (PIN: 7421).',
                'music'  => 'Put on good, upbeat shopping music and turn it up loud enough to hear outside.',
                'signs'  => 'Plug in the three neon signs (Digger\'s Paradise, Have you heard it on vinyl, Disco es la cultura) and turn on the scent purifier.',
            ],
            '2. First impression from outside' => [
                'aframe'          => 'Put out the A-frame outside the store where people walking by can see it.',
                'entrance'        => 'Keep the entrance clear, with nothing blocking the door or the windows.',
                'windows_clean'   => 'Wipe all the windows with glass cleaner until they are clean.',
                'window_displays' => 'Update both window displays with A products.',
            ],
            '3. Records look full and neat' => [
                'walls_full' => 'Fill any blank wall space and any bin that looks thin, so no gaps show.',
                'endcaps'    => 'Fill the end caps with A products and new releases.',
                'stray'      => 'Put stray records back where they belong and clear the tops of the bins and tables.',
            ],
            '4. Clean floor' => [
                'floor' => 'Sweep or mop the floor.',
            ],
            '5. Desk, bathroom, fridge' => [
                'front_desk'   => 'Tidy the front desk and the bathroom.',
                'drink_fridge' => 'Make sure the drink fridge and snack rack are full. Send a supply request if either is low.',
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
                'computer'   => 'Turn on the power to the computer tower on top of the desk. PIN: 7421.',
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

    /**
     * The stores the logged-in user actually works at (subset of Hollywood /
     * Pico, in canonical order). A Hollywood-only opener never sees Pico. Falls
     * back to all stores if we can't tell, so the page is never blank.
     *
     * Priority: the store they're actually clocked in at THIS session (POS
     * duty picker) beats everything else — an employee working Pico today
     * should only see/act on Pico, even if their static home_store says
     * Hollywood or they hold access_all_locations (manager decision
     * 2026-09-16: reported live as an employee with no home_store and
     * access_all_locations seeing and completing tasks at a store they
     * weren't actually working that day). Location permissions
     * (access_all_locations) are checked next as an inference, but most
     * Cashier-role accounts have "all locations" POS access, so that alone
     * can't distinguish Pico staff from Hollywood staff. `users.home_store`,
     * set explicitly by an admin at /admin/task-store-assignments, is the
     * last resort before falling back to "every store the business has".
     */
    public static function storesForUser()
    {
        $session = self::currentSessionStoreKey();
        if ($session !== null) {
            return [$session => self::STORE_LABELS[$session]];
        }

        $home = self::homeStoreForUser();
        if ($home !== null) {
            return [$home => self::STORE_LABELS[$home]];
        }

        $has = [];
        try {
            foreach (BusinessLocation::forDropdown(session('user.business_id')) as $name) {
                if (stripos($name, 'holly') !== false) {
                    $has['hollywood'] = true;
                }
                if (stripos($name, 'pico') !== false) {
                    $has['pico'] = true;
                }
            }
        } catch (\Exception $e) {
            // fall through to "all"
        }
        $ordered = [];
        foreach (self::STORE_LABELS as $key => $label) {
            if (empty($has) || isset($has[$key])) {
                $ordered[$key] = $label;
            }
        }
        return $ordered;
    }

    /** Normalize a requested store, clamped to the stores this user can see. */
    private function resolveStore($requested)
    {
        $available = self::storesForUser();
        $requested = strtolower(trim((string) $requested));
        if (isset($available[$requested])) {
            return $requested;
        }
        return array_key_first($available);
    }

    /** The logged-in user's explicit `home_store` assignment, or null if unset/invalid. */
    private static function homeStoreForUser()
    {
        $user = auth()->user();
        $store = $user ? $user->home_store : null;
        return isset(self::STORE_LABELS[$store]) ? $store : null;
    }

    /**
     * The store this employee is actually clocked in at for THIS session,
     * per the POS duty picker (session('pos_duty_location_id'), set at
     * SellPosController::savePosDuty when they pick a cashier duty + a
     * specific store — "Both" isn't offered to cashiers). Null when they
     * haven't gone through the picker this session — a non-cashier duty,
     * or a route outside /pos/* like /tasks itself, which isn't gated by
     * it — so callers fall back to home_store / "all stores" exactly like
     * before this existed.
     */
    private static function currentSessionStoreKey()
    {
        $locationId = session('pos_duty_location_id');
        if (empty($locationId)) {
            return null;
        }

        try {
            $location = BusinessLocation::find($locationId);
        } catch (\Exception $e) {
            return null;
        }
        if (!$location || !$location->name) {
            return null;
        }
        if (stripos($location->name, 'pico') !== false) {
            return 'pico';
        }
        if (stripos($location->name, 'holly') !== false) {
            return 'hollywood';
        }
        return null;
    }

    /** Best guess of the logged-in user's store: current session location first, then explicit home_store, then permitted locations. */
    public static function defaultStoreForUser()
    {
        $session = self::currentSessionStoreKey();
        if ($session !== null) {
            return $session;
        }

        $home = self::homeStoreForUser();
        if ($home !== null) {
            return $home;
        }

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
            'storeOptions' => self::storesForUser(),
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

        $submitted = (array) $request->input('items', []);
        $checked = array_values(array_filter($allKeys, function ($k) use ($submitted) {
            return in_array($k, $submitted, true);
        }));

        // Guard against a stale page: if nothing valid came through, don't log a
        // bogus 0/total. Ask for a reload instead.
        if (empty($checked)) {
            return redirect()->action('OpeningChecklistController@index', ['store' => $store])
                ->with('status', ['success' => 0, 'msg' => 'Nothing was recorded. The page may have been open too long, or no items were checked. Please reload, check what you finished, and submit again.']);
        }

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
