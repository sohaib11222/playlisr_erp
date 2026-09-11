<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSourcingTargetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sourcing_targets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id');
            $table->integer('category_id');
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->decimal('target_buy_price', 10, 2)->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sourcing_targets');
    }
}
