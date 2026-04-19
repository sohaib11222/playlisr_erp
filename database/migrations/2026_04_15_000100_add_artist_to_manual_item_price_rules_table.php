<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddArtistToManualItemPriceRulesTable extends Migration
{
    public function up()
    {
        Schema::table('manual_item_price_rules', function (Blueprint $table) {
            $table->string('artist', 255)->nullable()->after('sub_category_id');
        });
    }

    public function down()
    {
        Schema::table('manual_item_price_rules', function (Blueprint $table) {
            $table->dropColumn('artist');
        });
    }
}
