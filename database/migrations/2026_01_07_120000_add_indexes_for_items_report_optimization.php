<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIndexesForItemsReportOptimization extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Composite index for transactions table - commonly filtered together
        // purchase.business_id + purchase.location_id + purchase.transaction_date
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['business_id', 'location_id', 'transaction_date'], 'idx_transactions_business_location_date');
            $table->index(['business_id', 'transaction_date', 'type'], 'idx_transactions_business_date_type');
        });

        // Composite index for purchase_lines - commonly joined together
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->index(['product_id', 'variation_id'], 'idx_purchase_lines_product_variation');
            $table->index(['transaction_id', 'variation_id'], 'idx_purchase_lines_transaction_variation');
        });

        // Composite index for transaction_sell_lines - commonly joined
        Schema::table('transaction_sell_lines', function (Blueprint $table) {
            $table->index(['transaction_id', 'variation_id'], 'idx_sell_lines_transaction_variation');
        });

        // Index for variations - joining with product_variations
        Schema::table('variations', function (Blueprint $table) {
            $table->index(['product_variation_id', 'product_id'], 'idx_variations_pv_product');
        });

        // Index for products - category filtering
        Schema::table('products', function (Blueprint $table) {
            $table->index(['category_id', 'sub_category_id'], 'idx_products_category_subcat');
            $table->index(['business_id', 'category_id'], 'idx_products_business_category');
        });

        // Index for contacts - supplier/customer lookups
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['business_id', 'type'], 'idx_contacts_business_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_business_location_date');
            $table->dropIndex('idx_transactions_business_date_type');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropIndex('idx_purchase_lines_product_variation');
            $table->dropIndex('idx_purchase_lines_transaction_variation');
        });

        Schema::table('transaction_sell_lines', function (Blueprint $table) {
            $table->dropIndex('idx_sell_lines_transaction_variation');
        });

        Schema::table('variations', function (Blueprint $table) {
            $table->dropIndex('idx_variations_pv_product');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_category_subcat');
            $table->dropIndex('idx_products_business_category');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('idx_contacts_business_type');
        });
    }
}


