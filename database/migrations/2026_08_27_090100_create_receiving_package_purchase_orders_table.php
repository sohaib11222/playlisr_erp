<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReceivingPackagePurchaseOrdersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('receiving_package_purchase_orders')) {
            Schema::create('receiving_package_purchase_orders', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('receiving_package_id')->unsigned();
                $table->foreign('receiving_package_id')->references('id')->on('receiving_packages')->onDelete('cascade');
                $table->integer('transaction_id')->unsigned();
                $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['receiving_package_id', 'transaction_id'], 'receiving_package_po_unique');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('receiving_package_purchase_orders');
    }
}
