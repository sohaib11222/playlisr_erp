<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Recovery for variations whose default_purchase_price + dpp_inc_tax are both
// zero — most likely wiped by the 2026-04-27 purchase-price-mismatch backfill.
// Reuses the logic from the variations:backfill-cost-prices artisan command:
// pull most recent purchase_lines entry per variation, copy ex/inc onto it.
class RecoverZeroedCostsController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        $zeroVariations = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where(function ($q) {
                $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
            })
            ->where(function ($q) {
                $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
            })
            ->select(
                'v.id as variation_id',
                'v.product_id',
                'v.updated_at as v_updated_at',
                'p.name',
                'p.sku',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by")
            )
            ->orderBy('v.updated_at', 'desc')
            ->limit(2000)
            ->get();

        // Look up most recent purchase line per variation in one batch.
        $variationIds = $zeroVariations->pluck('variation_id')->all();
        $latestByVariation = collect();
        if (!empty($variationIds)) {
            $latestIds = DB::table('purchase_lines')
                ->select(DB::raw('MAX(id) as id'))
                ->whereIn('variation_id', $variationIds)
                ->where('quantity', '>', 0)
                ->groupBy('variation_id')
                ->pluck('id');

            $latestByVariation = DB::table('purchase_lines')
                ->whereIn('id', $latestIds)
                ->get(['variation_id', 'purchase_price', 'purchase_price_inc_tax', 'transaction_id'])
                ->keyBy('variation_id');
        }

        $rows = [];
        $recoverable = 0;
        $notRecoverable = 0;
        foreach ($zeroVariations as $v) {
            $hist = $latestByVariation->get($v->variation_id);
            $cost = $hist && (float) $hist->purchase_price > 0 ? (float) $hist->purchase_price : null;
            $costInc = $hist && (float) $hist->purchase_price_inc_tax > 0 ? (float) $hist->purchase_price_inc_tax : null;

            if ($cost) {
                $recoverable++;
            } else {
                $notRecoverable++;
            }

            $rows[] = (object) [
                'variation_id' => $v->variation_id,
                'product_id' => $v->product_id,
                'name' => $v->name,
                'sku' => $v->sku,
                'created_by' => trim($v->created_by) ?: '—',
                'updated_at' => $v->v_updated_at,
                'recovered_cost' => $cost,
                'recovered_cost_inc' => $costInc,
            ];
        }

        return view('admin.recover_zeroed_costs', [
            'rows' => $rows,
            'totalZeroed' => count($zeroVariations),
            'recoverable' => $recoverable,
            'notRecoverable' => $notRecoverable,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = $request->session()->get('user.business_id');

        $variationIds = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where(function ($q) {
                $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
            })
            ->where(function ($q) {
                $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
            })
            ->pluck('v.id')
            ->all();

        if (empty($variationIds)) {
            return redirect('/admin/recover-zeroed-costs')
                ->with('status', ['success' => 1, 'msg' => 'Nothing to recover.']);
        }

        $updated = 0;
        $skipped = 0;
        foreach (array_chunk($variationIds, 500) as $chunk) {
            $latestIds = DB::table('purchase_lines')
                ->select(DB::raw('MAX(id) as id'))
                ->whereIn('variation_id', $chunk)
                ->where('quantity', '>', 0)
                ->groupBy('variation_id')
                ->pluck('id');

            $latestByVariation = DB::table('purchase_lines')
                ->whereIn('id', $latestIds)
                ->get(['variation_id', 'purchase_price', 'purchase_price_inc_tax'])
                ->keyBy('variation_id');

            foreach ($chunk as $vid) {
                $hist = $latestByVariation->get($vid);
                if (!$hist || (float) $hist->purchase_price <= 0) {
                    $skipped++;
                    continue;
                }

                DB::table('variations')
                    ->where('id', $vid)
                    ->update([
                        'default_purchase_price' => $hist->purchase_price,
                        'dpp_inc_tax'            => $hist->purchase_price_inc_tax ?: $hist->purchase_price,
                        'updated_at'             => now(),
                    ]);
                $updated++;
            }
        }

        return redirect('/admin/recover-zeroed-costs')
            ->with('status', ['success' => 1, 'msg' => "Recovered $updated variations from purchase history. $skipped variations have no purchase history (need manual entry)."]);
    }
}
