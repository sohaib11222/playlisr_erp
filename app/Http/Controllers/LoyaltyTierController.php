<?php

namespace App\Http\Controllers;

use App\LoyaltyTier;
use Illuminate\Http\Request;

class LoyaltyTierController extends Controller
{
    /**
     * Display a listing of loyalty tiers
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Allow all authenticated users to view loyalty tiers (or add permission check later)
        // if (!auth()->user()->can('loyalty_tier.view')) {
        //     abort(403, 'Unauthorized action.');
        // }

        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {
            $tiers = LoyaltyTier::where('business_id', $business_id)
                ->select('loyalty_tiers.*');

            return \DataTables::of($tiers)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">
                        <button type="button" class="btn btn-info dropdown-toggle btn-xs" data-toggle="dropdown" aria-expanded="false">' .
                        __("messages.actions") .
                        '<span class="caret"></span><span class="sr-only">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-left" role="menu">';

                    // Show edit and delete for all users (or add permission check later)
                    $html .= '<li><a href="#" data-href="' . action('LoyaltyTierController@edit', [$row->id]) . '" class="edit_tier_button"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a></li>';
                    $html .= '<li><a href="' . action('LoyaltyTierController@destroy', [$row->id]) . '" class="delete_tier_button"><i class="glyphicon glyphicon-trash"></i> ' . __("messages.delete") . '</a></li>';

                    $html .= '</ul></div>';
                    return $html;
                })
                ->editColumn('min_lifetime_purchases', function ($row) {
                    return '<span class="display_currency" data-currency_symbol="true">' . $row->min_lifetime_purchases . '</span>';
                })
                ->editColumn('discount_percentage', function ($row) {
                    return $row->discount_percentage . '%';
                })
                ->editColumn('points_multiplier', function ($row) {
                    return number_format($row->points_multiplier, 2) . 'x';
                })
                ->editColumn('is_active', function ($row) {
                    return $row->is_active ? '<span class="label label-success">Active</span>' : '<span class="label label-default">Inactive</span>';
                })
                ->rawColumns(['action', 'min_lifetime_purchases', 'discount_percentage', 'points_multiplier', 'is_active'])
                ->removeColumn('id')
                ->make(true);
        }

        return view('loyalty_tier.index');
    }

    /**
     * Store a newly created loyalty tier
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Allow all authenticated users to create loyalty tiers (or add permission check later)
        // if (!auth()->user()->can('loyalty_tier.create')) {
        //     abort(403, 'Unauthorized action.');
        // }

        try {
            $business_id = $request->session()->get('user.business_id');

            $request->validate([
                'name' => 'required|string|max:255',
                'min_lifetime_purchases' => 'required|numeric|min:0',
                'discount_percentage' => 'nullable|integer|min:0|max:100',
                'points_multiplier' => 'nullable|numeric|min:0',
                'sort_order' => 'nullable|integer',
            ]);

            $tier = new LoyaltyTier();
            $tier->business_id = $business_id;
            $tier->name = $request->name;
            $tier->description = $request->description;
            $tier->min_lifetime_purchases = $request->min_lifetime_purchases;
            $tier->discount_percentage = $request->discount_percentage ?? 0;
            $tier->points_multiplier = $request->points_multiplier ?? 1;
            $tier->sort_order = $request->sort_order ?? 0;
            $tier->is_active = $request->has('is_active') ? true : false;
            $tier->save();

            $output = [
                'success' => true,
                'msg' => 'Loyalty tier created successfully'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => 'Something went wrong: ' . $e->getMessage()
            ];
        }

        if ($request->ajax()) {
            return response()->json($output);
        }

        return redirect()->action('LoyaltyTierController@index')->with('status', $output);
    }

    /**
     * Show the form for editing the specified loyalty tier
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        // Allow all authenticated users to update loyalty tiers (or add permission check later)
        // if (!auth()->user()->can('loyalty_tier.update')) {
        //     abort(403, 'Unauthorized action.');
        // }

        $business_id = request()->session()->get('user.business_id');
        $tier = LoyaltyTier::where('business_id', $business_id)->findOrFail($id);

        return view('loyalty_tier.edit')->with(compact('tier'));
    }

    /**
     * Update the specified loyalty tier
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Allow all authenticated users to update loyalty tiers (or add permission check later)
        // if (!auth()->user()->can('loyalty_tier.update')) {
        //     abort(403, 'Unauthorized action.');
        // }

        try {
            $business_id = $request->session()->get('user.business_id');
            $tier = LoyaltyTier::where('business_id', $business_id)->findOrFail($id);

            $request->validate([
                'name' => 'required|string|max:255',
                'min_lifetime_purchases' => 'required|numeric|min:0',
                'discount_percentage' => 'nullable|integer|min:0|max:100',
                'points_multiplier' => 'nullable|numeric|min:0',
                'sort_order' => 'nullable|integer',
            ]);

            $tier->name = $request->name;
            $tier->description = $request->description;
            $tier->min_lifetime_purchases = $request->min_lifetime_purchases;
            $tier->discount_percentage = $request->discount_percentage ?? 0;
            $tier->points_multiplier = $request->points_multiplier ?? 1;
            $tier->sort_order = $request->sort_order ?? 0;
            $tier->is_active = $request->has('is_active') ? true : false;
            $tier->save();

            $output = [
                'success' => true,
                'msg' => 'Loyalty tier updated successfully'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => 'Something went wrong: ' . $e->getMessage()
            ];
        }

        if ($request->ajax()) {
            return response()->json($output);
        }

        return redirect()->action('LoyaltyTierController@index')->with('status', $output);
    }

    /**
     * Remove the specified loyalty tier
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // Allow all authenticated users to delete loyalty tiers (or add permission check later)
        // if (!auth()->user()->can('loyalty_tier.delete')) {
        //     abort(403, 'Unauthorized action.');
        // }

        try {
            $business_id = request()->session()->get('user.business_id');
            $tier = LoyaltyTier::where('business_id', $business_id)->findOrFail($id);
            $tier->delete();

            $output = [
                'success' => true,
                'msg' => 'Loyalty tier deleted successfully'
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => 'Something went wrong: ' . $e->getMessage()
            ];
        }

        return $output;
    }
}

