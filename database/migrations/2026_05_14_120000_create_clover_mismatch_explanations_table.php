<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier / admin notes attached to a Clover ↔ ERP reconciliation row.
 *
 * One row per saved note. Sarah staged the read side on the recent_feed
 * (class_exists guard on \App\CloverMismatchExplanation, plus the
 * $clover_explanations map keyed by "{discrepancy_type}:{tx_id}:{cp_id}");
 * this migration ships the storage side.
 *
 * source defaults to 'register_reconciliation' so notes left during the
 * daily register reconciliation flow can be filtered / chipped distinctly
 * from notes left ad-hoc on the POS feed.
 */
class CreateCloverMismatchExplanationsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('clover_mismatch_explanations')) return;

        Schema::create('clover_mismatch_explanations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');

            // Either side can be null — a Clover-only orphan has no tx_id,
            // an ERP-only sale has no clover_payment_id. Mismatch rows
            // have both. The read path keys on discrepancy_type so the
            // view can route the right note to the right chip.
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('clover_payment_id')->nullable();

            // 'no_erp' | 'mismatch' | 'no_clover' — matches the keys the
            // view already constructs in $clover_explanations.
            $table->string('discrepancy_type', 20);

            $table->text('reason');

            // 'register_reconciliation' (default) | 'pos_feed' | other.
            // Lets the UI render a chip distinguishing daily reconciliation
            // notes from in-the-moment cashier explanations.
            $table->string('source', 32)->default('register_reconciliation');

            $table->unsignedInteger('explained_by')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'transaction_id'], 'cme_btx');
            $table->index(['business_id', 'clover_payment_id'], 'cme_bcp');
            $table->index(['business_id', 'discrepancy_type'], 'cme_btype');
            $table->index('source', 'cme_source');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clover_mismatch_explanations');
    }
}
