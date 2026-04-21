<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        /*
        $env = config('app.env');
        $email = config('mail.username');
        
        if ($env === 'live') {
            //Scheduling backup, specify the time when the backup will get cleaned & time when it will run.
            $schedule->command('backup:run')->dailyAt('23:50');

            //Schedule to create recurring invoices
            $schedule->command('pos:generateSubscriptionInvoices')->dailyAt('23:30');
            $schedule->command('pos:updateRewardPoints')->dailyAt('23:45');

            $schedule->command('pos:autoSendPaymentReminder')->dailyAt('8:00');
        }

        if ($env === 'demo' && !empty($email)) {
            //IMPORTANT NOTE: This command will delete all business details and create dummy business, run only in demo server.
            $schedule->command('pos:dummyBusiness')
                    ->cron('0 3 * * *')
                    ->emailOutputTo($email);
        }
        */

        $schedule->command('stock:refresh-cache')
            ->dailyAt('00:15')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(180);

        // StreetPulse daily upload (runs at 2:00 AM to upload yesterday's data)
        $schedule->command('streetpulse:upload-daily')->dailyAt('02:00');

        // Clover → ERP payment sync. Runs every 30 min during business hours
        // to keep the Clover-vs-ERP reconciliation report near-live, then once
        // more overnight at 02:30 PST to pick up any late-night stragglers.
        // Uses the default --days=2 window so a brief outage doesn't leave
        // gaps — the next run re-fetches yesterday + today and upserts.
        $schedule->command('clover:sync-payments')
            ->cron('*/30 10-23 * * *')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(25);
        $schedule->command('clover:sync-payments --days=2')
            ->dailyAt('02:30')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(50);
    }

    /**
     * Register the Closure based commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
