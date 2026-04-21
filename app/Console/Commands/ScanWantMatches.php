<?php

namespace App\Console\Commands;

use App\Contact;
use App\CustomerWant;
use App\Mail\CustomerWantMatched;
use App\Services\OpenPhoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ScanWantMatches extends Command
{
    /**
     * Scan recently-added products against every open customer_want for the
     * business and (in --commit mode) notify the customer that their record
     * is in.
     *
     * Intended cron cadence: once a day at 4 PM (after the afternoon pricing
     * push by the team). Runs over the last N days of product additions so
     * a brief outage doesn't miss anything — default 2 days.
     *
     * Idempotent: fulfilled wants are excluded, and we mark the want
     * 'fulfilled' with fulfilled_note pointing at the matched product so it
     * doesn't get re-notified on tomorrow's run.
     *
     * Usage:
     *   php artisan wants:scan-matches                  # dry-run, last 2 days
     *   php artisan wants:scan-matches --commit         # notify + mark fulfilled
     *   php artisan wants:scan-matches --days=7         # wider window
     *   php artisan wants:scan-matches --method=email   # force email only
     *   php artisan wants:scan-matches --method=sms     # force SMS only
     *   php artisan wants:scan-matches --method=both    # both channels
     */
    protected $signature = 'wants:scan-matches
                            {--commit : Actually send notifications + mark wants fulfilled (default is dry-run)}
                            {--days=2 : Look at products added in the last N days}
                            {--method= : Override notify channel (email|sms|both). Default: try SMS if we have phone, else email.}
                            {--limit=200 : Safety cap on how many wants to process in one run}';

    protected $description = 'Scan new products against open customer_wants and notify customers whose records just came in.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $days   = max(1, (int) $this->option('days'));
        $method = $this->option('method');
        $limit  = max(1, (int) $this->option('limit'));

        $since = now()->subDays($days);

        $this->info(sprintf('Scanning products added since %s (commit=%s)', $since->toDateTimeString(), $commit ? 'yes' : 'DRY-RUN'));

        $wants = CustomerWant::with(['contact', 'location'])
            ->where('status', 'active')
            ->whereNotNull('contact_id')
            ->limit($limit)
            ->get();

        $this->line(' open wants to check: ' . $wants->count());

        $matchedCount = 0;
        $notifiedCount = 0;
        $errorCount = 0;

        foreach ($wants as $want) {
            $product = $this->findRecentMatch($want, $since);
            if (!$product) continue;
            $matchedCount++;

            $contact = $want->contact;
            if (!$contact) continue;

            $chosenMethod = $method ?: $this->pickDefaultMethod($contact);
            $label = $this->labelFor($want);

            $this->line(sprintf('  MATCH  want#%d "%s" -> product#%d "%s" (notify: %s)',
                $want->id, $label, $product->id, $product->name, $chosenMethod));

            if (!$commit) continue;

            $results = $this->dispatch($chosenMethod, $want, $contact);
            foreach ($results as $ch => $r) {
                if ($r['ok']) {
                    $notifiedCount++;
                } else {
                    $errorCount++;
                    $this->warn("    {$ch} failed: " . $r['msg']);
                }
            }

            $want->status = 'fulfilled';
            $want->fulfilled_at = now();
            $want->fulfilled_by = null;   // automated
            $want->fulfilled_note = 'Auto-matched to product #' . $product->id . ' via wants:scan-matches';
            $want->save();
        }

        $this->info(sprintf("\nDone. matched=%d  notified=%d  errors=%d",
            $matchedCount, $notifiedCount, $errorCount));

        if (!$commit && $matchedCount > 0) {
            $this->warn('Dry-run. Re-run with --commit to actually notify + mark fulfilled.');
        }
        return 0;
    }

    /**
     * Find a product added in the recent window that matches this want's
     * artist + title. Matches on substring (LIKE) in both directions so
     * "Some Girls" finds a product named "Some Girls (1978)" and "Dark Side
     * of the Moon" finds "The Dark Side of the Moon, 1973 Pressing".
     */
    private function findRecentMatch(CustomerWant $want, $since)
    {
        $title = trim((string) $want->title);
        if ($title === '') return null;

        $q = DB::table('products as p')
            ->leftJoin('variation_location_details as vld', 'vld.product_id', '=', 'p.id')
            ->where('p.business_id', $want->business_id)
            ->where('p.created_at', '>=', $since)
            ->where('p.name', 'LIKE', '%' . $title . '%')
            ->select([
                'p.id', 'p.name', 'p.artist', 'p.sku',
                DB::raw('COALESCE(SUM(vld.qty_available), 0) as total_stock'),
            ])
            ->groupBy('p.id', 'p.name', 'p.artist', 'p.sku')
            ->havingRaw('total_stock > 0')
            ->limit(1);

        if (!empty($want->artist)) {
            $q->where('p.artist', 'LIKE', '%' . $want->artist . '%');
        }

        return $q->first();
    }

    private function pickDefaultMethod(Contact $contact): string
    {
        // Prefer SMS (higher read-rate) when we have a phone; fall back to
        // email. Customers only on email get email; only on phone get SMS.
        $hasPhone = !empty($contact->mobile ?: $contact->alternate_number);
        $hasEmail = !empty($contact->email);
        if ($hasPhone && $hasEmail) return 'sms';
        if ($hasPhone) return 'sms';
        if ($hasEmail) return 'email';
        return 'none';
    }

    private function labelFor(CustomerWant $w): string
    {
        $label = trim(trim((string) $w->artist) . ' — ' . trim((string) $w->title), ' —');
        if (!empty($w->format)) $label .= ' (' . $w->format . ')';
        return $label;
    }

    /** @return array  channel => ['ok' => bool, 'msg' => string] */
    private function dispatch(string $method, CustomerWant $want, Contact $contact): array
    {
        $results = [];
        $channels = [];
        if ($method === 'none') return $results;
        if (in_array($method, ['email', 'both'])) $channels[] = 'email';
        if (in_array($method, ['sms', 'both']))   $channels[] = 'sms';

        foreach ($channels as $ch) {
            if ($ch === 'email') {
                if (empty($contact->email)) { $results[$ch] = ['ok' => false, 'msg' => 'no email']; continue; }
                try {
                    Mail::to($contact->email)->send(new CustomerWantMatched($want, $contact));
                    $results[$ch] = ['ok' => true, 'msg' => 'emailed ' . $contact->email];
                } catch (\Throwable $e) {
                    Log::warning('wants:scan-matches email failed: ' . $e->getMessage());
                    $results[$ch] = ['ok' => false, 'msg' => $e->getMessage()];
                }
            } else {
                $phone = $contact->mobile ?: $contact->alternate_number;
                if (empty($phone)) { $results[$ch] = ['ok' => false, 'msg' => 'no phone']; continue; }
                $sms = app(OpenPhoneService::class);
                $first = trim((string) ($contact->first_name ?? ''));
                $hey = $first !== '' ? ('Hey ' . $first . ', ') : 'Hey, ';
                $label = $this->labelFor($want);
                $store = optional($want->location)->name ?: 'Nivessa';
                $msg = $hey . "Nivessa — we just got your {$label} in at {$store}. We'll hold it behind the counter.";
                $r = $sms->send($phone, $msg);
                $results[$ch] = ['ok' => (bool) $r['success'], 'msg' => $r['msg'] ?? ''];
            }
        }
        return $results;
    }
}
