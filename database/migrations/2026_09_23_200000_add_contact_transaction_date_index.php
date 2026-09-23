<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * ContactUtil::getContactQuery()'s max_transaction_date subquery does
 * MAX(DATE(transaction_date)) GROUP BY contact_id across every transaction
 * type — it's the one piece of that refactor that couldn't be narrowed by
 * type/status without changing existing has_no_sell_from filter behavior,
 * so business_id (not very selective — this app is single-tenant in
 * practice) was the only usable WHERE filter. This composite index lets
 * MySQL answer that GROUP BY/MAX straight from the index instead of
 * scanning+sorting every row.
 */
class AddContactTransactionDateIndex extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transactions')) return;
        $exists = DB::select("SHOW INDEX FROM transactions WHERE Key_name = 'transactions_contact_id_transaction_date_index'");
        if (empty($exists)) {
            DB::statement('ALTER TABLE transactions ADD INDEX transactions_contact_id_transaction_date_index (contact_id, transaction_date)');
        }
    }

    public function down()
    {
        if (!Schema::hasTable('transactions')) return;
        $exists = DB::select("SHOW INDEX FROM transactions WHERE Key_name = 'transactions_contact_id_transaction_date_index'");
        if (!empty($exists)) {
            DB::statement('ALTER TABLE transactions DROP INDEX transactions_contact_id_transaction_date_index');
        }
    }
}
