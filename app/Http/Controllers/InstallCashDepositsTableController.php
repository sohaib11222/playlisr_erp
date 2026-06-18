<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot installer for the cash_deposits table — the per-deposit log
 * behind the post-it deposit numbers (Deposit #N) shown when a cashier
 * drops cash to the safe at open/close.
 *
 * Same pattern as InstallSafeDropColumnController: `php artisan migrate
 * --force` runs every pending migration and one bad migration takes the
 * site down, so this page applies just THIS table with a single button.
 * Idempotent — safe to run more than once. The matching migration file
 * (2026_06_17_120000_create_cash_deposits_table.php) stays on disk for
 * fresh installs; run() also marks it as run so a later `migrate` won't
 * try to create the table a second time.
 */
class InstallCashDepositsTableController extends Controller
{
    public function index()
    {
        return view('admin.install_cash_deposits_table', [
            'has_cash_deposits' => Schema::hasTable('cash_deposits'),
        ]);
    }

    public function run(Request $request)
    {
        $log = [];

        try {
            if (!Schema::hasTable('cash_deposits')) {
                Schema::create('cash_deposits', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('business_id');
                    $table->unsignedInteger('location_id')->nullable();
                    $table->unsignedInteger('cash_register_id')->nullable();
                    $table->unsignedInteger('user_id')->nullable();
                    $table->string('cashier_name')->nullable();
                    $table->unsignedInteger('deposit_seq');
                    $table->decimal('amount', 22, 4)->default(0);
                    $table->string('phase', 20)->nullable();
                    $table->dateTime('deposited_at');
                    $table->timestamps();
                    $table->unique(['business_id', 'location_id', 'deposit_seq'], 'cd_biz_loc_seq_uniq');
                    $table->index(['business_id', 'location_id'], 'cd_biz_loc_idx');
                });
                $log[] = 'Created table: cash_deposits';
            } else {
                $log[] = 'Skipped: cash_deposits already exists';
            }

            // Mark the migration row as run so a future `php artisan migrate`
            // doesn't try to create the table a second time.
            $migrationName = '2026_06_17_120000_create_cash_deposits_table';
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
