<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who a weekly task is assigned to. Multi-person, same pattern as
// project_contributors (2026_08_30_150000) — but populated by whoever
// creates/edits the task rather than self-join.
class CreateTaskAssigneesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('task_assignees')) {
            Schema::create('task_assignees', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('task_id')->unsigned();
                $table->foreign('task_id')->references('id')->on('weekly_tasks')->onDelete('cascade');
                $table->integer('user_id')->unsigned();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['task_id', 'user_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('task_assignees');
    }
}
