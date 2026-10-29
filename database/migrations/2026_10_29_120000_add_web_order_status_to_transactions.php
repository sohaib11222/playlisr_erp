<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What happened to the website / Discogs order behind an ERP sale:
// refund_pending, refunded or cancelled (Sarah, 2026-10-29).
class AddWebOrderStatusToTransactions extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'web_order_status')) $table->string('web_order_status', 20)->nullable();
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'web_order_status')) $table->dropColumn('web_order_status');
        });
    }
}
