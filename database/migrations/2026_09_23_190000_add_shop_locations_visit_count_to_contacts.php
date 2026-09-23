<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Stored (not live-joined) versions of "which store(s) has this customer
 * bought from" and "how many separate visits" — same pattern as the
 * existing lifetime_purchases/loyalty_points columns. The Customers page
 * previously computed these via a JOIN over the whole transactions table
 * on every single page load/search/sort, which took 5-7+ seconds. Kept in
 * sync incrementally on each finalized sale (SellPosController) plus a
 * one-time backfill command for existing history.
 */
class AddShopLocationsVisitCountToContacts extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('contacts')) return;
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'shop_locations')) {
                $table->string('shop_locations', 255)->nullable()->after('last_purchase_date');
            }
            if (!Schema::hasColumn('contacts', 'visit_count')) {
                $table->integer('visit_count')->default(0)->after('shop_locations');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('contacts')) return;
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'visit_count')) {
                $table->dropColumn('visit_count');
            }
            if (Schema::hasColumn('contacts', 'shop_locations')) {
                $table->dropColumn('shop_locations');
            }
        });
    }
}
