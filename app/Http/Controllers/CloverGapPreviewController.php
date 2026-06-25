<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Read-only preview of Clover card sales that appear to be MISSING from the ERP
// (the Hollywood Mar-May 2026 gap). A Clover payment is flagged "missing" when it
// is not linked to an ERP sale (manual_erp_transaction_id NULL) AND there is no
// ERP finalized sell at that store on the same day whose total matches the
// Clover amount within $0.50. Conservative — it only flags clear non-matches.
// Nothing is created here; this is purely to see the gap before deciding.
class CloverGapPreviewController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $loc = DB::table('business_locations')->where('business_id', $businessId)
            ->where('name', 'like', '%hollywood%')->orderBy('id')->first(['id', 'name']);
        if (!$loc) { return view('admin.clover_gap_preview', ['groups' => [], 'loc' => null]); }

        $hasManualCol = \Schema::hasColumn('clover_payments', 'manual_erp_transaction_id');

        $clover = DB::table('clover_payments')
            ->where('business_id', $businessId)
            ->where('location_id', $loc->id)
            ->where(function ($q) { $q->whereNull('result')->orWhereIn('result', ['SUCCESS', 'APPROVED']); })
            ->whereBetween('paid_on', ['2026-03-01', '2026-05-31'])
            ->when($hasManualCol, fn($q) => $q->whereNull('manual_erp_transaction_id'))
            ->get(['id', 'amount', 'paid_on', 'employee_name', 'card_type', 'card_last4']);

        // Build a per-day multiset of ERP final-sale totals at Hollywood so we can
        // tell which Clover amounts already have an ERP twin that day.
        $erp = DB::table('transactions')
            ->where('business_id', $businessId)->where('location_id', $loc->id)
            ->where('type', 'sell')->where('status', 'final')
            ->whereBetween('transaction_date', ['2026-03-01 00:00:00', '2026-05-31 23:59:59'])
            ->select(DB::raw("DATE(transaction_date) as d"), 'final_total')->get();
        $erpByDay = [];
        foreach ($erp as $e) { $erpByDay[(string) $e->d][] = round((float) $e->final_total, 2); }

        $missing = [];
        foreach ($clover as $c) {
            $day = (string) $c->paid_on;
            $amt = round((float) $c->amount, 2);
            $twin = false;
            foreach (($erpByDay[$day] ?? []) as $i => $t) {
                if (abs($t - $amt) <= 0.50) { unset($erpByDay[$day][$i]); $twin = true; break; }
            }
            if (!$twin) { $missing[] = $c; }
        }

        $groups = [];
        foreach ($missing as $c) {
            $ym = substr((string) $c->paid_on, 0, 7);
            $groups[$ym]['cnt'] = ($groups[$ym]['cnt'] ?? 0) + 1;
            $groups[$ym]['sum'] = ($groups[$ym]['sum'] ?? 0) + (float) $c->amount;
        }
        krsort($groups);

        return view('admin.clover_gap_preview', ['groups' => $groups, 'loc' => $loc->name]);
    }
}
