<?php

namespace App\Http\Controllers;

use App\Coupon;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

// Discount coupon codes for nivessa.com checkout. The website API
// (jonhedvat/server) validates and redeems codes live against this table
// via the /api/v1/nivessa-web/coupons/* bridge (App\Http\Controllers\Api\
// NivessaCouponController) — the ERP stays the single source of truth for
// usage counts, so there's no separate copy to keep in sync or drift.
//
// Admin-only: a coupon is a live discount on real money, so unlike Gift
// Cards this is gated the same way Payroll is (ensureAdmin), not open to
// all authenticated staff.
class CouponController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function ensureAdmin()
    {
        if (!auth()->check() || !$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'Coupons is admin-only.');
        }
    }

    public function index()
    {
        $this->ensureAdmin();
        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {
            $coupons = Coupon::where('business_id', $business_id)
                ->with('creator')
                ->select('coupons.*');

            return \DataTables::of($coupons)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">
                        <button type="button" class="btn btn-info dropdown-toggle btn-xs" data-toggle="dropdown" aria-expanded="false">' .
                        __("messages.actions") .
                        '<span class="caret"></span><span class="sr-only">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-left" role="menu">';
                    $html .= '<li><a href="' . action('CouponController@edit', [$row->id]) . '" class="edit_coupon_button"><i class="glyphicon glyphicon-edit"></i> ' . __("messages.edit") . '</a></li>';
                    $html .= '<li><a href="' . action('CouponController@destroy', [$row->id]) . '" class="delete_coupon_button"><i class="glyphicon glyphicon-trash"></i> ' . __("messages.delete") . '</a></li>';
                    $html .= '</ul></div>';
                    return $html;
                })
                ->editColumn('discount', function ($row) {
                    return $row->type === 'percent'
                        ? rtrim(rtrim(number_format($row->value, 2), '0'), '.') . '%'
                        : '<span class="display_currency" data-currency_symbol="true">' . $row->value . '</span>';
                })
                ->editColumn('min_order_amount', function ($row) {
                    return $row->min_order_amount
                        ? '<span class="display_currency" data-currency_symbol="true">' . $row->min_order_amount . '</span>'
                        : 'None';
                })
                ->editColumn('usage', function ($row) {
                    return $row->times_used . ' / ' . ($row->usage_limit === null ? '∞' : $row->usage_limit);
                })
                ->editColumn('expiry_date', function ($row) {
                    return $row->expiry_date ? date('Y-m-d', strtotime($row->expiry_date)) : 'N/A';
                })
                ->editColumn('status', function ($row) {
                    $statuses = [
                        'active' => '<span class="label label-success">Active</span>',
                        'inactive' => '<span class="label label-default">Inactive</span>',
                    ];
                    return $statuses[$row->status] ?? $row->status;
                })
                ->rawColumns(['action', 'discount', 'min_order_amount', 'status'])
                ->removeColumn('id')
                ->make(true);
        }

        return view('coupon.index');
    }

    public function create()
    {
        $this->ensureAdmin();
        return view('coupon.create');
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();

        try {
            $business_id = $request->session()->get('user.business_id');

            $request->validate([
                'code' => 'required|string|max:64',
                'type' => 'required|in:percent,fixed',
                'value' => 'required|numeric|min:0.01',
                'min_order_amount' => 'nullable|numeric|min:0',
                'usage_limit' => 'nullable|integer|min:1',
                'expiry_date' => 'nullable|date',
            ]);

            $code = strtoupper(trim($request->code));

            $exists = Coupon::where('business_id', $business_id)
                ->where('code', $code)
                ->exists();
            if ($exists) {
                return redirect()->action('CouponController@index')->with('status', [
                    'success' => false,
                    'msg' => 'A coupon with that code already exists.',
                ]);
            }

            if ($request->type === 'percent' && $request->value > 100) {
                return redirect()->action('CouponController@index')->with('status', [
                    'success' => false,
                    'msg' => 'A percent-off coupon cannot exceed 100%.',
                ]);
            }

            $coupon = new Coupon();
            $coupon->business_id = $business_id;
            $coupon->code = $code;
            $coupon->type = $request->type;
            $coupon->value = $request->value;
            $coupon->min_order_amount = $request->min_order_amount ?: null;
            $coupon->usage_limit = $request->usage_limit ?: null;
            $coupon->times_used = 0;
            // Normalize to ISO before it hits Eloquent's date cast: the
            // date-picker submits the business's display format (e.g.
            // 08/25/2026), and this Laravel version's asDateTime() has no
            // fallback for a non-ISO/non-native string — it throws a raw
            // Carbon "Unexpected data found" instead of parsing it.
            $coupon->expiry_date = $request->expiry_date ? date('Y-m-d', strtotime($request->expiry_date)) : null;
            $coupon->status = 'active';
            $coupon->notes = $request->notes;
            $coupon->created_by = auth()->user()->id;
            $coupon->save();

            $output = ['success' => true, 'msg' => 'Coupon created successfully'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => 'Something went wrong: ' . $e->getMessage()];
        }

        return redirect()->action('CouponController@index')->with('status', $output);
    }

    public function edit($id)
    {
        $this->ensureAdmin();
        $business_id = request()->session()->get('user.business_id');
        $coupon = Coupon::where('business_id', $business_id)->findOrFail($id);

        return view('coupon.edit')->with(compact('coupon'));
    }

    public function update(Request $request, $id)
    {
        $this->ensureAdmin();

        try {
            $business_id = $request->session()->get('user.business_id');
            $coupon = Coupon::where('business_id', $business_id)->findOrFail($id);

            $request->validate([
                'code' => 'required|string|max:64',
                'type' => 'required|in:percent,fixed',
                'value' => 'required|numeric|min:0.01',
                'min_order_amount' => 'nullable|numeric|min:0',
                'usage_limit' => 'nullable|integer|min:1',
                'expiry_date' => 'nullable|date',
                'status' => 'required|in:active,inactive',
            ]);

            $code = strtoupper(trim($request->code));

            $exists = Coupon::where('business_id', $business_id)
                ->where('code', $code)
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return redirect()->action('CouponController@index')->with('status', [
                    'success' => false,
                    'msg' => 'A coupon with that code already exists.',
                ]);
            }

            if ($request->type === 'percent' && $request->value > 100) {
                return redirect()->action('CouponController@index')->with('status', [
                    'success' => false,
                    'msg' => 'A percent-off coupon cannot exceed 100%.',
                ]);
            }

            $coupon->code = $code;
            $coupon->type = $request->type;
            $coupon->value = $request->value;
            $coupon->min_order_amount = $request->min_order_amount ?: null;
            $coupon->usage_limit = $request->usage_limit ?: null;
            // Normalize to ISO before it hits Eloquent's date cast: the
            // date-picker submits the business's display format (e.g.
            // 08/25/2026), and this Laravel version's asDateTime() has no
            // fallback for a non-ISO/non-native string — it throws a raw
            // Carbon "Unexpected data found" instead of parsing it.
            $coupon->expiry_date = $request->expiry_date ? date('Y-m-d', strtotime($request->expiry_date)) : null;
            $coupon->status = $request->status;
            $coupon->notes = $request->notes;
            $coupon->save();

            $output = ['success' => true, 'msg' => 'Coupon updated successfully'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => 'Something went wrong: ' . $e->getMessage()];
        }

        return redirect()->action('CouponController@index')->with('status', $output);
    }

    public function destroy($id)
    {
        $this->ensureAdmin();

        try {
            $business_id = request()->session()->get('user.business_id');
            $coupon = Coupon::where('business_id', $business_id)->findOrFail($id);
            $coupon->delete();

            $output = ['success' => true, 'msg' => 'Coupon deleted successfully'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => 'Something went wrong: ' . $e->getMessage()];
        }

        return $output;
    }
}
