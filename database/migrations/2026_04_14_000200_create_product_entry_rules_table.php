<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductEntryRulesTable extends Migration
{
    public function up()
    {
        Schema::create('product_entry_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->string('trigger_type', 30)->default('title')->index(); // title|category_combo
            $table->string('trigger_value');
            $table->integer('category_id')->nullable()->index();
            $table->integer('sub_category_id')->nullable()->index();
            $table->string('artist')->nullable();
            $table->decimal('purchase_price', 22, 4)->nullable();
            $table->decimal('selling_price', 22, 4)->nullable();
            $table->boolean('is_active')->default(1)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_entry_rules');
    }
}

