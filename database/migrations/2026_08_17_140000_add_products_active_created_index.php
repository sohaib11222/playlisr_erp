<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Index for the default /products view.
 *
 * That view issues, once per request:
 *
 *   where products.business_id = ? and products.type != ? and products.is_inactive = ?
 *   order by products.created_at desc limit 100
 *
 * The is_inactive predicate comes from the page's default "Active" filter. With
 * it present the optimizer will not use idx_products_business_created_at for the
 * ordering, so it filesorts the whole business (~141k rows) — and because the
 * select list carries correlated subselects for stock and prices, that plan
 * evaluates them for every scanned row instead of the 100 on screen. Measured on
 * production at 6.6-7.2s for the main SELECT alone.
 *
 * This index puts the two equality columns first and created_at last, so the
 * sort is satisfied by the index and LIMIT 100 stops early.
 *
 * Safe if never run: the page works without it, just slowly.
 * Idempotent: skips when the index or table is missing, tolerates races.
 */
class AddProductsActiveCreatedIndex extends Migration
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
            Log::warning('AddProductsActiveCreatedIndex: could not read information_schema', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('products')
            || $this->indexExists('products', 'idx_products_business_inactive_created')) {
            return;
        }

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->index(
                    ['business_id', 'is_inactive', 'created_at'],
                    'idx_products_business_inactive_created'
                );
            });
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key name') !== false || stripos($msg, 'already exists') !== false) {
                Log::info('AddProductsActiveCreatedIndex: index already present');

                return;
            }

            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('products')
            || !$this->indexExists('products', 'idx_products_business_inactive_created')) {
            return;
        }

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('idx_products_business_inactive_created');
            });
        } catch (\Throwable $e) {
            Log::warning('AddProductsActiveCreatedIndex: drop index failed (non-fatal)', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
