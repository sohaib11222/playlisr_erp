<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReceivingPackagesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('receiving_packages')) {
            Schema::create('receiving_packages', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->integer('location_id')->unsigned();
                $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
                $table->enum('package_type', ['mail', 'box', 'bag', 'retail_delivery', 'listening_event', 'other']);
                // Free text so we don't keep expanding the enum per retailer (Walmart, Instacart, etc).
                $table->string('package_type_detail')->nullable();
                $table->string('order_number')->nullable();
                $table->string('invoice_number')->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['open', 'closed'])->default('open');
                $table->integer('received_by')->unsigned();
                $table->foreign('received_by')->references('id')->on('users')->onDelete('cascade');
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'location_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('receiving_packages');
    }
}
