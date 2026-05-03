<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Speeds up stock:refresh-cache aggregate subqueries (GROUP BY variation_id, location_id)
 * and joins from transaction_sell_lines / purchase_lines / stock_adjustment_lines.
 * Idempotent: skips if index name already exists.
 */
class AddStockCacheSupportingIndexes extends Migration
{
    /**
     * @param string $table
     * @param string $indexName
     * @return bool
     */
    protected function indexExists($table, $indexName)
    {
        $db = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$db, $table, $indexName]
        );

        return $row !== null;
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!$this->indexExists('transactions', 'idx_transactions_business_type_status')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->index(['business_id', 'type', 'status'], 'idx_transactions_business_type_status');
            });
        }

        if (!$this->indexExists('transaction_sell_lines', 'idx_transaction_sell_lines_variation_id')) {
            Schema::table('transaction_sell_lines', function (Blueprint $table) {
                $table->index('variation_id', 'idx_transaction_sell_lines_variation_id');
            });
        }

        if (!$this->indexExists('purchase_lines', 'idx_purchase_lines_variation_id')) {
            Schema::table('purchase_lines', function (Blueprint $table) {
                $table->index('variation_id', 'idx_purchase_lines_variation_id');
            });
        }

        if (!$this->indexExists('stock_adjustment_lines', 'idx_stock_adjustment_lines_variation_id')) {
            Schema::table('stock_adjustment_lines', function (Blueprint $table) {
                $table->index('variation_id', 'idx_stock_adjustment_lines_variation_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ($this->indexExists('stock_adjustment_lines', 'idx_stock_adjustment_lines_variation_id')) {
            Schema::table('stock_adjustment_lines', function (Blueprint $table) {
                $table->dropIndex('idx_stock_adjustment_lines_variation_id');
            });
        }

        if ($this->indexExists('purchase_lines', 'idx_purchase_lines_variation_id')) {
            Schema::table('purchase_lines', function (Blueprint $table) {
                $table->dropIndex('idx_purchase_lines_variation_id');
            });
        }

        if ($this->indexExists('transaction_sell_lines', 'idx_transaction_sell_lines_variation_id')) {
            Schema::table('transaction_sell_lines', function (Blueprint $table) {
                $table->dropIndex('idx_transaction_sell_lines_variation_id');
            });
        }

        if ($this->indexExists('transactions', 'idx_transactions_business_type_status')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex('idx_transactions_business_type_status');
            });
        }
    }
}
