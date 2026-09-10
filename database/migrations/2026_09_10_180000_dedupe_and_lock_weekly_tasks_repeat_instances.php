<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TaskController::rolloverRepeats had a check-then-create race: two
// overlapping requests (two /tasks page loads, or a POS register-close
// overlapping a page load) could each see "today's/this week's instance is
// missing" and both create one — no locking, no uniqueness constraint. That
// produced real duplicate rows in production (confirmed 23 duplicate
// (repeat_of, start_date) groups via the read-only diag workflow).
//
// This migration: (1) dedupes existing duplicates, keeping the row with the
// most progress (complete > in_progress > not_started, then lowest id so
// the result is deterministic) and deleting the rest — task_assignees rows
// cascade-delete with them; (2) adds a unique index on (repeat_of,
// start_date) so the database itself rejects a second instance for the same
// series+date, closing the race for good. MySQL treats NULL repeat_of
// values as distinct, so root tasks (repeat_of IS NULL) never collide with
// each other here.
class DedupeAndLockWeeklyTasksRepeatInstances extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('weekly_tasks')) {
            return;
        }

        $duplicateGroups = DB::table('weekly_tasks')
            ->select('repeat_of', 'start_date')
            ->whereNotNull('repeat_of')
            ->groupBy('repeat_of', 'start_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $rows = DB::table('weekly_tasks')
                ->where('repeat_of', $group->repeat_of)
                ->where('start_date', $group->start_date)
                ->orderByRaw("FIELD(status, 'complete', 'in_progress', 'not_started')")
                ->orderBy('id')
                ->get(['id']);

            $keepId = $rows->first()->id;
            $deleteIds = $rows->pluck('id')->reject(function ($id) use ($keepId) {
                return $id === $keepId;
            })->all();

            if (!empty($deleteIds)) {
                DB::table('weekly_tasks')->whereIn('id', $deleteIds)->delete();
            }
        }

        $indexExists = DB::select("SHOW INDEX FROM weekly_tasks WHERE Key_name = 'weekly_tasks_repeat_of_start_date_unique'");
        if (empty($indexExists)) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->unique(['repeat_of', 'start_date'], 'weekly_tasks_repeat_of_start_date_unique');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks')) {
            $indexExists = DB::select("SHOW INDEX FROM weekly_tasks WHERE Key_name = 'weekly_tasks_repeat_of_start_date_unique'");
            if (!empty($indexExists)) {
                Schema::table('weekly_tasks', function (Blueprint $table) {
                    $table->dropUnique('weekly_tasks_repeat_of_start_date_unique');
                });
            }
        }
        // Dedup itself is not reversible — deleted duplicate rows are gone.
    }
}
