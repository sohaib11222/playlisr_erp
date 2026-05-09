<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot installer for the per-cashier reconciliation column:
 *   clover_reconciliations.employee_key (VARCHAR 64, nullable)
 *   + composite index cr_bdek (business_id, day, employee_key)
 *
 * Sarah's policy: `php artisan migrate --force` is high-risk because one
 * bad pending migration can take the site down — and the run-migrations
 * GitHub workflow has been timing out trying to SSH from runner IPs that
 * aren't on the server's firewall whitelist. This page applies just THIS
 * column with a single button, independent of whatever else is unmigrated.
 * Same pattern as InstallSafeDropColumnController.
 *
 * The Laravel migration file is still on disk
 * (database/migrations/2026_05_05_000000_add_employee_key_to_clover_reconciliations.php)
 * for fresh installs; the run() method here also marks that migration as
 * run in the `migrations` table so a later `php artisan migrate` won't
 * try to add the column a second time.
 *
 * Why this matters: without this column the per-cashier "Mark reconciled"
 * checkbox and the per-cashier notes textarea on
 * /reports/clover-eod-reconciliation 500 on save (Sarah 2026-05-08:
 * "Save failed — will retry on blur"). Adding the column makes both work.
 */
class InstallEmployeeKeyColumnController extends Controller
{
    public function index()
    {
        return view('admin.install_employee_key_column', [
            'has_employee_key' => Schema::hasColumn('clover_reconciliations', 'employee_key'),
            'has_index'        => $this->hasIndex('clover_reconciliations', 'cr_bdek'),
        ]);
    }

    public function run(Request $request)
    {
        $log = [];

        try {
            if (!Schema::hasTable('clover_reconciliations')) {
                return back()->with('status', [
                    'success' => 0,
                    'msg'     => 'Failed: clover_reconciliations table does not exist. Run the create-table migration first.',
                ]);
            }

            if (!Schema::hasColumn('clover_reconciliations', 'employee_key')) {
                Schema::table('clover_reconciliations', function ($table) {
                    $table->string('employee_key', 64)->nullable()->after('day');
                });
                $log[] = 'Added column: clover_reconciliations.employee_key';
            } else {
                $log[] = 'Skipped: employee_key already exists';
            }

            if (!$this->hasIndex('clover_reconciliations', 'cr_bdek')) {
                Schema::table('clover_reconciliations', function ($table) {
                    $table->index(['business_id', 'day', 'employee_key'], 'cr_bdek');
                });
                $log[] = 'Added index: cr_bdek';
            } else {
                $log[] = 'Skipped: index cr_bdek already exists';
            }

            // Mark the migration row as run so a future `php artisan migrate`
            // doesn't try to add the column a second time. Same convention
            // Laravel uses internally.
            $migrationName = '2026_05_05_000000_add_employee_key_to_clover_reconciliations';
            $exists = DB::table('migrations')->where('migration', $migrationName)->exists();
            if (!$exists) {
                $batch = (int) (DB::table('migrations')->max('batch') ?? 0) + 1;
                DB::table('migrations')->insert([
                    'migration' => $migrationName,
                    'batch'     => $batch,
                ]);
                $log[] = "Marked migration as run: {$migrationName} (batch {$batch})";
            } else {
                $log[] = 'Migration row already present';
            }
        } catch (\Throwable $e) {
            return back()->with('status', [
                'success' => 0,
                'msg'     => 'Failed: ' . $e->getMessage(),
            ]);
        }

        return back()->with('status', [
            'success' => 1,
            'msg'     => 'Done. ' . implode(' · ', $log),
        ]);
    }

    /**
     * Cross-driver index check. Schema::hasIndex doesn't exist on this
     * Laravel version, and Doctrine's listTableIndexes is a hard
     * dependency on doctrine/dbal which may not be installed in prod.
     * Fall back to a raw INFORMATION_SCHEMA query, which works on every
     * MySQL/MariaDB the ERP has ever shipped on.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND index_name = ?',
                [$table, $indexName]
            );
            return $row && (int) $row->c > 0;
        } catch (\Throwable $e) {
            // If the diagnostic query itself fails, assume the index isn't
            // there and let CREATE INDEX bubble up its own error if it
            // already exists. Better than a false "skipped" message.
            return false;
        }
    }
}
