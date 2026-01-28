<?php

namespace App\Console\Commands;

use App\Business;
use App\Services\StreetpulseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UploadStreetpulseManual extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'streetpulse:upload-manual 
                            {--date= : Specific date to upload (YYYY-MM-DD format)}
                            {--start-date= : Start date for date range (YYYY-MM-DD format)}
                            {--end-date= : End date for date range (YYYY-MM-DD format)}
                            {--business-id= : Specific business ID to upload (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually upload StreetPulse sales data for a specific date or date range';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $date = $this->option('date');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $businessId = $this->option('business-id');

        // Validate date inputs
        if ($date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error('Invalid date format. Use YYYY-MM-DD format.');
                return 1;
            }
        }

        if ($startDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $this->error('Invalid start-date format. Use YYYY-MM-DD format.');
            return 1;
        }

        if ($endDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $this->error('Invalid end-date format. Use YYYY-MM-DD format.');
            return 1;
        }

        // Determine date range
        $dates = [];
        if ($date) {
            $dates = [$date];
        } elseif ($startDate && $endDate) {
            $current = strtotime($startDate);
            $end = strtotime($endDate);
            while ($current <= $end) {
                $dates[] = date('Y-m-d', $current);
                $current = strtotime('+1 day', $current);
            }
        } else {
            $this->error('Please provide either --date or both --start-date and --end-date options.');
            return 1;
        }

        // Get businesses to process
        if ($businessId) {
            $businesses = Business::where('id', $businessId)->get();
            if ($businesses->isEmpty()) {
                $this->error('Business ID ' . $businessId . ' not found.');
                return 1;
            }
        } else {
            $businesses = Business::all();
        }

        $this->info('Starting StreetPulse manual upload...');
        $this->info('Date(s) to upload: ' . implode(', ', $dates));
        $this->info('');

        $totalSuccess = 0;
        $totalFail = 0;

        foreach ($dates as $uploadDate) {
            $this->info('Processing date: ' . $uploadDate);

            foreach ($businesses as $business) {
                try {
                    $service = new StreetpulseService($business->id);

                    // Check if configured
                    if (!$service->isConfigured()) {
                        $this->warn('  Business ' . $business->name . ' (ID: ' . $business->id . ') is not configured. Skipping...');
                        continue;
                    }

                    $this->info('  Uploading for business: ' . $business->name . ' (ID: ' . $business->id . ')');

                    // Upload data
                    $result = $service->syncDailySales($uploadDate);

                    if ($result['success']) {
                        $this->info('  ✓ Successfully uploaded ' . ($result['record_count'] ?? 0) . ' records');
                        $totalSuccess++;
                    } else {
                        $this->error('  ✗ Failed: ' . $result['msg']);
                        $totalFail++;
                        Log::error('StreetPulse Manual Upload Failed', [
                            'business_id' => $business->id,
                            'business_name' => $business->name,
                            'date' => $uploadDate,
                            'error' => $result['msg']
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->error('  ✗ Exception: ' . $e->getMessage());
                    $totalFail++;
                    Log::error('StreetPulse Manual Upload Exception', [
                        'business_id' => $business->id,
                        'business_name' => $business->name,
                        'date' => $uploadDate,
                        'exception' => $e->getMessage()
                    ]);
                }
            }

            $this->info('');
        }

        $this->info('Upload Summary:');
        $this->info('  Successful: ' . $totalSuccess);
        $this->info('  Failed: ' . $totalFail);
        $this->info('  Total: ' . ($totalSuccess + $totalFail));

        return $totalFail > 0 ? 1 : 0;
    }
}
