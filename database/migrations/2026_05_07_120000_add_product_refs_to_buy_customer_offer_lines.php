<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds product_id / variation_id / purchase_line_id refs to BFC offer lines so
// accepting an offer can materialize the items into real Products + Variations
// and a real received Purchase. The undo path needs purchase_line_id to know
// which lines to revert.
class AddProductRefsToBuyCustomerOfferLines extends Migration
{
    public function up()
    {
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('buy_customer_offer_lines', 'product_id')) {
                $table->unsignedInteger('product_id')->nullable()->after('line_credit_total');
            }
            if (!Schema::hasColumn('buy_customer_offer_lines', 'variation_id')) {
                $table->unsignedInteger('variation_id')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('buy_customer_offer_lines', 'purchase_line_id')) {
                $table->unsignedBigInteger('purchase_line_id')->nullable()->after('variation_id');
            }
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
            if (Schema::hasColumn('buy_customer_offer_lines', 'purchase_line_id')) {
                $table->dropColumn('purchase_line_id');
            }
            if (Schema::hasColumn('buy_customer_offer_lines', 'variation_id')) {
                $table->dropColumn('variation_id');
            }
            if (Schema::hasColumn('buy_customer_offer_lines', 'product_id')) {
                $table->dropColumn('product_id');
            }
        });
    }
}
