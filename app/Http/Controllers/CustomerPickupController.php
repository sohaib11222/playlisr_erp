<?php

namespace App\Http\Controllers;

use App\CustomerPickup;
use App\Product;
use App\Variation;
use App\Contact;
use App\BusinessLocation;
use App\Services\AmsPickupOrders;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use DB;

class CustomerPickupController extends Controller
{
    /**
     * Display a listing of customer pickups.
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $statuses = ['on_order' => 'On Order (AMS)', 'ready' => 'Ready for Pickup', 'picked_up' => 'Picked Up', 'cancelled' => 'Cancelled'];

        if (request()->ajax()) {
            // AMS overlay: ids still inbound show as "On Order" instead of Ready.
            $ams = AmsPickupOrders::load($business_id);
            $onOrderIds = AmsPickupOrders::onOrderIds($business_id);

            $pickups = CustomerPickup::where('customer_pickups.business_id', $business_id)
                ->leftJoin('contacts', 'customer_pickups.contact_id', '=', 'contacts.id')
                ->leftJoin('products', 'customer_pickups.product_id', '=', 'products.id')
                ->leftJoin('variations', 'customer_pickups.variation_id', '=', 'variations.id')
                ->leftJoin('business_locations', 'customer_pickups.location_id', '=', 'business_locations.id')
                ->leftJoin('users as picked_up_users', 'customer_pickups.picked_up_by_user_id', '=', 'picked_up_users.id')
                ->leftJoin('users as creator_users', 'customer_pickups.created_by', '=', 'creator_users.id')
                ->select(
                    'customer_pickups.*',
                    'contacts.name as customer_name',
                    'contacts.mobile',
                    'products.name as product_name',
                    'variations.sub_sku',
                    'business_locations.name as location_name',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(picked_up_users.first_name,''), ' ', COALESCE(picked_up_users.last_name,''))), ''), picked_up_users.username) as picked_up_cashier_name"),
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(creator_users.first_name,''), ' ', COALESCE(creator_users.last_name,''))), ''), creator_users.username) as created_by_name")
                );

            if (request()->has('status') && request()->status != '') {
                $reqStatus = request()->status;
                if ($reqStatus === 'on_order') {
                    // On-order is an overlay flag, not a DB status. Empty list
                    // -> match nothing rather than every row.
                    $pickups->whereIn('customer_pickups.id', $onOrderIds ?: [0]);
                } elseif ($reqStatus === 'ready') {
                    // "Ready" means actually-in-hand: hide still-inbound AMS orders.
                    $pickups->where('customer_pickups.status', 'ready');
                    if (!empty($onOrderIds)) {
                        $pickups->whereNotIn('customer_pickups.id', $onOrderIds);
                    }
                } else {
                    $pickups->where('customer_pickups.status', $reqStatus);
                }
            }

            if (request()->has('contact_id') && request()->contact_id != '') {
                $pickups->where('customer_pickups.contact_id', request()->contact_id);
            }

            return DataTables::of($pickups)
                ->addColumn('action', function ($row) use ($onOrderIds) {
                    $isOnOrder = in_array($row->id, $onOrderIds);
                    $html = '<div class="btn-group">';
                    $html .= '<a href="' . action('CustomerPickupController@show', [$row->id]) . '" class="btn btn-info btn-xs"><i class="fa fa-eye"></i></a>';

                    if ($isOnOrder) {
                        // Still inbound from AMS — the only action is to mark it arrived.
                        $html .= '<button type="button" class="btn btn-success btn-xs mark_arrived" data-href="' . action('CustomerPickupController@markArrived', [$row->id]) . '" data-id="' . $row->id . '"><i class="fa fa-truck"></i> Arrived</button>';
                        $html .= '<button type="button" class="btn btn-danger btn-xs delete_pickup" data-href="' . action('CustomerPickupController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    } elseif ($row->status == 'ready') {
                        $html .= '<button type="button" class="btn btn-success btn-xs mark_picked_up" data-href="' . action('CustomerPickupController@markPickedUp', [$row->id]) . '"><i class="fa fa-check"></i> Picked Up</button>';
                        $html .= '<a href="' . action('CustomerPickupController@edit', [$row->id]) . '" class="btn btn-warning btn-xs"><i class="fa fa-edit"></i></a>';
                        $html .= '<button type="button" class="btn btn-danger btn-xs delete_pickup" data-href="' . action('CustomerPickupController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    }

                    $html .= '</div>';
                    return $html;
                })
                ->editColumn('status', function ($row) use ($onOrderIds, $ams) {
                    if (in_array($row->id, $onOrderIds)) {
                        $meta = $ams[(string) $row->id] ?? [];
                        $out = '<span class="label" style="background:#2C5F8A;">On Order</span>';
                        if (!empty($meta['ams_order_number'])) {
                            $out .= '<br><small>AMS #' . e($meta['ams_order_number']) . '</small>';
                        }
                        if (!empty($meta['expected_date'])) {
                            $out .= '<br><small>ETA ' . \Carbon::parse($meta['expected_date'])->format('n/j/y') . '</small>';
                        }
                        return $out;
                    }
                    $labels = [
                        'ready' => '<span class="label label-warning">Ready for Pickup</span>',
                        'picked_up' => '<span class="label label-success">Picked Up</span>',
                        'cancelled' => '<span class="label label-danger">Cancelled</span>',
                    ];
                    return $labels[$row->status] ?? $row->status;
                })
                ->editColumn('hold_date', function ($row) {
                    // M/D/YY format — e.g. 4/26/26
                    return $row->hold_date ? \Carbon::parse($row->hold_date)->format('n/j/y') : '-';
                })
                ->editColumn('quantity', function ($row) {
                    // Integer only — no decimals ever
                    return (int) $row->quantity;
                })
                ->editColumn('expected_pickup_date', function ($row) {
                    // Guard against null / zero-dates (previous rows showed -0001-11-30
                    // because Carbon::parse(null) returns year -0001).
                    $raw = $row->expected_pickup_date;
                    $hasDate = $raw && !in_array(substr((string) $raw, 0, 4), ['0000', '-000'], true);
                    $d = $hasDate ? \Carbon::parse($raw)->format('n/j/y') : '';
                    $t = $row->expected_pickup_time ? ' ' . $row->expected_pickup_time : '';
                    return trim($d . $t) ?: '-';
                })
                ->addColumn('is_paid_label', function ($row) {
                    return $row->is_paid
                        ? '<span class="label label-success">Paid</span>'
                        : '<span class="label label-default">Unpaid</span>';
                })
                ->addColumn('picked_up_info', function ($row) {
                    if ($row->status != 'picked_up') return '-';
                    $parts = [];
                    if (!empty($row->picked_up_cashier_name) && trim($row->picked_up_cashier_name) !== '') {
                        $parts[] = '<strong>' . e(trim($row->picked_up_cashier_name)) . '</strong>';
                    }
                    if ($row->picked_up_at) {
                        $parts[] = '<small>' . \Carbon::parse($row->picked_up_at)->format('n/j/y g:i A') . '</small>';
                    }
                    if ($row->picked_up_by_name) {
                        $parts[] = '<small>to: ' . e($row->picked_up_by_name) . '</small>';
                    }
                    return $parts ? implode('<br>', $parts) : '-';
                })
                ->addColumn('created_info', function ($row) {
                    $parts = [];
                    if (!empty($row->created_by_name) && trim($row->created_by_name) !== '') {
                        $parts[] = '<strong>' . e(trim($row->created_by_name)) . '</strong>';
                    }
                    if ($row->created_at) {
                        $parts[] = '<small>' . \Carbon::parse($row->created_at)->format('n/j/y g:i A') . '</small>';
                    }
                    return $parts ? implode('<br>', $parts) : '-';
                })
                ->rawColumns(['action', 'status', 'is_paid_label', 'picked_up_info', 'created_info'])
                ->make(true);
        }

        // Party + in-store-special-order preorders, folded in so this page is
        // the one place to see everything a customer is waiting on.
        $preorderShowAll = request()->input('preorder_status') === 'all';
        [$preorders, $preorderKeySet, $preorderReachable] = (new \App\Http\Controllers\EventsController())
            ->preordersRows($business_id, $preorderShowAll);

        return view('customer_pickup.index', compact(
            'statuses',
            'preorders',
            'preorderShowAll',
            'preorderKeySet',
            'preorderReachable'
        ));
    }

    /**
     * Show the form for creating a new pickup.
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        $customers = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->pluck('name', 'id');

        $locations = BusinessLocation::forDropdown($business_id);

        return view('customer_pickup.create', compact('customers', 'locations'));
    }

    /**
     * Store a newly created pickup.
     */
    public function store(Request $request)
    {
        try {
            $business_id = request()->session()->get('user.business_id');

            $request->validate([
                'contact_id' => 'required|exists:contacts,id',
                'product_id' => 'nullable|exists:products,id',
                'variation_id' => 'nullable|exists:variations,id',
                'location_id' => 'nullable|exists:business_locations,id',
                'quantity' => 'required|numeric|min:0.01',
                'hold_date' => 'required|date',
                'expected_pickup_date' => 'nullable|date',
                'expected_pickup_time' => 'nullable|string|max:50',
                'is_paid' => 'nullable',
                'notes' => 'nullable|string',
                'ams_order_number' => 'nullable|string|max:64',
                'is_on_order' => 'nullable',
                'ams_expected_date' => 'nullable|date',
                'notify_email' => 'nullable|email|max:255',
                'notify_phone' => 'nullable|string|max:40',
            ]);

            $pickup = new CustomerPickup();
            $pickup->business_id = $business_id;
            $pickup->contact_id = $request->contact_id;
            $pickup->location_id = $request->location_id;
            $pickup->product_id = $request->product_id;
            $pickup->variation_id = $request->variation_id;
            $pickup->quantity = $request->quantity;
            $pickup->status = 'ready';
            $pickup->hold_date = $request->hold_date;
            $pickup->expected_pickup_date = $request->expected_pickup_date;
            $pickup->expected_pickup_time = $request->expected_pickup_time;
            $pickup->is_paid = $request->has('is_paid') ? 1 : 0;
            $pickup->notes = $request->notes;
            $pickup->created_by = auth()->user()->id;
            $pickup->save();

            // Contact details for the arrival alert. Backfill the customer's
            // contact record where it's blank (so it works everywhere going
            // forward) and also stash on the pickup as a fallback.
            $notifyEmail = trim((string) $request->input('notify_email'));
            $notifyPhone = trim((string) $request->input('notify_phone'));
            if ($notifyEmail !== '' || $notifyPhone !== '') {
                $contact = Contact::find($request->contact_id);
                if ($contact) {
                    if ($notifyEmail !== '' && empty($contact->email)) {
                        $contact->email = $notifyEmail;
                    }
                    if ($notifyPhone !== '' && empty($contact->mobile)) {
                        $contact->mobile = $notifyPhone;
                    }
                    if ($contact->isDirty()) {
                        $contact->save();
                    }
                }
            }

            // AMS special order: record the order number / ETA and (if it
            // isn't in hand yet) flag it "on order" so it shows as inbound
            // and doesn't surface as ready-for-pickup until it arrives.
            $isOnOrder = $request->has('is_on_order');
            $amsNumber = trim((string) $request->input('ams_order_number'));
            if ($isOnOrder || $amsNumber !== '' || $notifyEmail !== '' || $notifyPhone !== '') {
                AmsPickupOrders::put($business_id, $pickup->id, [
                    'ams_order_number' => $amsNumber !== '' ? $amsNumber : null,
                    'expected_date'    => $request->input('ams_expected_date') ?: null,
                    'on_order'         => $isOnOrder,
                    'ams_email'        => $notifyEmail !== '' ? $notifyEmail : null,
                    'ams_phone'        => $notifyPhone !== '' ? $notifyPhone : null,
                ]);
            }

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->action('CustomerPickupController@index')->with('status', $output);
    }

    /**
     * Display the specified pickup.
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $pickup = CustomerPickup::where('business_id', $business_id)
            ->with(['contact', 'product', 'variation', 'creator', 'location', 'transaction', 'pickedUpByUser'])
            ->findOrFail($id);

        return view('customer_pickup.show', compact('pickup'));
    }

    /**
     * Show the form for editing the specified pickup.
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $pickup = CustomerPickup::where('business_id', $business_id)
            ->with('product')
            ->findOrFail($id);

        if ($pickup->status != 'ready') {
            return redirect()->action('CustomerPickupController@index')
                ->with('status', ['success' => false, 'msg' => 'Only ready pickups can be edited']);
        }

        $customers = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->pluck('name', 'id');

        $locations = BusinessLocation::forDropdown($business_id);

        $variations = [];
        if ($pickup->product_id) {
            $variations = Variation::where('product_id', $pickup->product_id)
                ->select(DB::raw("CONCAT(sub_sku, ' - ', name) as name"), 'id')
                ->pluck('name', 'id');
        }

        return view('customer_pickup.edit', compact('pickup', 'customers', 'locations', 'variations'));
    }

    /**
     * Update the specified pickup.
     */
    public function update(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $pickup = CustomerPickup::where('business_id', $business_id)->findOrFail($id);

            if ($pickup->status != 'ready') {
                return redirect()->action('CustomerPickupController@index')
                    ->with('status', ['success' => false, 'msg' => 'Only ready pickups can be updated']);
            }

            $request->validate([
                'contact_id' => 'required|exists:contacts,id',
                'product_id' => 'nullable|exists:products,id',
                'variation_id' => 'nullable|exists:variations,id',
                'location_id' => 'nullable|exists:business_locations,id',
                'quantity' => 'required|numeric|min:0.01',
                'hold_date' => 'required|date',
                'expected_pickup_date' => 'nullable|date',
                'expected_pickup_time' => 'nullable|string|max:50',
                'is_paid' => 'nullable',
                'notes' => 'nullable|string',
            ]);

            $pickup->contact_id = $request->contact_id;
            $pickup->location_id = $request->location_id;
            $pickup->product_id = $request->product_id;
            $pickup->variation_id = $request->variation_id;
            $pickup->quantity = $request->quantity;
            $pickup->hold_date = $request->hold_date;
            $pickup->expected_pickup_date = $request->expected_pickup_date;
            $pickup->expected_pickup_time = $request->expected_pickup_time;
            $pickup->is_paid = $request->has('is_paid') ? 1 : 0;
            $pickup->notes = $request->notes;
            $pickup->save();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->action('CustomerPickupController@index')->with('status', $output);
    }

    /**
     * Remove the specified pickup.
     */
    public function destroy($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $pickup = CustomerPickup::where('business_id', $business_id)->findOrFail($id);

            // On-order AMS rows carry DB status 'ready', so they delete via the
            // same path; we just also drop their sidecar entry below.
            if ($pickup->status != 'ready') {
                $output = [
                    'success' => false,
                    'msg' => 'Only ready pickups can be deleted',
                ];
            } else {
                $pickup->delete();
                AmsPickupOrders::forget($business_id, $pickup->id);
                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success'),
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Mark a pickup as picked up by the customer.
     */
    public function markPickedUp(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $pickup = CustomerPickup::where('business_id', $business_id)->findOrFail($id);

            if ($pickup->status != 'ready') {
                $output = [
                    'success' => false,
                    'msg' => 'Only ready pickups can be marked as picked up',
                ];
            } else {
                $pickup->status = 'picked_up';
                $pickup->picked_up_at = now();
                $pickup->picked_up_by_name = $request->input('picked_up_by_name');
                $pickup->picked_up_by_user_id = auth()->user()->id;
                $pickup->save();
                $output = [
                    'success' => true,
                    'msg' => 'Pickup marked as completed',
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Get ready pickups for a specific customer (AJAX).
     */
    public function getCustomerPickups($contact_id)
    {
        $business_id = request()->session()->get('user.business_id');
        $onOrderIds = AmsPickupOrders::onOrderIds($business_id);

        $pickups = CustomerPickup::where('customer_pickups.business_id', $business_id)
            ->where('customer_pickups.contact_id', $contact_id)
            ->where('customer_pickups.status', 'ready')
            ->when(!empty($onOrderIds), function ($q) use ($onOrderIds) {
                $q->whereNotIn('customer_pickups.id', $onOrderIds);
            })
            ->leftJoin('products', 'customer_pickups.product_id', '=', 'products.id')
            ->leftJoin('variations', 'customer_pickups.variation_id', '=', 'variations.id')
            ->select(
                'customer_pickups.*',
                'products.name as product_name',
                'products.artist',
                'variations.sub_sku'
            )
            ->orderBy('customer_pickups.hold_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'pickups' => $pickups,
        ]);
    }

    /**
     * POS sidebar feed: a customer's ready pickups + still-inbound AMS
     * orders. Used to flag "you've got an order waiting" when the cashier
     * pulls up the customer. Defensive — never throws at the POS.
     */
    public function forContact($contact_id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $ams = AmsPickupOrders::load($business_id);
            $onOrderIds = AmsPickupOrders::onOrderIds($business_id);

            $rows = CustomerPickup::where('customer_pickups.business_id', $business_id)
                ->where('customer_pickups.contact_id', $contact_id)
                ->where('customer_pickups.status', 'ready')
                ->leftJoin('products', 'customer_pickups.product_id', '=', 'products.id')
                ->leftJoin('variations', 'customer_pickups.variation_id', '=', 'variations.id')
                ->select(
                    'customer_pickups.*',
                    'products.name as product_name',
                    'products.artist',
                    'variations.sub_sku'
                )
                ->orderBy('customer_pickups.hold_date', 'desc')
                ->get();

            $ready = [];
            $onOrder = [];
            foreach ($rows as $r) {
                $label = trim(implode(' — ', array_filter([trim((string) $r->artist), trim((string) $r->product_name)])));
                $meta = $ams[(string) $r->id] ?? [];
                $item = [
                    'id' => $r->id,
                    'label' => $label !== '' ? $label : ($r->sub_sku ?: ('Pickup #' . $r->id)),
                    'qty' => (int) $r->quantity,
                    'is_paid' => (bool) $r->is_paid,
                    'ams_order_number' => $meta['ams_order_number'] ?? null,
                    'expected_date' => $meta['expected_date'] ?? null,
                ];
                if (in_array($r->id, $onOrderIds)) {
                    $onOrder[] = $item;
                } else {
                    $ready[] = $item;
                }
            }

            return response()->json(['success' => true, 'ready' => $ready, 'on_order' => $onOrder]);
        } catch (\Throwable $e) {
            \Log::warning('CustomerPickup forContact failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'ready' => [], 'on_order' => []]);
        }
    }

    /**
     * Mark an inbound AMS special order as arrived. Clears the on-order flag
     * (so the row becomes a normal Ready pickup) and optionally notifies the
     * customer by email / SMS that it's ready for pickup.
     */
    public function markArrived(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $pickup = CustomerPickup::where('business_id', $business_id)
                ->with(['contact', 'product', 'variation', 'location'])
                ->findOrFail($id);

            $meta = AmsPickupOrders::get($business_id, $pickup->id);
            if (!$meta || empty($meta['on_order'])) {
                return [
                    'success' => false,
                    'msg' => 'This order is not marked on-order.',
                ];
            }

            // Flip it to in-hand. The pickup row already carries status 'ready'.
            AmsPickupOrders::put($business_id, $pickup->id, [
                'on_order' => false,
                'arrived_at' => now()->toDateTimeString(),
            ]);

            $notifyMethod = strtolower((string) $request->input('notify_method', 'none'));
            $channels = [];
            if (in_array($notifyMethod, ['email', 'both'])) $channels[] = 'email';
            if (in_array($notifyMethod, ['sms', 'both']))   $channels[] = 'sms';

            $notifyResults = \App\Services\AmsArrivalNotifier::notifyCustomer($pickup, $channels);
            if (!empty($channels)) {
                AmsPickupOrders::put($business_id, $pickup->id, ['notified' => true]);
            }

            return [
                'success' => true,
                'msg' => 'Marked arrived — now ready for pickup.',
                'notifications' => $notifyResults,
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }
    }
}
