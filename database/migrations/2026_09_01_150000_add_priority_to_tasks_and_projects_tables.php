<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPriorityToTasksAndProjectsTables extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'priority')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->enum('priority', ['high', 'medium', 'low'])->default('medium')->after('store');
                $table->index('priority');
            });
        }

        if (Schema::hasTable('projects') && !Schema::hasColumn('projects', 'priority')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->enum('priority', ['high', 'medium', 'low'])->default('medium')->after('store');
                $table->index('priority');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'priority')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropIndex('weekly_tasks_priority_index');
                $table->dropColumn('priority');
            });
        }

        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'priority')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropIndex('projects_priority_index');
                $table->dropColumn('priority');
            });
        }
    }
}
