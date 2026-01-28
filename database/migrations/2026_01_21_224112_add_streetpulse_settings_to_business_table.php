<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddStreetpulseSettingsToBusinessTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('business', function (Blueprint $table) {
            $table->string('streetpulse_acronym', 10)->after('api_settings')->nullable()->comment('StreetPulse store acronym (3-4 characters)');
            $table->date('streetpulse_last_upload_date')->after('streetpulse_acronym')->nullable()->comment('Last successful upload date to prevent duplicates');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn(['streetpulse_acronym', 'streetpulse_last_upload_date']);
        });
    }
}
