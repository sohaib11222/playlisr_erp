<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets a manager flag, when adding a task, that finishing it requires a
// photo of the work done. `photo` holds the uploaded filename (same
// uploads-disk convention as receiving_photos); TaskController::update
// blocks marking the task complete until it's set, and posts it to the
// #taskphotos Slack channel when uploaded.
class AddRequiresPhotoToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'requires_photo')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->boolean('requires_photo')->default(false)->after('priority');
                $table->string('photo')->nullable()->after('requires_photo');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'requires_photo')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropColumn(['requires_photo', 'photo']);
            });
        }
    }
}
