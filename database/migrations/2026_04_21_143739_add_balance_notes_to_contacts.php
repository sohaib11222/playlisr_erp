<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds a contacts.balance_notes TEXT column so adjustStoreCredit() can
 * append an audit line (timestamp · who · delta · new balance · reason)
 * every time a customer's store-credit is changed. Without this, there's
 * no record of who adjusted a balance or why — Clyde's accidental credit
 * couldn't be undone with any history left behind. Nullable, reversible.
 */
class AddBalanceNotesToContacts extends Migration
{
    public function up()
    {
        if (Schema::hasTable('contacts') && !Schema::hasColumn('contacts', 'balance_notes')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->text('balance_notes')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'balance_notes')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('balance_notes');
            });
        }
    }
}
