<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tags a product with the buy-from-customer offer (and lot line) it was listed
// from on Mass Add, so the Buy Results report can follow bought items through
// to their sales. Both nullable: most products don't come from a buy.
class AddBuyOfferRefsToProductsTable extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'buy_offer_id')) {
                $table->unsignedBigInteger('buy_offer_id')->nullable()->index();
            }
            if (!Schema::hasColumn('products', 'buy_offer_line_id')) {
                $table->unsignedBigInteger('buy_offer_line_id')->nullable()->index();
            }
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'buy_offer_line_id')) {
                $table->dropIndex(['buy_offer_line_id']);
                $table->dropColumn('buy_offer_line_id');
            }
            if (Schema::hasColumn('products', 'buy_offer_id')) {
                $table->dropIndex(['buy_offer_id']);
                $table->dropColumn('buy_offer_id');
            }
        });
    }
}
