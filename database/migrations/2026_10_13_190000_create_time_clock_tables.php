<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phone time clock (Sarah 2026-10-13). Clover's Shifts app is no longer
// supported, so staff clock in and out from their own phones at /clock.
//
// time_clock_punches       one row per shift; never hard-deleted (voided_at)
// time_clock_punch_edits   admin change history (reason required)
// time_clock_pins          4-digit PIN per person (hashed) + lockout counter
// time_clock_devices       remembered phones (hashed token), revocable
class CreateTimeClockTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('time_clock_punches')) {
            Schema::create('time_clock_punches', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('user_id')->index();
                $table->unsignedInteger('location_id')->nullable()->index();
                $table->unsignedInteger('device_id')->nullable();
                $table->dateTime('clock_in_at')->index();
                $table->dateTime('clock_out_at')->nullable();
                // How the store was confirmed: gps, ip, none (not verified),
                // or null for admin-entered / End Shift without a client.
                $table->string('in_verify', 10)->nullable();
                $table->string('out_verify', 10)->nullable();
                $table->string('clock_in_ip', 64)->nullable();
                $table->string('clock_out_ip', 64)->nullable();
                $table->decimal('in_lat', 10, 7)->nullable();
                $table->decimal('in_lng', 10, 7)->nullable();
                $table->unsignedInteger('in_accuracy_m')->nullable();
                $table->unsignedInteger('in_distance_m')->nullable();
                $table->decimal('out_lat', 10, 7)->nullable();
                $table->decimal('out_lng', 10, 7)->nullable();
                $table->unsignedInteger('out_accuracy_m')->nullable();
                $table->unsignedInteger('out_distance_m')->nullable();
                $table->string('in_geo_error', 40)->nullable();
                $table->string('out_geo_error', 40)->nullable();
                $table->string('in_user_agent', 255)->nullable();
                $table->string('out_user_agent', 255)->nullable();
                $table->string('clock_in_source', 30)->default('phone');
                $table->string('clock_out_source', 30)->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->boolean('edited')->default(false);
                $table->dateTime('voided_at')->nullable();
                $table->unsignedInteger('voided_by')->nullable();
                $table->string('void_reason', 500)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'clock_out_at']);
            });
        }

        if (!Schema::hasTable('time_clock_punch_edits')) {
            Schema::create('time_clock_punch_edits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('punch_id')->index();
                $table->unsignedInteger('edited_by')->nullable();
                $table->string('action', 20); // add, edit, void, restore
                $table->string('reason', 500);
                $table->text('before')->nullable(); // JSON
                $table->text('after')->nullable();  // JSON
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('time_clock_pins')) {
            Schema::create('time_clock_pins', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('user_id')->unique();
                $table->string('pin_hash', 255);
                $table->unsignedInteger('failed_attempts')->default(0);
                $table->dateTime('locked_until')->nullable();
                $table->unsignedInteger('set_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('time_clock_devices')) {
            Schema::create('time_clock_devices', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('business_id')->index();
                $table->unsignedInteger('user_id')->index();
                $table->string('token_hash', 64)->unique();
                $table->string('user_agent', 255)->nullable();
                $table->string('ip', 64)->nullable();
                $table->dateTime('last_used_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->unsignedInteger('revoked_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('time_clock_devices');
        Schema::dropIfExists('time_clock_pins');
        Schema::dropIfExists('time_clock_punch_edits');
        Schema::dropIfExists('time_clock_punches');
    }
}
