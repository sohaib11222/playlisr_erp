<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCategoryFieldsToManualItemPriceRulesTable extends Migration
{
    public function up()
    {
        Schema::table('manual_item_price_rules', function (Blueprint $table) {
            $table->integer('category_id')->nullable()->after('price')->index();
            $table->integer('sub_category_id')->nullable()->after('category_id')->index();
        });
    }

    public function down()
    {
        Schema::table('manual_item_price_rules', function (Blueprint $table) {
            $table->dropColumn(['category_id', 'sub_category_id']);
        });
    }
}

