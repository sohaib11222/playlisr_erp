<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Lightweight consumables tracker (waters, bags, receipt/label paper, sleeves,
 * cleaning kits, etc.) — separate from the product inventory. Managers set each
 * item's status (OK / Low / Out), which store it's for, and the next expected
 * restock. Stored in storage/app/supplies.json (no migration). The help
 * assistant reads this so staff can ask "are we low on waters at Pico?" or
 * "when's the next shipment?".
 */
class SuppliesController extends Controller
{
    const STORE_PATH = 'supplies.json';

    const STATUSES = ['ok' => 'OK / In stock', 'low' => 'Running low', 'out' => 'Out'];

    // Seed list on first visit, based on what the floor actually runs out of.
    // location_id blank = applies to all stores until a manager assigns one.
    const DEFAULT_ITEMS = [
        ['name' => 'Bottled water',        'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
        ['name' => 'Shopping bags',        'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
        ['name' => 'Receipt paper rolls',  'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
        ['name' => 'Label paper / rolls',  'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
        ['name' => 'Record sleeves',       'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
        ['name' => 'Mailers / boxes',      'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
        ['name' => 'Cleaning kits',        'status' => 'ok', 'location_id' => null, 'location_name' => '', 'next_restock' => '', 'note' => ''],
    ];

    private function guard()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public static function readSupplies()
    {
        if (!Storage::exists(self::STORE_PATH)) {
            return self::DEFAULT_ITEMS;
        }
        $data = json_decode(Storage::get(self::STORE_PATH), true);
        return (is_array($data) && !empty($data)) ? $data : self::DEFAULT_ITEMS;
    }

    public function index()
    {
        $this->guard();
        $business_id = request()->session()->get('user.business_id');
        return view('admin.supplies', [
            'items' => self::readSupplies(),
            'statuses' => self::STATUSES,
            'locations' => BusinessLocation::forDropdown($business_id, false, false, false, false),
        ]);
    }

    public function save(Request $request)
    {
        $this->guard();

        $names = $request->input('name', []);
        $statuses = $request->input('status', []);
        $locationIds = $request->input('location_id', []);
        $restocks = $request->input('next_restock', []);
        $notes = $request->input('note', []);

        // Resolve location names once for the bot/readout (avoid per-row queries).
        $business_id = request()->session()->get('user.business_id');
        $locationNames = BusinessLocation::forDropdown($business_id, false, false, false, false)->toArray();

        $items = [];
        foreach ((array) $names as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue; // skip blank rows (e.g. the empty "add" row)
            }
            $status = $statuses[$i] ?? 'ok';
            if (!array_key_exists($status, self::STATUSES)) {
                $status = 'ok';
            }
            $locationId = !empty($locationIds[$i]) ? $locationIds[$i] : null;
            $items[] = [
                'name' => $name,
                'status' => $status,
                'location_id' => $locationId,
                'location_name' => $locationId ? ($locationNames[$locationId] ?? '') : '',
                'next_restock' => trim((string) ($restocks[$i] ?? '')),
                'note' => trim((string) ($notes[$i] ?? '')),
            ];
        }

        Storage::put(self::STORE_PATH, json_encode($items, JSON_PRETTY_PRINT));

        return redirect()->action('SuppliesController@index')
            ->with('status', ['success' => 1, 'msg' => 'Supplies updated.']);
    }

    /**
     * Human-readable supplies status for the help assistant's system prompt.
     */
    public static function formatForBot()
    {
        $items = self::readSupplies();
        if (empty($items)) {
            return "(No supplies are being tracked yet.)";
        }
        $lines = [];
        foreach ($items as $it) {
            $label = self::STATUSES[$it['status'] ?? 'ok'] ?? 'OK';
            $line = '- ' . ($it['name'] ?? 'Item');
            if (!empty($it['location_name'])) {
                $line .= ' [' . $it['location_name'] . ']';
            }
            $line .= ': ' . $label;
            if (!empty($it['next_restock'])) {
                $line .= '; next restock ' . $it['next_restock'];
            }
            if (!empty($it['note'])) {
                $line .= ' (' . $it['note'] . ')';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
