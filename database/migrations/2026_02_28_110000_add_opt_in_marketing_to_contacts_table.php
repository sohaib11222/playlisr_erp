<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOptInMarketingToContactsTable extends Migration
{
    public function up()
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'opt_in_marketing')) {
                $table->boolean('opt_in_marketing')->default(0)->after('favorite_genres');
            }
        });
    }

    public function down()
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'opt_in_marketing')) {
                $table->dropColumn('opt_in_marketing');
            }
        });
    }
}

