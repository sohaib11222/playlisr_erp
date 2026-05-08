<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSafeDropAmountToCashRegisters extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
            Schema::table('cash_registers', function (Blueprint $table) {
                $table->decimal('safe_drop_amount', 22, 4)->default(0);
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
            Schema::table('cash_registers', function (Blueprint $table) {
                $table->dropColumn('safe_drop_amount');
            });
        }
    }
}
