<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link columns used by the bidirectional Clover ↔ ERP sync.
 *
 *   products.clover_item_id        maps an ERP product to a Clover Inventory item
 *   transactions.clover_order_id   maps an ERP sale to a Clover Order
 *   *.clover_synced_at             last time we pushed/pulled this row (used as
 *                                  a dirty marker — if updated_at > clover_synced_at
 *                                  the row is queued for push next sync).
 *
 * (Customers already link via contacts.clover_customer_id — added in
 *  2026_01_22_020000_add_clover_customer_id_to_contacts_table.)
 */
class AddCloverLinkColumns extends Migration
{
    public function up()
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'clover_item_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('clover_item_id', 64)->nullable()->after('sku')->index();
                $table->timestamp('clover_synced_at')->nullable()->after('clover_item_id');
            });
        }

        if (Schema::hasTable('transactions') && !Schema::hasColumn('transactions', 'clover_order_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('clover_order_id', 64)->nullable()->index();
                $table->timestamp('clover_synced_at')->nullable();
            });
        }

        if (Schema::hasTable('contacts') && !Schema::hasColumn('contacts', 'clover_synced_at')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->timestamp('clover_synced_at')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'clover_item_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['clover_item_id', 'clover_synced_at']);
            });
        }
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'clover_order_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn(['clover_order_id', 'clover_synced_at']);
            });
        }
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'clover_synced_at')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('clover_synced_at');
            });
        }
    }
}
