<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBuyAppraisalsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buy_appraisals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('purchase_transaction_id')->nullable();
            $table->unsignedInteger('created_by');

            $table->enum('seller_mode', ['quick_phone', 'contact'])->default('quick_phone');
            $table->string('seller_name')->nullable();
            $table->string('seller_phone', 50)->nullable();

            $table->enum('status', ['draft', 'accepted', 'rejected'])->default('draft');
            $table->enum('accepted_offer_type', ['cash', 'credit'])->nullable();

            $table->decimal('starting_offer_cash', 22, 4)->default(0);
            $table->decimal('starting_offer_credit', 22, 4)->default(0);
            $table->decimal('second_offer_cash', 22, 4)->default(0);
            $table->decimal('second_offer_credit', 22, 4)->default(0);
            $table->decimal('final_offer_cash', 22, 4)->default(0);
            $table->decimal('final_offer_credit', 22, 4)->default(0);
            $table->decimal('accepted_amount', 22, 4)->default(0);

            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('purchase_transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');

            $table->index(['business_id', 'status']);
            $table->index(['contact_id', 'seller_phone']);
        });

        Schema::create('buy_appraisal_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('buy_appraisal_id');
            $table->string('format_type');
            $table->string('category')->nullable();
            $table->string('title')->nullable();
            $table->decimal('quantity', 22, 4)->default(1);
            $table->decimal('base_price', 22, 4)->default(0);
            $table->string('grade')->nullable();
            $table->decimal('grade_multiplier', 8, 4)->default(1);
            $table->decimal('standard_multiplier', 8, 4)->default(1);
            $table->decimal('line_total_cash', 22, 4)->default(0);
            $table->decimal('line_total_credit', 22, 4)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('buy_appraisal_id')->references('id')->on('buy_appraisals')->onDelete('cascade');
            $table->index(['buy_appraisal_id', 'format_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buy_appraisal_items');
        Schema::dropIfExists('buy_appraisals');
    }
}
