<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two independent manager asks (2026-09-15):
// 1. Let whoever's assigned to a task add a note at any point while working
//    it, visible to everyone else on the same task (not a single overwritable
//    field — a running log, so multiple people/multiple notes over time don't
//    clobber each other). Same append-only shape as task_completion_logs
//    (2026_09_11_190000), scoped to one task instead of one business.
// 2. A "requires photo" checkbox at task creation. The photo itself is posted
//    directly to #taskphotos in Slack by the assignee (no upload/webhook
//    integration here) — this just gates completion on an acknowledgement,
//    same spirit as the existing started_by/completed_by attribution.
class AddNotesAndPhotoRequirementToTasks extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('task_notes')) {
            Schema::create('task_notes', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('weekly_task_id')->unsigned();
                $table->foreign('weekly_task_id')->references('id')->on('weekly_tasks')->onDelete('cascade');
                $table->integer('user_id')->unsigned();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->text('note');
                $table->timestamps();

                $table->index(['weekly_task_id', 'created_at']);
            });
        }

        if (Schema::hasTable('weekly_tasks')) {
            if (!Schema::hasColumn('weekly_tasks', 'requires_photo')) {
                Schema::table('weekly_tasks', function (Blueprint $table) {
                    $table->boolean('requires_photo')->default(false)->after('priority');
                });
            }
            if (!Schema::hasColumn('weekly_tasks', 'photo_confirmed_by')) {
                Schema::table('weekly_tasks', function (Blueprint $table) {
                    $table->integer('photo_confirmed_by')->unsigned()->nullable()->after('requires_photo');
                    $table->foreign('photo_confirmed_by')->references('id')->on('users')->onDelete('set null');
                    $table->timestamp('photo_confirmed_at')->nullable()->after('photo_confirmed_by');
                });
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_notes');
        if (Schema::hasTable('weekly_tasks')) {
            if (Schema::hasColumn('weekly_tasks', 'photo_confirmed_by')) {
                Schema::table('weekly_tasks', function (Blueprint $table) {
                    $table->dropForeign(['photo_confirmed_by']);
                    $table->dropColumn(['photo_confirmed_by', 'photo_confirmed_at']);
                });
            }
            if (Schema::hasColumn('weekly_tasks', 'requires_photo')) {
                Schema::table('weekly_tasks', function (Blueprint $table) {
                    $table->dropColumn('requires_photo');
                });
            }
        }
    }
}
