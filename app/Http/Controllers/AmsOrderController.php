<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\AmsStoreOrders;
use Illuminate\Http\Request;

/**
 * Store-level AMS restock orders — the buyer's log of what we ordered from
 * AMS and what's still coming. Backed by a JSON sidecar (AmsStoreOrders),
 * no DB table. See that service for the data shape.
 */
class AmsOrderController extends Controller
{
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $statuses = AmsStoreOrders::statuses();

        $filter = request()->input('status', 'open');
        $rows = AmsStoreOrders::all($business_id);

        if ($filter === 'open') {
            $rows = array_values(array_filter($rows, function ($r) {
                return in_array($r['status'] ?? '', AmsStoreOrders::OPEN_STATUSES, true);
            }));
        } elseif ($filter !== '' && $filter !== 'all' && isset($statuses[$filter])) {
            $rows = array_values(array_filter($rows, function ($r) use ($filter) {
                return ($r['status'] ?? '') === $filter;
            }));
        }

        $openCount = count(AmsStoreOrders::open($business_id));

        return view('ams_order.index', compact('rows', 'statuses', 'filter', 'openCount'));
    }

    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id);
        // So she can eyeball what's already on order before placing a new one.
        $openOrders = AmsStoreOrders::open($business_id);

        return view('ams_order.create', compact('locations', 'openOrders'));
    }

    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        $data = $this->validated($request);
        $data['created_by'] = auth()->user()->id;
        $data['created_by_name'] = $this->userName();

        AmsStoreOrders::create($business_id, $data);

        return redirect()->action('AmsOrderController@index')
            ->with('status', ['success' => true, 'msg' => 'AMS order logged.']);
    }

    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $order = AmsStoreOrders::find($business_id, (int) $id);
        if (!$order) {
            return redirect()->action('AmsOrderController@index')
                ->with('status', ['success' => false, 'msg' => 'Order not found.']);
        }

        $locations = BusinessLocation::forDropdown($business_id);
        $statuses = AmsStoreOrders::statuses();

        return view('ams_order.edit', compact('order', 'locations', 'statuses'));
    }

    public function update(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');

        $data = $this->validated($request);
        if ($request->filled('status') && array_key_exists($request->input('status'), AmsStoreOrders::statuses())) {
            $data['status'] = $request->input('status');
        }

        $updated = AmsStoreOrders::update($business_id, (int) $id, $data);
        if (!$updated) {
            return redirect()->action('AmsOrderController@index')
                ->with('status', ['success' => false, 'msg' => 'Order not found.']);
        }

        return redirect()->action('AmsOrderController@index')
            ->with('status', ['success' => true, 'msg' => 'AMS order updated.']);
    }

    /** Flip an order's status (Arrived / Partial / Reopen / Cancel) from the list. */
    public function setStatus(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');
        $status = $request->input('status');
        if (!array_key_exists($status, AmsStoreOrders::statuses())) {
            return redirect()->action('AmsOrderController@index')
                ->with('status', ['success' => false, 'msg' => 'Unknown status.']);
        }

        $fields = ['status' => $status];
        $fields['arrived_at'] = ($status === 'arrived') ? now()->toDateTimeString() : null;

        $updated = AmsStoreOrders::update($business_id, (int) $id, $fields);
        $msg = $updated ? 'Status updated to ' . AmsStoreOrders::statuses()[$status] . '.' : 'Order not found.';

        return redirect()->action('AmsOrderController@index')
            ->with('status', ['success' => (bool) $updated, 'msg' => $msg]);
    }

    public function destroy($id)
    {
        $business_id = request()->session()->get('user.business_id');
        AmsStoreOrders::delete($business_id, (int) $id);

        return redirect()->action('AmsOrderController@index')
            ->with('status', ['success' => true, 'msg' => 'AMS order deleted.']);
    }

    protected function validated(Request $request): array
    {
        $request->validate([
            'store'         => 'required|string|max:120',
            'ordered_date'  => 'required|date',
            'expected_date' => 'nullable|date',
            'ams_ref'       => 'nullable|string|max:120',
            'items'         => 'required|string',
            'notes'         => 'nullable|string',
        ]);

        return [
            'store'         => trim((string) $request->input('store')),
            'ordered_date'  => $request->input('ordered_date'),
            'expected_date' => $request->input('expected_date') ?: null,
            'ams_ref'       => trim((string) $request->input('ams_ref')) ?: null,
            'items'         => (string) $request->input('items'),
            'notes'         => trim((string) $request->input('notes')) ?: null,
        ];
    }

    protected function userName(): string
    {
        $u = auth()->user();
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $name !== '' ? $name : ($u->username ?? '');
    }
}
