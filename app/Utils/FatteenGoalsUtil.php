<?php

namespace App\Utils;

use App\Http\Controllers\DailyChecklistController;
use Illuminate\Support\Facades\Storage;

/**
 * Fatteen's monthly goals + bonus, shown on /daily-checklist.
 *
 * Every goal has a baseline (where it stood when this was set up, 10/14/26),
 * a monthly target, and a bonus. Numbers come from three places:
 *   auto  - computed from ERP data (checklist ticks, wrap-up notes, register
 *           recon, website cancels, buy offers)
 *   owner - a number Sarah/Jon enter for the month (Quo answer rate, backlog,
 *           Google review counts)
 *   log   - entries: wins Fatteen logs (owner approves) and chases the owner logs
 *
 * No migration: storage/app/fatteen_goals.json.
 */
class FatteenGoalsUtil
{
    const STORE_PATH = 'fatteen_goals.json';

    /** Bonus starts here; earlier months show as baseline. */
    const START_MONTH = '2026-11';

    /** Raise is revisited on this date, based on Nov + Dec results. */
    const REVIEW_DATE = '2027-01-14';

    /** A collection buy counts as "good" at this payout or more. */
    const GOOD_BUY_MIN = 250;

    /** A checklist day counts when at least this share of tasks is ticked. */
    const CHECKLIST_DAY_SHARE = 0.8;

    /** Gate: both of these need this many days a month. */
    const GATE_DAYS = 26;

    const WIN_TYPES = [
        'collection_buy' => 'Collection buy',
        'venue_booking'  => 'Venue booking',
        'collab'         => 'Partnership / collab',
        'problem'        => 'Problem solved before anyone asked',
    ];

    /**
     * The goals. cmp 'gte' = at least target, 'lte' = at most target.
     * Fixed bonus pays when the target is hit; per-unit bonus pays for each
     * counted item up to the cap.
     */
    public static function goals(): array
    {
        return [
            'phones_pct' => [
                'label' => 'Calls and texts answered the same day',
                'unit' => '%', 'cmp' => 'gte', 'target' => 95, 'bonus' => 5, 'source' => 'owner',
                'baseline' => '1% (6 of 504 calls, 4 weeks to 10/8). 96% on 10/12 to 10/13.',
                'how' => 'Quo analytics. Above the 80% already required for the $10/day phone pay.',
            ],
            'backlog' => [
                'label' => 'Customers still waiting from earlier days',
                'unit' => '', 'cmp' => 'lte', 'target' => 3, 'bonus' => 5, 'source' => 'owner',
                'baseline' => '47 on 10/11, 38 on 10/13 (Hollywood 30, Pico 8).',
                'how' => 'Highest "still waiting from earlier days" on the nightly phone recap that month.',
            ],
            'recon_days' => [
                'label' => 'Register days fully reconciled within 2 days',
                'unit' => '%', 'cmp' => 'gte', 'target' => 85, 'bonus' => 5, 'source' => 'auto',
                'baseline' => '1 of 11 days (10/3 to 10/13). Drawers up to $150 short with no reason posted.',
                'how' => 'Register recon re-checked 2 days later. If a cashier does not reply, call them or ask the manager.',
            ],
            'web_cancels' => [
                'label' => 'Website orders cancelled',
                'unit' => '', 'cmp' => 'lte', 'target' => 4, 'bonus' => 5, 'source' => 'auto',
                'baseline' => '2 in 2 days, both sold in store first (#100192 and #100139, 10/13 to 10/14).',
                'how' => 'Every website cancel this month. Work with Nick to pull items that sold in store before they get ordered online.',
            ],
            'good_buys' => [
                'label' => 'Good collection buys from his calls and texts',
                'unit' => '', 'cmp' => 'gte', 'target' => 3, 'bonus' => 5, 'source' => 'log',
                'baseline' => 'None tracked. A seller with 1,000+ records waited 9+ days for a reply (Pico, 10/5).',
                'how' => 'Log the seller\'s phone. Counts automatically when a buy of $' . self::GOOD_BUY_MIN . '+ is accepted in the ERP for that number.',
            ],
            'venue_bookings' => [
                'label' => 'Venue bookings he closes',
                'unit' => '', 'cmp' => 'gte', 'target' => 2, 'bonus' => 5, 'source' => 'log',
                'baseline' => '9 paid bookings Jun to Sep (about 2 a month), none made yet in October.',
                'how' => 'Log the booking. Counts once it shows paid on the events calendar and the owner approves.',
            ],
            'collabs' => [
                'label' => 'Good partnerships and collabs that happen',
                'unit' => '', 'cmp' => 'gte', 'target' => 1, 'bonus' => 5, 'source' => 'log',
                'baseline' => 'Leads like Shondaland, Victoria Dunn and a December employee party were handed to Sarah.',
                'how' => 'Brand partners, listening parties, private events. Counts when it is confirmed and the owner approves.',
            ],
            'reviews' => [
                'label' => 'New Google reviews, both stores',
                'unit' => '', 'cmp' => 'gte', 'target' => 10, 'bonus' => 5, 'source' => 'owner',
                'baseline' => 'Hollywood 233, Pico 214 (10/14). Hollywood was 228 on 7/11, so about 2 a month.',
                'how' => 'Text happy customers the review link after a good call. Owner enters both counts at month end.',
            ],
            'chases' => [
                'label' => 'Times Sarah or Jon had to ask for an update',
                'unit' => '', 'cmp' => 'lte', 'target' => 2, 'bonus' => 5, 'source' => 'log',
                'baseline' => 'Recon asked 10/8, 10/9, 10/10, 10/11. Shipping 8/31, 9/4. Refund 10/8.',
                'how' => 'Close the loop without being asked: what got done, or who was called and what is next.',
            ],
        ];
    }

    /* ---------- access ---------- */

    /** Sarah or Jon. Owner-only actions: approve wins, enter numbers, log chases. */
    public static function isOwner(): bool
    {
        $u = auth()->user();
        if (!$u) {
            return false;
        }
        $first = strtolower(trim((string) $u->first_name));
        $email = strtolower(trim((string) ($u->email ?? '')));
        if (\App\Http\Controllers\EmployeeChecklistController::isFatteen()) {
            return false;
        }
        return in_array($first, ['jon', 'jonathan', 'sarah'], true) || $email === 'sarah@nivessa.com';
    }

    /* ---------- storage ---------- */

    public static function read(): array
    {
        $d = [];
        if (Storage::exists(self::STORE_PATH)) {
            $d = json_decode(Storage::get(self::STORE_PATH), true);
        }
        $d = is_array($d) ? $d : [];
        $d += ['entries' => [], 'metrics' => [], 'recon' => []];
        return $d;
    }

    public static function write(array $d): void
    {
        Storage::put(self::STORE_PATH, json_encode($d, JSON_PRETTY_PRINT));
    }

    public static function digits(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    /* ---------- register recon (re-checked 2 days later) ---------- */

    /**
     * Records the recon issue count for days that are 2+ days old and not yet
     * recorded, a few per page load so the page stays fast. Each day is checked
     * once, so it reflects what was resolved within 2 days.
     */
    public static function fillRecon(int $business_id, $session, int $max = 3): void
    {
        $d = self::read();
        $tz = 'America/Los_Angeles';
        $last = \Carbon\Carbon::now($tz)->subDays(2)->startOfDay();
        $day = \Carbon\Carbon::parse('2026-10-01', $tz);
        $done = 0;
        $changed = false;
        while ($day->lte($last) && $done < $max) {
            $date = $day->format('Y-m-d');
            if (!array_key_exists($date, $d['recon'])) {
                try {
                    $report = RegisterReconUtil::build($business_id, $date, $session);
                    $d['recon'][$date] = (int) ($report['issue_count'] ?? 0);
                    $changed = true;
                } catch (\Throwable $e) {
                    \Log::warning('fatteen goals recon ' . $date . ': ' . $e->getMessage());
                }
                $done++;
            }
            $day->addDay();
        }
        if ($changed) {
            self::write($d);
        }
    }

    /* ---------- monthly numbers ---------- */

    private static function inMonth(string $date, string $month): bool
    {
        return substr($date, 0, 7) === $month;
    }

    /** Accepted buy offers whose seller phone matches, with the payout. */
    public static function matchBuy(int $business_id, string $phone, string $loggedAt): ?array
    {
        $digits = self::digits($phone);
        if (strlen($digits) < 7) {
            return null;
        }
        $from = \Carbon\Carbon::parse($loggedAt)->subDays(3);
        $to = \Carbon\Carbon::parse($loggedAt)->addDays(45);
        $rows = \DB::table('buy_customer_offers')
            ->where('business_id', $business_id)
            ->where('status', 'accepted')
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotNull('seller_phone')
            ->get(['id', 'seller_phone', 'payout_type', 'final_offer_cash', 'final_offer_credit',
                'second_offer_cash', 'second_offer_credit', 'starting_offer_cash', 'starting_offer_credit', 'updated_at']);
        $best = null;
        foreach ($rows as $r) {
            if (self::digits((string) $r->seller_phone) !== $digits) {
                continue;
            }
            $credit = $r->payout_type === 'store_credit';
            $paid = 0.0;
            foreach (['final', 'second', 'starting'] as $stage) {
                $v = (float) ($credit ? $r->{$stage . '_offer_credit'} : $r->{$stage . '_offer_cash'});
                if ($v > 0) {
                    $paid = $v;
                    break;
                }
            }
            if (!$best || $paid > $best['paid']) {
                $best = ['id' => $r->id, 'paid' => round($paid, 2), 'date' => substr((string) $r->updated_at, 0, 10)];
            }
        }
        return $best;
    }

    /** Store-wide good buys this month, for context next to his own. */
    public static function storeGoodBuys(int $business_id, string $month): int
    {
        $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $rows = \DB::table('buy_customer_offers')
            ->where('business_id', $business_id)
            ->where('status', 'accepted')
            ->whereBetween('updated_at', [$start, $start->copy()->endOfMonth()])
            ->get(['payout_type', 'final_offer_cash', 'final_offer_credit']);
        $n = 0;
        foreach ($rows as $r) {
            $paid = (float) ($r->payout_type === 'store_credit' ? $r->final_offer_credit : $r->final_offer_cash);
            if ($paid >= self::GOOD_BUY_MIN) {
                $n++;
            }
        }
        return $n;
    }

    /** Website orders cancelled this month because the item sold elsewhere. */
    public static function webCancels(string $month): ?array
    {
        try {
            // Cached 10 min so the checklist page doesn't wait on the website every load.
            $resp = \Cache::remember('fatteen_goals_web_orders_all', 600, function () {
                $ctl = app(\App\Http\Controllers\WebsiteOrdersController::class);
                return $ctl->bridgeGet('/erp/orders/console?limit=300&payment_status=all');
            });
            if (!is_array($resp) || empty($resp['data'])) {
                return null;
            }
            $hits = [];
            foreach ($resp['data'] as $o) {
                $at = (string) ($o['cancelled_at'] ?? '');
                if ($at === '' || !self::inMonth(date('Y-m-d', strtotime($at)), $month)) {
                    continue;
                }
                // The bridge doesn't send the cancel reason, so every website
                // cancel counts (most are items that sold in store first).
                $hits[] = (string) ($o['display_name'] ?? $o['_id'] ?? '?');
            }
            return $hits;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Everything the goals card needs for one month: each goal's value,
     * whether it's hit, the bonus earned, plus the gate.
     */
    public static function summary(int $business_id, string $month): array
    {
        $d = self::read();
        $goals = self::goals();
        $metrics = $d['metrics'][$month] ?? [];
        $entries = array_values(array_filter($d['entries'], function ($e) use ($month) {
            return ($e['month'] ?? '') === $month;
        }));

        // Gate: checklist days + wrap-up days.
        $total = count(DailyChecklistController::allKeys());
        $checkDays = 0;
        $wrapDays = 0;
        foreach (DailyChecklistController::readAll() as $r) {
            if (!self::inMonth((string) ($r['date'] ?? ''), $month)) {
                continue;
            }
            if (count($r['checked'] ?? []) >= ceil($total * self::CHECKLIST_DAY_SHARE)) {
                $checkDays++;
            }
            if (mb_strlen(trim((string) ($r['wrapup'] ?? ''))) >= 20) {
                $wrapDays++;
            }
        }

        $values = [];

        // Owner-entered.
        $values['phones_pct'] = isset($metrics['phones_pct']) ? (float) $metrics['phones_pct'] : null;
        $values['backlog'] = isset($metrics['backlog']) ? (int) $metrics['backlog'] : null;
        $rv = null;
        if (isset($metrics['reviews_hw_end'], $metrics['reviews_pico_end'])) {
            $startHw = $metrics['reviews_hw_start'] ?? null;
            $startPico = $metrics['reviews_pico_start'] ?? null;
            if ($startHw !== null && $startPico !== null) {
                $rv = (int) $metrics['reviews_hw_end'] + (int) $metrics['reviews_pico_end'] - (int) $startHw - (int) $startPico;
            }
        }
        $values['reviews'] = $rv;

        // Recon.
        $reconDays = array_filter($d['recon'], function ($v, $date) use ($month) {
            return self::inMonth($date, $month);
        }, ARRAY_FILTER_USE_BOTH);
        $clean = count(array_filter($reconDays, function ($v) {
            return (int) $v === 0;
        }));
        $values['recon_days'] = count($reconDays) ? round(100 * $clean / count($reconDays)) : null;
        $reconNote = count($reconDays) ? $clean . ' of ' . count($reconDays) . ' days' : 'No days checked yet';

        // Website cancels.
        $cancels = self::webCancels($month);
        $values['web_cancels'] = $cancels === null ? null : count($cancels);

        // Logged wins.
        $buys = 0;
        $bookings = 0;
        $collabs = 0;
        $chases = 0;
        foreach ($entries as $i => $e) {
            $type = $e['type'] ?? '';
            if ($type === 'chase') {
                $chases++;
                continue;
            }
            if ($type === 'collection_buy') {
                $m = !empty($e['phone']) ? self::matchBuy($business_id, (string) $e['phone'], (string) ($e['at'] ?? 'now')) : null;
                $entries[$i]['buy'] = $m;
                if (($m && $m['paid'] >= self::GOOD_BUY_MIN && ($e['approved'] ?? null) !== false) || ($e['approved'] ?? null) === true) {
                    $buys++;
                }
            } elseif ($type === 'venue_booking' && ($e['approved'] ?? null) === true) {
                $bookings++;
            } elseif ($type === 'collab' && ($e['approved'] ?? null) === true) {
                $collabs++;
            }
        }
        $values['good_buys'] = $buys;
        $values['venue_bookings'] = $bookings;
        $values['collabs'] = $collabs;
        $values['chases'] = $chases;

        $rows = [];
        $bonus = 0;
        $hitCount = 0;
        foreach ($goals as $key => $g) {
            $v = $values[$key] ?? null;
            $hit = null;
            if ($v !== null) {
                $hit = $g['cmp'] === 'gte' ? $v >= $g['target'] : $v <= $g['target'];
            }
            if ($hit) {
                $hitCount++;
            }
            // 100% goal based: a goal pays its bonus only when the target is hit.
            $earn = $hit ? $g['bonus'] : 0;
            $max = $g['bonus'];
            $bonus += $earn;
            $rows[$key] = $g + ['value' => $v, 'hit' => $hit, 'earn' => $earn, 'max' => $max];
        }
        $rows['recon_days']['note'] = $reconNote;
        $rows['good_buys']['note'] = 'Store-wide good buys this month: ' . self::storeGoodBuys($business_id, $month);

        $gateOk = $checkDays >= self::GATE_DAYS && $wrapDays >= self::GATE_DAYS;
        $active = $month >= self::START_MONTH;

        return [
            'month' => $month,
            'active' => $active,
            'rows' => $rows,
            'check_days' => $checkDays,
            'wrap_days' => $wrapDays,
            'gate_ok' => $gateOk,
            'hit_count' => $hitCount,
            'goal_count' => count($goals),
            'bonus' => $gateOk && $active ? $bonus : 0,
            'bonus_if_gate' => $bonus,
            'max_bonus' => array_sum(array_column($goals, 'bonus')),
            'entries' => array_reverse($entries),
            'metrics' => $metrics,
        ];
    }
}
