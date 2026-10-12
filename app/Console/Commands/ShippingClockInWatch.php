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
        $file = storage_path('app/shipping-shift/posted.json');
        $posted = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $tz = 'America/Los_Angeles';
        $today = \Carbon::now($tz)->toDateString();
        $foundToday = !empty($posted['found:' . $today]);

        // Only look at Clover around Nick's scheduled Sling shifts (Sarah,
        // 2026-10-12: no polling all day). Sling shifts come from the ERP's
        // synced sling_shifts table; if it hasn't synced in 2 days, treat
        // Sling as unreadable and use the fallback window.
        $starts = null;
        try {
            $fresh = \App\SlingShift::where('last_synced_at', '>=', \Carbon::now()->subDays(2))->exists();
            if ($fresh) {
                $dayStart = \Carbon::now($tz)->startOfDay();
                $starts = \App\SlingShift::where('user_name', 'like', 'Nick%')
                    ->where('event_type', \App\SlingShift::TYPE_SHIFT)
                    ->where('dtstart', '>=', $dayStart->copy()->setTimezone(config('app.timezone')))
                    ->where('dtstart', '<', $dayStart->copy()->addDay()->setTimezone(config('app.timezone')))
                    ->pluck('dtstart')
                    ->map(function ($d) { return \Carbon::parse($d)->timestamp; })
                    ->values()->all();
            }
        } catch (\Throwable $e) {
            $starts = null;
        }
        $fbFrom = \Carbon::parse($today . ' 05:15:00', $tz)->timestamp;
        $fbTo = \Carbon::parse($today . ' 06:30:00', $tz)->timestamp;
        $plan = \App\Services\ShippingClockInWindow::decide(time(), $starts, $foundToday, $fbFrom, $fbTo);
        if (!$plan['poll']) {
            $this->info('No Clover check: ' . $plan['why']);
            return 0;
        }
        $clover = (new CloverService($businessId, $loc->id));
        $shifts = $clover->getRecentShifts($plan['since'] * 1000);

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
                $posted['found:' . $today] = true; // stop checking for today
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
