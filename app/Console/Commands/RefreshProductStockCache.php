<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\ProductStockCache;
use App\Business;
use Carbon\Carbon;

class RefreshProductStockCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:refresh-cache 
                            {--business_id= : Specific business ID to refresh}
                            {--location_id= : Specific location ID to refresh}
                            {--truncate : Truncate cache table before refresh}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh product stock cache for faster report generation';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $startedAt = microtime(true);
        $startedAtIso = Carbon::now()->toDateTimeString();

        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '1024M');

            $this->info('Starting stock cache refresh...');
            Log::info('stock:refresh-cache started', [
                'started_at' => $startedAtIso,
                'business_id' => $this->option('business_id'),
                'location_id' => $this->option('location_id'),
                'truncate' => (bool) $this->option('truncate'),
            ]);

            // Truncate if requested
            if ($this->option('truncate')) {
                $this->info('Truncating cache table...');
                ProductStockCache::truncate();
            }

            // Get businesses to process
            $businesses = Business::query();
            
            if ($this->option('business_id')) {
                $businesses->where('id', $this->option('business_id'));
            }
            
            $businesses = $businesses->get();

            foreach ($businesses as $business) {
                $this->info("Processing business: {$business->name} (ID: {$business->id})");
                $this->refreshStockForBusiness($business->id);
                // $this->cleanupOrphanedRecords($business->id);
            }

            $this->info('Stock cache refresh completed successfully!');
            Log::info('stock:refresh-cache completed', [
                'started_at' => $startedAtIso,
                'ended_at' => Carbon::now()->toDateTimeString(),
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'business_id' => $this->option('business_id'),
                'location_id' => $this->option('location_id'),
            ]);

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::emergency("File:" . $e->getFile(). " Line:" . $e->getLine(). " Message:" . $e->getMessage());
            Log::error('stock:refresh-cache failed', [
                'started_at' => $startedAtIso,
                'ended_at' => Carbon::now()->toDateTimeString(),
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'business_id' => $this->option('business_id'),
                'location_id' => $this->option('location_id'),
                'error' => $e->getMessage(),
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Refresh stock cache for a specific business
     *
     * @param int $business_id
     * @return void
     */
    protected function refreshStockForBusiness($business_id)
    {
        $businessStartedAt = microtime(true);

        // Pre-aggregate transaction metrics once per (variation_id, location_id), then join.
        // The old query used correlated subqueries (5× per row) plus OFFSET chunking, which
        // made each 500-row page scan transactions repeatedly (40s+ per chunk at high offset).
        $sellAgg = $this->aggregateSellQtyByVariationLocation($business_id);
        $transferAgg = $this->aggregateSellTransferQtyByVariationLocation($business_id);
        $adjustAgg = $this->aggregateStockAdjustmentByVariationLocation($business_id);
        $stockPriceAgg = $this->aggregateStockPriceByVariationLocation($business_id);
        $mfgAgg = $this->aggregateMfgStockByVariationLocation($business_id);

        $query = DB::table('variation_location_details as vld')
            ->join('variations', function ($join) {
                $join->on('variations.id', '=', 'vld.variation_id')
                    ->whereNull('variations.deleted_at');
            })
            ->join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->leftJoin('business_locations as l', 'vld.location_id', '=', 'l.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->leftJoinSub($sellAgg, 'agg_sell', function ($join) {
                $join->on('agg_sell.agg_variation_id', '=', 'vld.variation_id')
                    ->on('agg_sell.agg_location_id', '=', 'vld.location_id');
            })
            ->leftJoinSub($transferAgg, 'agg_transfer', function ($join) {
                $join->on('agg_transfer.agg_variation_id', '=', 'vld.variation_id')
                    ->on('agg_transfer.agg_location_id', '=', 'vld.location_id');
            })
            ->leftJoinSub($adjustAgg, 'agg_adj', function ($join) {
                $join->on('agg_adj.agg_variation_id', '=', 'vld.variation_id')
                    ->on('agg_adj.agg_location_id', '=', 'vld.location_id');
            })
            ->leftJoinSub($stockPriceAgg, 'agg_stock_price', function ($join) {
                $join->on('agg_stock_price.agg_variation_id', '=', 'vld.variation_id')
                    ->on('agg_stock_price.agg_location_id', '=', 'vld.location_id');
            })
            ->leftJoinSub($mfgAgg, 'agg_mfg', function ($join) {
                $join->on('agg_mfg.agg_variation_id', '=', 'vld.variation_id')
                    ->on('agg_mfg.agg_location_id', '=', 'vld.location_id');
            })
            ->where('p.business_id', $business_id)
            ->whereIn('p.type', ['single', 'variable'])
            ->whereNotNull('vld.location_id');

        if ($this->option('location_id')) {
            $query->where('vld.location_id', $this->option('location_id'));
        }

        $query->select(
            DB::raw((int) $business_id.' as business_id'),
            'p.id as product_id',
            'variations.id as variation_id',
            'l.id as location_id',
            'p.category_id',
            'p.sub_category_id',
            'p.brand_id',
            'p.unit_id',
            DB::raw('COALESCE(agg_sell.total_sold, 0) as total_sold'),
            DB::raw('COALESCE(agg_transfer.total_transfered, 0) as total_transfered'),
            DB::raw('COALESCE(agg_adj.total_adjusted, 0) as total_adjusted'),
            DB::raw('COALESCE(agg_stock_price.stock_price, 0) as stock_price'),
            DB::raw('COALESCE(vld.qty_available, 0) as stock'),
            DB::raw('COALESCE(agg_mfg.total_mfg_stock, 0) as total_mfg_stock'),
            'variations.sub_sku as sku',
            'p.name as product',
            'p.type',
            'p.alert_quantity',
            'units.short_name as unit',
            'p.enable_stock',
            'variations.sell_price_inc_tax as unit_price',
            'pv.name as product_variation',
            'variations.name as variation_name',
            'l.name as location_name',
            'c.name as category_name',
            'p.product_custom_field1',
            'p.product_custom_field2',
            'p.product_custom_field3',
            'p.product_custom_field4',
            'p.tax as tax_id',
            'p.is_inactive',
            'p.not_for_selling',
            'vld.id as chunk_pk'
        );

        $totalProcessed = 0;
        $chunkSize = 500;

        $query->orderBy('vld.id')->chunkById($chunkSize, function ($items) use (&$totalProcessed, $business_id) {
            $this->bulkUpsertProductStockCacheRows((int) $business_id, $items);
            $totalProcessed += $items->count();

            $this->info("Processed {$totalProcessed} records...");
        }, 'vld.id', 'chunk_pk');

        $elapsed = round(microtime(true) - $businessStartedAt, 2);
        $this->info("Completed business {$business_id}: {$totalProcessed} records in {$elapsed}s");
        Log::info('stock:refresh-cache business done', [
            'business_id' => $business_id,
            'rows' => $totalProcessed,
            'duration_seconds' => $elapsed,
        ]);
    }

    /**
     * One INSERT ... ON DUPLICATE KEY UPDATE per chunk (unique_stock_cache).
     * Avoids N× updateOrCreate round-trips. repair_model_id is not updated on conflict.
     *
     * @param int $business_id
     * @param \Illuminate\Support\Collection|\Traversable|array $items
     * @return void
     */
    protected function bulkUpsertProductStockCacheRows($business_id, $items)
    {
        $items = collect($items);
        if ($items->isEmpty()) {
            return;
        }

        $now = Carbon::now()->toDateTimeString();

        $columns = [
            'business_id',
            'product_id',
            'variation_id',
            'location_id',
            'category_id',
            'sub_category_id',
            'brand_id',
            'unit_id',
            'total_sold',
            'total_transfered',
            'total_adjusted',
            'stock_price',
            'stock',
            'total_mfg_stock',
            'sku',
            'product',
            'type',
            'alert_quantity',
            'unit',
            'enable_stock',
            'unit_price',
            'product_variation',
            'variation_name',
            'location_name',
            'category_name',
            'product_custom_field1',
            'product_custom_field2',
            'product_custom_field3',
            'product_custom_field4',
            'tax_id',
            'is_inactive',
            'not_for_selling',
            'repair_model_id',
            'calculated_at',
            'created_at',
            'updated_at',
        ];

        $quotedCols = array_map(function ($c) {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columns);

        $rowPlaceholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

        $bindings = [];
        $valueGroups = [];

        foreach ($items as $item) {
            $valueGroups[] = $rowPlaceholders;
            $bindings[] = $business_id;
            $bindings[] = $item->product_id;
            $bindings[] = $item->variation_id;
            $bindings[] = $item->location_id;
            $bindings[] = $item->category_id;
            $bindings[] = $item->sub_category_id;
            $bindings[] = $item->brand_id;
            $bindings[] = $item->unit_id;
            $bindings[] = $item->total_sold ?? 0;
            $bindings[] = $item->total_transfered ?? 0;
            $bindings[] = $item->total_adjusted ?? 0;
            $bindings[] = $item->stock_price ?? 0;
            $bindings[] = $item->stock ?? 0;
            $bindings[] = isset($item->total_mfg_stock) ? $item->total_mfg_stock : null;
            $bindings[] = $item->sku;
            $bindings[] = $item->product;
            $bindings[] = $item->type;
            $bindings[] = $item->alert_quantity;
            $bindings[] = $item->unit;
            $bindings[] = $item->enable_stock;
            $bindings[] = $item->unit_price;
            $bindings[] = $item->product_variation;
            $bindings[] = $item->variation_name;
            $bindings[] = $item->location_name;
            $bindings[] = $item->category_name;
            $bindings[] = $item->product_custom_field1;
            $bindings[] = $item->product_custom_field2;
            $bindings[] = $item->product_custom_field3;
            $bindings[] = $item->product_custom_field4;
            $bindings[] = $item->tax_id;
            $bindings[] = $item->is_inactive ?? 0;
            $bindings[] = $item->not_for_selling ?? 0;
            $bindings[] = null; // repair_model_id — preserve on duplicate
            $bindings[] = $now;
            $bindings[] = $now;
            $bindings[] = $now;
        }

        $updateParts = [];
        foreach ($columns as $c) {
            if (in_array($c, ['business_id', 'variation_id', 'location_id', 'repair_model_id', 'created_at'], true)) {
                continue;
            }
            $qc = '`' . str_replace('`', '``', $c) . '`';
            $updateParts[] = "{$qc} = VALUES({$qc})";
        }

        $sql = 'INSERT INTO `product_stock_cache` (' . implode(',', $quotedCols) . ') VALUES '
            . implode(',', $valueGroups)
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);

        DB::transaction(function () use ($sql, $bindings) {
            DB::statement($sql, $bindings);
        });
    }

    /**
     * Final sell lines: quantity minus returns, grouped for cache joins.
     */
    protected function aggregateSellQtyByVariationLocation(int $business_id)
    {
        return DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $business_id)
            ->where('t.status', 'final')
            ->where('t.type', 'sell')
            ->whereNotNull('tsl.product_id')
            ->groupBy('tsl.variation_id', 't.location_id')
            ->selectRaw(
                'tsl.variation_id as agg_variation_id, t.location_id as agg_location_id, '
                .'SUM(tsl.quantity - tsl.quantity_returned) as total_sold'
            );
    }

    /**
     * Sell transfer quantities by variation and location.
     */
    protected function aggregateSellTransferQtyByVariationLocation(int $business_id)
    {
        return DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $business_id)
            ->where('t.status', 'final')
            ->where('t.type', 'sell_transfer')
            ->whereNotNull('tsl.product_id')
            ->groupBy('tsl.variation_id', 't.location_id')
            ->selectRaw(
                'tsl.variation_id as agg_variation_id, t.location_id as agg_location_id, '
                .'SUM(tsl.quantity) as total_transfered'
            );
    }

    /**
     * Stock adjustment lines summed by variation and location.
     */
    protected function aggregateStockAdjustmentByVariationLocation(int $business_id)
    {
        return DB::table('transactions as t')
            ->join('stock_adjustment_lines as sal', 't.id', '=', 'sal.transaction_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'stock_adjustment')
            ->groupBy('sal.variation_id', 't.location_id')
            ->selectRaw(
                'sal.variation_id as agg_variation_id, t.location_id as agg_location_id, '
                .'SUM(sal.quantity) as total_adjusted'
            );
    }

    /**
     * Remaining purchase value (for stock price column) by variation and location.
     */
    protected function aggregateStockPriceByVariationLocation(int $business_id)
    {
        return DB::table('transactions as t')
            ->join('purchase_lines as pl', 't.id', '=', 'pl.transaction_id')
            ->where('t.business_id', $business_id)
            ->where(function ($q) {
                $q->where('t.status', 'received')
                    ->orWhere('t.type', 'purchase_return');
            })
            ->groupBy('pl.variation_id', 't.location_id')
            ->selectRaw(
                'pl.variation_id as agg_variation_id, t.location_id as agg_location_id, '
                .'SUM(COALESCE(pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) '
                .'- COALESCE(pl.quantity_returned, 0) - COALESCE(pl.mfg_quantity_used, 0), 0) * pl.purchase_price_inc_tax) '
                .'as stock_price'
            );
    }

    /**
     * Manufacturing / production purchase remaining qty by variation and location.
     */
    protected function aggregateMfgStockByVariationLocation(int $business_id)
    {
        return DB::table('transactions as t')
            ->join('purchase_lines as pl', 't.id', '=', 'pl.transaction_id')
            ->where('t.business_id', $business_id)
            ->where('t.status', 'received')
            ->where('t.type', 'production_purchase')
            ->groupBy('pl.variation_id', 't.location_id')
            ->selectRaw(
                'pl.variation_id as agg_variation_id, t.location_id as agg_location_id, '
                .'SUM(pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) '
                .'- COALESCE(pl.quantity_returned, 0) - COALESCE(pl.mfg_quantity_used, 0)) as total_mfg_stock'
            );
    }

    /**
     * Clean up orphaned cache records for deleted products, variations, or locations
     *
     * @param int $business_id
     * @return void
     */
    protected function cleanupOrphanedRecords($business_id)
    {
        $this->info("Cleaning up orphaned records for business {$business_id}...");

        $deletedCount = 0;

        // Apply location filter if specified
        $locationFilter = $this->option('location_id');

        // Delete cache records where variation no longer exists
        $query = ProductStockCache::where('business_id', $business_id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('variations')
                    ->whereRaw('variations.id = product_stock_cache.variation_id');
            });
        
        if ($locationFilter) {
            $query->where('location_id', $locationFilter);
        }

        $deleted = $query->delete();
        $deletedCount += $deleted;
        if ($deleted > 0) {
            $this->info("  - Removed {$deleted} records with deleted variations");
        }

        // Delete cache records where product no longer exists
        $query = ProductStockCache::where('business_id', $business_id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('products')
                    ->whereRaw('products.id = product_stock_cache.product_id');
            });

        if ($locationFilter) {
            $query->where('location_id', $locationFilter);
        }

        $deleted = $query->delete();
        $deletedCount += $deleted;
        if ($deleted > 0) {
            $this->info("  - Removed {$deleted} records with deleted products");
        }

        // Delete cache records where location no longer exists
        $query = ProductStockCache::where('business_id', $business_id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('business_locations')
                    ->whereRaw('business_locations.id = product_stock_cache.location_id');
            });

        if ($locationFilter) {
            $query->where('location_id', $locationFilter);
        }

        $deleted = $query->delete();
        $deletedCount += $deleted;
        if ($deleted > 0) {
            $this->info("  - Removed {$deleted} records with deleted locations");
        }

        // Delete cache records where variation-location combination no longer exists in variation_location_details
        $query = ProductStockCache::where('business_id', $business_id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('variation_location_details')
                    ->whereRaw('variation_location_details.variation_id = product_stock_cache.variation_id')
                    ->whereRaw('variation_location_details.location_id = product_stock_cache.location_id');
            });

        if ($locationFilter) {
            $query->where('location_id', $locationFilter);
        }

        $deleted = $query->delete();
        $deletedCount += $deleted;
        if ($deleted > 0) {
            $this->info("  - Removed {$deleted} records with removed variation-location combinations");
        }

        if ($deletedCount === 0) {
            $this->info("  - No orphaned records found");
        } else {
            $this->info("Total orphaned records removed: {$deletedCount}");
        }
    }
}

