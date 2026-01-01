<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProductStockCacheTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_stock_cache', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->integer('product_id')->unsigned();
            $table->integer('variation_id')->unsigned();
            $table->integer('location_id')->unsigned()->nullable();
            $table->integer('category_id')->unsigned()->nullable();
            $table->integer('sub_category_id')->unsigned()->nullable();
            $table->integer('brand_id')->unsigned()->nullable();
            $table->integer('unit_id')->unsigned()->nullable();
            
            // Stock calculation fields
            $table->decimal('total_sold', 22, 4)->default(0);
            $table->decimal('total_transfered', 22, 4)->default(0);
            $table->decimal('total_adjusted', 22, 4)->default(0);
            $table->decimal('stock_price', 22, 4)->default(0);
            $table->decimal('stock', 22, 4)->default(0);
            $table->decimal('total_mfg_stock', 22, 4)->nullable();
            
            // Product details
            $table->string('sku')->nullable();
            $table->string('product')->nullable();
            $table->string('type', 25)->nullable();
            $table->decimal('alert_quantity', 22, 4)->nullable();
            $table->string('unit', 100)->nullable();
            $table->tinyInteger('enable_stock')->default(0);
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->string('product_variation')->nullable();
            $table->string('variation_name')->nullable();
            $table->string('location_name')->nullable();
            $table->string('category_name')->nullable();
            $table->string('product_custom_field1')->nullable();
            $table->string('product_custom_field2')->nullable();
            $table->string('product_custom_field3')->nullable();
            $table->string('product_custom_field4')->nullable();
            
            // Additional fields for filtering (to avoid joins)
            $table->integer('tax_id')->unsigned()->nullable();
            $table->tinyInteger('is_inactive')->default(0);
            $table->tinyInteger('not_for_selling')->default(0);
            $table->integer('repair_model_id')->unsigned()->nullable();
            
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            
            // Add indexes for better query performance
            $table->index('business_id');
            $table->index('product_id');
            $table->index('variation_id');
            $table->index('location_id');
            $table->index('category_id');
            $table->index('sub_category_id');
            $table->index('brand_id');
            $table->index('unit_id');
            $table->index('tax_id');
            $table->index('is_inactive');
            $table->index('not_for_selling');
            $table->index('repair_model_id');
            $table->index(['business_id', 'location_id']);
            $table->index(['business_id', 'category_id']);
            $table->index('calculated_at');
            
            // Unique constraint to prevent duplicates
            $table->unique(['business_id', 'variation_id', 'location_id'], 'unique_stock_cache');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('product_stock_cache');
    }
}

