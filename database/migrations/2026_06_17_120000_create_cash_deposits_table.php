<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log of cash deposits (safe drops) the cashier physically moves to the
 * safe. One row per drop, stamped with a per-store running deposit number
 * the cashier writes on the post-it (Deposit #N), plus who/when/how much
 * so the bundle can be traced back later. Independent of
 * cash_registers.safe_drop_amount (which still sums per-shift drops);
 * this table is the per-deposit audit trail.
 */
class CreateCashDepositsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('cash_deposits')) {
            Schema::create('cash_deposits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->unsignedInteger('cash_register_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('cashier_name')->nullable();
                // Per-store running number (#1, #2, ... never resets).
                $table->unsignedInteger('deposit_seq');
                $table->decimal('amount', 22, 4)->default(0);
                // 'open' = dropped at register open; 'close' = at close.
                $table->string('phase', 20)->nullable();
                $table->dateTime('deposited_at');
                $table->timestamps();

                // Enforce one number per store — the backstop against two
                // cashiers grabbing the same #N at the same location.
                $table->unique(['business_id', 'location_id', 'deposit_seq'], 'cd_biz_loc_seq_uniq');
                $table->index(['business_id', 'location_id'], 'cd_biz_loc_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('cash_deposits');
    }
}
