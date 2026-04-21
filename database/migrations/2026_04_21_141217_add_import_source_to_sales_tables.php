<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Import-provenance columns for historical POS sales imports.
 *
 * transactions:
 *   import_source        — e.g. 'nivessa_backend_sales_hw_sep_25'
 *   import_external_id   — source row identifier for dedup
 *
 * transaction_sell_lines:
 *   import_source         + import_external_id — same pattern
 *   legacy_artist         — raw artist text from the sheet
 *   legacy_title          — raw title text from the sheet
 *   legacy_format         — raw format / media type text
 *   legacy_genre          — raw genre text
 *   legacy_condition      — raw condition text (new / used / VG+ / etc.)
 *
 * The legacy_* text columns let us preserve the sheet's item detail on
 * sell lines even when we can't link them to a real product_id (we fall
 * back to a placeholder 'Legacy Historical Item' product in that case).
 *
 * All additions are nullable + indexed appropriately. Reversible.
 */
class AddImportSourceToSalesTables extends Migration
{
    public function up()
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('transactions', 'import_source')) {
                    $table->string('import_source', 120)->nullable();
                    $table->index('import_source');
                }
                if (!Schema::hasColumn('transactions', 'import_external_id')) {
                    $table->string('import_external_id', 120)->nullable();
                    $table->index(['import_source', 'import_external_id']);
                }
            });
        }

        if (Schema::hasTable('transaction_sell_lines')) {
            Schema::table('transaction_sell_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('transaction_sell_lines', 'import_source')) {
                    $table->string('import_source', 120)->nullable();
                    $table->index('import_source');
                }
                if (!Schema::hasColumn('transaction_sell_lines', 'import_external_id')) {
                    $table->string('import_external_id', 120)->nullable();
                }
                if (!Schema::hasColumn('transaction_sell_lines', 'legacy_artist')) {
                    $table->string('legacy_artist', 255)->nullable();
                }
                if (!Schema::hasColumn('transaction_sell_lines', 'legacy_title')) {
                    $table->string('legacy_title', 255)->nullable();
                }
                if (!Schema::hasColumn('transaction_sell_lines', 'legacy_format')) {
                    $table->string('legacy_format', 80)->nullable();
                }
                if (!Schema::hasColumn('transaction_sell_lines', 'legacy_genre')) {
                    $table->string('legacy_genre', 80)->nullable();
                }
                if (!Schema::hasColumn('transaction_sell_lines', 'legacy_condition')) {
                    $table->string('legacy_condition', 40)->nullable();
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('transaction_sell_lines')) {
            Schema::table('transaction_sell_lines', function (Blueprint $table) {
                foreach (['legacy_condition', 'legacy_genre', 'legacy_format', 'legacy_title', 'legacy_artist', 'import_external_id', 'import_source'] as $col) {
                    if (Schema::hasColumn('transaction_sell_lines', $col)) {
                        try { $table->dropIndex([$col]); } catch (\Exception $e) {}
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (Schema::hasColumn('transactions', 'import_external_id')) {
                    try { $table->dropIndex(['import_source', 'import_external_id']); } catch (\Exception $e) {}
                    $table->dropColumn('import_external_id');
                }
                if (Schema::hasColumn('transactions', 'import_source')) {
                    try { $table->dropIndex(['import_source']); } catch (\Exception $e) {}
                    $table->dropColumn('import_source');
                }
            });
        }
    }
}
