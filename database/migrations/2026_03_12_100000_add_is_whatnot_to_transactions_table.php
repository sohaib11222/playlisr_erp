<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsWhatnotToTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('transactions', 'is_whatnot')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->boolean('is_whatnot')->default(0)->after('is_export');
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
        if (Schema::hasColumn('transactions', 'is_whatnot')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('is_whatnot');
            });
        }
    }
}
