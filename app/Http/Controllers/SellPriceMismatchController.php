<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Sister page to /admin/purchase-price-mismatch.
//
// Lists variations where default_sell_price (the column POS reads as the
// sticker) is meaningfully BELOW sell_price_inc_tax (what was actually entered
// on the Add Purchase form). This is the signature of the May 1, 2026 bug:
// updateProductFromPurchase back-calc'd default_sell_price = entered / (1+tax),
// so customers paid sticker instead of sticker+tax.
//
// Estimates lost revenue from past sales of affected variations using the
// item_tax recorded on each sell line (the tax that was rolled into the
// sticker instead of being added on top). Apply mirrors default_sell_price
// up to sell_price_inc_tax, with a snapshot to admin-snapshots/ for undo.
class SellPriceMismatchController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        $base = function () use ($businessId) {
            return DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $businessId)
                ->whereNull('v.deleted_at')
                ->where('v.default_sell_price', '>', 0)
                ->where('v.sell_price_inc_tax', '>', 0)
                ->whereRaw('ROUND(v.sell_price_inc_tax, 2) > ROUND(v.default_sell_price, 2) + 0.01');
        };

        $rows = $base()
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'v.id as variation_id',
                'p.id as product_id',
                'p.name',
                'p.sku',
                'c.name as category',
                'v.default_sell_price as exc_tax',
                'v.sell_price_inc_tax as inc_tax'
            )
            ->orderByRaw('(v.sell_price_inc_tax - v.default_sell_price) DESC')
            ->limit(2000)
            ->get();

        $totalAffected = $base()->count();

        // Estimated lost revenue: for each historical sale of an affected
        // variation, count the gap between the entered sticker
        // (sell_price_inc_tax) and what the customer actually paid per unit
        // (tsl.unit_price). MAX(...,0) so we don't subtract when a cashier
        // manually charged MORE than the deflated sticker. We use unit_price
        // not item_tax because item_tax is 0 in this DB (separate POS tax bug).
        $lostRevenue = 0;
        $affectedSales = 0;
        $undercharged = collect();
        $variationIds = $base()->pluck('v.id');
        if ($variationIds->isNotEmpty()) {
            $stats = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
                ->whereIn('tsl.variation_id', $variationIds)
                ->where('t.business_id', $businessId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->selectRaw('COALESCE(SUM(GREATEST(v.sell_price_inc_tax - tsl.unit_price, 0) * tsl.quantity), 0) as lost_revenue, COUNT(*) as line_count')
                ->first();
            $lostRevenue = (float) ($stats->lost_revenue ?? 0);
            $affectedSales = (int) ($stats->line_count ?? 0);

            // Per-sale list — actual transactions where the customer paid less
            // than the entered sticker. Newest first.
            $undercharged = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
                ->whereIn('tsl.variation_id', $variationIds)
                ->where('t.business_id', $businessId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereRaw('v.sell_price_inc_tax > tsl.unit_price + 0.01')
                ->select(
                    't.transaction_date',
                    't.invoice_no',
                    'bl.name as location',
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.sku',
                    'tsl.quantity',
                    'tsl.unit_price as charged',
                    'v.sell_price_inc_tax as intended',
                    DB::raw('(v.sell_price_inc_tax - tsl.unit_price) * tsl.quantity as loss')
                )
                ->orderByDesc('t.transaction_date')
                ->limit(1000)
                ->get();
        }

        // After Restore is clicked, the live mismatch goes to 0 — but the
        // damage in the historical sales table is still real. Read the most
        // recent snapshot (it preserves the original deflated default_sell_price
        // alongside the intended sell_price_inc_tax) and rebuild the per-sale
        // list from it. This way Sarah can see what undercharged even after
        // backfilling the live variations.
        $historical = collect();
        $historicalLost = 0;
        $historicalSnapshotKey = null;
        $historicalSnapshotTime = null;
        $snapshotFiles = collect(Storage::disk('local')->files('admin-snapshots'))
            ->filter(function ($f) {
                return strpos(basename($f), 'sell-price-mismatch-') === 0 && substr($f, -5) === '.json';
            })
            ->sort()
            ->reverse()
            ->values();

        if ($snapshotFiles->isNotEmpty()) {
            $latest = $snapshotFiles->first();
            $snap = json_decode(Storage::disk('local')->get($latest), true);
            if (!empty($snap['rows'])) {
                $historicalSnapshotKey = pathinfo($latest, PATHINFO_FILENAME);
                $historicalSnapshotTime = $snap['timestamp'] ?? null;
                // Track BOTH the intended sticker (sell_price_inc_tax) AND the
                // deflated one (default_sell_price). Per-sale undercharge from
                // this specific bug is capped at (intended - deflated) — anything
                // beyond that gap is a cashier discount, not the bug.
                $byVariation = [];
                foreach ($snap['rows'] as $r) {
                    $byVariation[(int) $r['id']] = [
                        'intended' => (float) $r['sell_price_inc_tax'],
                        'deflated' => (float) $r['default_sell_price'],
                    ];
                }
                $snapVariationIds = array_keys($byVariation);

                // qty_kept = original sold qty minus anything returned later.
                // Joins through transaction_sell_lines_purchase_lines since
                // qty_returned lives on the bridging table (matches the
                // pattern used in TransactionUtil for COGS / margin reports).
                $qtyKeptSubquery = '(SELECT COALESCE(SUM(tspl.quantity - tspl.qty_returned), 0) '
                                 . 'FROM transaction_sell_lines_purchase_lines tspl '
                                 . 'WHERE tspl.sell_line_id = tsl.id)';

                $historical = DB::table('transaction_sell_lines as tsl')
                    ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                    ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
                    ->whereIn('tsl.variation_id', $snapVariationIds)
                    ->where('t.business_id', $businessId)
                    ->where('t.type', 'sell')
                    ->where('t.status', 'final')
                    ->select(
                        'tsl.variation_id',
                        't.transaction_date',
                        't.invoice_no',
                        'bl.name as location',
                        'p.id as product_id',
                        'p.name as product_name',
                        'p.sku',
                        'tsl.quantity as qty_sold',
                        DB::raw("$qtyKeptSubquery as qty_kept"),
                        'tsl.unit_price as charged'
                    )
                    ->orderByDesc('t.transaction_date')
                    ->limit(2000)
                    ->get()
                    ->map(function ($row) use ($byVariation) {
                        $info = $byVariation[(int) $row->variation_id] ?? ['intended' => 0, 'deflated' => 0];
                        $intended = $info['intended'];
                        $deflated = $info['deflated'];
                        $taxGap = max(0, $intended - $deflated); // max bug loss per unit
                        $charged = (float) $row->charged;
                        $perUnitLoss = max(0, min($intended - $charged, $taxGap));
                        $qtyKept = (float) $row->qty_kept;
                        $row->intended = $intended;
                        $row->deflated = $deflated;
                        $row->quantity = $qtyKept; // for display
                        $row->loss = $perUnitLoss * $qtyKept;
                        return $row;
                    })
                    // qty_kept = 0 means the customer returned everything — drop it.
                    ->filter(function ($row) {
                        return $row->loss > 0.01 && $row->quantity > 0;
                    })
                    ->values();

                $historicalLost = $historical->sum('loss');
            }
        }

        return view('admin.sell_price_mismatch', [
            'rows' => $rows,
            'totalAffected' => $totalAffected,
            'lostRevenue' => $lostRevenue,
            'affectedSales' => $affectedSales,
            'undercharged' => $undercharged,
            'historical' => $historical,
            'historicalLost' => $historicalLost,
            'historicalSnapshotKey' => $historicalSnapshotKey,
            'historicalSnapshotTime' => $historicalSnapshotTime,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = $request->session()->get('user.business_id');

        // Only touch rows where BOTH columns are non-zero AND inc > exc.
        // (Same guard as PurchasePriceMismatchController — never copy a 0
        // over a real value; never overwrite legitimately-higher data.)
        $beforeRows = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where('v.default_sell_price', '>', 0)
            ->where('v.sell_price_inc_tax', '>', 0)
            ->whereRaw('ROUND(v.sell_price_inc_tax, 2) > ROUND(v.default_sell_price, 2) + 0.01')
            ->select('v.id', 'v.default_sell_price', 'v.sell_price_inc_tax', 'v.updated_at')
            ->get();

        if ($beforeRows->isEmpty()) {
            return redirect('/admin/sell-price-mismatch')
                ->with('status', ['success' => 1, 'msg' => 'Nothing to align.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "sell-price-mismatch-{$timestamp}";
        $snapshot = [
            'timestamp' => now()->toDateTimeString(),
            'action' => 'sell-price-mismatch',
            'business_id' => $businessId,
            'rows' => $beforeRows->map(function ($r) {
                return [
                    'id' => $r->id,
                    'default_sell_price' => $r->default_sell_price,
                    'sell_price_inc_tax' => $r->sell_price_inc_tax,
                    'updated_at' => (string) $r->updated_at,
                ];
            })->all(),
        ];
        Storage::disk('local')->put("admin-snapshots/{$snapshotKey}.json", json_encode($snapshot, JSON_PRETTY_PRINT));

        $variationIds = $beforeRows->pluck('id')->all();
        $updated = 0;
        foreach (array_chunk($variationIds, 500) as $chunk) {
            $updated += DB::table('variations')
                ->whereIn('id', $chunk)
                ->update([
                    'default_sell_price' => DB::raw('sell_price_inc_tax'),
                    'updated_at' => now(),
                ]);
        }

        return redirect('/admin/sell-price-mismatch')
            ->with('status', ['success' => 1, 'msg' => "Aligned $updated variations to their sticker price. Snapshot {$snapshotKey}.json — undo at /admin/admin-action-history."]);
    }
}
