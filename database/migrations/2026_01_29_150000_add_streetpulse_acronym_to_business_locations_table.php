<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddStreetpulseAcronymToBusinessLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('business_locations', 'streetpulse_acronym')) {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->string('streetpulse_acronym', 10)->after('location_id')->nullable()->comment('StreetPulse store acronym for this location (3-4 characters)');
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
        if (Schema::hasColumn('business_locations', 'streetpulse_acronym')) {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->dropColumn('streetpulse_acronym');
            });
        }
    }
}
