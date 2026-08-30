<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tasks & Projects sidebar section: general-purpose weekly tasks (start date,
// auto end date +7 days, started-by/completed-by attribution) and longer-running
// projects (title, description/why, status, multi-person contributor credit).
// Distinct from employee_tasks (2026_08_28_120000) which is the per-store floor
// chore board — this is a team-wide tracker, open to all employees.
class CreateTasksAndProjectsTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('weekly_tasks')) {
            Schema::create('weekly_tasks', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->date('start_date');
                $table->date('end_date');
                $table->enum('status', ['not_started', 'in_progress', 'complete'])->default('not_started');
                $table->integer('created_by')->unsigned();
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->integer('started_by')->unsigned()->nullable();
                $table->foreign('started_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('started_at')->nullable();
                $table->integer('completed_by')->unsigned()->nullable();
                $table->foreign('completed_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
            });
        }

        if (!Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->string('title', 200);
                // The "why" — description/motivation for the project.
                $table->text('description')->nullable();
                $table->enum('status', ['not_started', 'in_progress', 'complete'])->default('not_started');
                $table->integer('created_by')->unsigned();
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->integer('started_by')->unsigned()->nullable();
                $table->foreign('started_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('started_at')->nullable();
                $table->integer('completed_by')->unsigned()->nullable();
                $table->foreign('completed_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
            });
        }

        if (!Schema::hasTable('project_contributors')) {
            Schema::create('project_contributors', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('project_id')->unsigned();
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
                $table->integer('user_id')->unsigned();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'user_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('project_contributors');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('weekly_tasks');
    }
}
