<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBuyCustomerOffersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buy_customer_offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->unsigned();
            $table->integer('location_id')->unsigned()->nullable();
            $table->integer('created_by')->unsigned();
            $table->integer('contact_id')->unsigned()->nullable();

            $table->string('seller_name')->nullable();
            $table->string('seller_phone')->nullable();
            $table->enum('seller_mode', ['contact', 'phone'])->default('phone');

            $table->enum('status', ['draft', 'accepted', 'rejected'])->default('draft');
            $table->enum('payout_type', ['cash', 'store_credit'])->default('cash');

            $table->decimal('calculated_cash_total', 22, 4)->default(0);
            $table->decimal('calculated_credit_total', 22, 4)->default(0);
            $table->decimal('starting_offer_cash', 22, 4)->default(0);
            $table->decimal('starting_offer_credit', 22, 4)->default(0);
            $table->decimal('second_offer_cash', 22, 4)->default(0);
            $table->decimal('second_offer_credit', 22, 4)->default(0);
            $table->decimal('final_offer_cash', 22, 4)->default(0);
            $table->decimal('final_offer_credit', 22, 4)->default(0);

            $table->unsignedInteger('accepted_purchase_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->longText('calculation_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('accepted_purchase_id')->references('id')->on('transactions')->onDelete('set null');

            $table->index(['business_id', 'status']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buy_customer_offers');
    }
}

