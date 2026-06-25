<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Read-only: ERP monthly sales (what the LFL report sums) vs Clover's own record
// (synced clover_payments), per store and month, for the months Clover covers.
// Clover is CARD-ONLY, so ERP should sit ABOVE Clover by the cash amount; the
// "implied cash" column makes that visible. Where ERP < Clover, the ERP is
// missing card entries (the known Clover->ERP entry gap). Pure DB facts.
class CloverReconLflController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)->where('is_active', 1)
            ->where('name', 'not like', '%warehouse%')
            ->orderBy('id')->pluck('name', 'id');

        // Clover approved card sales (exclude voided/refunded), by store-month.
        $clover = DB::table('clover_payments')
            ->where('business_id', $businessId)
            ->where(function ($q) { $q->whereNull('result')->orWhereIn('result', ['SUCCESS', 'APPROVED']); })
            ->whereNotIn('result', ['REFUNDED', 'VOIDED', 'FAILED'])
            ->select(
                DB::raw("DATE_FORMAT(paid_on, '%Y-%m') as ym"),
                'location_id',
                // net card SALE amount only: strip tips so it compares to ERP sale totals
                DB::raw('COALESCE(SUM(GREATEST(amount_cents - tip_cents, 0)),0)/100 as total')
            )
            ->groupBy('ym', 'location_id')->get();

        $months = [];
        foreach ($clover as $c) {
            if ($c->location_id === null) { continue; }
            $months[$c->ym][$c->location_id]['clover'] = (float) $c->total;
        }

        // ERP finalized sells (same definition as LFL), by store-month, only for
        // the months Clover has data.
        $yms = array_keys($months);
        if (!empty($yms)) {
            $erp = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')->where('status', 'final')
                ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
                ->whereIn(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"), $yms)
                ->select(
                    DB::raw("DATE_FORMAT(transaction_date, '%Y-%m') as ym"),
                    'location_id',
                    DB::raw('COALESCE(SUM(final_total),0) as total')
                )
                ->groupBy('ym', 'location_id')->get();
            foreach ($erp as $e) {
                $months[$e->ym][$e->location_id]['erp'] = (float) $e->total;
            }
        }
        krsort($months);

        return view('admin.clover_recon_lfl', compact('locations', 'months'));
    }
}
