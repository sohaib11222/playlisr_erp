<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Manager Checklist (Zakary at Pico, Luis at Hollywood): a fixed recurring
// list of manager duties (daily / weekly / monthly), so Sarah/Jon can see
// whether each manager kept up with it. The item list itself is NOT stored
// here — it's a plain PHP array in ManagerChecklistController (matching how
// this app keeps small fixed config, e.g. DailyChecklistController::GROUPS).
// This table only holds the completion records, one row per item actually
// checked off in a given period, so "did they do it this week vs last week"
// can be queried by period_key instead of only ever showing the current
// state (which a JSON sidecar can't cleanly give history for).
class CreateManagerChecklistCompletionsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('manager_checklist_completions')) {
            Schema::create('manager_checklist_completions', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                // The manager this completion belongs to (whose duty it is).
                $table->integer('user_id')->unsigned();
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                // Stable key from ManagerChecklistController::DAILY/WEEKLY/MONTHLY,
                // e.g. "sales_check", "team_1on1s", "shrink_loss".
                $table->string('item_key', 60);
                // Period this completion is for: "D:2026-08-28" (daily, that date),
                // "W:2026-08-24" (weekly, that Monday), "M:2026-08" (monthly).
                // A row only exists for a period+item that was actually checked off,
                // so presence = done, absence = skipped.
                $table->string('period_key', 20);
                $table->timestamp('completed_at')->nullable();
                // Normally same as user_id (managers only check off their own), but
                // kept separate so an admin correction is distinguishable later.
                $table->integer('completed_by_user_id')->unsigned()->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'item_key', 'period_key'], 'mgr_checklist_unique');
                $table->index(['business_id', 'period_key']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('manager_checklist_completions');
    }
}
