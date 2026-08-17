<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Supporting indexes for the /products list.
 *
 * The list query filters on products(business_id, type), sorts on
 * products.created_at by default, and reads per-product stock/prices through
 * correlated subselects on variations. These indexes let MySQL satisfy the
 * default sort from an index instead of filesorting 140k rows, and keep each
 * subselect an index lookup.
 *
 * Safe if never run: every query works without these, just slower.
 * Idempotent: skips when the index or table is missing, tolerates races.
 */
class AddProductsListSupportingIndexes extends Migration
{
    /**
     * @return string|null
     */
    protected function schemaDatabaseName()
    {
        try {
            $name = DB::connection()->getDatabaseName();
            if (!empty($name)) {
                return $name;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $default = config('database.default');

        return config('database.connections.' . $default . '.database') ?: null;
    }

    /**
     * @param  string  $table
     * @param  string  $indexName
     * @return bool
     */
    protected function indexExists($table, $indexName)
    {
        $db = $this->schemaDatabaseName();
        if ($db === null || $db === '') {
            return false;
        }

        try {
            return DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?
                 LIMIT 1',
                [$db, $table, $indexName]
            ) !== null;
        } catch (\Throwable $e) {
            Log::warning('AddProductsListSupportingIndexes: could not read information_schema', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  string  $table
     * @param  string  $indexName
     * @param  \Closure  $callback
     * @return void
     */
    protected function safeAddIndex($table, $indexName, \Closure $callback)
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        try {
            Schema::table($table, $callback);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key name') !== false || stripos($msg, 'already exists') !== false) {
                Log::info('AddProductsListSupportingIndexes: index already present', [
                    'table' => $table,
                    'index' => $indexName,
                ]);

                return;
            }

            throw $e;
        }
    }

    /**
     * @param  string  $table
     * @param  string  $indexName
     * @return void
     */
    protected function safeDropIndex($table, $indexName)
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $indexName)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            Log::warning('AddProductsListSupportingIndexes: drop index failed (non-fatal)', [
                'table' => $table,
                'index' => $indexName,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Default ordering of the list: newest first, within one business.
        $this->safeAddIndex('products', 'idx_products_business_created_at', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'idx_products_business_created_at');
        });

        // Base filter: business_id + type != 'modifier'.
        $this->safeAddIndex('products', 'idx_products_business_type', function (Blueprint $table) {
            $table->index(['business_id', 'type'], 'idx_products_business_type');
        });

        // Stock / price / last-updated subselects all look up a product's live
        // variations, so this wants to be a covering-ish lookup.
        $this->safeAddIndex('variations', 'idx_variations_product_deleted', function (Blueprint $table) {
            $table->index(['product_id', 'deleted_at'], 'idx_variations_product_deleted');
        });

        // The stock subselect joins vld by variation and narrows by location.
        $this->safeAddIndex('variation_location_details', 'idx_vld_variation_location', function (Blueprint $table) {
            $table->index(['variation_id', 'location_id'], 'idx_vld_variation_location');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->safeDropIndex('variation_location_details', 'idx_vld_variation_location');
        $this->safeDropIndex('variations', 'idx_variations_product_deleted');
        $this->safeDropIndex('products', 'idx_products_business_type');
        $this->safeDropIndex('products', 'idx_products_business_created_at');
    }
}
