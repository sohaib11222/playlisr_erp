<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryCheckTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inventory_check_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('location_id')->nullable()->index();
            $table->string('note_type', 32);
            $table->date('reference_date')->nullable();
            $table->text('body');
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_check_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->string('name');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->date('sale_start')->nullable();
            $table->date('sale_end')->nullable();
            $table->string('preset_key', 64)->nullable();
            $table->text('state_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventory_check_sessions');
        Schema::dropIfExists('inventory_check_notes');
    }
}
