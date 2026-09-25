<?php

namespace App\Console\Commands;

use App\Business;
use App\User;
use App\Utils\RegisterReconUtil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Posts yesterday's register reconciliation flags to #register-reconciliation.
 *
 *   php artisan register-recon:post              (yesterday, once per day)
 *   php artisan register-recon:post --date=2026-09-24 --force
 *   php artisan register-recon:post --dry        (print, don't post)
 */
class PostRegisterRecon extends Command
{
    protected $signature = 'register-recon:post
                            {--date= : Day to reconcile (YYYY-MM-DD, default yesterday LA)}
                            {--force : Post even if this day was already posted}
                            {--dry : Print the message instead of posting}';

    protected $description = 'Post the daily register reconciliation flags to Slack';

    public function handle()
    {
        $date = (string) $this->option('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = \Carbon\Carbon::now('America/Los_Angeles')->subDay()->format('Y-m-d');
        }

        $settings = RegisterReconUtil::settings();
        if (!$this->option('dry')) {
            if (RegisterReconUtil::webhook() === '') {
                $this->info('No webhook set - skipping.');
                return 0;
            }
            if (!$this->option('force') && !empty($settings['posted'][$date])) {
                $this->info("Already posted {$date}.");
                return 0;
            }
        }

        // recent-feed reads the logged-in user + session business, so run
        // as the business owner (an admin).
        $business = Business::first();
        $owner = $business ? User::find($business->owner_id) : null;
        if (!$owner) {
            $this->error('No business owner found.');
            return 1;
        }
        Auth::setUser($owner);
        $session = app('session')->driver();
        $session->put('user.business_id', $business->id);
        $session->put('user.id', $owner->id);

        $report = RegisterReconUtil::build((int) $business->id, $date, $session);
        $text = RegisterReconUtil::formatSlack($report);

        if ($this->option('dry')) {
            $this->line($text);
            return 0;
        }
        if (!RegisterReconUtil::postToSlack($text)) {
            $this->error('Slack post failed.');
            return 1;
        }
        $posted = RegisterReconUtil::settings()['posted'] ?? [];
        $posted[$date] = \Carbon\Carbon::now('America/Los_Angeles')->format('Y-m-d g:ia');
        RegisterReconUtil::saveSettings(['posted' => array_slice($posted, -60, null, true)]);
        $this->info("Posted {$date}: {$report['issue_count']} items.");
        return 0;
    }
}
