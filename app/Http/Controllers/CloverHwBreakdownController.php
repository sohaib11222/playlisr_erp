<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Read-only: anatomy of Hollywood's Clover payments for the 3 months that read
// above the ERP, to find what's inflating Clover (duplicate/split payments,
// auth+capture, online vs in-store tender types, refunds). Pure DB facts.
class CloverHwBreakdownController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $loc = DB::table('business_locations')->where('business_id', $businessId)
            ->where('name', 'like', '%hollywood%')->orderBy('id')->first(['id', 'name']);
        if (!$loc) { return view('admin.clover_hw_breakdown', ['loc' => null]); }

        $base = fn() => DB::table('clover_payments')
            ->where('business_id', $businessId)->where('location_id', $loc->id)
            ->whereBetween('paid_on', ['2026-03-01', '2026-05-31']);

        // Overall
        $all = (clone $base())->selectRaw('COUNT(*) c, COUNT(DISTINCT clover_order_id) orders, COALESCE(SUM(amount),0) amt, COALESCE(SUM(tip_cents)/100,0) tips')->first();

        // By result
        $byResult = (clone $base())->selectRaw("COALESCE(result,'(null)') r, COUNT(*) c, COALESCE(SUM(amount),0) amt")
            ->groupBy('r')->orderByRaw('amt DESC')->get();

        // By tender_type
        $byTender = (clone $base())->selectRaw("COALESCE(tender_type,'(null)') t, COUNT(*) c, COALESCE(SUM(amount),0) amt")
            ->groupBy('t')->orderByRaw('amt DESC')->get();

        // Orders with >1 payment (split / auth+capture / duplicates)
        $multi = (clone $base())->whereNotNull('clover_order_id')
            ->select('clover_order_id', DB::raw('COUNT(*) c'), DB::raw('SUM(amount) amt'))
            ->groupBy('clover_order_id')->havingRaw('COUNT(*) > 1')->get();
        $multiCount = $multi->count();
        $multiExtra = $multi->sum(fn($m) => (float) $m->amt) - $multi->sum(fn($m) => (float) $m->amt / $m->c); // rough excess if dupes

        return view('admin.clover_hw_breakdown', [
            'loc' => $loc->name, 'all' => $all, 'byResult' => $byResult,
            'byTender' => $byTender, 'multiCount' => $multiCount, 'multiExtra' => $multiExtra,
        ]);
    }
}
