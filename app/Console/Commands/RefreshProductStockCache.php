<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\ProductStockCache;
use App\Variation;
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
        // Build the main query
        $query = Variation::join('products as p', 'p.id', '=', 'variations.product_id')
            ->join('units', 'p.unit_id', '=', 'units.id')
            ->leftJoin('variation_location_details as vld', 'variations.id', '=', 'vld.variation_id')
            ->leftJoin('business_locations as l', 'vld.location_id', '=', 'l.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->join('product_variations as pv', 'variations.product_variation_id', '=', 'pv.id')
            ->where('p.business_id', $business_id)
            ->whereIn('p.type', ['single', 'variable']);

        // Apply location filter if specified
        if ($this->option('location_id')) {
            $query->where('vld.location_id', $this->option('location_id'));
        }

        // Select all required fields
        $products = $query->select(
            DB::raw("$business_id as business_id"),
            'p.id as product_id',
            'variations.id as variation_id',
            'l.id as location_id',
            'p.category_id',
            'p.sub_category_id',
            'p.brand_id',
            'p.unit_id',
            
            // Stock calculation fields
            DB::raw("COALESCE((SELECT SUM(TSL.quantity - TSL.quantity_returned) FROM transactions 
                JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                WHERE transactions.status='final' AND transactions.type='sell' AND transactions.location_id=vld.location_id
                AND TSL.product_id is not null 
                AND TSL.variation_id=variations.id), 0) as total_sold"),
            
            DB::raw("COALESCE((SELECT SUM(IF(transactions.type='sell_transfer', TSL.quantity, 0)) FROM transactions 
                JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                WHERE transactions.status='final' AND transactions.type='sell_transfer' AND transactions.location_id=vld.location_id 
                AND TSL.product_id is not null AND TSL.variation_id=variations.id), 0) as total_transfered"),
            
            DB::raw("COALESCE((SELECT SUM(IF(transactions.type='stock_adjustment', SAL.quantity, 0)) FROM transactions 
                JOIN stock_adjustment_lines AS SAL ON transactions.id=SAL.transaction_id
                WHERE transactions.type='stock_adjustment' AND transactions.location_id=vld.location_id 
                AND SAL.variation_id=variations.id), 0) as total_adjusted"),
            
            DB::raw("COALESCE((SELECT SUM(COALESCE(pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0) - COALESCE(pl.mfg_quantity_used, 0), 0) * purchase_price_inc_tax) FROM transactions 
                JOIN purchase_lines AS pl ON transactions.id=pl.transaction_id
                WHERE (transactions.status='received' OR transactions.type='purchase_return') AND transactions.location_id=vld.location_id 
                AND pl.variation_id=variations.id), 0) as stock_price"),
            
            DB::raw("COALESCE(SUM(vld.qty_available), 0) as stock"),
            
            // Manufacturing stock
            DB::raw("COALESCE((SELECT SUM(PL.quantity - COALESCE(PL.quantity_sold, 0) - COALESCE(PL.quantity_adjusted, 0) - COALESCE(PL.quantity_returned, 0) - COALESCE(PL.mfg_quantity_used, 0)) FROM transactions 
                JOIN purchase_lines AS PL ON transactions.id=PL.transaction_id
                WHERE transactions.status='received' AND transactions.type='production_purchase' AND transactions.location_id=vld.location_id  
                AND PL.variation_id=variations.id), 0) as total_mfg_stock"),
                
            // Product details
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
            
            // Additional fields for filtering (to avoid joins)
            'p.tax as tax_id',
            'p.is_inactive',
            'p.not_for_selling'
        )
        ->groupBy('variations.id', 'vld.location_id')
        ->whereNotNull('vld.location_id'); // Only include products with location data

        // Process in chunks to avoid memory issues
        $totalProcessed = 0;
        $chunkSize = 500;

        $query->chunk($chunkSize, function ($items) use (&$totalProcessed, $business_id) {
            foreach ($items as $item) {
                // Update or create cache record
                ProductStockCache::updateOrCreate(
                    [
                        'business_id' => $business_id,
                        'variation_id' => $item->variation_id,
                        'location_id' => $item->location_id,
                    ],
                    [
                        'product_id' => $item->product_id,
                        'category_id' => $item->category_id,
                        'sub_category_id' => $item->sub_category_id,
                        'brand_id' => $item->brand_id,
                        'unit_id' => $item->unit_id,
                        'total_sold' => $item->total_sold ?? 0,
                        'total_transfered' => $item->total_transfered ?? 0,
                        'total_adjusted' => $item->total_adjusted ?? 0,
                        'stock_price' => $item->stock_price ?? 0,
                        'stock' => $item->stock ?? 0,
                        'total_mfg_stock' => $item->total_mfg_stock ?? 0,
                        'sku' => $item->sku,
                        'product' => $item->product,
                        'type' => $item->type,
                        'alert_quantity' => $item->alert_quantity,
                        'unit' => $item->unit,
                        'enable_stock' => $item->enable_stock,
                        'unit_price' => $item->unit_price,
                        'product_variation' => $item->product_variation,
                        'variation_name' => $item->variation_name,
                        'location_name' => $item->location_name,
                        'category_name' => $item->category_name,
                        'product_custom_field1' => $item->product_custom_field1,
                        'product_custom_field2' => $item->product_custom_field2,
                        'product_custom_field3' => $item->product_custom_field3,
                        'product_custom_field4' => $item->product_custom_field4,
                        'tax_id' => $item->tax_id,
                        'is_inactive' => $item->is_inactive ?? 0,
                        'not_for_selling' => $item->not_for_selling ?? 0,
                        'calculated_at' => Carbon::now(),
                    ]
                );

                $totalProcessed++;
            }

            $this->info("Processed {$totalProcessed} records...");
        });

        $this->info("Completed business {$business_id}: {$totalProcessed} records processed");
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

