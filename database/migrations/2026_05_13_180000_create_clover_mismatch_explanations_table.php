<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sarah 2026-05-13: captures the cashier's reason when an ERP↔Clover
 * discrepancy surfaces on /pos/recent-feed. Three discrepancy_type
 * values mirror the existing feed classification:
 *   - mismatch   (ERP sale paired to Clover charge, amounts differ)
 *   - no_clover  (ERP sale, no Clover charge found)
 *   - no_erp     (Clover charge, no ERP sale found)
 *
 * Keyed by (transaction_id, clover_payment_id) — one side may be NULL
 * for the no_* cases. business_id scopes to the tenant.
 */
class CreateCloverMismatchExplanationsTable extends Migration
{
    public function up()
    {
        Schema::create('clover_mismatch_explanations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('discrepancy_type', 16);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('clover_payment_id')->nullable();
            $table->unsignedInteger('cashier_id')->nullable();
            $table->integer('erp_amount_cents')->nullable();
            $table->integer('clover_amount_cents')->nullable();
            $table->text('reason');
            $table->unsignedInteger('explained_by');
            $table->timestamps();

            $table->index(['business_id', 'transaction_id'], 'cme_business_tx_idx');
            $table->index(['business_id', 'clover_payment_id'], 'cme_business_cp_idx');
            $table->index(['business_id', 'cashier_id', 'created_at'], 'cme_business_cashier_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clover_mismatch_explanations');
    }
}
