<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Employee Tasks (Asana-style board): managers create ad-hoc daily tasks and
// recurring weekly routine tasks for their store, optionally assigned to one
// employee (blank = anyone on shift can take it). See StoreTaskController.
//
// Two tables, same split as manager_checklist_completions: employee_tasks
// holds the task DEFINITIONS (title, store, assignee, recurrence), which are
// now manager-editable data (not a fixed PHP list like ManagerChecklistController
// — the whole point is managers create these themselves). employee_task_completions
// holds one row per instance actually checked off, keyed by period_key, so
// a weekly routine task's history isn't lost when the week rolls over.
class CreateEmployeeTasksTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('employee_tasks')) {
            Schema::create('employee_tasks', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                // 'pico' / 'hollywood'. Null would mean "both stores" but every
                // row written by the controller always sets one explicitly.
                $table->string('store', 20);
                $table->string('title', 200);
                $table->text('notes')->nullable();
                // Null = unassigned, a shared to-do anyone on shift can take.
                $table->integer('assigned_to_user_id')->unsigned()->nullable();
                $table->foreign('assigned_to_user_id')->references('id')->on('users')->onDelete('set null');
                // Null for system-seeded starter tasks (see below).
                $table->integer('created_by_user_id')->unsigned()->nullable();
                $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
                // 'once' (one-off, due on due_date) or 'weekly' (recurring
                // routine task, due each week on `weekday`, ISO 1=Mon..7=Sun;
                // null weekday = due the Sunday that ends the week, matching
                // ManagerChecklistController's uniform-weekly convention).
                $table->string('recurrence', 10)->default('once');
                $table->unsignedTinyInteger('weekday')->nullable();
                $table->date('due_date')->nullable();
                // Archived tasks stay for history but drop off the board.
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'store', 'active']);
                $table->index(['assigned_to_user_id']);
            });
        }

        if (!Schema::hasTable('employee_task_completions')) {
            Schema::create('employee_task_completions', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('task_id')->unsigned();
                $table->foreign('task_id')->references('id')->on('employee_tasks')->onDelete('cascade');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                // "D:2026-08-28" (once, that due date) or "W:2026-08-24"
                // (weekly, the Monday of that week) — same encoding as
                // manager_checklist_completions.
                $table->string('period_key', 20);
                $table->integer('completed_by_user_id')->unsigned()->nullable();
                $table->foreign('completed_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['task_id', 'period_key'], 'emp_task_completion_unique');
                $table->index(['business_id', 'period_key']);
            });
        }

        // Starter weekly routine tasks, one set per store, unassigned so
        // anyone on shift can take them — from Sarah's list of what keeps
        // getting skipped on the opening/closing checklists. Managers can
        // edit, reassign, or delete any of these from the board; this just
        // means the list isn't empty on day one. Idempotent: only runs if
        // the table was just created (skips silently on a rerun) and only
        // if we can find the single business row.
        $businessId = DB::table('business')->value('id');
        if ($businessId && Schema::hasTable('employee_tasks') && DB::table('employee_tasks')->count() === 0) {
            $starters = [
                ['title' => 'Windows', 'weekday' => 1],
                ['title' => 'Organize sections - DVD, Vinyl (per section), shirts', 'weekday' => 2],
                ['title' => 'Clean floors', 'weekday' => 3],
                ['title' => 'Put new arrivals into genre sections', 'weekday' => 3],
                ['title' => 'Remove items from floors', 'weekday' => 4],
                ['title' => 'Garbage', 'weekday' => 4],
                ['title' => 'Inventory counts - non-CD/vinyl (toys, misc products)', 'weekday' => 5],
                ['title' => 'Miscellaneous shift tasks', 'weekday' => 6],
                ['title' => 'Supplies check', 'weekday' => 7],
            ];
            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach (['pico', 'hollywood'] as $store) {
                foreach ($starters as $s) {
                    $rows[] = [
                        'business_id' => $businessId,
                        'store'       => $store,
                        'title'       => $s['title'],
                        'notes'       => null,
                        'assigned_to_user_id' => null,
                        'created_by_user_id'  => null,
                        'recurrence'  => 'weekly',
                        'weekday'     => $s['weekday'],
                        'due_date'    => null,
                        'active'      => true,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
            }
            DB::table('employee_tasks')->insert($rows);
        }
    }

    public function down()
    {
        Schema::dropIfExists('employee_task_completions');
        Schema::dropIfExists('employee_tasks');
    }
}
