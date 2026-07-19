<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'prepaid_pickup' to the transactions.channel enum.
 *
 * Sarah 2026-07-08: New POS channel for items that were already paid for
 * elsewhere and are just being collected in-store. It behaves like the
 * other off-register channels (whatnot / discogs): the sale still moves
 * inventory (stock down 1) but the money was collected ahead of time, so
 * it's finalized off-register (tender 'other') and excluded from the
 * Clover / in-store register reconciliation.
 *
 * The channel column is an enum (see
 * 2026_04_22_063000_add_channel_to_transactions_table), so a new value
 * requires an ALTER — otherwise MySQL rejects/truncates the insert.
 */
class AddPrepaidPickupToChannelEnum extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transactions') || !Schema::hasColumn('transactions', 'channel')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `transactions` MODIFY `channel` "
            . "ENUM('in_store', 'whatnot', 'discogs', 'ebay', 'prepaid_pickup') "
            . "NOT NULL DEFAULT 'in_store'"
        );
    }

    public function down()
    {
        if (!Schema::hasTable('transactions') || !Schema::hasColumn('transactions', 'channel')) {
            return;
        }

        // Fold any prepaid_pickup rows back to in_store before shrinking the
        // enum so the value doesn't become illegal mid-alter.
        DB::table('transactions')->where('channel', 'prepaid_pickup')->update(['channel' => 'in_store']);

        DB::statement(
            "ALTER TABLE `transactions` MODIFY `channel` "
            . "ENUM('in_store', 'whatnot', 'discogs', 'ebay') "
            . "NOT NULL DEFAULT 'in_store'"
        );
    }
}
