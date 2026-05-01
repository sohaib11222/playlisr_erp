<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot installer for the choose-role feature's two columns:
 *   business_locations.current_cashier_id
 *   business_locations.cashier_assigned_at
 *
 * Sarah doesn't SSH and `php artisan migrate` would otherwise have to wait
 * for Sohaib. This page lets her install just these two columns from the
 * browser — same pattern as /admin/nivessa-backend-import. Reads-friendly
 * preview, then a single Apply button.
 *
 * The migration file (2026_04_29_120000_add_current_cashier_to_business_locations)
 * still lives in the repo for future fresh installs / `php artisan migrate`
 * runs; this controller just gives Sarah a way to apply it without SSH.
 */
class InstallCashierColumnsController extends Controller
{
    public function index()
    {
        return view('admin.install_cashier_columns', [
            'has_current_cashier_id'  => Schema::hasColumn('business_locations', 'current_cashier_id'),
            'has_cashier_assigned_at' => Schema::hasColumn('business_locations', 'cashier_assigned_at'),
        ]);
    }

    public function run(Request $request)
    {
        $log = [];

        try {
            if (!Schema::hasColumn('business_locations', 'current_cashier_id')) {
                Schema::table('business_locations', function ($table) {
                    $table->unsignedInteger('current_cashier_id')->nullable()->index();
                });
                $log[] = 'Added column: business_locations.current_cashier_id (with index)';
            } else {
                $log[] = 'Skipped: current_cashier_id already exists';
            }

            if (!Schema::hasColumn('business_locations', 'cashier_assigned_at')) {
                Schema::table('business_locations', function ($table) {
                    $table->timestamp('cashier_assigned_at')->nullable();
                });
                $log[] = 'Added column: business_locations.cashier_assigned_at';
            } else {
                $log[] = 'Skipped: cashier_assigned_at already exists';
            }

            // Mark the migration row as run so a future `php artisan migrate`
            // doesn't try to add the columns a second time. Same Laravel
            // convention `php artisan migrate` uses internally.
            $migrationName = '2026_04_29_120000_add_current_cashier_to_business_locations';
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
            'msg'     => "Done. " . implode(' · ', $log),
        ]);
    }
}
