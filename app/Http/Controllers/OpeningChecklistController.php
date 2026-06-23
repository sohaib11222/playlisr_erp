<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Morning opening checklist. The opening shift person works top-to-bottom,
 * checks each item off, and submits it. We stamp who opened, when, and which
 * items were left unchecked so a manager can see at a glance whether the store
 * was actually set up right that morning.
 *
 * No migration: stored in storage/app/opening_checklist.json (same JSON sidecar
 * pattern as supply_requests / consignment).
 *
 * Items live in the GROUPS array below — to add or reword a step, edit that
 * array. Keys are stable so old completion records keep their labels.
 */
class OpeningChecklistController extends Controller
{
    const STORE_PATH = 'opening_checklist.json';

    /**
     * The Hollywood opening checklist. Grouped for the floor; each item has a
     * stable key and the exact instruction the opener follows.
     */
    const GROUPS = [
        'Lights, music, vibe' => [
            'lights_main' => 'Turn on all the lights in the main room',
            'computer'    => 'Turn on the computer',
            'music'       => 'Put on good, upbeat shopping music — loud enough to hear outside',
        ],
        'Neon signs' => [
            'sign_diggers' => '"Welcome to Digger\'s Paradise" — plug in behind the listening station',
            'sign_vinyl'   => '"Have you heard it on vinyl" — plug into the outlet behind the rock bins',
            'sign_disco'   => '"Disco es la cultura" on stage — plug into the wall',
        ],
        'Records & bins' => [
            'walls_full'     => 'No white space on the walls — they should be full of records',
            'bins_not_empty' => 'No bin looks empty — fill in any that are looking thin',
            'bins_organized' => 'Every bin looks organized — fix anything out of place',
            'trading_cards'  => 'Trading card bin is organized',
            'endcaps'        => 'End caps feature A-products and new releases',
        ],
        'Tidiness — nothing left out of place' => [
            'stray_items'    => 'All stray records and products put back on the proper shelves',
            'tidy_cassettes' => 'No cassettes sitting on top of the tables',
            'tidy_dvds'      => 'No DVDs sitting on top of the DVD bins',
            'tidy_drinks'    => 'No drinks on top of the bins',
            'clothes_hung'   => 'All clothes hung up — nothing on the floor (including under the T-shirt racks)',
            'stage_neat'     => 'Stage is neat — no trash, no stray items, set up like a cozy living-room set',
        ],
        'Front desk & floor' => [
            'windows'          => 'Windows wiped with glass cleaner and clear — no random or obstructive signs blocking the view inside',
            'front_desk_clear' => 'Front desk clear and clutter-free for customers',
            'no_trash_front'   => 'No trash or boxes at the front desk / front area',
            'floor'            => 'Sweep or mop the floor',
        ],
        'Bathroom & trash' => [
            'bathroom'  => 'Bathroom tidy, trash taken out',
            'trash_all' => 'Empty all trash bins and clear any trash around the store',
        ],
        'Fridge & snacks' => [
            'drink_fridge' => 'Drink fridge is full — if it needs restocking, put in a supply request',
            'snack_rack'   => 'Snack rack is full — if it needs restocking, put in a supply request',
        ],
    ];

    /**
     * Optional action link shown next to specific items (keyed by item key).
     * e.g. jump straight to the supply-request form for fridge/snack restocks.
     */
    const LINKS = [
        'endcaps'      => ['url' => '/reports/abc-full-report?class=A', 'text' => 'View A-products'],
        'drink_fridge' => ['url' => '/supply-requests', 'text' => 'Request a supply'],
        'snack_rack'   => ['url' => '/supply-requests', 'text' => 'Request a supply'],
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

    /** Flat key => label map across all groups. */
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

    /* ---------- "has the store been opened today?" helpers ---------- */

    /** Has anyone logged today's opening yet? */
    public static function openedToday()
    {
        $today = date('Y-m-d');
        foreach (self::readAll() as $r) {
            if (($r['date'] ?? '') === $today) {
                return true;
            }
        }
        return false;
    }

    /**
     * Should we nag the current user to run the opening checklist? Yes when it
     * hasn't been logged today and they have access to the Hollywood store
     * (this is the Hollywood opening list — don't pester Pico/Wilcox staff).
     * Drives the dashboard banner and the red sidebar badge.
     */
    public static function shouldPrompt()
    {
        if (!auth()->check() || self::openedToday()) {
            return false;
        }
        try {
            $business_id = session('user.business_id');
            foreach (BusinessLocation::forDropdown($business_id) as $name) {
                if (stripos($name, 'holly') !== false) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            return false;
        }
        return false;
    }

    /* ---------- page ---------- */

    public function index()
    {
        $all = self::readAll();

        // Recent openings, newest first — gives managers (and everyone) a quick
        // record of who set the store up and what got skipped.
        usort($all, function ($a, $b) {
            return strcmp($b['completed_at'] ?? '', $a['completed_at'] ?? '');
        });
        $recent = array_slice($all, 0, 20);

        // Has someone already completed an opening for this store today?
        $today = date('Y-m-d');
        $doneToday = array_values(array_filter($all, function ($r) use ($today) {
            return ($r['date'] ?? '') === $today;
        }));

        return view('opening_checklist.index', [
            'groups'     => self::GROUPS,
            'links'      => self::LINKS,
            'totalItems' => count(self::allItems()),
            'locations'  => $this->locations(),
            'recent'     => $recent,
            'doneToday'  => $doneToday,
        ]);
    }

    public function complete(Request $request)
    {
        $allKeys = array_keys(self::allItems());

        $checked = (array) $request->input('items', []);
        // Keep only real item keys, in checklist order.
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
            ? 'You rock! Thank you, and have a great day!'
            : 'Opening logged. ' . count($missed) . ' item(s) still need doing — please finish them.';

        return redirect()->action('OpeningChecklistController@index')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
