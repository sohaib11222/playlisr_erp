<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\Services\CloverService;
use App\Services\ShippingShiftNotifier;
use Illuminate\Console\Command;

/**
 * Posts "Nick clocked in" to #shipping when he clocks in on the Hollywood
 * Clover time clock (Sarah, 2026-10-12). Read-only against Clover; each
 * shift is posted once (ids kept in storage/app/shipping-shift/posted.json).
 */
class ShippingClockInWatch extends Command
{
    protected $signature = 'shipping:clock-in-watch {--business=1} {--dry-run}';
    protected $description = 'Post Nick\'s Clover clock-in to #shipping';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $loc = BusinessLocation::where('business_id', $businessId)->where('name', 'like', '%Hollywood%')->first();
        if (!$loc) {
            $this->info('No Hollywood location.');
            return 0;
        }
        $clover = (new CloverService($businessId, $loc->id));
        $sinceMs = (time() - 6 * 3600) * 1000;
        $shifts = $clover->getRecentShifts($sinceMs);

        $file = storage_path('app/shipping-shift/posted.json');
        $posted = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

        foreach ($shifts as $shift) {
            $id = (string) ($shift['id'] ?? '');
            $name = trim((string) ($shift['employee']['name'] ?? ''));
            $inMs = (int) ($shift['overrideInTime'] ?? $shift['inTime'] ?? 0);
            $out = $shift['outTime'] ?? $shift['overrideOutTime'] ?? null;
            if ($id === '' || $inMs <= 0 || isset($posted[$id])) continue;
            if (stripos($name, 'nick') !== 0) continue;
            if ($out) continue; // already clocked out
            if ($inMs < (time() - 45 * 60) * 1000) continue; // only fresh clock-ins
            $start = \Carbon::createFromTimestamp(intdiv($inMs, 1000));
            $this->info("Clock-in: {$name} {$start}");
            if ($this->option('dry-run')) continue;
            if (ShippingShiftNotifier::send('in', explode(' ', $name)[0] ?: 'Nick', 'Hollywood', $start)) {
                $posted[$id] = $start->toIso8601String();
            }
        }

        // keep the last 200 ids
        if (count($posted) > 200) {
            $posted = array_slice($posted, -200, null, true);
        }
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, json_encode($posted));
        return 0;
    }
}
