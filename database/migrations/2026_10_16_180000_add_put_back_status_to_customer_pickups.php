<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Put Back on Shelf" status (Sarah, 2026-10-16): a hold the customer didn't
// want stays on the Ready list flagged for staff until it's reshelved.
class AddPutBackStatusToCustomerPickups extends Migration
{
    public function up()
    {
        if (Schema::hasTable('customer_pickups')) {
            DB::statement("ALTER TABLE customer_pickups MODIFY status ENUM('ready','picked_up','cancelled','put_back') NOT NULL DEFAULT 'ready'");
        }
    }

    public function down()
    {
        if (Schema::hasTable('customer_pickups')) {
            DB::statement("UPDATE customer_pickups SET status = 'cancelled' WHERE status = 'put_back'");
            DB::statement("ALTER TABLE customer_pickups MODIFY status ENUM('ready','picked_up','cancelled') NOT NULL DEFAULT 'ready'");
        }
    }
}
