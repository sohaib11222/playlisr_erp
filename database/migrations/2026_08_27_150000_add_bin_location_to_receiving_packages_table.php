<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBinLocationToReceivingPackagesTable extends Migration
{
    public function up()
    {
        Schema::table('receiving_packages', function (Blueprint $table) {
            // Where the physical box itself currently sits (e.g. "Receiving
            // Bin 2", "Back room shelf B") — distinct from location_id, which
            // is which store it's at. Free text, mirrors the existing racks
            // convention, and stays editable while the box moves around.
            $table->string('bin_location')->nullable()->after('location_id');
        });
    }

    public function down()
    {
        Schema::table('receiving_packages', function (Blueprint $table) {
            $table->dropColumn('bin_location');
        });
    }
}
