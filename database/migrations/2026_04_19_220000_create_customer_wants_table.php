<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCustomerWantsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('customer_wants')) {
            return;
        }
        Schema::create('customer_wants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->string('artist')->nullable();
            $table->string('title');
            $table->string('format')->nullable()->comment('LP, CD, Cassette, etc.');
            $table->string('phone')->nullable()->comment('denormalized for quick lookup when contact_id is null');
            $table->text('notes')->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('status', ['active', 'fulfilled', 'cancelled'])->default('active');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('fulfilled_by')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('fulfilled_note')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('contact_id');
            $table->index('location_id');
            $table->index('priority');
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_wants');
    }
}
