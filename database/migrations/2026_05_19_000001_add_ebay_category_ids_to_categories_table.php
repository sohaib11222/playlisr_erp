<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEbayCategoryIdsToCategoriesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('categories', 'ebay_category_ids')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('ebay_category_ids', 1000)->nullable()->after('description');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('categories', 'ebay_category_ids')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('ebay_category_ids');
            });
        }
    }
}
