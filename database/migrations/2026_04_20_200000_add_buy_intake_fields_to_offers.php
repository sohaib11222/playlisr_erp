<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBuyIntakeFieldsToOffers extends Migration
{
    /**
     * Phase 1 of the Buy Calculator rebuild (2026-04-20): collect full
     * seller intake + compliance + item-count breakdown on every offer.
     *
     * Design notes:
     *  - Seller name is split into first/last (old seller_name stays for
     *    backfill — not dropped).
     *  - payment_method is a free string so we can add "zelle_jon" /
     *    "venmo_jon" etc. without a schema change. Legacy payout_type
     *    column is kept alongside it.
     *  - Item-count fields use unsigned smallints — one collection rarely
     *    exceeds ~thousands of a given format, and saving a JSON blob
     *    would make later reporting painful.
     *  - compliance_confirmed_ownership / compliance_ack_final_sale are
     *    booleans tied to the two required checkboxes. Storing them as
     *    discrete columns (vs. a JSON blob) so a future "buys with
     *    unchecked compliance" audit query is a one-liner.
     */
    public function up()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            // Seller intake
            $table->string('seller_first_name')->nullable()->after('seller_name');
            $table->string('seller_last_name')->nullable()->after('seller_first_name');
            $table->string('seller_email')->nullable()->after('seller_phone');
            $table->string('seller_id_type', 32)->nullable()->after('seller_email');
            $table->string('seller_id_last4', 4)->nullable()->after('seller_id_type');

            // Item-count breakdown (integers — 1 row per offer)
            $table->unsignedSmallInteger('items_lp_count')->default(0)->after('notes');
            $table->unsignedSmallInteger('items_45_count')->default(0)->after('items_lp_count');
            $table->unsignedSmallInteger('items_cd_count')->default(0)->after('items_45_count');
            $table->unsignedSmallInteger('items_cassette_count')->default(0)->after('items_cd_count');
            $table->unsignedSmallInteger('items_dvd_count')->default(0)->after('items_cassette_count');
            $table->unsignedSmallInteger('items_bluray_count')->default(0)->after('items_dvd_count');
            $table->unsignedSmallInteger('items_other_count')->default(0)->after('items_bluray_count');

            // Condition breakdown — rough buckets the cashier eyeballs
            $table->unsignedSmallInteger('condition_mint_nm_count')->default(0)->after('items_other_count');
            $table->unsignedSmallInteger('condition_vg_plus_count')->default(0)->after('condition_mint_nm_count');
            $table->unsignedSmallInteger('condition_g_below_count')->default(0)->after('condition_vg_plus_count');

            // Transaction
            $table->decimal('final_price_paid', 22, 4)->nullable()->after('final_offer_credit');
            $table->string('payment_method', 32)->nullable()->after('payout_type');
            $table->text('override_reason')->nullable()->after('payment_method');

            // Compliance
            $table->boolean('compliance_confirmed_ownership')->default(0)->after('override_reason');
            $table->boolean('compliance_ack_final_sale')->default(0)->after('compliance_confirmed_ownership');

            $table->index(['seller_phone']);
            $table->index(['seller_email']);
        });
    }

    public function down()
    {
        Schema::table('buy_customer_offers', function (Blueprint $table) {
            $table->dropIndex(['seller_phone']);
            $table->dropIndex(['seller_email']);

            $table->dropColumn([
                'seller_first_name', 'seller_last_name', 'seller_email',
                'seller_id_type', 'seller_id_last4',
                'items_lp_count', 'items_45_count', 'items_cd_count',
                'items_cassette_count', 'items_dvd_count', 'items_bluray_count',
                'items_other_count',
                'condition_mint_nm_count', 'condition_vg_plus_count', 'condition_g_below_count',
                'final_price_paid', 'payment_method', 'override_reason',
                'compliance_confirmed_ownership', 'compliance_ack_final_sale',
            ]);
        });
    }
}
