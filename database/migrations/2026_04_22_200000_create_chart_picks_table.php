<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Unified store for Street Pulse / Universal chart rows parsed from the
 * weekly emails. A single import batch has a source + week_of, and each
 * row records one chart entry.
 */
class CreateChartPicksTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('chart_pick_imports')) {
            Schema::create('chart_pick_imports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->enum('source', ['street_pulse', 'universal_top']);
                $table->date('week_of');
                $table->unsignedInteger('imported_by');
                $table->unsignedInteger('row_count')->default(0);
                $table->text('raw_body')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'source', 'week_of']);
            });
        }

        if (!Schema::hasTable('chart_picks')) {
            Schema::create('chart_picks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('import_id');
                $table->unsignedInteger('business_id');
                $table->enum('source', ['street_pulse', 'universal_top']);
                $table->date('week_of');
                $table->unsignedInteger('chart_rank')->nullable();
                $table->string('artist')->nullable();
                $table->string('title')->nullable();
                $table->string('format')->nullable();
                $table->boolean('is_new_release')->default(false);
                $table->unsignedInteger('matched_variation_id')->nullable();
                $table->unsignedInteger('matched_product_id')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'source', 'week_of']);
                $table->index('matched_variation_id');
                $table->index(['artist']);
                $table->foreign('import_id')
                    ->references('id')->on('chart_pick_imports')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('chart_picks');
        Schema::dropIfExists('chart_pick_imports');
    }
}
