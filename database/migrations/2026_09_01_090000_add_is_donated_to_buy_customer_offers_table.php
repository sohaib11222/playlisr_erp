<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a cashier mark a whole buy-from-customer collection as donated so the
// $0 payout doesn't get flagged as an unfinished negotiation — see
// BuyFromCustomerController::validateRequest()/saveOffer()/createPurchaseFromOffer().
class AddIsDonatedToBuyCustomerOffersTable extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('buy_customer_offers', 'is_donated')) {
                $table->boolean('is_donated')->default(false)->after('payment_method');
            }
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            if (Schema::hasColumn('buy_customer_offers', 'is_donated')) {
                $table->dropColumn('is_donated');
            }
        });
    }
}
