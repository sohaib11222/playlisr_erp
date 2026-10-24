<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Received stock at a store the product wasn't assigned to was invisible to
 * that store's POS (scans said "doesn't exist" or hit a duplicate with no
 * stock). 222 rows / 359 units as of 2026-10-23, all received since 9/15.
 * Assign each product to the store where it has stock. Additive only; the
 * added pairs are saved to storage/app/admin-snapshots for reference.
 */
class AssignProductsToStoresWithStock extends Migration
{
    public function up()
    {
        $rows = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->whereNull('v.deleted_at')
            ->where('vld.qty_available', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('product_locations as pl')
                    ->whereColumn('pl.product_id', 'vld.product_id')
                    ->whereColumn('pl.location_id', 'vld.location_id');
            })
            ->select('vld.product_id', 'vld.location_id')
            ->distinct()
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $pairs = $rows->map(function ($r) {
            return ['product_id' => (int) $r->product_id, 'location_id' => (int) $r->location_id];
        })->all();

        Storage::disk('local')->put(
            'admin-snapshots/assign-products-to-stores-with-stock-' . date('Ymd-His') . '.json',
            json_encode(['action' => 'assign-products-to-stores-with-stock', 'added' => $pairs])
        );

        foreach (array_chunk($pairs, 500) as $chunk) {
            DB::table('product_locations')->insert($chunk);
        }
    }

    public function down()
    {
    }
}
