<?php

namespace App\Http\Controllers;

use App\Preorder;
use App\Product;
use App\Variation;
use App\Contact;
use App\BusinessLocation;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use DB;

class PreorderController extends Controller
{
    /**
     * Display a listing of preorders.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (!auth()->user()->can('preorder.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $statuses = ['pending' => 'Pending', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancelled'];

        if (request()->ajax()) {
            $preorders = Preorder::where('preorders.business_id', $business_id)
                ->leftJoin('contacts', 'preorders.contact_id', '=', 'contacts.id')
                ->leftJoin('products', 'preorders.product_id', '=', 'products.id')
                ->leftJoin('variations', 'preorders.variation_id', '=', 'variations.id')
                ->select(
                    'preorders.*',
                    'contacts.name as customer_name',
                    'contacts.mobile',
                    'products.name as product_name',
                    'variations.sub_sku'
                );

            // Filter by status
            if (request()->has('status') && request()->status != '') {
                $preorders->where('preorders.status', request()->status);
            }

            // Filter by customer
            if (request()->has('contact_id') && request()->contact_id != '') {
                $preorders->where('preorders.contact_id', request()->contact_id);
            }

            return DataTables::of($preorders)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">';
                    $html .= '<button type="button" class="btn btn-info btn-xs view_preorder" data-href="' . action('PreorderController@show', [$row->id]) . '"><i class="fa fa-eye"></i></button>';
                    
                    if ($row->status == 'pending') {
                        $html .= '<button type="button" class="btn btn-success btn-xs fulfill_preorder" data-href="' . action('PreorderController@fulfill', [$row->id]) . '"><i class="fa fa-check"></i> Fulfill</button>';
                        $html .= '<button type="button" class="btn btn-warning btn-xs edit_preorder" data-href="' . action('PreorderController@edit', [$row->id]) . '"><i class="fa fa-edit"></i></button>';
                        $html .= '<button type="button" class="btn btn-danger btn-xs delete_preorder" data-href="' . action('PreorderController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    }
                    
                    $html .= '</div>';
                    return $html;
                })
                ->editColumn('status', function ($row) {
                    $statuses = [
                        'pending' => '<span class="label label-warning">Pending</span>',
                        'fulfilled' => '<span class="label label-success">Fulfilled</span>',
                        'cancelled' => '<span class="label label-danger">Cancelled</span>'
                    ];
                    return $statuses[$row->status] ?? $row->status;
                })
                ->editColumn('order_date', function ($row) {
                    return \Carbon::parse($row->order_date)->format('Y-m-d');
                })
                ->editColumn('expected_date', function ($row) {
                    return $row->expected_date ? \Carbon::parse($row->expected_date)->format('Y-m-d') : '-';
                })
                ->rawColumns(['action', 'status'])
                ->make(true);
        }

        return view('preorder.index', compact('statuses'));
    }

    /**
     * Show the form for creating a new preorder.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (!auth()->user()->can('preorder.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $customers = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->pluck('name', 'id');
        
        $products = Product::where('business_id', $business_id)
            ->where('not_for_selling', 0)
            ->pluck('name', 'id');

        return view('preorder.create', compact('customers', 'products'));
    }

    /**
     * Store a newly created preorder.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('preorder.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            
            $request->validate([
                'contact_id' => 'required|exists:contacts,id',
                'product_id' => 'required|exists:products,id',
                'variation_id' => 'nullable|exists:variations,id',
                'quantity' => 'required|numeric|min:0.01',
                'order_date' => 'required|date',
                'expected_date' => 'nullable|date',
                'notes' => 'nullable|string'
            ]);

            $preorder = new Preorder();
            $preorder->business_id = $business_id;
            $preorder->contact_id = $request->contact_id;
            $preorder->product_id = $request->product_id;
            $preorder->variation_id = $request->variation_id;
            $preorder->quantity = $request->quantity;
            $preorder->status = 'pending';
            $preorder->order_date = $request->order_date;
            $preorder->expected_date = $request->expected_date;
            $preorder->notes = $request->notes;
            $preorder->created_by = auth()->user()->id;
            $preorder->save();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return redirect()->action('PreorderController@index')->with('status', $output);
    }

    /**
     * Display the specified preorder.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('preorder.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $preorder = Preorder::where('business_id', $business_id)
            ->with(['contact', 'product', 'variation', 'creator'])
            ->findOrFail($id);

        return view('preorder.show', compact('preorder'));
    }

    /**
     * Show the form for editing the specified preorder.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('preorder.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $preorder = Preorder::where('business_id', $business_id)
            ->with('product')
            ->findOrFail($id);

        if ($preorder->status != 'pending') {
            return redirect()->action('PreorderController@index')
                ->with('status', ['success' => false, 'msg' => 'Only pending preorders can be edited']);
        }

        $customers = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->pluck('name', 'id');
        
        $products = Product::where('business_id', $business_id)
            ->where('not_for_selling', 0)
            ->pluck('name', 'id');

        $variations = [];
        if ($preorder->product_id) {
            $variations = Variation::where('product_id', $preorder->product_id)
                ->select(DB::raw("CONCAT(sub_sku, ' - ', name) as name"), 'id')
                ->pluck('name', 'id');
        }

        return view('preorder.edit', compact('preorder', 'customers', 'products', 'variations'));
    }

    /**
     * Update the specified preorder.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('preorder.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $preorder = Preorder::where('business_id', $business_id)->findOrFail($id);

            if ($preorder->status != 'pending') {
                return redirect()->action('PreorderController@index')
                    ->with('status', ['success' => false, 'msg' => 'Only pending preorders can be updated']);
            }

            $request->validate([
                'contact_id' => 'required|exists:contacts,id',
                'product_id' => 'required|exists:products,id',
                'variation_id' => 'nullable|exists:variations,id',
                'quantity' => 'required|numeric|min:0.01',
                'order_date' => 'required|date',
                'expected_date' => 'nullable|date',
                'notes' => 'nullable|string'
            ]);

            $preorder->contact_id = $request->contact_id;
            $preorder->product_id = $request->product_id;
            $preorder->variation_id = $request->variation_id;
            $preorder->quantity = $request->quantity;
            $preorder->order_date = $request->order_date;
            $preorder->expected_date = $request->expected_date;
            $preorder->notes = $request->notes;
            $preorder->save();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success')
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return redirect()->action('PreorderController@index')->with('status', $output);
    }

    /**
     * Remove the specified preorder.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('preorder.delete')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $preorder = Preorder::where('business_id', $business_id)->findOrFail($id);

            if ($preorder->status != 'pending') {
                $output = [
                    'success' => false,
                    'msg' => 'Only pending preorders can be deleted'
                ];
            } else {
                $preorder->delete();
                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success')
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Fulfill a preorder (mark as fulfilled).
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function fulfill($id)
    {
        if (!auth()->user()->can('preorder.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $preorder = Preorder::where('business_id', $business_id)->findOrFail($id);

            if ($preorder->status != 'pending') {
                $output = [
                    'success' => false,
                    'msg' => 'Only pending preorders can be fulfilled'
                ];
            } else {
                $preorder->status = 'fulfilled';
                $preorder->save();
                $output = [
                    'success' => true,
                    'msg' => 'Preorder marked as fulfilled'
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Get preorders for a specific customer (for POS/AJAX).
     *
     * @param  int  $contact_id
     * @return \Illuminate\Http\Response
     */
    public function getCustomerPreorders($contact_id)
    {
        $business_id = request()->session()->get('user.business_id');
        
        $preorders = Preorder::where('preorders.business_id', $business_id)
            ->where('preorders.contact_id', $contact_id)
            ->where('preorders.status', 'pending')
            ->leftJoin('products', 'preorders.product_id', '=', 'products.id')
            ->leftJoin('variations', 'preorders.variation_id', '=', 'variations.id')
            ->select(
                'preorders.*',
                'products.name as product_name',
                'products.artist',
                'variations.sub_sku'
            )
            ->orderBy('preorders.order_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'preorders' => $preorders
        ]);
    }
}
