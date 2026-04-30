<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks who is the active "Front Desk" cashier at each location right now.
 *
 *   business_locations.current_cashier_id   user_id of whoever last picked
 *                                           "Cashier" on the choose-role page
 *                                           for this location. Every POS sale
 *                                           at this location attributes to
 *                                           this user, regardless of who is
 *                                           clicking buttons on the screen.
 *   business_locations.cashier_assigned_at  when current_cashier_id was set,
 *                                           for an audit trail of handoffs.
 *
 * Why: multiple staff log in throughout the day (managers, inventory,
 * shipping) and the previous attribution was "whoever happens to be logged
 * in", which produced sales attributed to the wrong person. With one active
 * cashier per location, attribution becomes deterministic and matches what
 * the schedule (Sling: Front Desk shifts) says.
 */
class AddCurrentCashierToBusinessLocations extends Migration
{
    public function up()
    {
        if (Schema::hasTable('business_locations') && !Schema::hasColumn('business_locations', 'current_cashier_id')) {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->unsignedInteger('current_cashier_id')->nullable()->index();
                $table->timestamp('cashier_assigned_at')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('business_locations') && Schema::hasColumn('business_locations', 'current_cashier_id')) {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->dropColumn(['current_cashier_id', 'cashier_assigned_at']);
            });
        }
    }
}
