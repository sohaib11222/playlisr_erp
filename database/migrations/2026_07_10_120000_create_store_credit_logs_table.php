<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured store-credit ledger. Until now the only record of "who gave
 * store credit to whom" was the free-text contacts.balance_notes column,
 * which stores just a first name and no link to a purchase form. This table
 * captures every credit event with a real user_id and (when the credit came
 * from an accepted buy-from-customer offer) the offer id, so credits given
 * WITHOUT a purchase form are queryable and attributable going forward.
 *
 * The /admin/store-credit-log report still parses balance_notes for full
 * historical coverage; this table makes future attribution reliable.
 */
class CreateStoreCreditLogsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('store_credit_logs')) {
            return;
        }

        Schema::create('store_credit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->unsigned();
            $table->integer('contact_id')->unsigned();
            $table->integer('user_id')->unsigned()->nullable(); // employee who issued it
            // manual_add  = green "Add Store Credit" button (no purchase form)
            // manual_adjust = signed adjustment (no purchase form)
            // buy_from_customer = accepted buy-from-customer payout (has form)
            // redeem = store credit SPENT on a sale (negative amount)
            $table->enum('source', ['manual_add', 'manual_adjust', 'buy_from_customer', 'redeem'])
                ->default('manual_add');
            $table->decimal('amount', 22, 4)->default(0);        // signed: +add / -subtract
            $table->decimal('balance_after', 22, 4)->default(0);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('buy_customer_offer_id')->nullable();
            $table->unsignedInteger('transaction_id')->nullable(); // the sell, for redemptions
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['user_id']);
            $table->index(['contact_id']);
            $table->index(['source']);
            $table->index(['transaction_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('store_credit_logs');
    }
}
