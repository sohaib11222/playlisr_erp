<?php

namespace App\Console\Commands;

use App\Business;
use App\Contact;
use App\Services\SpendRewardService;
use Illuminate\Console\Command;

/**
 * Sarah 2026-07-17: one-time catch-up for the cumulative store-credit reward.
 *
 * When we switched from per-sale to cumulative accrual, existing customers had
 * already spent money since the program start date but hadn't been credited for
 * crossing $100 marks (e.g. two $70 visits = $140 = one $5 bracket owed). This
 * grants each customer any brackets they've earned since the start date but not
 * yet been paid — using the exact same SpendRewardService the live checkout
 * uses, so it can't double-pay anyone who already earned under the old rule.
 *
 * DRY RUN BY DEFAULT. It prints the total that would be granted and who gets
 * it; nothing is written until you pass --commit.
 *
 *   php artisan rewards:backfill-spend                      # dry run
 *   php artisan rewards:backfill-spend --highlight=Sandoval # dry run, call out a name
 *   php artisan rewards:backfill-spend --commit             # actually grant
 */
class BackfillSpendRewards extends Command
{
    protected $signature = 'rewards:backfill-spend
                            {--business=1 : business_id}
                            {--commit : Actually grant credit (default: dry-run)}
                            {--highlight= : Substring of a customer name to call out in the summary}
                            {--limit=0 : Cap customers processed (0 = all)}';

    protected $description = 'Cumulative store-credit catch-up: grant each customer any reward brackets earned since the start date but not yet paid. Dry-run by default.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $highlight = trim((string) $this->option('highlight'));
        $limit = (int) $this->option('limit');

        $service = app(SpendRewardService::class);
        $cfg = $service->config(Business::find($businessId));
        if (!$cfg) {
            $this->error("Spend reward is disabled or misconfigured for business {$businessId}; nothing to do.");
            return 1;
        }

        $fmt = function ($n) {
            return rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
        };

        $this->line('Mode:      ' . ($commit ? 'COMMIT (granting credit)' : 'DRY RUN (no changes)'));
        $this->line('Business:  ' . $businessId);
        $this->line('Rate:      $' . $fmt($cfg['amount']) . ' per $' . $fmt($cfg['per']) . ' of qualifying pre-tax spend');
        $this->line('Start:     ' . ($cfg['start'] ?? 'ALL-TIME — no cutoff set!'));
        $this->line(str_repeat('-', 64));

        $query = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_default', 0)
            ->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $totalCredit = 0.0;
        $customersCredited = 0;
        $processed = 0;
        $rows = [];
        $highlightRows = [];

        $handle = function ($contacts) use (&$totalCredit, &$customersCredited, &$processed, &$rows, &$highlightRows, $service, $commit, $highlight) {
            foreach ($contacts as $contact) {
                $processed++;
                $res = $service->applyForContact($contact, null, !$commit);
                if ($res['granted'] > 0) {
                    $customersCredited++;
                    $totalCredit += $res['granted'];
                    $name = trim($contact->name ?: trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')));
                    $row = ['id' => $contact->id, 'name' => $name ?: '(no name)', 'cumulative' => $res['cumulative'], 'granted' => $res['granted']];
                    $rows[] = $row;
                    if ($highlight !== '' && stripos($row['name'], $highlight) !== false) {
                        $highlightRows[] = $row;
                    }
                }
            }
        };

        if ($limit > 0) {
            $handle($query->get());
        } else {
            $query->chunkById(200, $handle);
        }

        usort($rows, function ($a, $b) {
            return $b['granted'] <=> $a['granted'];
        });

        $line = function ($r) use ($fmt) {
            return sprintf('  #%-6d %-30s cumulative $%-10s →  +$%s', $r['id'], mb_strimwidth($r['name'], 0, 30), $fmt($r['cumulative']), $fmt($r['granted']));
        };

        $this->line('Customers processed:      ' . $processed);
        $this->line('Customers getting credit: ' . $customersCredited);
        $this->line('TOTAL credit ' . ($commit ? 'granted' : 'that WOULD be granted') . ': $' . number_format($totalCredit, 2));
        $this->line(str_repeat('-', 64));

        if ($highlight !== '') {
            $this->line("Matches for \"{$highlight}\":");
            if (empty($highlightRows)) {
                $this->line('  (none earned credit)');
            } else {
                foreach ($highlightRows as $r) {
                    $this->line($line($r));
                }
            }
            $this->line(str_repeat('-', 64));
        }

        $this->line('Top ' . min(25, count($rows)) . ' by credit:');
        foreach (array_slice($rows, 0, 25) as $r) {
            $this->line($line($r));
        }

        $this->line('');
        if ($commit) {
            $this->info('COMMIT complete — credit granted and logged.');
        } else {
            $this->info('DRY RUN complete — nothing was written. Re-run with --commit to apply.');
        }

        return 0;
    }
}
