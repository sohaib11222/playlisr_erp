<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAddedViaToProductsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('products', 'added_via')) {
            Schema::table('products', function (Blueprint $table) {
                // Keep this as lightweight as possible for large live tables.
                $table->string('added_via', 30)->nullable();
            });
        }

        $indexExists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'products')
            ->where('index_name', 'products_added_via_index')
            ->exists();

        if (!$indexExists) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('added_via', 'products_added_via_index');
            });
        }
    }

    public function down()
    {
        $indexExists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'products')
            ->where('index_name', 'products_added_via_index')
            ->exists();

        if ($indexExists) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_added_via_index');
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'added_via')) {
                $table->dropColumn('added_via');
            }
        });
    }
}

