<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sarah 2026-10-26: two modules.
//  - transfer_receipts: every Transfer Items move gets an acceptance ID the
//    other store scans to mark the box received (who sent, who received,
//    which items were missing).
//  - product_markdowns: one row per price cut from the Markdowns page, so the
//    original price and every step stay on record.
//  - products.listed_at: the original listed date (earliest of created_at and
//    the first purchase / opening stock). Markdown age counts from it and the
//    tag keeps printing it, even after a restock or a transfer.
class CreateTransferReceiptsAndMarkdowns extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('transfer_receipts')) {
            Schema::create('transfer_receipts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('transaction_id'); // the sell_transfer
                $table->string('code', 20)->unique();
                $table->unsignedInteger('from_location_id');
                $table->unsignedInteger('to_location_id');
                $table->decimal('item_count', 22, 4)->default(0);
                $table->enum('status', ['in_transit', 'received', 'received_missing'])->default('in_transit');
                $table->unsignedInteger('sent_by');
                $table->dateTime('sent_at');
                $table->unsignedInteger('received_by')->nullable();
                $table->dateTime('received_at')->nullable();
                $table->text('missing_json')->nullable();
                $table->string('receive_note')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index('transaction_id');
            });
        }

        if (!Schema::hasTable('product_markdowns')) {
            Schema::create('product_markdowns', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variation_id');
                $table->decimal('original_price', 22, 4);
                $table->decimal('old_price', 22, 4);
                $table->decimal('new_price', 22, 4);
                $table->decimal('cost', 22, 4)->default(0);
                $table->unsignedInteger('age_days');
                $table->unsignedInteger('pct_off'); // off the original price
                $table->boolean('hit_floor')->default(false);
                $table->date('listed_at')->nullable();
                $table->unsignedInteger('created_by');
                $table->timestamps();

                $table->index(['business_id', 'created_at']);
                $table->index('variation_id');
            });
        }

        if (!Schema::hasColumn('products', 'listed_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dateTime('listed_at')->nullable();
            });
        }

        // Backfill in chunks so the products table is never locked for long
        // (cashiers are ringing while migrations run).
        $max = (int) DB::table('products')->max('id');
        for ($start = 0; $start <= $max; $start += 5000) {
            $end = $start + 5000;
            DB::statement("
                UPDATE products p
                LEFT JOIN (
                    SELECT pl.product_id, MIN(t.transaction_date) AS first_buy
                    FROM purchase_lines pl
                    JOIN transactions t ON t.id = pl.transaction_id
                    WHERE t.type IN ('purchase', 'opening_stock')
                      AND pl.product_id > ? AND pl.product_id <= ?
                    GROUP BY pl.product_id
                ) x ON x.product_id = p.id
                SET p.listed_at = IF(x.first_buy IS NOT NULL AND x.first_buy < p.created_at, x.first_buy, p.created_at)
                WHERE p.id > ? AND p.id <= ? AND p.listed_at IS NULL
            ", [$start, $end, $start, $end]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('transfer_receipts');
        Schema::dropIfExists('product_markdowns');
        if (Schema::hasColumn('products', 'listed_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('listed_at');
            });
        }
    }
}
