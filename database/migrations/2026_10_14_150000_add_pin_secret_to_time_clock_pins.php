<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Time clock PIN lookup for Jon, Sarah and Fatteen (Sarah 2026-10-14): each
// PIN is also kept encrypted with the app key so those three can see it on
// Time clock settings. PINs set before this are filled in on the person's
// next good punch. The code checks this column exists before using it.
class AddPinSecretToTimeClockPins extends Migration
{
    public function up()
    {
        if (Schema::hasTable('time_clock_pins') && !Schema::hasColumn('time_clock_pins', 'pin_secret')) {
            Schema::table('time_clock_pins', function (Blueprint $table) {
                $table->text('pin_secret')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('time_clock_pins') && Schema::hasColumn('time_clock_pins', 'pin_secret')) {
            Schema::table('time_clock_pins', function (Blueprint $table) {
                $table->dropColumn('pin_secret');
            });
        }
    }
}
