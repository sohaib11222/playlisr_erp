<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Old setup listings cleanup (Sarah 2026-10-07).
 *
 * The Oct 2024 catalog load left listings with a made-up SKU (a short
 * in-house number, no barcode) and a selling price that is exactly cost
 * x 1.25 — UltimatePOS's default 25% markup applied to whatever was typed as
 * cost. Sarah: those with no sales history can go. They're retired
 * (is_inactive = 1), never hard-deleted, with a snapshot + undo.
 *
 * Only listings with NO stock are retired here. The ones still showing stock
 * may be real copies, so they're listed for a shelf check instead.
 */
class LegacyListingController extends Controller
{
    protected function isOwner()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    /** Active, single-variation, made-up SKU, price = cost x 1.25, never sold. */
    protected function candidates($business_id)
    {
        return \DB::table('products as p')
            ->join('variations as v', function ($j) {
                $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at');
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.business_id', $business_id)
            ->where('p.is_inactive', 0)
            ->where('v.dpp_inc_tax', '>', 0)
            ->whereRaw('ABS(v.sell_price_inc_tax - v.dpp_inc_tax * 1.25) < 0.011')
            ->whereRaw("p.sku REGEXP '^[0-9]{1,8}$'")
            ->whereRaw('(select count(*) from variations v3 where v3.product_id = p.id and v3.deleted_at is null) = 1')
            ->whereRaw('not exists (select 1 from transaction_sell_lines tsl where tsl.product_id = p.id)')
            ->select(
                'p.id', 'p.name', 'p.sku', 'p.created_at', 'c.name as category',
                'v.dpp_inc_tax as cost', 'v.sell_price_inc_tax as price',
                \DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by_name"),
                \DB::raw('(select coalesce(sum(vld.qty_available),0) from variation_location_details vld where vld.product_id = p.id) as stock')
            )
            ->orderBy('p.id');
    }

    public function index()
    {
        if (!$this->isOwner()) { abort(403, 'Owner-only.'); }
        return view('products.legacy_cleanup');
    }

    public function scan(Request $request)
    {
        @set_time_limit(0);
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $retire = []; $check = [];
        $nRetire = 0; $nCheck = 0; $checkUnits = 0;
        foreach ($this->candidates($business_id)->get() as $r) {
            $row = [
                'id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku,
                'category' => $r->category, 'cost' => (float) $r->cost, 'price' => (float) $r->price,
                'stock' => (float) $r->stock, 'created' => substr((string) $r->created_at, 0, 10),
                'by' => trim((string) $r->created_by_name),
            ];
            if ((float) $r->stock > 0) {
                $nCheck++; $checkUnits += (float) $r->stock;
                if (count($check) < 500) { $check[] = $row; }
            } else {
                $nRetire++;
                if (count($retire) < 500) { $retire[] = $row; }
            }
        }
        return response()->json([
            'success' => true,
            'retire_count' => $nRetire, 'retire' => $retire,
            'check_count' => $nCheck, 'check_units' => $checkUnits, 'check' => $check,
        ]);
    }

    public function apply(Request $request)
    {
        @set_time_limit(0);
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        // Re-run the same rules at apply time; only no-stock rows are retired.
        $ids = [];
        foreach ($this->candidates($business_id)->get() as $r) {
            if ((float) $r->stock <= 0) { $ids[] = (int) $r->id; }
        }
        if (empty($ids)) {
            return response()->json(['success' => true, 'retired' => 0]);
        }

        $timestamp = now()->format('Y-m-d_His');
        \DB::beginTransaction();
        try {
            $done = 0;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $done += \DB::table('products')->whereIn('id', $chunk)->where('is_inactive', 0)
                    ->update(['is_inactive' => 1, 'updated_at' => now()]);
            }
            \Storage::disk('local')->put(
                "admin-snapshots/legacy-listing-retire-{$timestamp}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'legacy-listing-retire',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'source_name' => $done . ' old setup listing(s) with cost x1.25 pricing, made-up SKU, no sales, no stock',
                    'target_name' => 'retired (is_inactive = 1)',
                    'rows' => array_map(function ($id) { return ['id' => $id]; }, $ids),
                ], JSON_PRETTY_PRINT)
            );
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::emergency('legacy-listing-retire failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Retire failed, nothing changed.']);
        }

        // These have no stock, so the site already shows them out of stock;
        // the nightly sync unpublishes inactive products. Push small batches now.
        if (count($ids) <= 200) {
            try { (new \App\Services\NivessaStockNotifier())->push($ids); } catch (\Throwable $e) {}
        }
        return response()->json(['success' => true, 'retired' => $done]);
    }
}
