<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customer / employee holds (Jon, 2026-10-23). A hold keeps the copy in the
// store's hold bin and off the website until a person clears it. ERP stock is
// untouched: the copy is still physically in the store.
class CreateProductHoldsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('product_holds')) {
            Schema::create('product_holds', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variation_id');
                $table->decimal('quantity', 22, 4)->default(1);
                $table->enum('held_for_type', ['customer', 'employee']);
                $table->unsignedInteger('contact_id')->nullable();
                $table->string('held_for_name');
                $table->unsignedInteger('held_for_user_id')->nullable();
                $table->date('hold_until');
                $table->text('notes')->nullable();
                $table->enum('status', ['active', 'released', 'sold'])->default('active');
                $table->unsignedInteger('created_by');
                $table->dateTime('released_at')->nullable();
                $table->unsignedInteger('released_by')->nullable();
                $table->string('release_note')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['variation_id', 'location_id', 'status']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_holds');
    }
}
