<?php

namespace App\Console\Commands;

use App\VariationLocationDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Sarah 2026-09-02: emergency point-restore for specific products wrongly
 * caught by the "everything ever purchased from supplier X" bootleg sweep
 * (2026-09-01) that turned out to be real, non-bootleg products the
 * supplier-link heuristic can't distinguish from an actual bootleg. Takes
 * explicit product:variation:location:qty tuples (matching exactly what the
 * original run's console log printed) and writes them directly. Snapshots
 * the (zero) state it overwrites so this itself stays undoable.
 *
 *   php artisan stock:restore-vld 9619:9647:2:2,9619:9647:1:1
 */
class RestoreProductStock extends Command
{
    protected $signature = 'stock:restore-vld {pairs : Comma-separated product:variation:location:qty tuples}';
    protected $description = 'Directly restore qty_available for specific product/variation/location rows to known values.';

    public function handle()
    {
        $tuples = [];
        foreach (explode(',', $this->argument('pairs')) as $p) {
            [$productId, $variationId, $locationId, $qty] = explode(':', trim($p));
            $tuples[] = [(int) $productId, (int) $variationId, (int) $locationId, (float) $qty];
        }

        $snapshotRows = [];
        $productIds = [];
        foreach ($tuples as [$productId, $variationId, $locationId, $qty]) {
            $vld = VariationLocationDetails::where('product_id', $productId)
                ->where('variation_id', $variationId)
                ->where('location_id', $locationId)
                ->first();
            if (!$vld) {
                $this->error("no vld found for product #$productId variation #$variationId location #$locationId");
                continue;
            }
            $snapshotRows[] = ['id' => $vld->id, 'qty_available' => (float) $vld->qty_available];
            $vld->qty_available = $qty;
            $vld->save();
            $productIds[] = $productId;
            $this->line("Restored product #$productId variation #$variationId location #$locationId to qty $qty");
        }

        if (empty($snapshotRows)) {
            $this->error('Nothing restored.');
            return 1;
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "restore-vld-{$timestamp}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp' => now()->toDateTimeString(),
                'action' => 'zero-retired-stock',
                'business_id' => 1,
                'rows' => $snapshotRows,
            ], JSON_PRETTY_PRINT)
        );
        $this->line("Snapshot: {$snapshotKey}");

        $productIds = array_values(array_unique($productIds));
        try {
            (new \App\Services\NivessaStockNotifier())->push($productIds);
            $this->line('Pushed ' . count($productIds) . ' product(s) to website.');
        } catch (\Throwable $e) {
            $this->error('Push failed: ' . $e->getMessage());
        }

        return 0;
    }
}
