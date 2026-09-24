<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a task belong to a project (Asana-style). Null = standalone task.
// Deleting a project leaves its tasks in place, just unlinked.
class AddProjectIdToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'project_id')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->integer('project_id')->unsigned()->nullable()->after('business_id');
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
                $table->index('project_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'project_id')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
                $table->dropIndex(['project_id']);
                $table->dropColumn('project_id');
            });
        }
    }
}
