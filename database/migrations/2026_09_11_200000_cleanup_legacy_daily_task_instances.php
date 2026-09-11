<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The daily-reset-in-place migration (2026_09_11_190000) changed how daily
// repeats work going forward, but couldn't touch rows the OLD per-day-row
// system had already created — those are untouched by
// resetRepeatingDailyTasks (it only ever looks at repeat_of IS NULL roots),
// so they sit frozen forever next to the root they were cloned from. Same
// title, same date once the root resets onto today — a permanent duplicate
// that will never go away on its own. Confirmed live: "Check QUO",
// "Putting away new arrivals", "Put away listening station items",
// "Bathroom check", "Complete opening shift tasks", "Tidy up bin spaces"
// each had exactly this leftover pair.
//
// Cleanup: for every weekly_tasks row that is a *daily* instance
// (repeat_of not null, and its root has repeat_daily = true — weekly
// instances are untouched, they're still the live mechanism for weekly
// repeats), log it to task_completion_logs if it carries real state
// (anything other than not_started, so nothing informative is lost), then
// delete the row. One-time backfill; the reset-in-place path already logs
// correctly for everything going forward.
class CleanupLegacyDailyTaskInstances extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('weekly_tasks') || !Schema::hasTable('task_completion_logs')) {
            return;
        }

        $legacyInstances = DB::table('weekly_tasks as instances')
            ->join('weekly_tasks as roots', 'roots.id', '=', 'instances.repeat_of')
            ->where('roots.repeat_daily', true)
            ->select('instances.*')
            ->get();

        foreach ($legacyInstances as $instance) {
            if ($instance->status !== 'not_started') {
                DB::table('task_completion_logs')->insert([
                    'weekly_task_id' => $instance->repeat_of,
                    'business_id' => $instance->business_id,
                    'title' => $instance->title,
                    'store' => $instance->store,
                    'priority' => $instance->priority,
                    'log_date' => $instance->start_date,
                    'status' => $instance->status,
                    'started_by' => $instance->started_by,
                    'completed_by' => $instance->completed_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('task_assignees')->where('task_id', $instance->id)->delete();
            DB::table('weekly_tasks')->where('id', $instance->id)->delete();
        }
    }

    public function down()
    {
        // Not reversible — the point is removing stray rows; nothing to
        // restore them from beyond the completion log entries left behind.
    }
}
