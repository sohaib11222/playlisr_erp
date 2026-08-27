<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\ProductRack;
use App\ReceivingItem;
use App\ReceivingPackage;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\Util;
use App\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Yajra\DataTables\Facades\DataTables;

class ReceivingPackageController extends Controller
{
    protected $productUtil;
    protected $commonUtil;

    public function __construct(ProductUtil $productUtil, Util $commonUtil)
    {
        $this->productUtil = $productUtil;
        $this->commonUtil = $commonUtil;
    }

    /**
     * List of received packages.
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {
            $packages = ReceivingPackage::where('receiving_packages.business_id', $business_id)
                ->leftJoin('business_locations', 'receiving_packages.location_id', '=', 'business_locations.id')
                ->leftJoin('users', 'receiving_packages.received_by', '=', 'users.id')
                ->select(
                    'receiving_packages.*',
                    'business_locations.name as location_name',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(users.first_name,''), ' ', COALESCE(users.last_name,''))), ''), users.username) as received_by_name")
                )
                // Must come after select() — withCount()'s subselects use
                // addSelect(), so select() called after it would wipe them out.
                ->withCount(['items', 'items as priced_items_count' => function ($q) {
                    $q->where('status', 'priced');
                }]);

            if (request()->has('status') && request()->status != '') {
                $packages->where('receiving_packages.status', request()->status);
            }
            if (request()->has('package_type') && request()->package_type != '') {
                $packages->where('receiving_packages.package_type', request()->package_type);
            }

            $isAdmin = $this->isAdmin();

            return DataTables::of($packages)
                ->addColumn('action', function ($row) use ($isAdmin) {
                    $html = '<a href="' . action('ReceivingPackageController@show', [$row->id]) . '" class="btn btn-info btn-xs"><i class="fa fa-eye"></i> Open</a>';
                    if ($isAdmin) {
                        $html .= ' <a href="' . action('ReceivingPackageController@edit', [$row->id]) . '" class="btn btn-warning btn-xs"><i class="fa fa-edit"></i></a>';
                        $html .= ' <button type="button" class="btn btn-danger btn-xs delete_package" data-href="' . action('ReceivingPackageController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    }
                    return $html;
                })
                ->editColumn('package_type', function ($row) {
                    $label = ReceivingPackage::$packageTypes[$row->package_type] ?? $row->package_type;
                    return $row->package_type_detail ? $label . ' — ' . e($row->package_type_detail) : $label;
                })
                ->editColumn('status', function ($row) {
                    return $row->status == 'open'
                        ? '<span class="label label-warning">Open</span>'
                        : '<span class="label label-success">Closed</span>';
                })
                ->addColumn('items_progress', function ($row) {
                    return $row->priced_items_count . ' / ' . $row->items_count . ' priced';
                })
                ->editColumn('received_at', function ($row) {
                    return $row->received_at ? \Carbon::parse($row->received_at)->format('n/j/y g:i A') : '-';
                })
                ->rawColumns(['action', 'status'])
                ->make(true);
        }

        $statuses = ['open' => 'Open (receiving window)', 'closed' => 'Closed'];
        $isAdmin = $this->isAdmin();

        return view('receiving.index', compact('statuses', 'isAdmin'));
    }

    /**
     * Quick intake form — log a package as it arrives.
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id);

        return view('receiving.create', [
            'locations' => $locations,
            'packageTypes' => ReceivingPackage::$packageTypes,
        ]);
    }

    /**
     * Store a newly logged package.
     */
    public function store(Request $request)
    {
        try {
            $business_id = request()->session()->get('user.business_id');

            $request->validate([
                'location_id' => 'required|exists:business_locations,id',
                'bin_location' => 'nullable|string|max:255',
                'package_type' => 'required|in:' . implode(',', array_keys(ReceivingPackage::$packageTypes)),
                'package_type_detail' => 'nullable|string|max:255',
                'order_number' => 'nullable|string|max:255',
                'invoice_number' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'purchase_order_ids' => 'nullable|array',
                'purchase_order_ids.*' => 'exists:transactions,id',
            ]);

            $package = new ReceivingPackage();
            $package->business_id = $business_id;
            $package->location_id = $request->location_id;
            $package->bin_location = $request->bin_location;
            $package->package_type = $request->package_type;
            $package->package_type_detail = $request->package_type_detail;
            $package->order_number = $request->order_number;
            $package->invoice_number = $request->invoice_number;
            $package->notes = $request->notes;
            $package->status = 'open';
            $package->received_by = auth()->user()->id;
            $package->received_at = now();
            $package->save();

            if (!empty($request->purchase_order_ids)) {
                $package->purchaseOrders()->sync($request->purchase_order_ids);
            }

            $this->commonUtil->activityLog($package, 'received', null, [], false, $business_id);

            $output = ['success' => true, 'msg' => 'Package logged.', 'id' => $package->id];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        if ($request->ajax()) {
            return $output;
        }

        return redirect()->action('ReceivingPackageController@show', [$package->id ?? null])->with('status', $output);
    }

    /**
     * "What's inside the box" — view + log contents.
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $package = ReceivingPackage::where('business_id', $business_id)
            ->with(['location', 'receiver', 'purchaseOrders', 'items' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }, 'items.pricedByUser'])
            ->findOrFail($id);

        $activities = Activity::forSubject($package)->with('causer')->latest()->get();
        $isAdmin = $this->isAdmin();

        return view('receiving.show', compact('package', 'activities', 'isAdmin'));
    }

    /**
     * Scan/add an item into an open package.
     */
    public function addItem(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);

            if ($package->status != 'open') {
                return ['success' => false, 'msg' => 'This receiving window is closed.'];
            }

            $request->validate([
                'product_id' => 'nullable|exists:products,id',
                'variation_id' => 'nullable|exists:variations,id',
                'sku' => 'nullable|string|max:255',
                'product_name' => 'nullable|string|max:255',
                'quantity' => 'required|numeric|min:0.01',
                'cost_price' => 'nullable|numeric|min:0',
                'msrp' => 'nullable|numeric|min:0',
                'pending_sell_price' => 'nullable|numeric|min:0',
                'rack' => 'nullable|string|max:255',
            ]);

            $item = new ReceivingItem();
            $item->receiving_package_id = $package->id;
            $item->product_id = $request->product_id;
            $item->variation_id = $request->variation_id;
            $item->sku = $request->sku;
            $item->product_name = $request->product_name;
            $item->quantity = $request->quantity;
            $item->cost_price = $request->cost_price;
            $item->msrp = $request->msrp;
            $item->pending_sell_price = $request->pending_sell_price;
            $item->rack = $request->rack;
            $item->status = 'in_progress';
            $item->save();

            $this->commonUtil->activityLog($item, 'item_added', null, [], false, $business_id);

            return ['success' => true, 'msg' => 'Item added.', 'item' => $this->formatItem($item)];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Edit an item's cost/MSRP/pending sell price/rack while still in progress.
     */
    public function updateItem(Request $request, $id, $itemId)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);
            $item = ReceivingItem::where('receiving_package_id', $package->id)->findOrFail($itemId);

            $request->validate([
                'quantity' => 'required|numeric|min:0.01',
                'cost_price' => 'nullable|numeric|min:0',
                'msrp' => 'nullable|numeric|min:0',
                'pending_sell_price' => 'nullable|numeric|min:0',
                'rack' => 'nullable|string|max:255',
            ]);

            $item->quantity = $request->quantity;
            $item->cost_price = $request->cost_price;
            $item->msrp = $request->msrp;
            $item->pending_sell_price = $request->pending_sell_price;
            $item->rack = $request->rack;
            $item->save();

            return ['success' => true, 'msg' => 'Item updated.', 'item' => $this->formatItem($item)];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Finalize pricing on an item: pushes the staged sell price live and
     * records the shelf location — the one point where receiving data
     * touches the live catalog.
     */
    public function markPriced(Request $request, $id, $itemId)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);
            $item = ReceivingItem::where('receiving_package_id', $package->id)->findOrFail($itemId);

            if (empty($item->pending_sell_price)) {
                return ['success' => false, 'msg' => 'Set a sell price before marking this priced.'];
            }

            DB::beginTransaction();

            if ($item->variation_id) {
                $variation = Variation::find($item->variation_id);
                if ($variation) {
                    $variation->default_sell_price = $item->pending_sell_price;
                    $variation->save();
                }
            }

            if ($item->product_id && !empty($item->rack)) {
                $existing = ProductRack::where('business_id', $business_id)
                    ->where('product_id', $item->product_id)
                    ->where('location_id', $package->location_id)
                    ->first();

                if ($existing) {
                    $this->productUtil->updateRackDetails($business_id, $item->product_id, [
                        $package->location_id => ['rack' => $item->rack],
                    ]);
                } else {
                    $this->productUtil->addRackDetails($business_id, $item->product_id, [
                        $package->location_id => ['rack' => $item->rack],
                    ]);
                }
            }

            $item->status = 'priced';
            $item->priced_by = auth()->user()->id;
            $item->priced_at = now();
            $item->save();

            DB::commit();

            $this->commonUtil->activityLog($item, 'priced', null, [], false, $business_id);

            return ['success' => true, 'msg' => 'Priced and shelved.', 'item' => $this->formatItem($item)];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Cross-package "bin" — every item left in progress, for any employee
     * to pick up and finish, regardless of who logged it.
     */
    public function inProgressQueue()
    {
        $business_id = request()->session()->get('user.business_id');

        $items = ReceivingItem::whereHas('package', function ($q) use ($business_id) {
                $q->where('business_id', $business_id);
            })
            ->where('status', 'in_progress')
            ->with(['package.location'])
            ->orderBy('created_at')
            ->get();

        return view('receiving.in_progress', compact('items'));
    }

    /**
     * Close the receiving window once everything inside the box is logged.
     * Items can still be priced afterward — this just marks intake done.
     */
    public function close($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);
            $package->status = 'closed';
            $package->save();

            $this->commonUtil->activityLog($package, 'closed', null, [], false, $business_id);

            return ['success' => true, 'msg' => 'Receiving window closed.'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Quick bin/location move — open to any staff, since physically moving
     * the box around is routine, not a mistake to correct.
     */
    public function updateBin(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);

            $request->validate(['bin_location' => 'nullable|string|max:255']);

            $package->bin_location = $request->bin_location;
            $package->save();

            return ['success' => true, 'msg' => 'Bin location updated.', 'bin_location' => $package->bin_location];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }
    }

    /**
     * Correct a package logged in error — admin only. Rewriting store/type/
     * order info is a mistake-fix, not routine receiving work.
     */
    public function edit($id)
    {
        if (!$this->isAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);
        $locations = BusinessLocation::forDropdown($business_id);

        return view('receiving.edit', [
            'package' => $package,
            'locations' => $locations,
            'packageTypes' => ReceivingPackage::$packageTypes,
        ]);
    }

    public function update(Request $request, $id)
    {
        if (!$this->isAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);

            $request->validate([
                'location_id' => 'required|exists:business_locations,id',
                'bin_location' => 'nullable|string|max:255',
                'package_type' => 'required|in:' . implode(',', array_keys(ReceivingPackage::$packageTypes)),
                'package_type_detail' => 'nullable|string|max:255',
                'order_number' => 'nullable|string|max:255',
                'invoice_number' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            $before = clone $package;

            $package->location_id = $request->location_id;
            $package->bin_location = $request->bin_location;
            $package->package_type = $request->package_type;
            $package->package_type_detail = $request->package_type_detail;
            $package->order_number = $request->order_number;
            $package->invoice_number = $request->invoice_number;
            $package->notes = $request->notes;
            $package->save();

            $this->commonUtil->activityLog($package, 'edited', $before, [], true, $business_id);

            $output = ['success' => true, 'msg' => 'Package updated.'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return redirect()->action('ReceivingPackageController@show', [$id])->with('status', $output);
    }

    /**
     * Delete a package logged in error — admin only. Cascades to its items
     * and PO links via FK.
     */
    public function destroy($id)
    {
        if (!$this->isAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $package = ReceivingPackage::where('business_id', $business_id)->findOrFail($id);
            $package->delete();

            $output = ['success' => true, 'msg' => 'Package deleted.'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    private function isAdmin()
    {
        return auth()->user()->hasRole('Admin#' . session('business.id'));
    }

    /**
     * Select2 source for attaching open purchase orders to a package.
     */
    public function searchPurchaseOrders(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');
        $term = $request->input('term', '');

        $orders = Transaction::where('transactions.business_id', $business_id)
            ->where('transactions.type', 'purchase_order')
            ->whereIn('transactions.status', ['ordered', 'partial'])
            ->leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($q2) use ($term) {
                    $q2->where('transactions.ref_no', 'like', '%' . $term . '%')
                        ->orWhere('contacts.name', 'like', '%' . $term . '%');
                });
            })
            ->select('transactions.id', 'transactions.ref_no', 'contacts.name as supplier_name')
            ->limit(20)
            ->get();

        $results = $orders->map(function ($o) {
            return ['id' => $o->id, 'text' => ($o->ref_no ?: ('PO #' . $o->id)) . ' — ' . ($o->supplier_name ?: 'Unknown supplier')];
        });

        return response()->json(['results' => $results]);
    }

    private function formatItem(ReceivingItem $item)
    {
        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'product_name' => $item->product_name,
            'quantity' => (float) $item->quantity,
            'cost_price' => $item->cost_price,
            'msrp' => $item->msrp,
            'pending_sell_price' => $item->pending_sell_price,
            'rack' => $item->rack,
            'status' => $item->status,
        ];
    }
}
