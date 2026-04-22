<?php

namespace App\Http\Controllers;

use App\CustomerPickup;
use App\Product;
use App\Variation;
use App\Contact;
use App\BusinessLocation;
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
        $statuses = ['ready' => 'Ready for Pickup', 'picked_up' => 'Picked Up', 'cancelled' => 'Cancelled'];

        if (request()->ajax()) {
            $pickups = CustomerPickup::where('customer_pickups.business_id', $business_id)
                ->leftJoin('contacts', 'customer_pickups.contact_id', '=', 'contacts.id')
                ->leftJoin('products', 'customer_pickups.product_id', '=', 'products.id')
                ->leftJoin('variations', 'customer_pickups.variation_id', '=', 'variations.id')
                ->leftJoin('business_locations', 'customer_pickups.location_id', '=', 'business_locations.id')
                ->select(
                    'customer_pickups.*',
                    'contacts.name as customer_name',
                    'contacts.mobile',
                    'products.name as product_name',
                    'variations.sub_sku',
                    'business_locations.name as location_name'
                );

            if (request()->has('status') && request()->status != '') {
                $pickups->where('customer_pickups.status', request()->status);
            }

            if (request()->has('contact_id') && request()->contact_id != '') {
                $pickups->where('customer_pickups.contact_id', request()->contact_id);
            }

            return DataTables::of($pickups)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">';
                    $html .= '<a href="' . action('CustomerPickupController@show', [$row->id]) . '" class="btn btn-info btn-xs"><i class="fa fa-eye"></i></a>';

                    if ($row->status == 'ready') {
                        $html .= '<button type="button" class="btn btn-success btn-xs mark_picked_up" data-href="' . action('CustomerPickupController@markPickedUp', [$row->id]) . '"><i class="fa fa-check"></i> Picked Up</button>';
                        $html .= '<a href="' . action('CustomerPickupController@edit', [$row->id]) . '" class="btn btn-warning btn-xs"><i class="fa fa-edit"></i></a>';
                        $html .= '<button type="button" class="btn btn-danger btn-xs delete_pickup" data-href="' . action('CustomerPickupController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    }

                    $html .= '</div>';
                    return $html;
                })
                ->editColumn('status', function ($row) {
                    $labels = [
                        'ready' => '<span class="label label-warning">Ready for Pickup</span>',
                        'picked_up' => '<span class="label label-success">Picked Up</span>',
                        'cancelled' => '<span class="label label-danger">Cancelled</span>',
                    ];
                    return $labels[$row->status] ?? $row->status;
                })
                ->editColumn('hold_date', function ($row) {
                    return \Carbon::parse($row->hold_date)->format('Y-m-d');
                })
                ->editColumn('expected_pickup_date', function ($row) {
                    return $row->expected_pickup_date ? \Carbon::parse($row->expected_pickup_date)->format('Y-m-d') : '-';
                })
                ->rawColumns(['action', 'status'])
                ->make(true);
        }

        return view('customer_pickup.index', compact('statuses'));
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
                'notes' => 'nullable|string',
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
            $pickup->notes = $request->notes;
            $pickup->created_by = auth()->user()->id;
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
     * Display the specified pickup.
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $pickup = CustomerPickup::where('business_id', $business_id)
            ->with(['contact', 'product', 'variation', 'creator', 'location', 'transaction'])
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
                'notes' => 'nullable|string',
            ]);

            $pickup->contact_id = $request->contact_id;
            $pickup->location_id = $request->location_id;
            $pickup->product_id = $request->product_id;
            $pickup->variation_id = $request->variation_id;
            $pickup->quantity = $request->quantity;
            $pickup->hold_date = $request->hold_date;
            $pickup->expected_pickup_date = $request->expected_pickup_date;
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

            if ($pickup->status != 'ready') {
                $output = [
                    'success' => false,
                    'msg' => 'Only ready pickups can be deleted',
                ];
            } else {
                $pickup->delete();
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

        $pickups = CustomerPickup::where('customer_pickups.business_id', $business_id)
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

        return response()->json([
            'success' => true,
            'pickups' => $pickups,
        ]);
    }
}
