<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLoyaltyFieldsToContactsTableIfNotExists extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('contacts', 'lifetime_purchases')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->decimal('lifetime_purchases', 20, 2)->default(0)->after('balance');
            });
        }
        
        if (!Schema::hasColumn('contacts', 'loyalty_points')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->integer('loyalty_points')->default(0)->after('lifetime_purchases');
            });
        }
        
        if (!Schema::hasColumn('contacts', 'loyalty_tier')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('loyalty_tier')->nullable()->after('loyalty_points');
            });
        }
        
        if (!Schema::hasColumn('contacts', 'last_purchase_date')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->date('last_purchase_date')->nullable()->after('loyalty_tier');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('contacts', 'lifetime_purchases')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('lifetime_purchases');
            });
        }
        
        if (Schema::hasColumn('contacts', 'loyalty_points')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('loyalty_points');
            });
        }
        
        if (Schema::hasColumn('contacts', 'loyalty_tier')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('loyalty_tier');
            });
        }
        
        if (Schema::hasColumn('contacts', 'last_purchase_date')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('last_purchase_date');
            });
        }
    }
}
