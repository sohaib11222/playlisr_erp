<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website "Get a Quote" (nivessa.com Sell Your Stuff, 2026-11-04): a seller
 * lists what they want to sell and gets a firm 7-day quote priced with the
 * same calculator as the in-store buy form. Saved at step 1 (name + phone) so
 * staff see abandoned quotes too; staff open it from /sell-quotes and turn it
 * into a buy-from-customer draft when the seller comes in.
 */
class CreateSellQuotesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('sell_quotes')) {
            return;
        }
        Schema::create('sell_quotes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->string('token', 40)->unique();
            $table->string('name', 120);
            $table->string('phone', 40)->index();
            $table->string('email', 160)->nullable();
            $table->unsignedInteger('location_id')->nullable();
            // What the seller picked on step 1 / what the items actually came to.
            $table->string('size_picked', 12)->nullable();
            $table->string('size_tier', 12)->nullable();
            // started -> quoted -> in_buy
            $table->string('status', 12)->default('started');
            $table->unsignedInteger('item_count')->default(0);
            $table->longText('input_json')->nullable();
            $table->longText('calculation_json')->nullable();
            $table->decimal('cash_total', 12, 2)->nullable();
            $table->decimal('credit_total', 12, 2)->nullable();
            $table->decimal('estimate_low_cash', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('buy_offer_id')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sell_quotes');
    }
}
