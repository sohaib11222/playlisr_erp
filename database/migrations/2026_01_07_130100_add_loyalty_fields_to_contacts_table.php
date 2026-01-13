<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLoyaltyFieldsToContactsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->decimal('lifetime_purchases', 20, 2)->default(0)->after('balance');
            $table->integer('loyalty_points')->default(0)->after('lifetime_purchases');
            $table->string('loyalty_tier')->nullable()->after('loyalty_points');
            $table->date('last_purchase_date')->nullable()->after('loyalty_tier');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['lifetime_purchases', 'loyalty_points', 'loyalty_tier', 'last_purchase_date']);
        });
    }
}


