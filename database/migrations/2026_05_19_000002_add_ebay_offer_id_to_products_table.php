<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEbayOfferIdToProductsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('products', 'ebay_offer_id')) {
            Schema::table('products', function (Blueprint $table) {
                $after = Schema::hasColumn('products', 'ebay_listing_id')
                    ? 'ebay_listing_id'
                    : 'listing_location';
                $table->string('ebay_offer_id')->nullable()->after($after);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('products', 'ebay_offer_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('ebay_offer_id');
            });
        }
    }
}
