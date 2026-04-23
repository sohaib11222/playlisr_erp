<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store, per-day reconciliation audit — "Fatteen eyeballed PICO on
 * 2026-04-23, numbers match, ✓ signed off, no notes."
 *
 * One row per (business, location, day). location_id is nullable to
 * accommodate the "(no location)" bucket — test registers opened without
 * picking a store — so Fatteen can dismiss those rows too. notes holds
 * whatever context she wants to leave for the next person (variance
 * explanation, pending investigation, "register drawer jammed", etc.).
 */
class CreateCloverReconciliationsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('clover_reconciliations')) return;

        Schema::create('clover_reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            // nullable so the "(no location)" day-bucket can be marked too
            $table->unsignedInteger('location_id')->nullable();
            $table->date('day');

            // Audit stamp — null until Fatteen clicks ✓. Re-clicking
            // un-reconciles (sets these back to null).
            $table->unsignedInteger('reconciled_by_user_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'day']);
            // Can't use a true UNIQUE with a nullable location_id on MySQL
            // in a fully portable way — a generated string key would work
            // but complicates the lookup. Instead we rely on the app layer
            // to upsert on (business_id, location_id|=0, day) and add a
            // plain composite index to keep the find/update path cheap.
            $table->index(['business_id', 'location_id', 'day'], 'cr_blocdy');

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('reconciled_by_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clover_reconciliations');
    }
}
