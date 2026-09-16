<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A task's assignee can post a progress note at any point while working
// it (not just description at create time) — visible to managers and
// anyone else viewing the task. Append-only log rather than a single
// mutable field, so a manager reviewing later sees the whole trail, not
// just whatever was typed last. See TaskController::addNote.
class CreateTaskNotesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('task_notes')) {
            Schema::create('task_notes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('weekly_task_id');
                $table->unsignedInteger('user_id');
                $table->text('note');
                $table->timestamps();

                $table->foreign('weekly_task_id')->references('id')->on('weekly_tasks')->onDelete('cascade');
                $table->index(['weekly_task_id', 'created_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_notes');
    }
}
