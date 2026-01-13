<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPurchaseSaleDatesToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->date('first_purchase_date')->nullable()->after('listing_location')->comment('Date when product was first purchased');
            $table->date('last_sale_date')->nullable()->after('first_purchase_date')->comment('Date when product was last sold');
        });
        
        // Add indexes for performance
        Schema::table('products', function (Blueprint $table) {
            $table->index('first_purchase_date');
            $table->index('last_sale_date');
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
            $table->dropIndex(['first_purchase_date']);
            $table->dropIndex(['last_sale_date']);
            $table->dropColumn(['first_purchase_date', 'last_sale_date']);
        });
    }
}


