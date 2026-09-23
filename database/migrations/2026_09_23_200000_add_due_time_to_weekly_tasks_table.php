<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional "due by" time on a task (e.g. by 2pm). Null = end of the due
// day. Used by My Tasks / Team Progress to tell past-due from on-time.
class AddDueTimeToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'due_time')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->time('due_time')->nullable()->after('end_date');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'due_time')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropColumn('due_time');
            });
        }
    }
}
