<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddBinPositionToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Check if column already exists (from older migration)
        if (!Schema::hasColumn('products', 'bin_position')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('bin_position', 50)->nullable()->after('alert_quantity');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('bin_position');
        });
    }
}

