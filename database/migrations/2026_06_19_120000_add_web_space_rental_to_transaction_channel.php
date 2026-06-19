<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Add 'web' and 'space_rental' to the transactions.channel enum so
 * nivessa.com web orders and venue (space rental) bookings can be stored
 * as real ERP transactions — the source of truth — instead of being
 * live-fetched per-render only for the Sales-by-Channel report.
 *
 *   before: enum('in_store','whatnot','discogs','ebay')
 *   after:  enum('in_store','whatnot','discogs','ebay','web','space_rental')
 *
 * Add-only and non-destructive: existing rows keep their value, the
 * default stays 'in_store'. Mirrors the safe pattern of the original
 * 2026_04_22 channel migration. Raw MODIFY is used because Laravel's
 * Blueprint can't alter an existing enum's value list without doctrine/dbal.
 */
class AddWebSpaceRentalToTransactionChannel extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transactions') || !Schema::hasColumn('transactions', 'channel')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `transactions` MODIFY COLUMN `channel` " .
            "ENUM('in_store','whatnot','discogs','ebay','web','space_rental') " .
            "NOT NULL DEFAULT 'in_store'"
        );
    }

    public function down()
    {
        if (!Schema::hasTable('transactions') || !Schema::hasColumn('transactions', 'channel')) {
            return;
        }

        // Fold any rows on the new channels back to 'in_store' first so the
        // narrowed enum can't reject existing data, then restore the old list.
        DB::table('transactions')
            ->whereIn('channel', ['web', 'space_rental'])
            ->update(['channel' => 'in_store']);

        DB::statement(
            "ALTER TABLE `transactions` MODIFY COLUMN `channel` " .
            "ENUM('in_store','whatnot','discogs','ebay') " .
            "NOT NULL DEFAULT 'in_store'"
        );
    }
}
