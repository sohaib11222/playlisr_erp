<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sarah 2026-07-10: adds a 'spend_reward' value to the store_credit_logs
 * source enum. This is the automatic "$5 per $100 of pre-tax spend on a
 * store-credit-free sale" loyalty grant issued from SellPosController::
 * grantSpendReward(). Without the new enum value MySQL (strict mode) would
 * reject the ledger insert and the grant would have no audit row.
 *
 * Enum edits can't go through the Schema builder without doctrine/dbal, so
 * this uses a raw MODIFY COLUMN (same approach the codebase uses elsewhere).
 * Reversible: down() restores the original four-value enum.
 */
class AddSpendRewardToStoreCreditLogsSource extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('store_credit_logs')) {
            return;
        }

        DB::statement(
            "ALTER TABLE store_credit_logs MODIFY COLUMN source " .
            "ENUM('manual_add', 'manual_adjust', 'buy_from_customer', 'redeem', 'spend_reward') " .
            "NOT NULL DEFAULT 'manual_add'"
        );
    }

    public function down()
    {
        if (!Schema::hasTable('store_credit_logs')) {
            return;
        }

        // Collapse any existing spend_reward rows back to manual_add so the
        // narrower enum doesn't reject them on the way down.
        DB::table('store_credit_logs')->where('source', 'spend_reward')->update(['source' => 'manual_add']);

        DB::statement(
            "ALTER TABLE store_credit_logs MODIFY COLUMN source " .
            "ENUM('manual_add', 'manual_adjust', 'buy_from_customer', 'redeem') " .
            "NOT NULL DEFAULT 'manual_add'"
        );
    }
}
