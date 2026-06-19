<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash Deposits report — the per-deposit safe-drop log written on the
 * post-its (Deposit #N + name + time + amount). Admin-only audit trail so
 * Sarah can trace any envelope/bundle back to who dropped it, when, and
 * how much. Backed by the cash_deposits table
 * (/admin/install-cash-deposits-table).
 */
class CashDepositsReportController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        // Deposit amounts are store-cash totals — admin-only, same policy
        // as the other reconciliation reports.
        if (!$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'This report is admin-only.');
        }

        $business_id = $request->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        // Table may not be installed yet on this environment.
        if (!Schema::hasTable('cash_deposits')) {
            return view('report.cash_deposits', [
                'not_installed'      => true,
                'deposits'          => collect(),
                'business_locations' => $business_locations,
                'filters'           => [],
                'totals'            => ['count' => 0, 'amount' => 0.0],
            ]);
        }

        // Filters. Default to the current month through today.
        $start_date = $request->get('start_date') ?: \Carbon::now()->startOfMonth()->format('Y-m-d');
        $end_date   = $request->get('end_date')   ?: \Carbon::now()->format('Y-m-d');
        $location_id = $request->get('location_id');
        $phase = $request->get('phase'); // open | close | (all)

        $query = DB::table('cash_deposits as cd')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'cd.location_id')
            ->where('cd.business_id', $business_id)
            // Hide carryforward markers — they only advance the counter
            // (zero amount), they're not real deposits.
            ->where(function ($q) {
                $q->where('cd.phase', '!=', 'carryforward')->orWhereNull('cd.phase');
            })
            ->whereDate('cd.deposited_at', '>=', $start_date)
            ->whereDate('cd.deposited_at', '<=', $end_date)
            ->select('cd.*', 'bl.name as location_name');

        // Respect location permissions for admins scoped to certain stores.
        $permitted = auth()->user()->permitted_locations();
        if ($permitted != 'all') {
            $query->whereIn('cd.location_id', $permitted);
        }
        if (!empty($location_id)) {
            $query->where('cd.location_id', $location_id);
        }
        if (!empty($phase)) {
            $query->where('cd.phase', $phase);
        }

        $deposits = $query
            ->orderBy('cd.deposited_at', 'desc')
            ->orderBy('cd.id', 'desc')
            ->get();

        $totals = [
            'count'  => $deposits->count(),
            'amount' => (float) $deposits->sum('amount'),
        ];

        return view('report.cash_deposits', [
            'not_installed'      => false,
            'deposits'          => $deposits,
            'business_locations' => $business_locations,
            'filters'           => [
                'start_date'  => $start_date,
                'end_date'    => $end_date,
                'location_id' => $location_id,
                'phase'       => $phase,
            ],
            'totals'            => $totals,
        ]);
    }
}
