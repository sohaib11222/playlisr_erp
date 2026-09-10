<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A daily task marked "repeat daily" auto-generates a fresh instance for
// today (lazily, the first time anyone hits /tasks or closes a register that
// day — see TaskController::rolloverRepeatingDailyTasks) instead of a
// manager having to re-create it, or push its date forward, every morning.
// repeat_of links a generated instance back to the original ("root") task
// it was cloned from, so each day keeps its own row/status/history — you
// can still see whether Monday's instance got done and by whom, same as any
// other daily task. Root tasks have repeat_of = null.
class AddRepeatDailyToWeeklyTasksTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('weekly_tasks')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                if (!Schema::hasColumn('weekly_tasks', 'repeat_daily')) {
                    $table->boolean('repeat_daily')->default(false)->after('task_type');
                }
                if (!Schema::hasColumn('weekly_tasks', 'repeat_of')) {
                    $table->integer('repeat_of')->unsigned()->nullable()->after('repeat_daily');
                    // Deliberately NOT cascade: deleting a root task must never
                    // wipe the daily history it generated. TaskController@destroy
                    // blocks deleting a root that still has instances and points
                    // the user at unchecking "repeat daily" instead.
                    $table->foreign('repeat_of')->references('id')->on('weekly_tasks')->onDelete('restrict');
                    $table->index(['business_id', 'repeat_daily', 'repeat_of']);
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('weekly_tasks') && Schema::hasColumn('weekly_tasks', 'repeat_of')) {
            Schema::table('weekly_tasks', function (Blueprint $table) {
                $table->dropIndex(['business_id', 'repeat_daily', 'repeat_of']);
                $table->dropForeign(['repeat_of']);
                $table->dropColumn('repeat_of');
                $table->dropColumn('repeat_daily');
            });
        }
    }
}
