<?php

namespace App\Utils;

use Illuminate\Http\Request;

/**
 * Daily register reconciliation digest for the #register-reconciliation
 * Slack channel (Sarah 2026-09-25: Fatteen is supposed to reconcile the
 * registers daily but doesn't really know how — post a plain list of what
 * needs fixing and who to ask).
 *
 * The Clover <-> ERP matching is NOT reimplemented here. We run the
 * /pos/recent-feed controller for the day (the agreed source of truth)
 * and read its computed view data, applying the same "is this a
 * discrepancy" rules the feed's filter uses. Drawer counts and auto-closed
 * registers come straight from cash_registers.
 *
 * Callers must have an admin user logged in (web request, or Auth::setUser
 * in the console command) — recent-feed checks auth()->user().
 */
class RegisterReconUtil
{
    const SETTINGS_FILE = 'register-recon/settings.json';
    const ERP_URL = 'https://playlist.nivessa.com';

    // Same tolerance the recent feed uses for a paired swipe (bag fees +
    // tax rounding drift a few cents).
    const MISMATCH_TOLERANCE_CENTS = 15;
    // Drawer count off by less than this is treated as counting noise.
    const DRAWER_TOLERANCE = 5.00;
    const FLAG_DRAWER_VARIANCE = false;

    public static function settings(): array
    {
        try {
            $file = storage_path('app/' . self::SETTINGS_FILE);
            if (is_file($file)) {
                return json_decode((string) file_get_contents($file), true) ?: [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    public static function saveSettings(array $patch): void
    {
        $file = storage_path('app/' . self::SETTINGS_FILE);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $data = array_merge(self::settings(), $patch);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    public static function webhook(): string
    {
        return trim((string) (self::settings()['slack_webhook'] ?? ''));
    }

    /**
     * Build the day's flags, grouped by store.
     *
     * Returns [
     *   'date' => 'Y-m-d', 'label' => 'Thursday, Sep 24',
     *   'stores' => [ ['name' => 'Hollywood', 'erp' => .., 'erp_count' => ..,
     *                  'clover' => .., 'clover_count' => .., 'diff' => ..,
     *                  'items' => [ ['kind' => .., 'text' => .., 'ask' => ..,
     *                                'note' => ?string, 'amount' => float] ] ] ],
     *   'issue_count' => int,
     * ]
     */
    public static function build(int $business_id, string $date, $session): array
    {
        $tz = 'America/Los_Angeles';

        $req = Request::create('/pos/recent-feed', 'GET', ['date' => $date]);
        $req->setLaravelSession($session);
        $view = app(\App\Http\Controllers\SellPosController::class)->recentSalesFeed($req);
        $d = $view->getData();

        $sales        = $d['sales'];
        $cloverByTx   = $d['clover_by_transaction'] ?? [];
        $expected     = $d['clover_expected_cents'] ?? [];
        $webPaid      = $d['web_paid_ids'] ?? [];
        $reconciled   = $d['clover_reconciled'] ?? [];
        $explanations = $d['clover_explanations'] ?? [];
        $orphans      = $d['unclaimed_clover_payments'] ?? collect();
        $cashierFor   = $d['cashier_for_orphan'] ?? [];
        $cashierName  = $d['cashierNameById'] ?? [];
        $dupOf        = $d['orphan_duplicate_of'] ?? [];
        $byStore      = $d['today_by_store'] ?? [];
        $locations    = $d['business_locations'] ?? [];
        $pairCands    = $d['erp_only_pair_candidates'] ?? [];

        $stores = [];
        $storeKey = function ($locId) use (&$stores, $locations, $date) {
            $k = (int) ($locId ?? 0);
            if (!isset($stores[$k])) {
                $name = $k && isset($locations[$k]) ? $locations[$k] : 'No store set';
                $stores[$k] = [
                    'name' => self::storeName($name), 'erp' => 0.0, 'erp_count' => 0,
                    'clover' => 0.0, 'clover_count' => 0, 'diff' => 0.0, 'items' => [],
                    'url' => self::ERP_URL . '/pos/recent-feed?' . http_build_query(array_filter(['date' => $date, 'location_id' => $k ?: null, 'discrepancy' => 'any'])),
                ];
            }
            return $k;
        };
        foreach ($byStore as $locId => $s) {
            $s = (array) $s;
            $k = $storeKey($s['location_id'] ?? $locId);
            $stores[$k]['erp']          = round((float) ($s['erp_net'] ?? 0), 2);
            $stores[$k]['erp_count']    = (int) ($s['erp_count'] ?? 0);
            $stores[$k]['clover']       = round((float) ($s['clover'] ?? 0), 2);
            $stores[$k]['clover_count'] = (int) ($s['clover_count'] ?? 0);
            $stores[$k]['diff']         = round($stores[$k]['clover'] - $stores[$k]['erp'], 2);
        }

        $cents = function ($x) { return (int) round(((float) $x) * 100); };
        $money = function ($x) { return '$' . number_format((float) $x, 2); };
        $time  = function ($ts) use ($tz) {
            try { return \Carbon\Carbon::parse((string) $ts, $tz)->format('g:ia'); } catch (\Throwable $e) { return ''; }
        };
        $first = function ($full) {
            $full = trim(preg_replace('/\s+/', ' ', (string) $full));
            if ($full === '') return '';
            $parts = explode(' ', $full);
            return ucfirst(strtolower($parts[0]));
        };
        $feed = function (array $q) use ($date) {
            return self::ERP_URL . '/pos/recent-feed?' . http_build_query(array_filter(array_merge(['date' => $date], $q)));
        };
        $noteFor = function ($key) use ($explanations) {
            $n = $explanations[$key] ?? null;
            return $n ? trim((string) ($n->reason ?? '')) : null;
        };

        // 0) Probable pairs: an ERP-only sale and a Clover-only charge at the
        //    same store, close in time/amount (the feed's "Probable Clover
        //    match"). These are one sale that just needs matching on the
        //    feed, so they become one MATCH line for Fatteen instead of two
        //    separate problems. Closest amount wins; each charge used once.
        $orphanByCpId = [];
        foreach ($orphans as $cp) {
            $orphanByCpId[(string) $cp->clover_payment_id] = $cp;
        }
        $pairFor = [];   // sale id => candidate
        $pairedCp = [];  // clover_payment_id => true
        $flat = [];
        foreach ($pairCands as $saleId => $cands) {
            foreach ($cands as $c) {
                $flat[] = ['sale' => (int) $saleId] + $c;
            }
        }
        usort($flat, fn($a, $b) => ($a['amt_delta'] <=> $b['amt_delta']) ?: ($a['time_delta'] <=> $b['time_delta']));
        $saleById = collect($sales)->keyBy('id');
        foreach ($flat as $c) {
            $sale = $saleById->get($c['sale']);
            $cpKey = (string) $c['cp_id'];
            if (!$sale || isset($pairFor[$c['sale']]) || isset($pairedCp[$cpKey]) || !isset($orphanByCpId[$cpKey])) continue;
            if ($c['loc_id'] !== null && (int) $c['loc_id'] !== (int) $sale->location_id) continue; // other store
            if (isset($reconciled[$sale->id]) || isset($webPaid[$sale->id])) continue;
            $pairFor[$c['sale']] = $c;
            $pairedCp[$cpKey] = true;
        }

        // 1) ERP sales: rung in ERP but no Clover swipe, or amounts differ.
        foreach ($sales as $sale) {
            if (isset($reconciled[$sale->id]) || isset($webPaid[$sale->id])) continue;
            if (isset($pairFor[$sale->id])) {
                $c    = $pairFor[$sale->id];
                $who  = $first(optional($sale->sales_person)->first_name ?: optional($sale->sales_person)->username);
                $cpWhen = \Carbon\Carbon::createFromTimestamp($c['ts'], $tz)->format('g:ia');
                $off  = $c['amt_delta'] > self::MISMATCH_TOLERANCE_CENTS
                    ? ', ' . $money($c['amt_delta'] / 100) . ' off' : '';
                $stores[$storeKey($sale->location_id)]['items'][] = [
                    'kind'   => 'match',
                    'mini'   => '#' . $sale->invoice_no . ' ' . $money($sale->final_total) . ' = Clover ' . $money($c['amount']),
                    'short'  => '#' . $sale->invoice_no . ' ' . $money($sale->final_total) . ' = Clover ' . $money($c['amount']) . ' at ' . $cpWhen,
                    'text'   => '#' . $sale->invoice_no . ' (' . $money($sale->final_total) . ' in ERP) is probably the '
                        . $money($c['amount']) . ' Clover charge at ' . $cpWhen . '.',
                    'q'      => 'Fatteen: match them.',
                    'ask'    => '',
                    'fatteen'=> true,
                    'url'    => $feed(['location_id' => $sale->location_id, 'discrepancy' => 'any']),
                    'note'   => $noteFor('no_clover:' . $sale->id . ':0'),
                    'amount' => $c['amt_delta'] / 100,
                ];
                continue;
            }
            $info = $cloverByTx[$sale->id] ?? null;
            $exp  = $expected[$sale->id] ?? $cents($sale->final_total);
            $who  = $first(optional($sale->sales_person)->first_name ?: optional($sale->sales_person)->username);
            $k    = $storeKey($sale->location_id);
            $inv  = $sale->invoice_no ? ' #' . $sale->invoice_no : '';

            if ($info === null) {
                if ($exp <= 0) continue; // fully covered by store credit
                $stores[$k]['items'][] = [
                    'kind'   => 'no_clover',
                    'mini'   => '#' . $sale->invoice_no . ' ' . $money($exp / 100) . ' at ' . $time($sale->transaction_date),
                    'short'  => '#' . $sale->invoice_no . ' ' . $money($exp / 100) . ' at ' . $time($sale->transaction_date),
                    'text'   => $inv . ' ' . $money($exp / 100) . ' rung in ERP at ' . $time($sale->transaction_date)
                        . ' but never charged on Clover.',
                    'q'      => 'why wasn\'t it charged?',
                    'ask'    => $who,
                    'url'    => $feed(['location_id' => $sale->location_id, 'created_by' => $sale->created_by, 'discrepancy' => 'no_clover']),
                    'note'   => $noteFor('no_clover:' . $sale->id . ':0'),
                    'amount' => $exp / 100,
                ];
                continue;
            }
            $gross = (int) ($info['amount_cents'] ?? 0);
            $net   = $gross - (int) ($info['tax_cents'] ?? 0);
            $gap   = min(abs($gross - $exp), abs($net - $exp));
            if ($gap > self::MISMATCH_TOLERANCE_CENTS) {
                $stores[$k]['items'][] = [
                    'kind'   => 'mismatch',
                    'mini'   => '#' . $sale->invoice_no . ' ERP ' . $money($exp / 100) . ' vs Clover ' . $money($gross / 100),
                    'under'  => $gross < $exp, // charged less than rung = missing cash
                    'short'  => '#' . $sale->invoice_no . ' ERP ' . $money($exp / 100) . ', Clover ' . $money($gross / 100),
                    'text'   => $inv . ' rung ' . $money($exp / 100) . ' in ERP but charged '
                        . $money($gross / 100) . ' on Clover (' . $time($sale->transaction_date) . ').',
                    'q'      => 'why the difference?',
                    'ask'    => $who,
                    'url'    => $feed(['location_id' => $sale->location_id, 'created_by' => $sale->created_by, 'discrepancy' => 'mismatch']),
                    'note'   => $noteFor('mismatch:' . $sale->id . ':0'),
                    'amount' => $gap / 100,
                ];
            }
        }

        // 2) Clover charges with no ERP ring (the customer paid, nothing
        //    was rung up / inventory not taken out).
        foreach ($orphans as $cp) {
            if (isset($pairedCp[(string) $cp->clover_payment_id])) continue;
            // Same Clover order as an already-paired sale = sync-side
            // duplicate payment record, not a missed ring-up (per the feed).
            if (isset($dupOf[$cp->id])) continue;
            $res = (string) ($cp->result ?? '');
            if ($res !== '' && $res !== 'SUCCESS' && $res !== 'APPROVED') continue; // voids / test charges
            $amt = (float) ($cp->amount ?? 0);
            $k   = $storeKey($cp->location_id);
            try {
                $when = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($cp)->format('g:ia');
            } catch (\Throwable $e) {
                $when = '';
            }
            $who = $first($cp->employee_name ?? '');
            if ($who === '' && isset($cashierFor[$cp->id])) {
                $who = $first($cashierName[$cashierFor[$cp->id]] ?? '');
            }
            $items = [];
            if (!empty($cp->clover_order_id)) {
                $rec = \App\Services\CloverLineItemStore::load($business_id, (string) $cp->clover_order_id);
                foreach (($rec['items'] ?? []) as $li) {
                    if (!empty($li['refunded']) || trim((string) ($li['name'] ?? '')) === '') continue;
                    // Keyed-in amounts ("Sale", "Custom Amount") aren't items.
                    if (preg_match('/^(sale|custom( amount)?|manual|misc|item)$/i', trim($li['name']))) continue;
                    $items[] = trim($li['name']) . ' ' . $money(((int) ($li['price_cents'] ?? 0)) / 100);
                }
            }
            if ($amt < 0) {
                $text = $money(abs($amt)) . ' refunded on Clover at ' . $when . ' with no return in ERP.';
                $q = 'please do the return in ERP so inventory updates.';
            } else {
                $text = $money($amt) . ' charged on Clover at ' . $when . ' but not rung in ERP'
                    . (!empty($items) ? ' (' . implode(', ', $items) . ').' : '.');
                $q = 'please ring ' . (!empty($items) ? 'these items' : 'what they bought') . ' in ERP so inventory updates.';
            }
            $stores[$k]['items'][] = [
                'kind'   => 'no_erp',
                'mini'   => $money(abs($amt)) . ' at ' . $when,
                'short'  => $money(abs($amt)) . ($amt < 0 ? ' refund' : '') . ' at ' . $when
                    . (!empty($items) ? ' (' . implode(', ', $items) . ')' : ''),
                'text'   => $text,
                'q'      => $q,
                'ask'    => $who,
                'url'    => $feed(['location_id' => $cp->location_id, 'discrepancy' => 'no_erp']),
                'note'   => $noteFor('no_erp:0:' . $cp->id),
                'amount' => abs($amt),
            ];
        }

        // 3) Drawer counts + registers that were never counted.
        foreach (self::drawerFlags($business_id, $date) as $f) {
            $k = $storeKey($f['location_id']);
            $stores[$k]['items'][] = $f;
        }

        // Drop the empty no-store bucket, sort items by time within store.
        $out = [];
        $issues = 0;
        foreach ($stores as $k => $s) {
            if ($k === 0 && empty($s['items']) && $s['erp_count'] === 0 && $s['clover_count'] === 0) continue;
            $issues += count($s['items']);
            $out[] = $s;
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'date'        => $date,
            'label'       => \Carbon\Carbon::parse($date, $tz)->format('l, M j'),
            'stores'      => $out,
            'issue_count' => $issues,
            'feed_url'    => self::ERP_URL . '/pos/recent-feed?date=' . $date,
        ];
    }

    /**
     * Registers closed on $date: drawer count vs what the ERP expected
     * (opening cash + cash in - cash out), and shifts auto-closed by the
     * system with no count at all.
     */
    public static function drawerFlags(int $business_id, string $date): array
    {
        $regs = \DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->where('cr.business_id', $business_id)
            ->where('cr.status', 'close')
            ->whereNotNull('cr.closed_at')
            ->whereDate('cr.closed_at', $date)
            ->select('cr.id', 'cr.location_id', 'cr.created_at', 'cr.closed_at', 'cr.closing_amount',
                'cr.closing_note', 'u.first_name', 'u.username')
            ->get();
        if ($regs->isEmpty()) return [];

        $crt = \DB::table('cash_register_transactions')
            ->whereIn('cash_register_id', $regs->pluck('id')->all())
            ->selectRaw("cash_register_id,
                SUM(CASE WHEN pay_method='cash' AND transaction_type='initial' THEN amount ELSE 0 END) as opening_cash,
                SUM(CASE WHEN pay_method='cash' THEN CASE WHEN type='credit' THEN amount ELSE -amount END ELSE 0 END) as cash_net,
                SUM(CASE WHEN transaction_type IN ('sell','purchase','refund') THEN 1 ELSE 0 END) as activity")
            ->groupBy('cash_register_id')
            ->get()
            ->keyBy('cash_register_id');

        $flags = [];
        foreach ($regs as $r) {
            $who = ucfirst(strtolower(trim((string) ($r->first_name ?: $r->username))));
            $row = $crt->get($r->id);
            $autoClosed = stripos((string) $r->closing_note, 'Auto-closed by system') !== false;
            if ($autoClosed || $r->closing_amount === null) {
                $flags[] = [
                    'location_id' => $r->location_id,
                    'kind'   => 'uncounted',
                    'short'  => 'Opened ' . \Carbon\Carbon::parse($r->created_at)->format('g:ia') . ', never closed',
                    'text'   => 'Register opened ' . \Carbon\Carbon::parse($r->created_at)->format('g:ia')
                        . ' was never closed/counted (system closed it)',
                    'ask'    => $who,
                    'note'   => null,
                    'amount' => 0,
                ];
                continue;
            }
            // Drawer short/over is NOT flagged yet: on 9/24 every closed
            // drawer at both stores came out $70-$550 "short" against
            // opening + cash net, so the expected-cash math doesn't match
            // how cashiers count/drop. Re-enable once that's reconciled.
            if (!self::FLAG_DRAWER_VARIANCE) continue;
            if (!$row || (int) $row->activity === 0) continue;
            $expected = (float) $row->opening_cash + (float) $row->cash_net;
            $variance = round((float) $r->closing_amount - $expected, 2);
            if (abs($variance) < self::DRAWER_TOLERANCE) continue;
            $flags[] = [
                'location_id' => $r->location_id,
                'kind'   => 'drawer',
                'text'   => 'Drawer ' . ($variance < 0 ? 'SHORT ' : 'OVER ') . '$' . number_format(abs($variance), 2)
                    . ' at close ' . \Carbon\Carbon::parse($r->closed_at)->format('g:ia')
                    . ' (counted $' . number_format((float) $r->closing_amount, 2)
                    . ', expected $' . number_format($expected, 2) . ')',
                'ask'    => $who,
                'note'   => null,
                'amount' => abs($variance),
            ];
        }
        return $flags;
    }

    /**
     * Slack message - kept very short (Sarah 9/25): per store, one line
     * per person with what they need to do; amounts link to the feed.
     */
    public static function formatSlack(array $r): string
    {
        $dollars = function ($x) { return '$' . number_format((float) $x, 0); };
        // Sarah 9/25: two buckets that matter - money we didn't collect and
        // stock the ERP still thinks we have. Everything else is minor.
        $cats = [
            'cash'  => ['Did we capture the transaction?', 'rung in ERP, no charge on Clover - ask why'],
            'inv'   => ['Needs inventory update', 'charged on Clover, not rung in ERP - ring the items in'],
            'wrong' => ['Wrong amount', 'charged more than rung - ask why'],
            'match' => ['To match', 'same sale, Fatteen match on the feed'],
            'count' => ['Register not counted', 'ask why they did not close out'],
            'other' => ['Other', ''],
        ];
        $catOf = function ($it) {
            switch ($it['kind']) {
                case 'no_clover': return 'cash';
                case 'no_erp':    return 'inv';
                case 'mismatch':  return !empty($it['under']) ? 'cash' : 'wrong';
                case 'match':     return 'match';
                case 'uncounted': return 'count';
                default:          return 'other';
            }
        };
        $lines = ['*Register check - ' . $r['label'] . '*'
            . ($r['issue_count'] === 0 ? '  All good.' : '  ' . $r['issue_count'] . ' to fix')];
        foreach ($r['stores'] as $s) {
            $lines[] = '';
            // Slack has no text colors; inline code renders red, so the
            // ERP/Clover difference goes in backticks.
            $diff = $s['clover'] - $s['erp'];
            $lines[] = '*<' . $s['url'] . '|' . strtoupper($s['name']) . '>*  ERP ' . $dollars($s['erp']) . ' / Clover ' . $dollars($s['clover'])
                . (abs($diff) >= 1 ? '  `' . ($diff > 0 ? '+' : '-') . '$' . number_format(abs($diff), 2) . '`' : '');
            if (empty($s['items'])) {
                $lines[] = 'All good';
                continue;
            }
            foreach ($cats as $cat => [$title, $hint]) {
                $byPerson = [];
                foreach ($s['items'] as $it) {
                    if ($catOf($it) !== $cat) continue;
                    $who = $cat === 'match' ? '' : (($it['ask'] ?? '') !== '' ? $it['ask'] : 'Unknown');
                    $label = $it['mini'] ?? ($it['short'] ?? $it['text']);
                    $byPerson[$who][] = '<' . ($it['url'] ?? $s['url']) . '|' . $label . '>';
                }
                if (empty($byPerson)) continue;
                $lines[] = '*' . $title . '*' . ($hint !== '' ? '  _' . $hint . '_' : '');
                foreach ($byPerson as $who => $links) {
                    $lines[] = '• ' . ($who !== '' ? '*' . $who . '*: ' : '') . implode(', ', $links);
                }
            }
        }
        if ($r['issue_count'] > 0) {
            $lines[] = '';
            $lines[] = '<@' . self::FATTEEN_SLACK_ID . '> please follow up with these people to fix these errors, then reply in thread when done.';
        }
        return implode("\n", $lines);
    }

    const FATTEEN_SLACK_ID = 'U07QEGGQ7B2';

    /** Block Kit layout was tried 9/25 and dropped as too long; plain text only. */
    public static function slackBlocks(array $r): array
    {
        return [];
    }

    public static function postToSlack(string $text, array $blocks = []): bool
    {
        $webhook = self::webhook();
        if ($webhook === '') return false;
        try {
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(!empty($blocks) ? ['text' => $text, 'blocks' => $blocks] : ['text' => $text]),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) {
            \Log::warning('register recon slack post failed: ' . $e->getMessage());
            return false;
        }
    }

    // Slack user IDs for cashiers in #register-reconciliation, so their line
    // pings them. Anyone not listed shows as a plain name.
    // Empty since 9/25: the channel is Sarah/Jon/Fatteen only, and Fatteen
    // asks the cashiers. Add first-name => Slack user id to ping someone.
    const SLACK_IDS = [];

    private static function mention(string $first): string
    {
        $id = self::SLACK_IDS[strtolower($first)] ?? null;
        return $id ? '<@' . $id . '>' : '*Ask ' . $first . ':*';
    }

    private static function storeName(string $name): string
    {
        $n = strtolower($name);
        if (strpos($n, 'pico') !== false) return 'Pico';
        if (strpos($n, 'hollywood') !== false) return 'Hollywood';
        return $name;
    }
}
