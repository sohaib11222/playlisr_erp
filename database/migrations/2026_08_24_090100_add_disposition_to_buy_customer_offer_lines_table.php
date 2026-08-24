<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where an item from a purchased collection is meant to end up: store,
// discogs, ebay, hollywood, trash, or clearance_bin (records only). Set by
// the employee at intake; carried onto the materialized Product on accept
// (see BuyFromCustomerController@createPurchaseFromOffer).
class AddDispositionToBuyCustomerOfferLinesTable extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('buy_customer_offer_lines', 'disposition')) {
                $table->string('disposition', 20)->nullable()->after('condition_grade');
            }
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            if (Schema::hasColumn('buy_customer_offer_lines', 'disposition')) {
                $table->dropColumn('disposition');
            }
        });
    }
}
