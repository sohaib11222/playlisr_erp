<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSlingShiftsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('sling_shifts')) {
            return;
        }

        Schema::create('sling_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('sling_shift_id', 64)->unique();
            $table->string('sling_user_id', 64)->nullable()->index();

            $table->string('user_email', 191)->nullable()->index();
            $table->string('user_name', 191)->nullable();

            // ERP join: resolved at sync time by lowercased-email match.
            // Nullable because Sling can have users not in the ERP roster
            // (or with a different email on file).
            $table->unsignedInteger('erp_user_id')->nullable()->index();

            $table->string('event_type', 32)->default('shift')->index();

            $table->string('location_name', 191)->nullable();
            $table->string('position_name', 191)->nullable();

            $table->dateTime('dtstart')->index();
            $table->dateTime('dtend')->nullable();

            $table->decimal('hours', 8, 2)->default(0);
            $table->boolean('published')->default(true);

            $table->longText('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['dtstart', 'dtend']);
            $table->index(['erp_user_id', 'dtstart']);

            $table->foreign('erp_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sling_shifts');
    }
}
