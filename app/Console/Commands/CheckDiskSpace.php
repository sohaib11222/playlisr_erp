<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Disk filled up to 96% (mostly uncompressed daily DB backups with no
 * retention cap) and caused intermittent site-wide connection timeouts on
 * 2026-09-02 — nobody noticed until cashiers started reporting the POS
 * "glitching". This alerts by email before it gets that far again, instead
 * of relying on someone spotting it.
 */
class CheckDiskSpace extends Command
{
    protected $signature = 'system:check-disk-space {--threshold=80 : Percent used that triggers an email alert}';

    protected $description = 'Alert by email when the app disk is getting full';

    public function handle()
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (!$total || $free === false) {
            $this->warn('Could not read disk space for ' . $path);
            return 0;
        }

        $used = $total - $free;
        $percentUsed = round(($used / $total) * 100, 1);
        $threshold = (int) $this->option('threshold');

        $this->info("Disk usage: {$percentUsed}% (threshold {$threshold}%)");

        if ($percentUsed < $threshold) {
            return 0;
        }

        // Don't spam — only email once per rolling 6 hours while still over
        // threshold, so a slow climb doesn't flood the inbox.
        $cacheKey = 'system.disk_space_alert.last_sent';
        if (Cache::has($cacheKey)) {
            $this->info('Already alerted recently — skipping duplicate email.');
            return 0;
        }

        $to = config('nivessa.system_alert_email');
        if (empty($to)) {
            Log::warning('Disk space over threshold but no system_alert_email configured', [
                'percent_used' => $percentUsed,
            ]);
            $this->warn('No nivessa.system_alert_email configured — logged only.');
            return 0;
        }

        $freeGb = round($free / 1024 / 1024 / 1024, 1);
        $totalGb = round($total / 1024 / 1024 / 1024, 1);
        $subject = "⚠ playlist.nivessa.com disk at {$percentUsed}% — {$freeGb}G free of {$totalGb}G";
        $body = "Disk usage on playlist.nivessa.com's server has reached {$percentUsed}% "
            . "({$freeGb}G free of {$totalGb}G total).\n\n"
            . "This crossed 96% on 2026-09-02 and caused intermittent site-wide connection "
            . "timeouts (cashiers saw the POS drop mid-shift). The main culprit was "
            . "unbounded local database_backups accumulation — check storage/app/database_backups "
            . "first if this recurs.\n\n"
            . "This alert won't repeat for 6 hours while still over threshold.";

        // MAIL_FROM_ADDRESS is empty in prod .env, which makes Swift Mailer
        // refuse to send ("Cannot send message without a sender address") —
        // found by smoke-testing this command before trusting it. Set an
        // explicit from address so this alert doesn't depend on that being
        // fixed elsewhere.
        // mail.username is the actual authenticated SMTP mailbox (same
        // fallback the app's own commented-out scheduler code used for
        // this), which is more likely to be accepted by the mail provider
        // than a made-up address.
        $fromAddress = config('mail.from.address') ?: config('mail.username') ?: 'noreply@playlist.nivessa.com';
        $fromName = config('mail.from.name') ?: 'Playlist ERP';

        // Set the dedup lock before sending, not after — a broken mail
        // server (SMTP auth, DNS, etc.) must not turn this into a scheduled
        // job that fails every 4 hours forever. The failure is still fully
        // visible in the log either way.
        Cache::put($cacheKey, true, now()->addHours(6));

        try {
            Mail::raw($body, function ($message) use ($to, $subject, $fromAddress, $fromName) {
                $message->to($to)->subject($subject)->from($fromAddress, $fromName);
            });
            Log::warning('Disk space alert email sent', ['percent_used' => $percentUsed, 'to' => $to]);
            $this->info("Alert email sent to {$to}.");
        } catch (\Throwable $e) {
            Log::error('Disk space alert email FAILED to send — check mail config', [
                'percent_used' => $percentUsed,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            $this->error('Disk usage is over threshold but the alert email failed to send: ' . $e->getMessage());
        }

        return 0;
    }
}
