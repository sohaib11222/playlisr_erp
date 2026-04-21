<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCloverPaymentsTable extends Migration
{
    /**
     * clover_payments holds the raw pull from Clover's /v3/merchants/{mid}/payments
     * endpoint, one row per Clover payment. Kept verbatim so reconciliation can
     * compare Clover's record of a sale to the ERP's record of the same sale
     * without needing to hit the API again.
     *
     * Matching to an ERP transaction happens later, via transaction_payments
     * that share the same dollar amount + same employee on the same day. A
     * one-shot "unmatched Clover payments" report surfaces the diffs.
     */
    public function up()
    {
        Schema::create('clover_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();

            // Clover IDs — the unique constraint is what makes the sync idempotent.
            $table->string('clover_payment_id', 64)->unique();
            $table->string('clover_order_id', 64)->nullable()->index();
            $table->string('clover_employee_id', 64)->nullable()->index();

            // Money (Clover stores cents as integers; we keep both representations
            // — cents for exact diffing, decimal for display/joining).
            $table->unsignedInteger('amount_cents')->default(0);
            $table->unsignedInteger('tip_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->decimal('amount', 22, 4)->default(0);

            // Payment metadata
            $table->string('card_type', 40)->nullable();                 // VISA / MC / etc.
            $table->string('card_last4', 8)->nullable();                  // last 4 of PAN
            $table->string('tender_type', 40)->nullable();                // "com.clover.tender.credit_card" etc.
            $table->string('result', 32)->nullable();                     // APPROVED / FAILED / VOIDED / REFUNDED

            // Identity
            $table->string('employee_name')->nullable();                  // cached from Clover at sync time

            // Timing
            $table->timestamp('paid_at');                                 // UTC — from createdTime on Clover payment
            $table->date('paid_on');                                      // business-local date, for cheap indexing in reports

            // Audit / debug
            $table->longText('raw_payload')->nullable();                  // JSON blob of the full Clover response

            $table->timestamps();

            $table->index(['business_id', 'paid_on']);
            $table->index(['business_id', 'employee_name', 'paid_on']);

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clover_payments');
    }
}
