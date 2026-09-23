<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * The Customers page's new Store(s)/Store Visits columns run a subquery
 * over the whole transactions table (WHERE type='sell' AND status='final'
 * GROUP BY contact_id) on every page load, search, and sort — transactions
 * only had single-column indexes on type/contact_id/etc, no status index,
 * so this was a real table scan every time. This composite index lets
 * MySQL satisfy the whole WHERE + GROUP BY from the index directly.
 */
class AddTypeStatusContactIndexToTransactions extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transactions')) return;
        $exists = DB::select("SHOW INDEX FROM transactions WHERE Key_name = 'transactions_type_status_contact_id_index'");
        if (empty($exists)) {
            DB::statement('ALTER TABLE transactions ADD INDEX transactions_type_status_contact_id_index (type, status, contact_id)');
        }
    }

    public function down()
    {
        if (!Schema::hasTable('transactions')) return;
        $exists = DB::select("SHOW INDEX FROM transactions WHERE Key_name = 'transactions_type_status_contact_id_index'");
        if (!empty($exists)) {
            DB::statement('ALTER TABLE transactions DROP INDEX transactions_type_status_contact_id_index');
        }
    }
}
