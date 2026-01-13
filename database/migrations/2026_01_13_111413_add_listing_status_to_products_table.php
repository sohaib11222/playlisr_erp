<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddListingStatusToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ebay_listing_id')->nullable()->after('listing_location');
            $table->string('discogs_listing_id')->nullable()->after('ebay_listing_id');
            $table->enum('listing_status', ['not_listed', 'listed', 'error'])->default('not_listed')->after('discogs_listing_id');
            
            $table->index('listing_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['listing_status']);
            $table->dropColumn(['ebay_listing_id', 'discogs_listing_id', 'listing_status']);
        });
    }
}

