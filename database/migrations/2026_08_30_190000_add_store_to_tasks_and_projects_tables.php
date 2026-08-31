<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 'pico' / 'hollywood', same convention as employee_tasks.store. Null means
// company-wide (both stores) — useful for projects that aren't store-specific.
class AddStoreToTasksAndProjectsTables extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'store')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->string('store', 20)->nullable()->after('business_id');
                $table->index(['business_id', 'store']);
            });
        }

        if (Schema::hasTable('projects') && !Schema::hasColumn('projects', 'store')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('store', 20)->nullable()->after('business_id');
                $table->index(['business_id', 'store']);
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'store')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropIndex(['business_id', 'store']);
                $table->dropColumn('store');
            });
        }

        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'store')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropIndex(['business_id', 'store']);
                $table->dropColumn('store');
            });
        }
    }
}
