<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales channel for POS transactions.
 *
 * Sarah 2026-04-22: "Add a column for where the purchase came from —
 * In Store (most purchases), Whatnot, Discogs, eBay." Previously the
 * system only distinguished Whatnot via an is_whatnot boolean (added
 * 2026_03_12), which is not enough once Discogs and eBay sales flow
 * through the same register.
 *
 * We introduce a `channel` enum on transactions and backfill from
 * is_whatnot so existing reports keep working:
 *   is_whatnot = 1  →  channel = 'whatnot'
 *   everything else →  channel = 'in_store'
 *
 * is_whatnot stays in place for now (both columns are kept in sync by
 * SellPosController) so reports / filters that already read is_whatnot
 * don't break. A follow-up migration can drop it once all callers are
 * moved over.
 */
class AddChannelToTransactionsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'channel')) {
                // enum kept flat/lowercase so it's easy to grep + cheap to
                // compare. Display labels live in the view layer.
                $table->enum('channel', ['in_store', 'whatnot', 'discogs', 'ebay'])
                    ->default('in_store')
                    ->after('is_whatnot');
                $table->index('channel');
            }
        });

        // Backfill from the existing is_whatnot boolean so historical
        // Whatnot sales keep their provenance. Guarded in case is_whatnot
        // hasn't been migrated yet on some environments.
        if (Schema::hasColumn('transactions', 'is_whatnot')
            && Schema::hasColumn('transactions', 'channel')) {
            DB::table('transactions')
                ->where('is_whatnot', 1)
                ->where('channel', 'in_store')
                ->update(['channel' => 'whatnot']);
        }
    }

    public function down()
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'channel')) {
                try { $table->dropIndex(['channel']); } catch (\Exception $e) {}
                $table->dropColumn('channel');
            }
        });
    }
}
