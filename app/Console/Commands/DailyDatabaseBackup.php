<?php

namespace App\Console\Commands;

use App\Business;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DailyDatabaseBackup extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:backup-daily {--business_id= : Run backup for a single business}';

    /**
     * @var string
     */
    protected $description = 'Create daily SQL database backup (local + optional Google Drive upload)';

    /**
     * @var DatabaseBackupService
     */
    protected $backupService;

    public function __construct(DatabaseBackupService $backupService)
    {
        parent::__construct();
        $this->backupService = $backupService;
    }

    /**
     * @return int
     */
    public function handle()
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        $query = Business::query()->select('id', 'name');
        $optBusinessId = $this->option('business_id');
        if (!empty($optBusinessId)) {
            $query->where('id', (int) $optBusinessId);
        }

        $businesses = $query->get();
        if ($businesses->isEmpty()) {
            $this->warn('No business found to back up.');
            return 0;
        }

        $ok = 0;
        $failed = 0;

        foreach ($businesses as $business) {
            $this->info("Backing up business #{$business->id} ({$business->name})...");
            $result = $this->backupService->createBackup((int) $business->id);

            if (!empty($result['success'])) {
                $ok++;
                $msg = "  saved: {$result['filename']} (" . number_format((int) ($result['size'] ?? 0)) . ' bytes)';
                if (!empty($result['uploaded_to_drive'])) {
                    $msg .= ' | uploaded to Google Drive';
                } elseif (!empty($result['upload_message'])) {
                    $msg .= ' | drive: ' . $result['upload_message'];
                }
                $this->line($msg);
            } else {
                $failed++;
                $err = (string) ($result['message'] ?? 'Unknown backup error');
                $this->error("  failed: {$err}");
                Log::warning('db:backup-daily failed for business', [
                    'business_id' => (int) $business->id,
                    'error' => $err,
                ]);
            }
        }

        $this->info("Daily DB backup complete. success={$ok}, failed={$failed}");
        Log::info('db:backup-daily summary', ['success' => $ok, 'failed' => $failed]);

        return $failed > 0 ? 1 : 0;
    }
}

