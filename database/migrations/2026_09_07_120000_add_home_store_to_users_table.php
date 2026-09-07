<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Explicit 'pico' / 'hollywood' assignment per employee, set by an admin at
// /admin/task-store-assignments. Location permissions (access_all_locations)
// can't be used to infer this: most Cashier-role accounts have "all
// locations" POS access, so that signal can't tell Pico staff from
// Hollywood staff. Null = not assigned yet, falls back to the old
// permission-based guess (see OpeningChecklistController::storesForUser()).
class AddHomeStoreToUsersTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'home_store')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('home_store', 20)->nullable()->after('allow_login');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'home_store')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('home_store');
            });
        }
    }
}
