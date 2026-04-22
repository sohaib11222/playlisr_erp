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

        // Weekly pull of Street Pulse + UMe Universal chart emails from
        // sarah@nivessa.com → chart_picks table, feeding the Inventory
        // Check Assistant. Wednesdays 08:15 PST: Street Pulse lands Tue
        // night, UMe arrives Monday — running Wed morning catches both.
        $schedule->command('charts:import-from-email')
            ->weeklyOn(3, '08:15')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(30);

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

        // Full bidirectional Clover ↔ ERP sync (items, orders, customers
        // pulls + dirty-product/contact pushes). Every 15 min during business
        // hours + a --days=2 rewalk at 02:45 PST as the safety net. Webhooks
        // trigger intra-tick syncs via /webhooks/clover when configured.
        $schedule->command('clover:sync')
            ->cron('*/15 10-23 * * *')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(30);
        $schedule->command('clover:sync --days=2')
            ->dailyAt('02:45')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(90);

        // Clover → ERP rewards / lifetime-spend sync. Walks every contact
        // linked to a Clover customer and refreshes their loyalty_points,
        // lifetime_purchases, and last_purchase_date from Clover (read-only).
        // Runs at 03:00 PST after the overnight payment sync has settled.
        $schedule->command('clover:sync-customer-rewards')
            ->dailyAt('03:00')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(90);

        // Customer wants — scan recently-added products against open wants
        // and notify the customer when we find a match. Runs at 4 PM PST so
        // the team's morning pricing push gets a same-day check-in, and the
        // --days=2 window stays forgiving enough to catch anything that
        // slipped past yesterday's run.
        $schedule->command('wants:scan-matches --commit --days=2')
            ->dailyAt('16:00')
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
