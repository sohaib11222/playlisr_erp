<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pico's manager asked for daily tasks, separate from the weekly ones. Rather
// than a new table, weekly_tasks grows a task_type column — 'daily' tasks
// use the same start/status/attribution machinery, just with end_date ==
// start_date instead of start_date + 7 days. See TaskController.
class AddTaskTypeToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'task_type')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->enum('task_type', ['daily', 'weekly'])->default('weekly')->after('business_id');
                $table->index(['business_id', 'task_type']);
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'task_type')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropIndex(['business_id', 'task_type']);
                $table->dropColumn('task_type');
            });
        }
    }
}
