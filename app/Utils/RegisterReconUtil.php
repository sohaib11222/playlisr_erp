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
    // Sarah 9/27: "i dont want to bug them daily for no reason" - small
    // stuff stays off the list.
    const DRAWER_TOLERANCE = 20.00;
    const MIN_ITEM_DOLLARS = 5.00;
    const FLAG_DRAWER_VARIANCE = true;

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

        // Cashiers sometimes apply store credit by ringing the whole sale as
        // cash with a note "Store credit used: $ 39" (Manolo #31175, Alec
        // #31222 on 9/26) instead of the store-credit tender. Take that
        // note off what was due on Clover.
        foreach ($sales as $sale) {
            $noteCents = 0;
            foreach ($sale->payment_lines as $pl) {
                if (preg_match('/store credit used:\s*\$?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', (string) $pl->note, $m)) {
                    $noteCents += $cents($m[1]);
                }
            }
            if ($noteCents > 0) {
                $base = $expected[$sale->id] ?? $cents($sale->final_total);
                $expected[$sale->id] = max(0, $base - $noteCents);
            }
        }

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
            // Only a real pair if a card charge was actually due (not paid
            // in store credit) and the amounts are within 20% - #30980
            // ($4.39 store credit vs a $1.65 charge) was a bad suggestion.
            $due = $expected[$sale->id] ?? $cents($sale->final_total);
            if ($due <= 0 || $c['amt_delta'] > 0.2 * $due) continue;
            $pairFor[$c['sale']] = $c;
            $pairedCp[$cpKey] = true;
        }

        // 0b) Our own pass: the feed only suggests pairs within $3 of the
        //     full sale total, so a sale partly paid with store credit
        //     (#31197: $30.73, card part $25.73 vs a $25.24 charge) never
        //     pairs. Match what was due on Clover instead: same store,
        //     within an hour, within 20%.
        foreach ($sales as $sale) {
            if (isset($pairFor[$sale->id]) || isset($cloverByTx[$sale->id])) continue;
            if (isset($reconciled[$sale->id]) || isset($webPaid[$sale->id])) continue;
            $due = $expected[$sale->id] ?? $cents($sale->final_total);
            if ($due <= 0) continue;
            $sTs = strtotime((string) $sale->transaction_date);
            $best = null;
            foreach ($orphans as $cp) {
                $key = (string) $cp->clover_payment_id;
                if (isset($pairedCp[$key]) || isset($dupOf[$cp->id])) continue;
                if ($cp->location_id !== null && (int) $cp->location_id !== (int) $sale->location_id) continue;
                $amt = $cents($cp->amount);
                if ($amt <= 0) continue;
                try {
                    $cTs = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($cp)->getTimestamp();
                } catch (\Throwable $e) {
                    continue;
                }
                $delta = abs($amt - $due);
                if (abs($cTs - $sTs) > 3600 || $delta > 0.2 * $due) continue;
                if ($best === null || $delta < $best['amt_delta']) {
                    $best = ['cp_id' => $key, 'amount' => round($amt / 100, 2), 'ts' => $cTs, 'amt_delta' => $delta, 'loc_id' => $cp->location_id];
                }
            }
            if ($best !== null) {
                $pairFor[$sale->id] = $best;
                $pairedCp[$best['cp_id']] = true;
            }
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
                if ($exp < self::MIN_ITEM_DOLLARS * 100) continue; // too small to chase
                $stores[$k]['items'][] = [
                    'kind'   => 'no_clover',
                    // Cash sales still go on Clover (Sarah's rule) - call it out.
                    'is_cash'=> $sale->payment_lines->pluck('method')->filter()->unique()->values()->all() === ['cash'],
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
            if ($gap > self::MISMATCH_TOLERANCE_CENTS && $gap >= self::MIN_ITEM_DOLLARS * 100) {
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
            if (abs($amt) < self::MIN_ITEM_DOLLARS) continue; // too small to chase
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
        foreach (array_merge(self::drawerFlags($business_id, $date), self::handoverFlags($business_id, $date)) as $f) {
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
    /**
     * Handover gaps (Sarah 9/27: "could have also been the guy before").
     * Each shift is checked against its own opening count, so a gap between
     * one cashier leaving the drawer and the next one counting it shows up
     * here instead - naming both, blaming neither.
     *   left  = previous closing count - previous close-time safe drop
     *   found = next opening count (saved opening cash + open-time drop)
     */
    public static function handoverFlags(int $business_id, string $date): array
    {
        $flags = [];
        $opened = \DB::table('cash_registers as cr')->leftJoin('users as u', 'u.id', '=', 'cr.user_id')
            ->where('cr.business_id', $business_id)->whereDate('cr.created_at', $date)
            ->orderBy('cr.created_at')->get(['cr.id', 'cr.user_id', 'cr.location_id', 'cr.created_at', 'u.first_name', 'u.username']);
        $hasDeposits = \Schema::hasTable('cash_deposits');
        foreach ($opened as $n) {
            $prev = \DB::table('cash_registers as cr')->leftJoin('users as u', 'u.id', '=', 'cr.user_id')
                ->where('cr.business_id', $business_id)->where('cr.location_id', $n->location_id)
                ->where('cr.status', 'close')->whereNotNull('cr.closing_amount')
                ->where('cr.closed_at', '<=', $n->created_at)
                ->where('cr.closed_at', '>=', \Carbon\Carbon::parse($n->created_at)->subHours(24))
                ->where(function ($q) { $q->whereNull('cr.closing_note')->orWhere('cr.closing_note', 'not like', '%Auto-closed by system%'); })
                ->orderByDesc('cr.closed_at')->first(['cr.id', 'cr.user_id', 'cr.closing_amount', 'cr.closed_at', 'u.first_name', 'u.username']);
            if (!$prev || (int) $prev->user_id === (int) $n->user_id) continue; // same person, not a handover
            $closeDrop = $hasDeposits ? (float) \DB::table('cash_deposits')->where('cash_register_id', $prev->id)->where('phase', 'close')->sum('amount') : 0.0;
            $openDrop  = $hasDeposits ? (float) \DB::table('cash_deposits')->where('cash_register_id', $n->id)->where('phase', 'open')->sum('amount') : 0.0;
            $initial = (float) \DB::table('cash_register_transactions')->where('cash_register_id', $n->id)->where('transaction_type', 'initial')->sum('amount');
            $left  = (float) $prev->closing_amount - $closeDrop;
            $found = $initial + $openDrop;
            $gap = round($found - $left, 2);
            if ($gap > -self::DRAWER_TOLERANCE) continue; // only drops matter
            $pName = ucfirst(strtolower(trim((string) ($prev->first_name ?: $prev->username))));
            $nName = ucfirst(strtolower(trim((string) ($n->first_name ?: $n->username))));
            $flags[] = [
                'location_id' => $n->location_id,
                'kind'   => 'handover',
                'detail' => 'drawer went from $' . number_format($left, 0) . ' (' . $pName . ' closed ' . \Carbon\Carbon::parse($prev->closed_at)->format('g:ia')
                    . ') to $' . number_format($found, 0) . ' (' . $nName . ' opened ' . \Carbon\Carbon::parse($n->created_at)->format('g:ia') . ')',
                'ask'    => $pName . ' / ' . $nName,
                'note'   => null,
                'amount' => abs($gap),
            ];
        }
        return $flags;
    }

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
                SUM(CASE WHEN pay_method='cash' AND transaction_type <> 'initial' THEN CASE WHEN type='credit' THEN amount ELSE -amount END ELSE 0 END) as cash_net,
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
            // Expected = opening cash + cash in/out during the shift. The
            // 'initial' row IS the opening cash, so cash_net excludes it
            // (it was double-counted before 9/26, making every drawer look
            // $70-$550 short). closing_amount is counted before the safe drop.
            if (!self::FLAG_DRAWER_VARIANCE) continue;
            if (!$row) continue;
            // Collection buys paid in cash come out of the drawer but never
            // write a cash_register_transactions row - pull them from
            // buy_customer_offers for this store during the shift (any
            // ringer, so admin-rung buys still count against the drawer).
            $cashBuys = 0.0;
            if (\Schema::hasTable('buy_customer_offers')) {
                $cashBuys = (float) \DB::table('transactions as t')
                    ->join('buy_customer_offers as o', 'o.accepted_purchase_id', '=', 't.id')
                    ->where('t.business_id', $business_id)
                    ->where('t.type', 'purchase')
                    ->where('o.payment_method', 'cash_in_store')
                    ->where('t.location_id', $r->location_id)
                    ->whereBetween('t.transaction_date', [$r->created_at, $r->closed_at])
                    ->sum('t.final_total');
            }
            // "Use Store Credit" on the POS books the whole sale as cash with
            // a "Store credit used: $X" note - that $X never went in the
            // drawer, so take it back out of expected cash.
            $scCents = 0;
            $txIds = \DB::table('cash_register_transactions')->where('cash_register_id', $r->id)
                ->where('transaction_type', 'sell')->where('pay_method', 'cash')
                ->whereNotNull('transaction_id')->pluck('transaction_id')->all();
            if (!empty($txIds)) {
                foreach (\DB::table('transaction_payments')->whereIn('transaction_id', $txIds)
                    ->where('method', 'cash')->where('note', 'like', '%Store credit used:%')->pluck('note') as $n) {
                    if (preg_match('/store credit used:\s*\$?\s*([0-9]+(?:\.[0-9]{1,2})?)/i', (string) $n, $m)) {
                        $scCents += (int) round(((float) $m[1]) * 100);
                    }
                }
            }
            $expected = (float) $row->opening_cash + (float) $row->cash_net - $cashBuys - $scCents / 100;
            $variance = round((float) $r->closing_amount - $expected, 2);
            // Only shorts matter (Sarah 9/27); a negative expected means the
            // buy was paid from outside the drawer - not a drawer problem.
            if ($variance > -self::DRAWER_TOLERANCE || $expected < 0) continue;
            $flags[] = [
                'location_id' => $r->location_id,
                'kind'   => 'drawer',
                'tiny'   => 'drawer short $' . number_format(abs($variance), 2),
                'detail' => 'drawer count came in $' . number_format(abs($variance), 2) . ' under (counted $'
                    . number_format((float) $r->closing_amount, 0) . ', expected $' . number_format($expected, 0) . ')',
                'mini'   => ($variance < 0 ? 'short ' : 'over ') . '$' . number_format(abs($variance), 2)
                    . ' (counted $' . number_format((float) $r->closing_amount, 2) . ', expected $' . number_format($expected, 2)
                    . ($cashBuys > 0 ? ', after $' . number_format($cashBuys, 2) . ' cash buys' : '')
                    . ', closed ' . \Carbon\Carbon::parse($r->closed_at)->format('g:ia') . ')',
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

    const FATTEEN_SLACK_ID = 'U07QEGGQ7B2';

    /**
     * One short line per issue (Sarah 9/27: "way too many words").
     * Returns per store a list of "Name  <link|what's wrong>" strings;
     * all suggested matches collapse into a single Fatteen line.
     */
    /** Rows [who, what, url] - one per issue; matches collapse to Fatteen. */
    public static function shortRows(array $s): array
    {
        $rows = [];
        $matches = [];
        foreach ($s['items'] as $it) {
            $url = $it['url'] ?? $s['url'];
            $mini = (string) ($it['mini'] ?? '');
            switch ($it['kind']) {
                case 'no_clover':
                    $what = $mini . ' - no Clover charge found' . (!empty($it['is_cash']) ? ' (cash sale - please log cash sales in Clover too)' : '');
                    break;
                case 'no_erp':
                    $what = preg_replace('/ at /', ' charged at ', $mini, 1) . ' - no ERP sale found, please ring the items so stock updates';
                    break;
                case 'mismatch':
                    $what = preg_replace('/^(#\S+) ERP (\S+) vs Clover (\S+)$/', '$1 rung $2, Clover shows $3', $mini);
                    break;
                case 'match':
                    $matches[] = [preg_replace('/^(#\S+) (\S+) = Clover (\S+)$/', '$1 ($2) to Clover charge $3', $mini), $url];
                    continue 2;
                case 'drawer':
                    $what = $it['detail'] ?? ($it['tiny'] ?? 'drawer short');
                    break;
                case 'handover':
                    $what = $it['detail'];
                    break;
                case 'uncounted':
                    $what = 'register never closed/counted';
                    break;
                default:
                    $what = $it['short'] ?? $it['text'];
            }
            $rows[] = [($it['ask'] ?? '') !== '' ? $it['ask'] : '?', $what, $url];
        }
        usort($rows, fn($x, $y) => strcmp($x[0] . $x[1], $y[0] . $y[1]));
        foreach ($matches as [$inv, $url]) {
            $rows[] = ['Fatteen', 'match ' . $inv, $url];
        }
        return $rows;
    }

    private static function shortLines(array $s): array
    {
        $esc = function ($t) { return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '/'], (string) $t); };
        return array_map(fn($r) => $esc($r[0]) . ': <' . $r[2] . '|' . $esc($r[1]) . '>', self::shortRows($s));
    }

    /** Very simple plain text (Sarah 9/27: "much simpler"). */
    public static function formatSlack(array $r): string
    {
        $day = \Carbon\Carbon::parse($r['date'])->format('D n/j');
        if ($r['issue_count'] === 0) {
            return '*' . $day . ' register check:* all good';
        }
        $lines = ['*' . $day . ' register check*'];
        foreach ($r['stores'] as $s) {
            $short = self::shortLines($s);
            if (empty($short)) continue;
            $lines[] = '';
            $lines[] = '*' . $s['name'] . '*';
            foreach ($short as $l) {
                $lines[] = '• ' . $l;
            }
        }
        $anyCash = false;
        foreach ($r['stores'] as $s) {
            foreach ($s['items'] as $it) {
                if ($it['kind'] === 'no_clover' && !empty($it['is_cash'])) $anyCash = true;
            }
        }
        $lines[] = '';
        if ($anyCash) {
            $lines[] = 'Reminder: please log cash sales in Clover too.';
        }
        $lines[] = '<@' . self::FATTEEN_SLACK_ID . '> can you check these with the team? Most are quick fixes.';
        return implode("\n", $lines);
    }

    /** Plain text only - no rich layout. */
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
