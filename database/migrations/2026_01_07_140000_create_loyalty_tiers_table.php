<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLoyaltyTiersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('loyalty_tiers')) {
            Schema::create('loyalty_tiers', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id')->unsigned();
                $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
                $table->string('name'); // e.g., Bronze, Silver, Gold, Platinum
                $table->text('description')->nullable();
                $table->decimal('min_lifetime_purchases', 20, 2)->default(0); // Minimum lifetime purchases to reach this tier
                $table->integer('discount_percentage')->default(0); // Discount percentage for this tier
                $table->decimal('points_multiplier', 5, 2)->default(1.00); // Points multiplier (e.g., 1.5x points)
                $table->integer('sort_order')->default(0); // For ordering tiers
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'is_active']);
                $table->index(['min_lifetime_purchases']);
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
        Schema::dropIfExists('loyalty_tiers');
    }
}

