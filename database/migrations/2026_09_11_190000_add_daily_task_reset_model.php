<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employee feedback (Jon, Zak, and a third employee, all independently):
// the "repeat daily" design that generates a fresh row each day reads as
// duplicates — a daily task's name showing twice (finished yesterday, not
// started today) looks broken even though it isn't. Replacing it for daily
// tasks specifically: one persistent row whose status just resets to "not
// started" each day (see TaskController::resetRepeatingDailyTasks), instead
// of a new row. Weekly repeats are unaffected — they're a week apart, not
// what anyone flagged.
//
// last_reset_date tracks the last day a repeat_daily root was reset, so the
// lazy daily check (same pattern as the old rollover — runs on whoever
// hits /tasks or closes a register first each day) knows whether today's
// reset has already happened. task_completion_logs captures what a task's
// status was right before each reset, so a history report is still
// possible later even though the day-by-day rows are gone — nothing is
// silently lost, just moved out of the list itself.
class AddDailyTaskResetModel extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks') && !Schema::hasColumn('weekly_tasks', 'last_reset_date')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->date('last_reset_date')->nullable()->after('repeat_weekly');
            });
        }

        if (!Schema::hasTable('task_completion_logs')) {
            Schema::create('task_completion_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('weekly_task_id')->unsigned()->nullable();
                $table->foreign('weekly_task_id')->references('id')->on('weekly_tasks')->onDelete('set null');
                $table->integer('business_id')->unsigned();
                $table->string('title', 200);
                $table->string('store', 32)->nullable();
                $table->string('priority', 16)->nullable();
                $table->date('log_date');
                $table->enum('status', ['not_started', 'in_progress', 'complete']);
                $table->integer('started_by')->unsigned()->nullable();
                $table->integer('completed_by')->unsigned()->nullable();
                $table->timestamps();

                $table->index(['business_id', 'log_date']);
                $table->index(['weekly_task_id', 'log_date']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_completion_logs');
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'last_reset_date')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropColumn('last_reset_date');
            });
        }
    }
}
