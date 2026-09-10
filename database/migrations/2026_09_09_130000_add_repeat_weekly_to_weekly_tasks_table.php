<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Same idea as repeat_daily (see 2026_09_09_120000), for weekly tasks: a
// root with repeat_weekly = true gets a fresh instance generated once 7+
// days have passed since its last instance, instead of a manager having to
// re-create it every week. See TaskController::rolloverRepeatingTasks.
class AddRepeatWeeklyToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'repeat_weekly')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->boolean('repeat_weekly')->default(false)->after('repeat_of');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'repeat_weekly')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropColumn('repeat_weekly');
            });
        }
    }
}
