<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds import-provenance columns to customer_wants so any row brought in
 * from an external spreadsheet (Nivessa Backend xlsx, etc.) can be traced
 * back to its source and safely de-duped on re-import.
 *
 *   import_source       — short tag like 'nivessa_backend_customer_asks'
 *   import_external_id  — row identifier from the source (e.g. sheet row #)
 *
 * Together they form a lookup key for idempotency: the import command
 * skips any row whose (import_source, import_external_id) pair already
 * exists.
 */
class AddImportSourceToCustomerWants extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('customer_wants')) {
            return;
        }
        Schema::table('customer_wants', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_wants', 'import_source')) {
                $table->string('import_source', 120)->nullable()->after('notes');
                $table->index('import_source');
            }
            if (!Schema::hasColumn('customer_wants', 'import_external_id')) {
                $table->string('import_external_id', 120)->nullable()->after('import_source');
                $table->index(['import_source', 'import_external_id']);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('customer_wants')) {
            return;
        }
        Schema::table('customer_wants', function (Blueprint $table) {
            if (Schema::hasColumn('customer_wants', 'import_external_id')) {
                $table->dropIndex(['import_source', 'import_external_id']);
                $table->dropColumn('import_external_id');
            }
            if (Schema::hasColumn('customer_wants', 'import_source')) {
                $table->dropIndex(['import_source']);
                $table->dropColumn('import_source');
            }
        });
    }
}
