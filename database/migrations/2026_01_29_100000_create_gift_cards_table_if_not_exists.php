<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGiftCardsTableIfNotExists extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('gift_cards')) {
            Schema::create('gift_cards', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->string('card_number');
                $table->integer('contact_id')->unsigned()->nullable();
                $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
                $table->decimal('initial_value', 20, 2);
                $table->decimal('balance', 20, 2);
                $table->date('expiry_date')->nullable();
                $table->enum('status', ['active', 'expired', 'used', 'cancelled'])->default('active');
                $table->text('notes')->nullable();
                $table->integer('created_by')->unsigned();
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->timestamps();
                $table->softDeletes();

                // Unique constraint: card_number should be unique per business
                $table->unique(['business_id', 'card_number']);
                $table->index(['business_id', 'status']);
                $table->index(['card_number']);
                $table->index(['contact_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('gift_cards');
    }
}
