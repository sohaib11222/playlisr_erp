<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateManualItemPriceRulesTable extends Migration
{
    public function up()
    {
        Schema::create('manual_item_price_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->string('label');
            $table->string('keywords');
            $table->decimal('price', 22, 4)->default(0);
            $table->boolean('is_active')->default(1)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('manual_item_price_rules');
    }
}

