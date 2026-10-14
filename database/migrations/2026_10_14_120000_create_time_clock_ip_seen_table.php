<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Time clock store networks learned from POS activity (Sarah 2026-10-13).
// One row per store, network, person and day: the IP a sale was rung or a
// register opened/closed from. TimeClockService promotes a network to a
// trusted store IP once 2 staff on 2 days (or 10 sales in a day) used it.
// The code checks this table exists before using it.
class CreateTimeClockIpSeenTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('time_clock_ip_seen')) {
            Schema::create('time_clock_ip_seen', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('location_id');
                $table->string('net', 64); // IPv4 address, or IPv6 /64
                $table->unsignedInteger('user_id');
                $table->date('day');
                $table->unsignedInteger('cnt')->default(1);
                $table->string('source', 10)->default('pos'); // pos, backfill
                $table->timestamps();
                $table->unique(['location_id', 'net', 'user_id', 'day'], 'tc_ip_seen_unique');
                $table->index(['net', 'day']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('time_clock_ip_seen');
    }
}
