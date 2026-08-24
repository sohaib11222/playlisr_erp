<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

// Carries the disposition an employee set on the BFC offer line (store,
// discogs, ebay, hollywood, trash, clearance_bin) onto the materialized
// Product so it isn't lost once the item leaves the BFC flow.
class AddDispositionToProductsTable extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'disposition')) {
                $table->string('disposition', 20)->nullable()->after('listing_status');
                $table->index('disposition');
            }
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'disposition')) {
                $table->dropIndex(['disposition']);
                $table->dropColumn('disposition');
            }
        });
    }
}
