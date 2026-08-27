<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReceivingItemsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('receiving_items')) {
            Schema::create('receiving_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('receiving_package_id')->unsigned();
                $table->foreign('receiving_package_id')->references('id')->on('receiving_packages')->onDelete('cascade');
                $table->integer('product_id')->unsigned()->nullable();
                $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
                $table->integer('variation_id')->unsigned()->nullable();
                $table->foreign('variation_id')->references('id')->on('variations')->onDelete('set null');
                // Snapshot of SKU/name at receipt time, so the row still reads
                // correctly even if not matched to a catalog product yet, or
                // the product is later renamed.
                $table->string('sku')->nullable();
                $table->string('product_name')->nullable();
                $table->decimal('quantity', 22, 4)->default(1);
                $table->decimal('cost_price', 22, 4)->nullable();
                $table->decimal('msrp', 22, 4)->nullable();
                // Staged sell price — only written to variations.default_sell_price
                // once marked priced, never live before that.
                $table->decimal('pending_sell_price', 22, 4)->nullable();
                $table->string('rack')->nullable();
                $table->enum('status', ['in_progress', 'priced'])->default('in_progress');
                $table->integer('priced_by')->unsigned()->nullable();
                $table->foreign('priced_by')->references('id')->on('users')->onDelete('set null');
                $table->timestamp('priced_at')->nullable();
                $table->timestamps();

                $table->index(['receiving_package_id', 'status']);
                $table->index(['product_id', 'variation_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('receiving_items');
    }
}
