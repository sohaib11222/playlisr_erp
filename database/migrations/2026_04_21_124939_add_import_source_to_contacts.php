<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Same provenance pattern as the customer_wants migration — adds
 * import_source + import_external_id to contacts so rows brought in
 * from the legacy Nivessa Backend xlsx (Store Credit, future Distributor
 * sheets, etc.) are traceable and safely re-importable.
 */
class AddImportSourceToContacts extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('contacts')) return;
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'import_source')) {
                $table->string('import_source', 120)->nullable();
                $table->index('import_source');
            }
            if (!Schema::hasColumn('contacts', 'import_external_id')) {
                $table->string('import_external_id', 120)->nullable();
                $table->index(['import_source', 'import_external_id']);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('contacts')) return;
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'import_external_id')) {
                $table->dropIndex(['import_source', 'import_external_id']);
                $table->dropColumn('import_external_id');
            }
            if (Schema::hasColumn('contacts', 'import_source')) {
                $table->dropIndex(['import_source']);
                $table->dropColumn('import_source');
            }
        });
    }
}
