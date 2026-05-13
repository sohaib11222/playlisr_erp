<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sarah 2026-05-13: lets the user manually pair a Clover charge with an
 * ERP transaction when the automatic matcher rejects them (amount diff
 * exceeds the ±$1.25 tolerance, or wrong-store, or whatever). The
 * matcher honors this column first, before doing auto-pairing — so a
 * manual link always wins. NULL means "no manual override, let auto
 * matcher decide".
 */
class AddManualErpLinkToCloverPayments extends Migration
{
    public function up()
    {
        Schema::table('clover_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('manual_erp_transaction_id')->nullable()->after('clover_order_id');
            $table->index('manual_erp_transaction_id', 'clover_payments_manual_erp_idx');
        });
    }

    public function down()
    {
        Schema::table('clover_payments', function (Blueprint $table) {
            $table->dropIndex('clover_payments_manual_erp_idx');
            $table->dropColumn('manual_erp_transaction_id');
        });
    }
}
