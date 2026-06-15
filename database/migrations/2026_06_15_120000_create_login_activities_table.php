<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Records every login attempt (success or failure) with the client IP so the
 * "Outside-Store Logins" admin report can flag logins that don't come from the
 * IPs the stores normally use. Passwords are never stored — only the username
 * that was entered, the IP, and the device/user-agent.
 */
class CreateLoginActivitiesTable extends Migration
{
    public function up()
    {
        Schema::create('login_activities', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id')->nullable()->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            // Username typed at the login screen (kept even when no user matches,
            // e.g. a stranger guessing a cashier's login).
            $table->string('username')->nullable();
            // Snapshot of whether the matched user was an admin at login time, so
            // the report can build the "store" IP set from staff logins only
            // without re-joining roles later.
            $table->boolean('is_admin')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful')->default(1);
            $table->timestamps();

            $table->index(['business_id', 'ip_address']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('login_activities');
    }
}
