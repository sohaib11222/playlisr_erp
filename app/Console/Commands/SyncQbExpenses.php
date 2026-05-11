<?php

namespace App\Console\Commands;

use App\Business;
use App\QuickBooksConnection;
use App\Services\QuickBooksService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncQbExpenses extends Command
{
    protected $signature = 'quickbooks:sync-expenses {--days=14 : Look-back window} {--from= : Override start date YYYY-MM-DD} {--to= : Override end date YYYY-MM-DD}';

    protected $description = 'Pull QB Transaction List (expense rows) into ERP transactions';

    public function handle()
    {
        @set_time_limit(0);

        $businesses = QuickBooksConnection::query()->pluck('business_id')->unique();
        if ($businesses->isEmpty()) {
            $this->info('No QB-connected businesses to sync.');
            return 0;
        }

        $days = (int) $this->option('days');
        $from = $this->option('from') ?: Carbon::now()->subDays(max(1, $days))->format('Y-m-d');
        $to   = $this->option('to')   ?: Carbon::now()->format('Y-m-d');
        $this->info("Window: $from → $to");

        foreach ($businesses as $business_id) {
            $name = optional(Business::find($business_id))->name ?: "Business $business_id";
            try {
                $service = new QuickBooksService($business_id);
                $result = $service->syncExpensesFromQb($from, $to);
                $line = "[$name] " . ($result['msg'] ?? 'no result');
                if (!empty($result['success'])) {
                    $this->info($line);
                } else {
                    $this->warn($line);
                }
            } catch (\Throwable $e) {
                $this->error("[$name] " . $e->getMessage());
            }
        }
        return 0;
    }
}
