<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommunicationsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('communications')) {
            Schema::create('communications', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->enum('channel', ['phone_1', 'phone_2', 'instagram', 'whatsapp', 'facebook', 'tiktok', 'other'])->default('other');
                $table->enum('topic', ['unhappy_customer', 'shipping', 'stock', 'events', 'careers', 'partnerships', 'general'])->default('general');
                $table->enum('status', ['pending', 'resolved'])->default('pending');
                $table->boolean('is_priority')->default(0);
                $table->string('customer_name')->nullable();
                $table->string('contact_info')->nullable();
                $table->text('message')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->integer('assigned_to')->unsigned()->nullable();
                $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
                $table->integer('resolved_by')->unsigned()->nullable();
                $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('resolved_at')->nullable();
                $table->integer('created_by')->unsigned();
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'topic']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('communications');
    }
}
