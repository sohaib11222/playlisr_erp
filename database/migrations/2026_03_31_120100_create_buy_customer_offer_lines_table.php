<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBuyCustomerOfferLinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('buy_customer_offer_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('offer_id');
            $table->integer('line_order')->default(0);

            $table->string('item_type', 60);
            $table->string('title')->nullable();
            $table->string('genre')->nullable();
            $table->string('condition_grade')->nullable();

            $table->decimal('quantity', 22, 4)->default(1);
            $table->decimal('discogs_median_price', 22, 4)->nullable();
            $table->decimal('grade_multiplier', 22, 4)->nullable();
            $table->decimal('standard_multiplier', 22, 4)->nullable();
            $table->decimal('unit_rate', 22, 4)->nullable();

            $table->decimal('line_cash_total', 22, 4)->default(0);
            $table->decimal('line_credit_total', 22, 4)->default(0);
            $table->timestamps();

            $table->foreign('offer_id')->references('id')->on('buy_customer_offers')->onDelete('cascade');
            $table->index(['offer_id', 'line_order']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('buy_customer_offer_lines');
    }
}

