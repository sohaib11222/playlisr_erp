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
            'phones_pct'     => ['label' => 'Calls and texts answered', 'unit' => '%', 'cmp' => 'gte', 'target' => 95, 'bonus' => 5, 'src' => 'Quo, auto'],
            'backlog'        => ['label' => 'Customers waiting over 24h (right now)', 'unit' => '', 'cmp' => 'lte', 'target' => 3, 'bonus' => 5, 'src' => 'Quo, auto'],
            'thanks'         => ['label' => 'Customers who thanked us for the help', 'unit' => '', 'cmp' => 'gte', 'target' => 'up10', 'bonus' => 5, 'src' => 'Quo texts, auto'],
            'angry_open'     => ['label' => 'Unhappy customers not resolved', 'unit' => '', 'cmp' => 'lte', 'target' => 0, 'bonus' => 5, 'src' => 'Communications Hub, auto'],
            'recon_days'     => ['label' => 'Register days reconciled within 2 days', 'unit' => '%', 'cmp' => 'gte', 'target' => 85, 'bonus' => 5, 'src' => 'Register recon, auto'],
            'cancel_rate'    => ['label' => 'Website orders cancelled', 'unit' => '%', 'cmp' => 'lte', 'target' => 3, 'bonus' => 5, 'src' => 'Website orders, auto'],
            'store_sales'    => ['label' => 'In-store purchases, both stores', 'unit' => '', 'cmp' => 'gte', 'target' => 'up5', 'bonus' => 5, 'src' => 'ERP sales, auto'],
            'good_buys'      => ['label' => 'Good collection buys ($' . self::GOOD_BUY_MIN . '+) from his leads', 'unit' => '', 'cmp' => 'gte', 'target' => 3, 'bonus' => 5, 'src' => 'Logged + ERP buys'],
            'venue_bookings' => ['label' => 'Venue bookings closed', 'unit' => '', 'cmp' => 'gte', 'target' => 2, 'bonus' => 5, 'src' => 'Logged, approved'],
            'collabs'        => ['label' => 'Good partnerships or collabs', 'unit' => '', 'cmp' => 'gte', 'target' => 1, 'bonus' => 5, 'src' => 'Logged, approved'],
            'reviews'        => ['label' => 'New Google reviews, both stores', 'unit' => '', 'cmp' => 'gte', 'target' => 10, 'bonus' => 5, 'src' => 'Google, entered monthly'],
            'chases'         => ['label' => 'Times Sarah or Jon had to ask for an update', 'unit' => '', 'cmp' => 'lte', 'target' => 2, 'bonus' => 5, 'src' => 'Logged by Sarah/Jon'],
        ];
    }

    /** Words that mean a customer thanked us. */
    const THANKS_RE = '/\b(thank you|thanks|thank u|thx|appreciate (it|you|that|the help|your help)|so helpful|very helpful|super helpful|really helpful|you.?re the best|you guys are the best)\b/i';

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
    /** Phone inquiries (Quo) for a month: total, answered, and customers who thanked us. */
    public static function phoneStats(int $business_id, string $month): array
    {
        $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $rows = \DB::table('communications')
            ->where('business_id', $business_id)
            ->whereIn('channel', ['phone_1', 'phone_2'])
            ->whereBetween('created_at', [$start, $start->copy()->endOfMonth()])
            ->where('message', '!=', '(no inbound message logged for this contact)')
            ->get(['contact_info', 'message', 'status', 'resolution_notes']);
        $total = 0;
        $answered = 0;
        $thanked = [];
        foreach ($rows as $r) {
            $total++;
            if ($r->status === 'resolved' || trim((string) $r->resolution_notes) !== '') {
                $answered++;
            }
            if (preg_match(self::THANKS_RE, (string) $r->message)) {
                $thanked[substr(preg_replace('/\D/', '', (string) $r->contact_info), -10) ?: uniqid()] = true;
            }
        }
        return ['total' => $total, 'answered' => $answered, 'thanked' => count($thanked)];
    }

    /** Customers still waiting on a reply for more than 24 hours, right now. */
    public static function backlogNow(int $business_id): int
    {
        return (int) \DB::table('communications')
            ->where('business_id', $business_id)
            ->whereIn('channel', ['phone_1', 'phone_2'])
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('resolution_notes')->orWhere('resolution_notes', '');
            })
            ->where('created_at', '<', now()->subDay())
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
    }

    /** In-store purchases (POS sales, not web/Discogs/eBay) for a month. */
    public static function storeSales(int $business_id, string $month): int
    {
        $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        return (int) \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereBetween('transaction_date', [$start, $start->copy()->endOfMonth()])
            ->where(function ($q) {
                $q->whereNull('channel')->orWhere('channel', 'in_store');
            })
            ->count();
    }

    /** Website orders placed in a month and how many of them were cancelled. */
    public static function cancelStats(string $month): ?array
    {
        try {
            // Cache only a good answer, so one slow website call doesn't blank the number for 10 min.
            $resp = \Cache::get('fatteen_goals_web_orders_ok');
            if (!$resp) {
                $ctl = app(\App\Http\Controllers\WebsiteOrdersController::class);
                $resp = $ctl->bridgeGet('/erp/orders/console?limit=300&payment_status=all', 25);
                if (!is_array($resp) || empty($resp['data'])) {
                    return null;
                }
                \Cache::put('fatteen_goals_web_orders_ok', $resp, 600);
            }
            $placed = 0;
            $cancelled = 0;
            foreach ($resp['data'] as $o) {
                $created = strtotime((string) ($o['createdAt'] ?? ''));
                if (!$created || date('Y-m', $created) !== $month) {
                    continue;
                }
                if (($o['payment_status'] ?? '') !== 'completed' && ($o['payment_status'] ?? '') !== 'refunded') {
                    continue;
                }
                $placed++;
                if (!empty($o['cancelled_at']) || ($o['order_status'] ?? '') === 'cancelled') {
                    $cancelled++;
                }
            }
            return ['placed' => $placed, 'cancelled' => $cancelled];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Every goal's number for one month (null = no data). */
    public static function monthValues(int $business_id, string $month, array $d, bool $isCurrent): array
    {
        $v = [];
        $notes = [];
        $metrics = $d['metrics'][$month] ?? [];

        $ph = self::phoneStats($business_id, $month);
        $v['phones_pct'] = $ph['total'] ? round(100 * $ph['answered'] / $ph['total']) : null;
        $notes['phones_pct'] = $ph['answered'] . ' of ' . $ph['total'];
        $v['thanks'] = $ph['total'] ? $ph['thanked'] : null;
        $v['backlog'] = $isCurrent ? self::backlogNow($business_id) : null;
        // Unhappy customers still open: this month counts everything open now;
        // past months count the ones from that month that are still open.
        $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $q = \DB::table('communications')->where('business_id', $business_id)
            ->where('topic', 'unhappy_customer')->where('status', 'pending');
        if (!$isCurrent) {
            $q->whereBetween('created_at', [$start, $start->copy()->endOfMonth()]);
        }
        $v['angry_open'] = (int) $q->count();

        $reconDays = array_filter($d['recon'], function ($x, $date) use ($month) {
            return self::inMonth($date, $month);
        }, ARRAY_FILTER_USE_BOTH);
        $clean = count(array_filter($reconDays, function ($x) {
            return (int) $x === 0;
        }));
        $v['recon_days'] = count($reconDays) ? round(100 * $clean / count($reconDays)) : null;
        $notes['recon_days'] = count($reconDays) ? $clean . ' of ' . count($reconDays) . ' days' : '';

        $cs = self::cancelStats($month);
        // Needs 20+ orders in the window (the bridge returns the latest 300).
        $v['cancel_rate'] = $cs && $cs['placed'] >= 20 ? round(100 * $cs['cancelled'] / $cs['placed'], 1) : null;
        $notes['cancel_rate'] = $cs ? $cs['cancelled'] . ' of ' . $cs['placed'] . ' orders' : '';

        $v['store_sales'] = self::storeSales($business_id, $month) ?: null;

        $buys = 0;
        $bookings = 0;
        $collabs = 0;
        $chases = 0;
        foreach ($d['entries'] as $e) {
            if (($e['month'] ?? '') !== $month) {
                continue;
            }
            $type = $e['type'] ?? '';
            $ok = ($e['approved'] ?? null) === true;
            if ($type === 'chase') {
                $chases++;
            } elseif ($type === 'collection_buy') {
                $m = !empty($e['phone']) ? self::matchBuy($business_id, (string) $e['phone'], (string) ($e['at'] ?? 'now')) : null;
                if (($m && $m['paid'] >= self::GOOD_BUY_MIN && ($e['approved'] ?? null) !== false) || $ok) {
                    $buys++;
                }
            } elseif ($type === 'venue_booking' && $ok) {
                $bookings++;
            } elseif ($type === 'collab' && $ok) {
                $collabs++;
            }
        }
        $v['good_buys'] = $buys;
        $v['venue_bookings'] = $bookings;
        $v['collabs'] = $collabs;
        $v['chases'] = $chases;
        $v['reviews'] = isset($metrics['reviews_new']) ? (int) $metrics['reviews_new'] : null;

        return [$v, $notes];
    }

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

        $prevMonth = \Carbon\Carbon::parse($month . '-01')->subMonth()->format('Y-m');
        [$values, $notes] = self::monthValues($business_id, $month, $d, $month === date('Y-m'));
        [$prev] = self::monthValues($business_id, $prevMonth, $d, false);

        // Entries list (with the matched buy shown) for this month.
        foreach ($entries as $i => $e) {
            if (($e['type'] ?? '') === 'collection_buy' && !empty($e['phone'])) {
                $entries[$i]['buy'] = self::matchBuy($business_id, (string) $e['phone'], (string) ($e['at'] ?? 'now'));
            }
        }

        $rows = [];
        $bonus = 0;
        $hitCount = 0;
        foreach ($goals as $key => $g) {
            $v = $values[$key] ?? null;
            // Growth targets are set from last month's number.
            if ($g['target'] === 'up5' || $g['target'] === 'up10') {
                $base = $prev[$key] ?? null;
                $g['target'] = $base ? (int) ceil($base * ($g['target'] === 'up5' ? 1.05 : 1.10)) : null;
            }
            $hit = null;
            if ($v !== null && $g['target'] !== null) {
                $hit = $g['cmp'] === 'gte' ? $v >= $g['target'] : $v <= $g['target'];
            }
            if ($hit) {
                $hitCount++;
            }
            // 100% goal based: a goal pays its bonus only when the target is hit.
            $earn = $hit ? $g['bonus'] : 0;
            $max = $g['bonus'];
            $bonus += $earn;
            $rows[$key] = $g + ['value' => $v, 'prev' => $prev[$key] ?? null, 'note' => $notes[$key] ?? '', 'hit' => $hit, 'earn' => $earn, 'max' => $max];
        }

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
