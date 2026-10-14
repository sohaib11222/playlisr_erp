<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Time clock late arrivals (Sarah 2026-10-13): clock-in is allowed from 15
// minutes before a Sling shift until it ends; more than 5 minutes after the
// start is saved here at punch time and flagged "Late" on Timecards. The
// code checks these columns exist before using them.
class AddLateToTimeClockPunches extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('time_clock_punches')) {
            return;
        }
        if (!Schema::hasColumn('time_clock_punches', 'late_min')) {
            Schema::table('time_clock_punches', function (Blueprint $table) {
                $table->unsignedSmallInteger('late_min')->nullable();
            });
        }
        if (!Schema::hasColumn('time_clock_punches', 'sched_start_at')) {
            Schema::table('time_clock_punches', function (Blueprint $table) {
                $table->dateTime('sched_start_at')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('time_clock_punches')) {
            Schema::table('time_clock_punches', function (Blueprint $table) {
                if (Schema::hasColumn('time_clock_punches', 'late_min')) {
                    $table->dropColumn('late_min');
                }
                if (Schema::hasColumn('time_clock_punches', 'sched_start_at')) {
                    $table->dropColumn('sched_start_at');
                }
            });
        }
    }
}
