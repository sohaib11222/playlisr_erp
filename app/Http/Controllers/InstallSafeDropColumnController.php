<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot installer for the cash-register safe-drop column:
 *   cash_registers.safe_drop_amount
 *
 * Sarah's policy: `php artisan migrate --force` runs every pending migration,
 * and any one of them breaking the site is a hard outage. This page lets her
 * apply just THIS column with a single button — independent of whatever else
 * is unmigrated. Same pattern as the older InstallCashierColumnsController.
 *
 * The Laravel migration file is still on disk
 * (database/migrations/2026_05_07_120000_add_safe_drop_amount_to_cash_registers.php)
 * for fresh installs / future `migrate` runs; the run() method here also
 * marks that migration as run in the `migrations` table so a later
 * `php artisan migrate` won't try to add the column a second time.
 */
class InstallSafeDropColumnController extends Controller
{
    public function index()
    {
        return view('admin.install_safe_drop_column', [
            'has_safe_drop_amount' => Schema::hasColumn('cash_registers', 'safe_drop_amount'),
        ]);
    }

    public function run(Request $request)
    {
        $log = [];

        try {
            if (!Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
                Schema::table('cash_registers', function ($table) {
                    $table->decimal('safe_drop_amount', 22, 4)->default(0);
                });
                $log[] = 'Added column: cash_registers.safe_drop_amount';
            } else {
                $log[] = 'Skipped: safe_drop_amount already exists';
            }

            // Mark the migration row as run so a future `php artisan migrate`
            // doesn't try to add the column a second time. Same convention
            // Laravel uses internally.
            $migrationName = '2026_05_07_120000_add_safe_drop_amount_to_cash_registers';
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
}
