<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Asana-style "no due date" for tasks (mostly project tasks). The row still
// keeps start/end dates for sorting, but nothing treats it as late or missed.
class AddNoDueDateToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'no_due_date')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->boolean('no_due_date')->default(false)->after('due_time');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'no_due_date')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropColumn('no_due_date');
            });
        }
    }
}
