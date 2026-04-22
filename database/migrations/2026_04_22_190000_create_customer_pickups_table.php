<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerPickupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('customer_pickups')) {
            Schema::create('customer_pickups', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->integer('location_id')->unsigned()->nullable();
                $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
                $table->integer('contact_id')->unsigned();
                $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
                $table->integer('transaction_id')->unsigned()->nullable();
                $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
                $table->integer('product_id')->unsigned()->nullable();
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                $table->integer('variation_id')->unsigned()->nullable();
                $table->foreign('variation_id')->references('id')->on('variations')->onDelete('set null');
                $table->decimal('quantity', 22, 4)->default(1);
                $table->enum('status', ['ready', 'picked_up', 'cancelled'])->default('ready');
                $table->date('hold_date');
                $table->date('expected_pickup_date')->nullable();
                // Free-text time window e.g. "5-6pm", "after 3pm" — not a strict TIME
                // because the real-world use is "sometime in this window"
                $table->string('expected_pickup_time', 50)->nullable();
                $table->boolean('is_paid')->default(1);
                $table->timestamp('picked_up_at')->nullable();
                $table->string('picked_up_by_name')->nullable();
                $table->integer('picked_up_by_user_id')->unsigned()->nullable();
                $table->foreign('picked_up_by_user_id')->references('id')->on('users')->onDelete('set null');
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
        Schema::dropIfExists('customer_pickups');
    }
}
