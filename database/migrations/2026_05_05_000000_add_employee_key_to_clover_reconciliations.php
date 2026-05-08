<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-cashier reconciliation. Sarah needs to sign off "yes, I checked
 * Henry's drawer for 2026-05-05 — sales match Clover and the cash
 * variance is acceptable" rather than just "yes, the Pico store totals
 * for the day look right." NULL employee_key keeps the legacy per-
 * (location, day) rows working untouched.
 */
class AddEmployeeKeyToCloverReconciliations extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('clover_reconciliations')) return;
        if (Schema::hasColumn('clover_reconciliations', 'employee_key')) return;

        Schema::table('clover_reconciliations', function (Blueprint $table) {
            $table->string('employee_key', 64)->nullable()->after('day');
            $table->index(['business_id', 'day', 'employee_key'], 'cr_bdek');
        });
    }

    public function down()
    {
        if (!Schema::hasTable('clover_reconciliations')) return;
        if (!Schema::hasColumn('clover_reconciliations', 'employee_key')) return;

        Schema::table('clover_reconciliations', function (Blueprint $table) {
            $table->dropIndex('cr_bdek');
            $table->dropColumn('employee_key');
        });
    }
}
