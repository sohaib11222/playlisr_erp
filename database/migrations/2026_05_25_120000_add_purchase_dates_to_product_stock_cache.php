<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPurchaseDatesToProductStockCache extends Migration
{
    public function up()
    {
        Schema::table('product_stock_cache', function (Blueprint $table) {
            $table->date('first_purchase_date')->nullable()->after('total_mfg_stock');
            $table->date('last_purchase_date')->nullable()->after('first_purchase_date');
        });
    }

    public function down()
    {
        Schema::table('product_stock_cache', function (Blueprint $table) {
            $table->dropColumn(['first_purchase_date', 'last_purchase_date']);
        });
    }
}
