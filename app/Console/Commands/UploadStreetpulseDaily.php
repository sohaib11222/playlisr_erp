<?php

namespace App\Console\Commands;

use App\Business;
use App\BusinessLocation;
use App\Services\StreetpulseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UploadStreetpulseDaily extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'streetpulse:upload-daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload daily sales data to StreetPulse FTP server';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting StreetPulse daily upload...');

        // Get yesterday's date (12:00am to 11:59pm window)
        $date = date('Y-m-d', strtotime('-1 day'));
        $this->info('Uploading data for date: ' . $date);

        // Get all businesses
        $businesses = Business::all();

        $successCount = 0;
        $failCount = 0;

        foreach ($businesses as $business) {
            try {
                $service = new StreetpulseService($business->id);

                // Check if configured at business or location level
                if (!$service->isConfigured()) {
                    $this->warn('Business ID ' . $business->id . ' (' . $business->name . ') is not configured for StreetPulse. Skipping...');
                    continue;
                }

                $this->info('Processing business: ' . $business->name . ' (ID: ' . $business->id . ')');

                // Get all locations with StreetPulse acronym configured
                $locations = BusinessLocation::where('business_id', $business->id)
                    ->whereNotNull('streetpulse_acronym')
                    ->where('streetpulse_acronym', '!=', '')
                    ->get();

                // If business has acronym but no locations, use business-level
                if ($locations->isEmpty() && !empty($business->streetpulse_acronym)) {
                    $result = $service->syncDailySales($date);
                    
                    if ($result['success']) {
                        $this->info('✓ Successfully uploaded ' . ($result['record_count'] ?? 0) . ' records for ' . $business->name);
                        $successCount++;
                    } else {
                        $this->error('✗ Failed to upload for ' . $business->name . ': ' . $result['msg']);
                        $failCount++;
                        Log::error('StreetPulse Daily Upload Failed', [
                            'business_id' => $business->id,
                            'business_name' => $business->name,
                            'date' => $date,
                            'error' => $result['msg']
                        ]);
                    }
                } else {
                    // Upload for each location
                    foreach ($locations as $location) {
                        $this->info('  Processing location: ' . $location->name . ' (ID: ' . $location->id . ', Acronym: ' . $location->streetpulse_acronym . ')');
                        
                        $result = $service->syncDailySales($date, $location->id);
                        
                        if ($result['success']) {
                            $this->info('  ✓ Successfully uploaded ' . ($result['record_count'] ?? 0) . ' records for ' . $location->name);
                            $successCount++;
                        } else {
                            $this->error('  ✗ Failed to upload for ' . $location->name . ': ' . $result['msg']);
                            $failCount++;
                            Log::error('StreetPulse Daily Upload Failed', [
                                'business_id' => $business->id,
                                'business_name' => $business->name,
                                'location_id' => $location->id,
                                'location_name' => $location->name,
                                'date' => $date,
                                'error' => $result['msg']
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error('✗ Exception for ' . $business->name . ': ' . $e->getMessage());
                $failCount++;
                Log::error('StreetPulse Daily Upload Exception', [
                    'business_id' => $business->id,
                    'business_name' => $business->name,
                    'date' => $date,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->info('');
        $this->info('Upload Summary:');
        $this->info('  Successful: ' . $successCount);
        $this->info('  Failed: ' . $failCount);
        $this->info('  Total: ' . ($successCount + $failCount));

        return $successCount > 0 ? 0 : 1;
    }
}
