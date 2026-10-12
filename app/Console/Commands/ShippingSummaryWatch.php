<?php

namespace App\Console\Commands;

use App\Services\ShippingShiftNotifier;
use App\Services\ShippingSummaryTiming;
use Illuminate\Console\Command;

/**
 * Posts the daily shipping summary 15 min after Nick's Sling shift ends
 * (or 9 AM on his days off). His End Shift posts it sooner. The website
 * posts once per day, so repeat calls are harmless; this also remembers
 * locally that today is done to stop calling.
 */
class ShippingSummaryWatch extends Command
{
    protected $signature = 'shipping:summary-watch {--dry-run}';
    protected $description = 'Post the shipping summary after Nick\'s shift';

    public function handle()
    {
        $tz = 'America/Los_Angeles';
        $now = \Carbon::now($tz);
        $today = $now->toDateString();
        $file = storage_path('app/shipping-shift/summary-posted.json');
        $state = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $postedToday = ($state['date'] ?? '') === $today;

        $shifts = null;
        try {
            if (\App\SlingShift::where('last_synced_at', '>=', \Carbon::now()->subDays(2))->exists()) {
                $dayStart = $now->copy()->startOfDay();
                $shifts = \App\SlingShift::where('user_name', 'like', 'Nick%')
                    ->where('event_type', \App\SlingShift::TYPE_SHIFT)
                    ->where('dtstart', '>=', $dayStart->copy()->setTimezone(config('app.timezone')))
                    ->where('dtstart', '<', $dayStart->copy()->addDay()->setTimezone(config('app.timezone')))
                    ->get(['dtstart', 'dtend'])
                    ->map(function ($s) { return [\Carbon::parse($s->dtstart)->timestamp, \Carbon::parse($s->dtend ?: $s->dtstart)->timestamp]; })
                    ->values()->all();
            }
        } catch (\Throwable $e) {
            $shifts = null;
        }

        $trigger = ShippingSummaryTiming::due(
            $now->timestamp,
            $shifts,
            $postedToday,
            \Carbon::parse($today . ' 09:00:00', $tz)->timestamp,
            \Carbon::parse($today . ' 09:30:00', $tz)->timestamp,
            $now->isSaturday()
        );
        if (!$trigger) {
            return 0;
        }
        $this->info("Posting shipping summary ({$trigger})");
        if ($this->option('dry-run')) {
            return 0;
        }
        $resp = ShippingShiftNotifier::postSummary($trigger);
        if (is_array($resp) && ($resp['success'] ?? false)) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            @file_put_contents($file, json_encode(['date' => $today, 'trigger' => $trigger, 'resp' => $resp['posted'] ?? null]));
        }
        return 0;
    }
}
