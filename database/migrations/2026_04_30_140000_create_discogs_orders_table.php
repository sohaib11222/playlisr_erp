<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Storage for Discogs marketplace orders pulled from Discogs's API.
 *
 * We deliberately keep these OUT of the `transactions` table — Discogs
 * orders carry messy half-mappable line items (release_id, not local
 * SKU), and shoving them into the POS sell flow risks the live
 * register. The Sales-by-Channel report unions transactions and this
 * table to surface Discogs revenue alongside in-store sales.
 *
 * `discogs_order_id` is unique so syncs are idempotent — re-running the
 * sync command updates existing rows in place rather than duplicating.
 */
class CreateDiscogsOrdersTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('discogs_orders')) {
            return;
        }

        Schema::create('discogs_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('discogs_order_id', 64);
            $table->dateTime('order_date');
            // Discogs order statuses are free-form strings (e.g. "New Order",
            // "Shipped", "Cancelled"). We don't enum them — Discogs may add
            // new statuses and we'd rather not block on a migration.
            $table->string('status', 64)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->unsignedInteger('items_count')->default(0);
            // Buyer email if Discogs returns it — useful for matching to
            // existing customers later. Nullable; not all orders expose it.
            $table->string('buyer', 255)->nullable();
            // Full Discogs payload for debugging / future field extraction.
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'discogs_order_id'], 'discogs_orders_business_order_unique');
            $table->index(['business_id', 'order_date'], 'discogs_orders_business_date_idx');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('discogs_orders');
    }
}
