<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who a project is assigned to at creation — same pattern as
// task_assignees (2026_09_03_120000), populated by whoever creates/edits
// the project rather than self-join. Distinct from project_contributors
// (2026_08_30_150000), which is self-service "I'm helping with this" —
// assignees is "this is on you", contributors is "I pitched in".
class CreateProjectAssigneesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('project_assignees')) {
            Schema::create('project_assignees', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('project_id')->unsigned();
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
                $table->integer('user_id')->unsigned();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['project_id', 'user_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('project_assignees');
    }
}
