<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Nivessa has a resale certificate, so purchase prices have no sales tax.
// dpp_inc_tax should equal default_purchase_price. The product form auto-
// inflates one from the other via 9.75% tax math, leaving phantom mismatches.
// This page lists those and one-click backfills both columns to a single value.
//
// HARDENED 2026-04-27 after the data-loss incident:
//   1. Excludes rows where either column is 0 — those are NOT a tax-math
//      mismatch, they're missing data, and copying the 0 would wipe a real
//      cost on the other side.
//   2. Snapshots the BEFORE state of every affected row to
//      storage/admin-snapshots/*.json before applying the UPDATE, so any
//      future regression is reversible via /admin/admin-action-history.
class PurchasePriceMismatchController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        $base = function () use ($businessId) {
            return DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $businessId)
                ->whereNull('v.deleted_at')
                ->whereRaw('ROUND(v.default_purchase_price, 2) <> ROUND(v.dpp_inc_tax, 2)');
        };

        // Safe-to-fix: tax-math mismatch where BOTH sides have a real value.
        // Unsafe-skip: one side is 0 — those need manual review, not auto-align.
        $rows = $base()
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('v.default_purchase_price', '>', 0)
            ->where('v.dpp_inc_tax', '>', 0)
            ->select(
                'v.id as variation_id',
                'p.id as product_id',
                'p.name',
                'p.sku',
                'c.name as category',
                'v.default_purchase_price as exc_tax',
                'v.dpp_inc_tax as inc_tax'
            )
            ->orderBy('p.name')
            ->limit(2000)
            ->get();

        $safeToFix = $base()
            ->where('v.default_purchase_price', '>', 0)
            ->where('v.dpp_inc_tax', '>', 0)
            ->count();

        $skippedZeros = $base()
            ->where(function ($q) {
                $q->where('v.default_purchase_price', '<=', 0)->orWhereNull('v.default_purchase_price')
                  ->orWhere('v.dpp_inc_tax', '<=', 0)->orWhereNull('v.dpp_inc_tax');
            })
            ->count();

        $totalProducts = DB::table('products')
            ->where('business_id', $businessId)
            ->count();

        return view('admin.purchase_price_mismatch', [
            'rows' => $rows,
            'totalMismatched' => $safeToFix,
            'skippedZeros' => $skippedZeros,
            'totalProducts' => $totalProducts,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = $request->session()->get('user.business_id');
        $direction = $request->input('direction', 'use_exc'); // use_exc | use_inc

        $sourceCol = $direction === 'use_inc' ? 'dpp_inc_tax' : 'default_purchase_price';
        $targetCol = $direction === 'use_inc' ? 'default_purchase_price' : 'dpp_inc_tax';

        // GUARD: only touch rows where BOTH columns are non-zero. Rows with a
        // zero on either side aren't a tax-math mismatch, they're missing data,
        // and copying a 0 over a real value would wipe the cost (this exact
        // bug nuked 1,886 rows on 2026-04-27 — never again).
        $beforeRows = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->whereRaw('ROUND(v.default_purchase_price, 2) <> ROUND(v.dpp_inc_tax, 2)')
            ->where('v.default_purchase_price', '>', 0)
            ->where('v.dpp_inc_tax', '>', 0)
            ->select('v.id', 'v.default_purchase_price', 'v.dpp_inc_tax', 'v.updated_at')
            ->get();

        if ($beforeRows->isEmpty()) {
            return redirect('/admin/purchase-price-mismatch')
                ->with('status', ['success' => 1, 'msg' => 'Nothing to align — rows with a $0 side are skipped to protect real values.']);
        }

        // Snapshot BEFORE state so this can be undone if we screw up again.
        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "purchase-price-mismatch-{$timestamp}";
        $snapshot = [
            'timestamp' => now()->toDateTimeString(),
            'action' => 'purchase-price-mismatch',
            'direction' => $direction,
            'source_col' => $sourceCol,
            'target_col' => $targetCol,
            'business_id' => $businessId,
            'rows' => $beforeRows->map(function ($r) {
                return [
                    'id' => $r->id,
                    'default_purchase_price' => $r->default_purchase_price,
                    'dpp_inc_tax' => $r->dpp_inc_tax,
                    'updated_at' => (string) $r->updated_at,
                ];
            })->all(),
        ];
        Storage::disk('local')->put("admin-snapshots/{$snapshotKey}.json", json_encode($snapshot, JSON_PRETTY_PRINT));

        // Apply the UPDATE.
        $variationIds = $beforeRows->pluck('id')->all();
        $updated = 0;
        foreach (array_chunk($variationIds, 500) as $chunk) {
            $updated += DB::table('variations')
                ->whereIn('id', $chunk)
                ->update([
                    $targetCol => DB::raw($sourceCol),
                    'updated_at' => now(),
                ]);
        }

        return redirect('/admin/purchase-price-mismatch')
            ->with('status', ['success' => 1, 'msg' => "Aligned $updated variations. Snapshot saved as {$snapshotKey}.json — visit /admin/admin-action-history to undo."]);
    }
}
