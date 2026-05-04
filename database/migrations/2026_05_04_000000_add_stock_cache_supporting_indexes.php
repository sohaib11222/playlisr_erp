<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optional performance indexes for stock cache refresh / heavy reports.
 * Safe if never run: POS and all features work without these indexes (queries are slower only).
 * Idempotent: skips when index name or table missing; tolerates duplicate-index races.
 */
class AddStockCacheSupportingIndexes extends Migration
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
        $db = config('database.connections.' . $default . '.database');

        return $db ?: null;
    }

    /**
     * @param string $table
     * @param string $indexName
     * @return bool
     */
    protected function indexExists($table, $indexName)
    {
        $db = $this->schemaDatabaseName();
        if ($db === null || $db === '') {
            return false;
        }

        try {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?
                 LIMIT 1',
                [$db, $table, $indexName]
            );

            return $row !== null;
        } catch (\Throwable $e) {
            Log::warning('AddStockCacheSupportingIndexes: could not read information_schema', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param string $table
     * @param string $indexName
     * @param \Closure $callback
     * @return void
     */
    protected function safeAddIndex($table, $indexName, \Closure $callback)
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if ($this->indexExists($table, $indexName)) {
            return;
        }

        try {
            Schema::table($table, $callback);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // MySQL / MariaDB: duplicate name; SQLite: already exists — safe to ignore (idempotent / race).
            if (stripos($msg, 'Duplicate key name') !== false
                || stripos($msg, 'already exists') !== false
                || stripos($msg, 'duplicate key') !== false) {
                Log::info('AddStockCacheSupportingIndexes: index skipped (already present)', [
                    'table' => $table,
                    'index' => $indexName,
                ]);

                return;
            }

            throw $e;
        }
    }

    /**
     * @param string $table
     * @param string $indexName
     * @return void
     */
    protected function safeDropIndex($table, $indexName)
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!$this->indexExists($table, $indexName)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            Log::warning('AddStockCacheSupportingIndexes: drop index failed (non-fatal)', [
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
        $this->safeAddIndex('transactions', 'idx_transactions_business_type_status', function (Blueprint $table) {
            $table->index(['business_id', 'type', 'status'], 'idx_transactions_business_type_status');
        });

        $this->safeAddIndex('transaction_sell_lines', 'idx_transaction_sell_lines_variation_id', function (Blueprint $table) {
            $table->index('variation_id', 'idx_transaction_sell_lines_variation_id');
        });

        $this->safeAddIndex('purchase_lines', 'idx_purchase_lines_variation_id', function (Blueprint $table) {
            $table->index('variation_id', 'idx_purchase_lines_variation_id');
        });

        $this->safeAddIndex('stock_adjustment_lines', 'idx_stock_adjustment_lines_variation_id', function (Blueprint $table) {
            $table->index('variation_id', 'idx_stock_adjustment_lines_variation_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->safeDropIndex('stock_adjustment_lines', 'idx_stock_adjustment_lines_variation_id');
        $this->safeDropIndex('purchase_lines', 'idx_purchase_lines_variation_id');
        $this->safeDropIndex('transaction_sell_lines', 'idx_transaction_sell_lines_variation_id');
        $this->safeDropIndex('transactions', 'idx_transactions_business_type_status');
    }
}
