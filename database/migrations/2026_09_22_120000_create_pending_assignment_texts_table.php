<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task/project assignment texts no longer send immediately at creation —
// they queue here, and Console\Commands\SendPendingAssignmentTexts (run
// every 5 min) fires each one once the assignee's next Sling shift
// (sling_shifts.dtstart) is close, so the text lands a few minutes before
// they clock in rather than whenever the task happened to be created.
class CreatePendingAssignmentTextsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('pending_assignment_texts')) {
            Schema::create('pending_assignment_texts', function (Blueprint $table) {
                $table->increments('id');
                // Exactly one of these two is set — plain nullable FKs
                // rather than a polymorphic relation, matching how the
                // rest of this app links weekly_tasks/projects elsewhere
                // (e.g. task_completion_logs.weekly_task_id).
                $table->integer('weekly_task_id')->unsigned()->nullable();
                $table->integer('project_id')->unsigned()->nullable();
                $table->integer('user_id')->unsigned();
                $table->text('message');
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->foreign('weekly_task_id')->references('id')->on('weekly_tasks')->onDelete('cascade');
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['sent_at', 'user_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('pending_assignment_texts');
    }
}
