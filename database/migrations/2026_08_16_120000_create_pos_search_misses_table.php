<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePosSearchMissesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('pos_search_misses')) {
            return;
        }

        Schema::create('pos_search_misses', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('business_id')->index();
            $table->unsignedInteger('location_id')->nullable()->index();
            $table->unsignedInteger('user_id')->nullable()->index();

            // Stored normalized (trimmed, collapsed whitespace, lowercased) so
            // "Radiohead" and "radiohead " roll up together in the report.
            $table->string('term', 191)->index();

            // Numeric-only terms are barcode/SKU scans of something we don't
            // carry; everything else is a cashier typing an artist or title a
            // customer asked for. Very different signals, so keep them apart.
            $table->boolean('is_scan')->default(0);

            $table->timestamp('created_at')->nullable()->index();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'term']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_search_misses');
    }
}
