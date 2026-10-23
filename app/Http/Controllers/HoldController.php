<?php

namespace App\Http\Controllers;

use App\Services\ProductHolds;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer / employee holds (Jon, 2026-10-23). See App\Services\ProductHolds.
 *
 * /holds lists what's in each store's hold bin, with past-due holds on top so
 * someone pulls them. Placing or clearing a hold re-pushes the product to the
 * website so it goes out of (or back in) stock there right away.
 */
class HoldController extends Controller
{
    private function authorizeStaff()
    {
        $user = auth()->user();
        if (!$user || !($user->can('sell.create') || $user->can('product.create'))) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    /** Store locations this user may hold at, id => name. */
    private function locations(int $businessId): array
    {
        $q = DB::table('business_locations')->where('business_id', $businessId)->where('is_active', 1);
        $permitted = auth()->user()->permitted_locations($businessId);
        if ($permitted !== 'all') $q->whereIn('id', $permitted ?: [0]);
        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The open register's store, else the first permitted store. */
    private function defaultLocationId(int $businessId, array $locations)
    {
        try {
            $reg = DB::table('cash_registers')->where('business_id', $businessId)->where('user_id', auth()->id())
                ->where('status', 'open')->orderByDesc('id')->value('location_id');
            if ($reg && isset($locations[$reg])) return (int) $reg;
        } catch (\Throwable $e) {
        }
        foreach ($locations as $id => $name) return (int) $id;
        return null;
    }

    private function employees(int $businessId): array
    {
        $out = [];
        foreach (User::forDropdown($businessId, false, false, false, false, true) as $id => $name) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $name));
            if ($name !== '') $out[$id] = $name;
        }
        asort($out);
        return $out;
    }

    public function index(Request $request)
    {
        $this->authorizeStaff();
        $businessId = $this->businessId();
        $locations = $this->locations($businessId);
        $tab = $request->input('tab') === 'history' ? 'history' : 'active';
        $overdueOnly = $request->input('filter') === 'overdue';
        $locationFilter = (int) $request->input('location_id');

        $holds = collect();
        $overdueCount = 0;
        if (ProductHolds::enabled()) {
            $today = Carbon::today()->toDateString();
            $q = DB::table('product_holds as h')
                ->leftJoin('products as p', 'p.id', '=', 'h.product_id')
                ->leftJoin('variations as v', 'v.id', '=', 'h.variation_id')
                ->leftJoin('business_locations as bl', 'bl.id', '=', 'h.location_id')
                ->leftJoin('users as cu', 'cu.id', '=', 'h.created_by')
                ->leftJoin('users as ru', 'ru.id', '=', 'h.released_by')
                ->where('h.business_id', $businessId)
                ->whereIn('h.location_id', array_keys($locations) ?: [0])
                ->select(
                    'h.*', 'p.name as product_name', 'p.artist', 'v.sub_sku', 'bl.name as location_name',
                    DB::raw("TRIM(CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,''))) as created_by_name"),
                    DB::raw("TRIM(CONCAT(COALESCE(ru.first_name,''), ' ', COALESCE(ru.last_name,''))) as released_by_name")
                );
            if ($locationFilter) $q->where('h.location_id', $locationFilter);

            $overdueCount = (clone $q)->where('h.status', 'active')->where('h.hold_until', '<', $today)->count();

            if ($tab === 'active') {
                $q->where('h.status', 'active');
                if ($overdueOnly) $q->where('h.hold_until', '<', $today);
                $q->orderBy('h.hold_until')->orderBy('h.id');
            } else {
                $q->whereIn('h.status', ['released', 'sold'])->orderByDesc('h.released_at')->orderByDesc('h.id')->limit(500);
            }
            $holds = $q->get();
        }

        return view('holds.index', [
            'tableReady' => ProductHolds::enabled(),
            'holds' => $holds,
            'tab' => $tab,
            'overdueOnly' => $overdueOnly,
            'overdueCount' => $overdueCount,
            'locations' => $locations,
            'locationFilter' => $locationFilter,
            'defaultLocationId' => $this->defaultLocationId($businessId, $locations),
            'employees' => $this->employees($businessId),
            'today' => Carbon::today(),
            'maxCustomer' => ProductHolds::MAX_DAYS_CUSTOMER,
            'maxEmployee' => ProductHolds::MAX_DAYS_EMPLOYEE,
            'defaultUntilCustomer' => ProductHolds::defaultUntil('customer')->toDateString(),
            'defaultUntilEmployee' => ProductHolds::defaultUntil('employee')->toDateString(),
            'latestUntilCustomer' => ProductHolds::latestUntil('customer')->toDateString(),
            'latestUntilEmployee' => ProductHolds::latestUntil('employee')->toDateString(),
        ]);
    }

    /** SKU / title lookup for the New hold form. */
    public function lookup(Request $request)
    {
        $this->authorizeStaff();
        $term = trim((string) $request->input('q'));
        if ($term === '') return response()->json(['items' => []]);

        $base = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $this->businessId())->whereNull('v.deleted_at')
            ->select('v.id as variation_id', 'p.id as product_id', 'p.name', 'p.artist', 'v.sub_sku');

        // Exact SKU first (a scan), then a loose title / artist match.
        $rows = (clone $base)->where(function ($w) use ($term) {
            $w->where('v.sub_sku', $term)->orWhere('p.sku', $term);
        })->limit(10)->get();
        if ($rows->isEmpty()) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $rows = (clone $base)->where(function ($w) use ($like) {
                $w->where('p.name', 'like', $like)->orWhere('p.artist', 'like', $like)->orWhere('v.sub_sku', 'like', $like);
            })->orderBy('p.name')->limit(15)->get();
        }

        return response()->json(['items' => $rows->map(function ($r) {
            return [
                'variation_id' => (int) $r->variation_id,
                'product_id' => (int) $r->product_id,
                'label' => self::itemLabel($r->artist, $r->name),
                'sku' => $r->sub_sku,
            ];
        })->values()]);
    }

    public static function itemLabel($artist, $name): string
    {
        $artist = trim((string) $artist);
        $name = trim((string) $name);
        if ($artist !== '' && stripos($name, $artist) !== 0) return $artist . ' - ' . $name;
        return $name;
    }

    /**
     * Validate who the hold is for + the date. Returns [fields, error].
     */
    private function holderFields(Request $request, int $businessId): array
    {
        $type = $request->input('held_for_type') === 'employee' ? 'employee' : 'customer';
        $fields = ['held_for_type' => $type, 'contact_id' => null, 'held_for_user_id' => null];

        if ($type === 'employee') {
            $userId = (int) $request->input('held_for_user_id');
            $employees = $this->employees($businessId);
            if (!$userId || !isset($employees[$userId])) return [null, 'Pick the employee the hold is for.'];
            $fields['held_for_user_id'] = $userId;
            $fields['held_for_name'] = $employees[$userId];
        } else {
            $name = trim((string) $request->input('held_for_name'));
            if ($name === '') return [null, 'Enter the customer name.'];
            $fields['held_for_name'] = mb_substr($name, 0, 190);
            $contactId = (int) $request->input('contact_id');
            if ($contactId && DB::table('contacts')->where('business_id', $businessId)->where('id', $contactId)->exists()) {
                $fields['contact_id'] = $contactId;
            }
        }

        $until = $request->input('hold_until') ?: ProductHolds::defaultUntil($type)->toDateString();
        if ($err = ProductHolds::untilError($type, $until)) return [null, $err];
        $fields['hold_until'] = Carbon::parse($until)->toDateString();
        $notes = trim((string) $request->input('notes'));
        $fields['notes'] = $notes === '' ? null : $notes;
        return [$fields, null];
    }

    /** Variation that belongs to this business, or null. */
    private function variation(int $businessId, int $variationId)
    {
        return DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)->where('v.id', $variationId)
            ->select('v.id', 'v.product_id')->first();
    }

    private function insertHolds(int $businessId, int $locationId, array $fields, array $items): array
    {
        $now = Carbon::now();
        $productIds = [];
        DB::transaction(function () use ($businessId, $locationId, $fields, $items, $now, &$productIds) {
            foreach ($items as $it) {
                DB::table('product_holds')->insert($fields + [
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'product_id' => $it['product_id'],
                    'variation_id' => $it['variation_id'],
                    'quantity' => $it['quantity'],
                    'status' => 'active',
                    'created_by' => auth()->id(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $productIds[] = $it['product_id'];
            }
        });
        ProductHolds::pushToWebsite($productIds);
        return $productIds;
    }

    /** New hold form on /holds (one item). */
    public function store(Request $request)
    {
        $this->authorizeStaff();
        if (!ProductHolds::enabled()) return back()->with('error', 'Holds are not set up yet (migration pending).');
        $businessId = $this->businessId();

        $locationId = (int) $request->input('location_id');
        if (!isset($this->locations($businessId)[$locationId])) return back()->withInput()->with('error', 'Pick a store.');
        $var = $this->variation($businessId, (int) $request->input('variation_id'));
        if (!$var) return back()->withInput()->with('error', 'Scan or search the item first.');
        list($fields, $err) = $this->holderFields($request, $businessId);
        if ($err) return back()->withInput()->with('error', $err);
        $qty = max(1, (float) $request->input('quantity', 1));

        $this->insertHolds($businessId, $locationId, $fields, [[
            'product_id' => (int) $var->product_id, 'variation_id' => (int) $var->id, 'quantity' => $qty,
        ]]);

        return redirect()->action('HoldController@index')->with('status', 'On hold for ' . $fields['held_for_name']
            . ' until ' . Carbon::parse($fields['hold_until'])->format('m/d/y') . '. Put it in the hold bin.');
    }

    /** POS "Hold" button: every item in the cart becomes a hold. JSON. */
    public function fromPos(Request $request)
    {
        try {
            $this->authorizeStaff();
            if (!ProductHolds::enabled()) return response()->json(['success' => false, 'msg' => 'Holds are not set up yet.']);
            $businessId = $this->businessId();

            $locationId = (int) $request->input('location_id');
            if (!isset($this->locations($businessId)[$locationId])) {
                return response()->json(['success' => false, 'msg' => 'This register has no store you can hold at.']);
            }
            list($fields, $err) = $this->holderFields($request, $businessId);
            if ($err) return response()->json(['success' => false, 'msg' => $err]);

            // Same variation on several rows: one hold with the summed qty.
            $items = [];
            foreach ((array) $request->input('items', []) as $row) {
                $var = $this->variation($businessId, (int) ($row['variation_id'] ?? 0));
                if (!$var) continue;
                $qty = max(1, (float) ($row['quantity'] ?? 1));
                if (isset($items[$var->id])) {
                    $items[$var->id]['quantity'] += $qty;
                } else {
                    $items[$var->id] = ['product_id' => (int) $var->product_id, 'variation_id' => (int) $var->id, 'quantity' => $qty];
                }
            }
            if (empty($items)) return response()->json(['success' => false, 'msg' => 'No catalog items in the cart to hold (manual lines can not be held).']);

            $this->insertHolds($businessId, $locationId, $fields, array_values($items));
            $n = count($items);
            return response()->json(['success' => true, 'msg' => $n . ' item' . ($n === 1 ? '' : 's') . ' on hold for '
                . $fields['held_for_name'] . ' until ' . Carbon::parse($fields['hold_until'])->format('m/d/y') . '. Put them in the hold bin.']);
        } catch (\Throwable $e) {
            \Log::error('HoldController@fromPos: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Could not place the hold. Try again or use the Holds page.']);
        }
    }

    private function activeHoldOr404(int $id)
    {
        if (!ProductHolds::enabled()) abort(404);
        $businessId = $this->businessId();
        $hold = DB::table('product_holds')->where('business_id', $businessId)->where('id', $id)->first();
        if (!$hold || !isset($this->locations($businessId)[$hold->location_id])) abort(404);
        return $hold;
    }

    private function close(Request $request, int $id, string $status, string $message)
    {
        $this->authorizeStaff();
        $hold = $this->activeHoldOr404($id);
        if ($hold->status !== 'active') return back()->with('error', 'That hold was already cleared.');
        $note = trim((string) $request->input('release_note'));
        DB::table('product_holds')->where('id', $hold->id)->update([
            'status' => $status,
            'released_at' => Carbon::now(),
            'released_by' => auth()->id(),
            'release_note' => $note === '' ? null : mb_substr($note, 0, 190),
            'updated_at' => Carbon::now(),
        ]);
        ProductHolds::pushToWebsite([(int) $hold->product_id]);
        return back()->with('status', $message);
    }

    /** "Back on floor": the copy goes back on sale. */
    public function release(Request $request, $id)
    {
        return $this->close($request, (int) $id, 'released', 'Hold cleared. Put the copy back on the floor.');
    }

    /** "Sold to them": they still ring it at the POS like any sale. */
    public function sold(Request $request, $id)
    {
        return $this->close($request, (int) $id, 'sold', 'Hold marked sold. Ring it up at the POS as usual.');
    }

    public function extend(Request $request, $id)
    {
        $this->authorizeStaff();
        $hold = $this->activeHoldOr404((int) $id);
        if ($hold->status !== 'active') return back()->with('error', 'That hold was already cleared.');
        if ($err = ProductHolds::untilError($hold->held_for_type, $request->input('hold_until'))) return back()->with('error', $err);
        $until = Carbon::parse($request->input('hold_until'))->toDateString();
        DB::table('product_holds')->where('id', $hold->id)->update(['hold_until' => $until, 'updated_at' => Carbon::now()]);
        return back()->with('status', 'Hold for ' . $hold->held_for_name . ' now runs until ' . Carbon::parse($until)->format('m/d/y') . '.');
    }
}

