<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInStoreOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('in_store_orders')) {
            Schema::create('in_store_orders', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->integer('location_id')->unsigned()->nullable();
                $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
                // Free-text customer + item — this is a quick walk-in log, not
                // tied to the Contact/Product catalog like Customer Pickups is.
                $table->string('customer_name');
                $table->string('customer_phone', 40)->nullable();
                $table->string('customer_email')->nullable();
                $table->string('item_name');
                $table->decimal('price_paid', 22, 4)->default(0);
                $table->boolean('is_paid')->default(0);
                $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
                $table->timestamp('notified_at')->nullable();
                $table->string('notify_method', 20)->nullable();
                $table->text('notes')->nullable();
                $table->integer('created_by')->unsigned();
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->timestamps();

                $table->index(['business_id', 'status']);
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
        Schema::dropIfExists('in_store_orders');
    }
}
