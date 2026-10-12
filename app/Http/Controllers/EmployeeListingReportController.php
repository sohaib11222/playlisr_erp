<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Employee Listing Report — what each employee listed/priced into inventory,
 * per store, broken down by category / sub-category: number of items and the
 * total listed value (qty × the variation's selling price), with a grand
 * total per employee.
 *
 * "Listed" = a purchase or opening-stock line entered in the date range
 * (mass add, purchases, buy-from-customer intake). The employee is the
 * transaction's created_by and the store is its location. Value uses the
 * item's current selling price inc. tax.
 *
 * $ figures per employee: admins see everyone, staff only see their own rows
 * (same scoping as the other per-employee $ reports).
 */
class EmployeeListingReportController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $is_admin = $this->businessUtil->is_admin(auth()->user());
        $business_locations = BusinessLocation::forDropdown($business_id);

        $start_date = $request->get('start_date') ?: \Carbon::now()->subDays(29)->format('Y-m-d');
        $end_date   = $request->get('end_date') ?: \Carbon::now()->format('Y-m-d');
        if ($start_date > $end_date) {
            [$start_date, $end_date] = [$end_date, $start_date];
        }
        $location_id = $request->get('location_id');
        $user_id = $request->get('user_id');

        $query = DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->join('products as p', 'pl.product_id', '=', 'p.id')
            ->leftJoin('variations as v', 'pl.variation_id', '=', 'v.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.business_id', $business_id)
            ->whereIn('t.type', ['purchase', 'opening_stock'])
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date)
            ->whereNotNull('t.created_by');

        $permitted = auth()->user()->permitted_locations();
        if ($permitted != 'all') {
            $query->whereIn('t.location_id', $permitted);
        }
        if (!empty($location_id)) {
            $query->where('t.location_id', $location_id);
        }
        if (!$is_admin) {
            $query->where('t.created_by', auth()->user()->id);
        } elseif (!empty($user_id)) {
            $query->where('t.created_by', $user_id);
        }

        $rows = $query
            ->groupBy('t.location_id', 't.created_by', 'p.category_id', 'p.sub_category_id')
            ->select(
                't.location_id',
                't.created_by',
                DB::raw("COALESCE(bl.name, 'No store') as location_name"),
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee"),
                DB::raw("COALESCE(c.name, 'Uncategorized') as category"),
                DB::raw("COALESCE(sc.name, '') as sub_category"),
                DB::raw('SUM(pl.quantity) as items'),
                DB::raw('SUM(pl.quantity * COALESCE(v.sell_price_inc_tax, 0)) as value')
            )
            ->get();

        // location => [name, items, value, employees => [id => [name, items, value, cats => [...]]]]
        $report = [];
        $grand = ['items' => 0.0, 'value' => 0.0];
        foreach ($rows as $r) {
            $loc = $r->location_id ?? 0;
            if (!isset($report[$loc])) {
                $report[$loc] = ['name' => $r->location_name, 'items' => 0.0, 'value' => 0.0, 'employees' => []];
            }
            $emps = &$report[$loc]['employees'];
            if (!isset($emps[$r->created_by])) {
                $emps[$r->created_by] = ['name' => $r->employee !== '' ? $r->employee : 'User #' . $r->created_by, 'items' => 0.0, 'value' => 0.0, 'cats' => []];
            }
            $items = (float) $r->items;
            $value = (float) $r->value;
            $emps[$r->created_by]['cats'][] = [
                'category' => $r->category,
                'sub_category' => $r->sub_category,
                'items' => $items,
                'value' => $value,
            ];
            $emps[$r->created_by]['items'] += $items;
            $emps[$r->created_by]['value'] += $value;
            $report[$loc]['items'] += $items;
            $report[$loc]['value'] += $value;
            $grand['items'] += $items;
            $grand['value'] += $value;
            unset($emps);
        }

        uasort($report, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($report as &$loc) {
            uasort($loc['employees'], fn($a, $b) => $b['value'] <=> $a['value']);
            foreach ($loc['employees'] as &$emp) {
                usort($emp['cats'], fn($a, $b) => $b['value'] <=> $a['value']);
            }
            unset($emp);
        }
        unset($loc);

        $employees = [];
        if ($is_admin) {
            $employees = \App\User::where('business_id', $business_id)
                ->orderBy('first_name')
                ->select('id', DB::raw("TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as name"))
                ->pluck('name', 'id')
                ->toArray();
        }

        return view('report.employee_listing', [
            'report' => $report,
            'grand' => $grand,
            'business_locations' => $business_locations,
            'employees' => $employees,
            'is_admin' => $is_admin,
            'filters' => compact('start_date', 'end_date', 'location_id', 'user_id'),
        ]);
    }
}
