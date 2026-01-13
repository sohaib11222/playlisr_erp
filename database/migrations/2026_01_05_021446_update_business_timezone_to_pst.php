<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateBusinessTimezoneToPst extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Update all existing businesses to PST timezone
        DB::table('business')->update(['time_zone' => 'America/Los_Angeles']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Revert back to Asia/Kolkata (original default)
        DB::table('business')->update(['time_zone' => 'Asia/Kolkata']);
    }
}


