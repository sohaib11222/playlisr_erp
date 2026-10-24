<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records, CDs, tapes and movies rung up as "Item With No Barcode" (Sarah,
// 2026-10-23). One row per item with the cashier's reason, so /reports/barcode-misses
// shows barcodes that would not scan and items the ERP already had.
class CreateBarcodeScanMissesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('barcode_scan_misses')) {
            Schema::create('barcode_scan_misses', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('location_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('product_name', 191);
                $table->string('artist', 191)->nullable();
                $table->string('category_name', 191)->nullable();
                $table->decimal('price', 22, 4)->default(0);
                $table->string('reason', 191);
                $table->text('erp_matches')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['business_id', 'created_at']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('barcode_scan_misses');
    }
}
