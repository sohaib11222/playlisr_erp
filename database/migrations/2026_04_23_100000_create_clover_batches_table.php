<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCloverBatchesTable extends Migration
{
    public function up()
    {
        Schema::create('clover_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();

            $table->string('clover_batch_id', 64)->index();
            $table->date('batch_on')->index();
            $table->timestamp('batch_at')->nullable();

            $table->unsignedInteger('payment_count')->default(0);
            $table->bigInteger('amount_cents')->default(0);
            $table->decimal('amount', 22, 4)->default(0);

            // Clover doesn't always expose settled deposit amount in the payment payload.
            // Keep this separate so we can fill true deposit totals when available later.
            $table->bigInteger('deposit_cents')->nullable();
            $table->decimal('deposit_total', 22, 4)->nullable();

            $table->string('status', 32)->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'location_id', 'clover_batch_id', 'batch_on'], 'clover_batches_unique_scope');
            $table->index(['business_id', 'batch_on']);

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clover_batches');
    }
}

