<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShippingTaxToReceivingPackagesTable extends Migration
{
    public function up()
    {
        Schema::table('receiving_packages', function (Blueprint $table) {
            // Whole-package totals — not per item — so employees can see the
            // full landed cost (item cost + shipping + tax) when pricing.
            $table->decimal('shipping_cost', 10, 2)->nullable()->after('invoice_number');
            $table->decimal('tax_amount', 10, 2)->nullable()->after('shipping_cost');
        });
    }

    public function down()
    {
        Schema::table('receiving_packages', function (Blueprint $table) {
            $table->dropColumn(['shipping_cost', 'tax_amount']);
        });
    }
}
