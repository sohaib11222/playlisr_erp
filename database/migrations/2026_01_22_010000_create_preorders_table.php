<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePreordersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('preorders')) {
            Schema::create('preorders', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->integer('contact_id')->unsigned();
                $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
                $table->integer('product_id')->unsigned()->nullable();
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                $table->integer('variation_id')->unsigned()->nullable();
                $table->foreign('variation_id')->references('id')->on('variations')->onDelete('set null');
                $table->decimal('quantity', 22, 4)->default(1);
                $table->enum('status', ['pending', 'fulfilled', 'cancelled'])->default('pending');
                $table->date('order_date');
                $table->date('expected_date')->nullable();
                $table->text('notes')->nullable();
                $table->integer('created_by')->unsigned();
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->timestamps();
                
                $table->index(['business_id', 'contact_id']);
                $table->index(['business_id', 'status']);
                $table->index(['product_id', 'variation_id']);
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
        Schema::dropIfExists('preorders');
    }
}
