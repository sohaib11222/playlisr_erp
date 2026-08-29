<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDistributorAndPhotoToReceivingPackagesTable extends Migration
{
    public function up()
    {
        Schema::table('receiving_packages', function (Blueprint $table) {
            $table->string('distributor')->nullable()->after('package_type_detail');
            $table->string('photo')->nullable()->after('notes');
        });
    }

    public function down()
    {
        Schema::table('receiving_packages', function (Blueprint $table) {
            $table->dropColumn(['distributor', 'photo']);
        });
    }
}
