<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExternalIdToCommunicationsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('communications', 'external_id')) {
            Schema::table('communications', function (Blueprint $table) {
                $table->string('external_id')->nullable()->after('message');
                $table->unique(['business_id', 'external_id'], 'communications_business_external_unique');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('communications', 'external_id')) {
            Schema::table('communications', function (Blueprint $table) {
                $table->dropUnique('communications_business_external_unique');
                $table->dropColumn('external_id');
            });
        }
    }
}
