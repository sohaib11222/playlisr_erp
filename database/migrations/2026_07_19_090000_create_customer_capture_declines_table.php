<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-capture declines.
 *
 * Sarah 2026-07-19: when a cashier finalizes an in-store register sale without
 * attaching a Nivessa account, the POS gate forces them to record WHY (the
 * customer declined, quick cash sale, couldn't find the account, etc.). One row
 * per declined sale — attached-account sales aren't logged here (those are
 * derivable from transactions.contact_id), so this table holds only the
 * exceptions and feeds the per-cashier customer-capture rate report.
 *
 * SellPosController::logCustomerCaptureDecline also CREATE-TABLE-IF-NOT-EXISTS
 * the same shape at runtime, because prod doesn't reliably run `artisan
 * migrate`. This migration keeps fresh installs / CI consistent; both are
 * idempotent.
 */
class CreateCustomerCaptureDeclinesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('customer_capture_declines')) {
            return;
        }

        Schema::create('customer_capture_declines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id');
            $table->integer('transaction_id');
            $table->integer('location_id')->nullable();
            $table->integer('created_by');
            $table->string('reason', 191);
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'created_at'], 'ccd_business_created_idx');
            $table->index(['business_id', 'created_by'], 'ccd_cashier_idx');
            $table->unique('transaction_id', 'ccd_transaction_uq');
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_capture_declines');
    }
}
