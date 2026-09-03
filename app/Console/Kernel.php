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

        // Daily DB backup for each business. Saves locally; optionally uploads
        // to Google Drive when nivessa.backup_google_drive is configured.
        $schedule->command('db:backup-daily')
            ->dailyAt('03:20')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(180);

        // Disk filled to 96% on 2026-09-02 (unbounded backup accumulation)
        // and caused site-wide connection timeouts before anyone noticed.
        // Check a few times a day and email if it's climbing again.
        $schedule->command('system:check-disk-space')
            ->cron('0 */4 * * *')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(10);

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

        // Apple Music Top 100 refresh — daily at 09:00 PST. Public RSS
        // feed, no credentials, always safe to run. Feeds the same
        // chart_picks table with source=apple_music_top.
        $schedule->command('charts:import-apple-music')
            ->dailyAt('09:00')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(15);

        // Per-supplier price fetch (AMS / Secretly / Beggars / Redeye /
        // VP) — Mondays 06:00 PST so prices are fresh before Sarah does
        // the Wednesday ordering pass. Each fetcher skips itself if its
        // .env credentials aren't set, so this is safe to ship before
        // every supplier is wired up. Sarah 2026-05-21.
        $schedule->command('supplier-prices:fetch all --full')
            ->weeklyOn(1, '06:00')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(120);

        // "Backfill now" trigger — the ICA page drops a request flag file
        // (queueSupplierBackfill) so Sarah can force a full-catalog pull
        // without waiting for Monday and without SSH. This command picks it
        // up within a few minutes and runs the full pull with no web-request
        // timeout. runInBackground so a multi-minute pull never blocks
        // schedule:run; it no-ops instantly when no flag is present.
        $schedule->command('supplier-prices:backfill-if-requested')
            ->everyFiveMinutes()
            ->withoutOverlapping(120)
            ->runInBackground();

        // Channel Sales Sync → store nivessa.com web orders + space rentals
        // (nivessa:sync-web-sales) and Discogs marketplace orders
        // (nivessa:sync-discogs-sales) as ERP transactions. Daily 06:00 PST.
        // 14-day look-back re-walks recent orders; idempotent on
        // (import_source, import_external_id) so re-runs upsert without
        // duplicating. Same buttons live at /admin/channel-sales-sync for
        // manual/backfill runs. Sarah 2026-06-19.
        $schedule->command('nivessa:sync-web-sales --days=14 --commit')
            ->dailyAt('06:00')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(30);
        $schedule->command('nivessa:sync-discogs-sales --days=14 --commit')
            ->dailyAt('06:00')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(30);

        // Auto-apply the listening-party / double-staffed-floor commission split
        // so it's ready on the Commissions page each morning — paying stays a
        // manual "Mark paid". Runs after the sales syncs so the day's data is in.
        $schedule->command('commissions:apply-party-splits --days=10')
            ->dailyAt('06:30')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(30);

        // ABC-XYZ recalculation from ERP sales — replaces Sabina's manual
        // monthly analyzer-CSV upload at /admin/abc-import (Sarah 2026-08-19:
        // "fully replace sabina"). Runs 1st of the month at 07:00 PST, after
        // the daily web/Discogs sales syncs, so the just-completed month's
        // data is in before the window (Jan through last full month) locks it in.
        $schedule->command('abc:recalculate-from-sales')
            ->monthlyOn(1, '07:00')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(120);

        // ~58,800 products were eligible at launch (2026-08-19). Originally ran
        // 13 of every 15 min at ~54 req/min — that alone used ~87% of Discogs'
        // shared ~60 req/min account-wide budget nearly around the clock,
        // starving the website's own Discogs calls (order sync every 30s,
        // stock sync, image/artist backfill, the release-rematch sweep) of any
        // headroom and causing their constant 429s/stalls (2026-08-20). Cut to
        // 6 of every 15 min so this makes steady progress without crowding out
        // time-sensitive live-commerce syncs. Once caught up this exits fast
        // (nothing eligible) and just tops up newly Discogs-linked products as
        // they're added. Manual catch-up also available at
        // /admin/discogs-street-dates if you want to push it faster still.
        $schedule->command('discogs:backfill-street-dates --minutes=6 --commit')
            ->everyFifteenMinutes()
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(20);

        // QuickBooks → ERP expense sync. Runs every 30 min so Sabina's QB
        // edits land in the ERP expense report without a manual import. The
        // 14-day window is intentional — late posts and reconcile edits in
        // QB sometimes change rows after their date, so we re-sync the
        // recent past every tick and rely on ref_no idempotency. Once a day
        // at 03:15 PST we widen to 60 days as a safety net.
        $schedule->command('quickbooks:sync-expenses --days=14')
            ->cron('*/30 * * * *')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(25);
        $schedule->command('quickbooks:sync-expenses --days=60')
            ->dailyAt('03:15')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(60);

        // Clover → ERP payment sync. Sarah 2026-05-13: tightened from
        // every 30 min to every 5 min during business hours. 30 min was
        // creating a "missing in Clover" alarm on the recon page for any
        // charge that happened in the current half-hour window — Sarah
        // would look at a 9:57am Clover charge on the dashboard, see it
        // flagged as missing on our report, and waste time investigating
        // when really the sync just hadn't caught up. Every 5 min keeps
        // the lag tight enough that "missing" actually means missing.
        // Still uses the default --days=2 window so a brief outage
        // re-fetches yesterday + today and upserts.
        $schedule->command('clover:sync-payments')
            ->cron('*/5 10-23 * * *')
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

        // Sling → ERP shift sync. Pulls last week + next month of scheduled
        // shifts into sling_shifts so the ERP has its own roster of who
        // worked when, independent of Sling availability. Daily at 03:30
        // PST (after Clover overnight reconciliation has settled).
        $schedule->command('sling:sync-shifts')
            ->dailyAt('03:30')
            ->timezone('America/Los_Angeles')
            ->withoutOverlapping(60);

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
