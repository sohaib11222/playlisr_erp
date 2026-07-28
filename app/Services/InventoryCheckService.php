<?php

namespace App\Services;

use App\BusinessLocation;
use App\Category;
use App\ChartPick;
use App\Contact;
use App\CustomerWant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cache-bust: deploy 2026-04-29 to ensure FPM OPcache reloads chartPickReason
// signature change (?array $match). Sarah saw stale "must be of the type array"
// errors after the fix landed because OPcache held the pre-fix bytecode.

class InventoryCheckService
{
    /** @var NivessaEventsFetcher */
    protected $eventsFetcher;

    public function __construct(NivessaEventsFetcher $eventsFetcher)
    {
        $this->eventsFetcher = $eventsFetcher;
    }

    /**
     * Resolve preset into filter defaults (location_id, category_ids, dates, supplier_id).
     */
    public function resolvePreset(int $business_id, string $presetKey): array
    {
        $presets = config('inventory_check.presets', []);
        if (!isset($presets[$presetKey])) {
            return [];
        }
        $p = $presets[$presetKey];
        $out = [
            'preset_key' => $presetKey,
            'sale_days' => $p['sale_days'] ?? 90,
        ];

        $locPattern = $p['location_name_pattern'] ?? '';
        if ($locPattern !== '') {
            $loc = BusinessLocation::where('business_id', $business_id)
                ->where('name', 'like', '%' . $locPattern . '%')
                ->orderBy('id')
                ->first();
            if ($loc) {
                $out['location_id'] = $loc->id;
            }
        }

        $catPattern = $p['category_name_pattern'] ?? '';
        if ($catPattern !== '') {
            $ids = Category::where('business_id', $business_id)
                ->where('category_type', 'product')
                ->where('name', 'like', '%' . $catPattern . '%')
                ->pluck('id')
                ->all();
            $out['category_ids'] = $ids;
        }

        $days = (int) ($p['sale_days'] ?? 90);
        $out['sale_end'] = Carbon::now()->format('Y-m-d');
        $out['sale_start'] = Carbon::now()->subDays($days)->format('Y-m-d');

        $supplierPattern = config('inventory_check.default_supplier_name_pattern', 'AMS');
        if ($supplierPattern) {
            $sup = Contact::where('business_id', $business_id)
                ->where(function ($q) {
                    $q->where('type', 'supplier')->orWhere('type', 'both');
                })
                ->where(function ($q) use ($supplierPattern) {
                    $q->where('name', 'like', '%' . $supplierPattern . '%')
                        ->orWhere('supplier_business_name', 'like', '%' . $supplierPattern . '%');
                })
                ->orderBy('id')
                ->first();
            if ($sup) {
                $out['supplier_id'] = $sup->id;
            }
        }

        return $out;
    }

    /**
     * Build the bucketed "Order for this week" view.
     *
     * @param  array<string,mixed>  $input
     * @return array{buckets: array<string,array>, meta: array<string,mixed>}
     */
    /**
     * Current week's purchase budget + actual spend, mirroring the schedule
     * that the product purchase report uses. Returns null outside the
     * 13-week window. Pulled in here so the ICA page can show "you have
     * $X left this week" right next to the reorder list — buying decisions
     * stay anchored to the cash plan instead of running the export blind.
     */
    /**
     * Out-of-band purchase entries logged from the ICA page — collections
     * Jon buys on the floor, cash buys that haven't gone through
     * /buy-from-customer yet, etc. JSON-on-disk so no migration and no
     * risk of crossing into the formal purchase_lines accounting flow.
     */
    public function loadManualBudgetEntries(int $business_id): array
    {
        $path = storage_path('app/ica-manual-budget-entries-' . $business_id . '.json');
        if (!is_file($path)) return [];
        try {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function saveManualBudgetEntries(int $business_id, array $entries): void
    {
        $path = storage_path('app/ica-manual-budget-entries-' . $business_id . '.json');
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public function currentPurchaseBudget(int $business_id, $permittedLocations): ?array
    {
        $schedule = $this->purchaseBudgetSchedule();
        $today = Carbon::now()->format('Y-m-d');
        $week = null;
        foreach ($schedule as $w) {
            if ($today >= $w['start'] && $today <= $w['end']) {
                $week = $w;
                break;
            }
        }
        if (!$week) {
            return null;
        }

        // NEW spend now counts distributor orders at the OPEN 'ordered' stage
        // only — see the $newRows classification loop below. (Sarah 2026-07-19,
        // supersedes the 2026-06-20 "count every status" rule for New.)
        //
        // $countableStatuses drives the category BREAKDOWN reconciliation query
        // (line ~573) only, NOT the New/Used spend split, so it still spans every
        // status a real weekly outlay can carry.
        $countableStatuses = ['received', 'draft', 'ordered', 'pending'];

        // Top-line spend is computed from the per-transaction loop below, which
        // counts ONLY real weekly outlays: distributor orders (New) and
        // buy-from-customer collections (Used). Mass-add warehouse back-dating
        // and other non-purchases are excluded. (Sarah 2026-06-21 — previously
        // this summed every purchase txn, which inflated the week by thousands.)
        $spentFromTransactions = 0.0;

        // ── Used vs New split (Sarah 2026-05-27) ──────────────────────
        // Sub-budget the weekly cap into 30-40% used / 60-70% new, per
        // Jon's Q3 cash-flow plan. Locked at 35/65 (mid-range) for now.
        // Spend is bucketed by category name LIKE '%used%' on the purchase
        // line's product — same convention the `hot_used_oos` bucket
        // already relies on (config/inventory_check.php).
        $usedCatIds = $this->usedCategoryIds($business_id);

        // ── What counts as weekly spend (Sarah 2026-06-21, settled) ──────
        // Two distinct transaction types, matching how the store actually works:
        //  • NEW  = PURCHASE ORDERS (type 'purchase_order') — the orders we place
        //           with distributors/sources (AMS, CMURDA, jonmurda, …). This is
        //           the "Purchase Orders" screen Sarah trusts as ground truth.
        //  • USED = BUY-FROM-CUSTOMER collections (type 'purchase' built from
        //           added_via='buy_from_customer' products) — in-store used buys.
        // Plain 'purchase' records that are NOT buy-from-customer are warehouse
        // stock being date-stamped (mass-add "send to add purchase") and are NOT
        // counted. No supplier allow-list, no proration — just the two real
        // sources, each summed by final_total.
        $userNameSql = "TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.surname,''))) as entered_by_name";

        // Pull every purchase this week that has a CONTACT on it, then classify:
        //   • NEW  — a distributor (AMS, SHein, DeeJay/Vinylfuture, Alliance,
        //            Posters Wholesale, …) via distributorSuppliers().
        //   • USED — a buy-from-customer collection ONLY (a line whose product is
        //            added_via='buy_from_customer'). Real in-store used buys.
        // Everything else is EXCLUDED — in particular warehouse staff (Manolo,
        // etc.) adding mass-add products and "sending to purchase" just to assign
        // a purchase price. Those are NOT money spent this week. (Sarah 2026-06-22)
        $purQ = DB::table('transactions as t')
            ->join('contacts as ct', 'ct.id', '=', 't.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->whereBetween(DB::raw('date(t.transaction_date)'), [$week['start'], $week['end']]);
        if ($permittedLocations !== 'all') {
            $purQ->whereIn('t.location_id', $permittedLocations);
        }
        $purRows = $purQ->selectRaw("t.id, t.location_id, t.final_total, t.ref_no, t.status, t.transaction_date, t.created_at, ct.name as supplier, {$userNameSql}, u.username as entered_by_username")->get();

        // ── Used = ACCEPTED buy-from-customer offers (Jon 2026-06-23) ────────
        // "The accepted ones in the history ARE what we spent." Source the Used
        // number straight from the offer's negotiated payout, NOT the
        // materialized draft purchase: a quick buy where the lines were never
        // priced leaves the purchase at $0, but the offer always carries the
        // agreed cash/credit. Dated by accepted_at (falling back to
        // updated/created for legacy rows). payout_type 'store_credit' → credit
        // payout, otherwise cash.
        $offerQ = DB::table('buy_customer_offers as o')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'o.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->where('o.business_id', $business_id)
            ->where('o.status', 'accepted')
            ->whereBetween(DB::raw('date(COALESCE(o.accepted_at, o.updated_at, o.created_at))'), [$week['start'], $week['end']]);
        if ($permittedLocations !== 'all') {
            $offerQ->whereIn('o.location_id', $permittedLocations);
        }
        $offerRows = $offerQ->selectRaw("o.id, o.location_id, o.payout_type, o.final_offer_cash, o.final_offer_credit, o.accepted_at, o.created_at, o.accepted_purchase_id, COALESCE(ct.name, o.seller_name) as supplier, {$userNameSql}, u.username as entered_by_username")->get();

        // BFC-materialized purchases this week — represented by their offers
        // above, so keep them OUT of the "not counted" tally (they aren't
        // warehouse re-dating). Counted/summed distinctly to avoid the
        // purchase_lines join multiplying final_total.
        $bfcIdsQ = DB::table('transactions as t')
            ->join('purchase_lines as pl', 'pl.transaction_id', '=', 't.id')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->where('p.added_via', 'buy_from_customer')
            ->whereBetween(DB::raw('date(t.transaction_date)'), [$week['start'], $week['end']]);
        if ($permittedLocations !== 'all') {
            $bfcIdsQ->whereIn('t.location_id', $permittedLocations);
        }
        $bfcIdsList = array_values(array_unique(array_map('intval', $bfcIdsQ->distinct()->pluck('t.id')->all())));
        $bfcCount = count($bfcIdsList);
        $bfcSum = $bfcCount ? (float) DB::table('transactions')->whereIn('id', $bfcIdsList)->sum('final_total') : 0.0;

        $spentTxnUsed = 0.0;
        $spentTxnNew = 0.0;
        $perLocUsedNew = [];
        $txnList = [];
        $newRows = collect();
        foreach ($purRows as $r) {
            if ((float) $r->final_total <= 0) { continue; } // skip $0 noise rows
            // New = distributor orders at the OPEN 'ordered' stage ONLY. Once an
            // order is checked in (status 'received') it drops out of the weekly
            // New tally, so the figure reflects only orders still on the books.
            // (Sarah 2026-07-19 — supersedes the 2026-06-20 "count every status"
            // rule for New: received/pending/draft distributor rows no longer
            // count here. Note this figure recovers through the week as orders
            // are received.)
            if ($this->isDistributorSupplier($r->supplier)
                && strtolower((string) ($r->status ?? '')) === 'ordered') {
                $newRows->push($r);                       // open distributor order = New
            }
            // else: received/draft distributor rows, BFC purchase (counted via its
            // offer), or warehouse add → not counted as New here.
        }
        $usedRows = $offerRows->filter(function ($o) {
            $amt = ($o->payout_type === 'store_credit') ? (float) $o->final_offer_credit : (float) $o->final_offer_cash;
            return $amt > 0;
        })->values();

        // amount/date/meta vary by source, so addRow takes them explicitly.
        $addRow = function ($amount, $lid, $kind, array $meta) use (&$spentTxnUsed, &$spentTxnNew, &$perLocUsedNew, &$txnList) {
            $ft = (float) $amount;
            $lid = (int) $lid;
            if ($kind === 'used') { $spentTxnUsed += $ft; } else { $spentTxnNew += $ft; }
            if ($lid) {
                if (!isset($perLocUsedNew[$lid])) { $perLocUsedNew[$lid] = ['used' => 0.0, 'new' => 0.0]; }
                $perLocUsedNew[$lid][$kind] += $ft;
            }
            $txnList[] = array_merge([
                'id' => 0,
                'ref_no' => null,
                'date' => null,
                'entered' => null,
                'entered_by' => '',
                'late_days' => 0,
                'location_id' => $lid,
                'supplier' => null,
                'status' => '',
                'total' => round($ft, 2),
                'new_amount' => $kind === 'new' ? round($ft, 2) : 0,
                'used_amount' => $kind === 'used' ? round($ft, 2) : 0,
                'kind' => $kind,
                'view_url' => null,
            ], $meta);
        };
        foreach ($newRows as $r) {
            $enteredBy = trim((string) ($r->entered_by_name ?? '')) ?: ($r->entered_by_username ?? '');
            $addRow((float) $r->final_total, (int) $r->location_id, 'new', [
                'id' => (int) $r->id,
                'ref_no' => $r->ref_no,
                'date' => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : null,
                'entered' => $r->created_at ? substr((string) $r->created_at, 0, 10) : null,
                'entered_by' => $enteredBy,
                'late_days' => ($r->transaction_date && $r->created_at)
                    ? (int) round((strtotime(substr((string) $r->created_at, 0, 10)) - strtotime(substr((string) $r->transaction_date, 0, 10))) / 86400)
                    : 0,
                'supplier' => $r->supplier,
                'status' => $r->status ?? '',
                'view_url' => url('/purchases/' . $r->id),
            ]);
        }
        foreach ($usedRows as $o) {
            $amt = ($o->payout_type === 'store_credit') ? (float) $o->final_offer_credit : (float) $o->final_offer_cash;
            $enteredBy = trim((string) ($o->entered_by_name ?? '')) ?: ($o->entered_by_username ?? '');
            $accDate = $o->accepted_at ? substr((string) $o->accepted_at, 0, 10)
                : ($o->created_at ? substr((string) $o->created_at, 0, 10) : null);
            $addRow($amt, (int) $o->location_id, 'used', [
                'id' => (int) ($o->accepted_purchase_id ?: $o->id),
                'ref_no' => 'BFC-' . str_pad((string) $o->id, 6, '0', STR_PAD_LEFT), // mirrors BuyCustomerOffer accessor

                'date' => $accDate,
                'entered' => $accDate,
                'entered_by' => $enteredBy,
                'late_days' => 0,
                'supplier' => $o->supplier,
                'status' => 'accepted',
                'view_url' => $o->accepted_purchase_id
                    ? url('/purchases/' . $o->accepted_purchase_id)
                    : url('/buy-from-customer/history'),
            ]);
        }
        // Used group first, then newest first within each group.
        usort($txnList, function ($a, $b) {
            $ka = $a['kind'] === 'used' ? 0 : 1;
            $kb = $b['kind'] === 'used' ? 0 : 1;
            if ($ka !== $kb) return $ka <=> $kb;
            return ($b['date'] ?? '') <=> ($a['date'] ?? '');
        });
        $spentFromTransactions = $spentTxnUsed + $spentTxnNew;

        // Warehouse 'add purchase' records that are NOT collections — shown as
        // "not counted" for transparency.
        $allPurchAgg = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->whereBetween(DB::raw('date(t.transaction_date)'), [$week['start'], $week['end']]);
        if ($permittedLocations !== 'all') {
            $allPurchAgg->whereIn('t.location_id', $permittedLocations);
        }
        $allPurchAgg = $allPurchAgg->selectRaw('COUNT(*) as c, COALESCE(SUM(t.final_total),0) as s')->first();
        // Excluded = all purchases this week minus New distributor orders and BFC
        // collection purchases (counted via their accepted offers) = warehouse
        // re-dating and contactless / $0 rows.
        $excludedCount = max(0, (int) $allPurchAgg->c - $newRows->count() - $bfcCount);
        $excludedTotal = max(0, round((float) $allPurchAgg->s - $spentTxnNew - $bfcSum, 2));

        // Add manual budget entries logged from the ICA "+ Log a buy"
        // form (e.g. Jon's $2000 collection on Sunday that hasn't been
        // entered through the formal purchase flow yet). Same week window.
        // 2026-05-27: now also carries a 'kind' field (used|new); default
        // 'new' for legacy entries saved before the toggle existed.
        $manualEntries = $this->loadManualBudgetEntries($business_id);
        $spentFromManual = 0.0;
        $spentManualUsed = 0.0;
        $spentManualNew = 0.0;
        $manualThisWeek = [];
        foreach ($manualEntries as $e) {
            if (!is_array($e) || empty($e['date'])) continue;
            $date = substr((string) $e['date'], 0, 10);
            if ($date < $week['start'] || $date > $week['end']) continue;
            $amt = (float) ($e['amount'] ?? 0);
            $kind = strtolower((string) ($e['kind'] ?? 'new'));
            if ($kind !== 'used') $kind = 'new';
            // Normalise so the chip renders the badge consistently.
            $e['kind'] = $kind;
            $spentFromManual += $amt;
            if ($kind === 'used') {
                $spentManualUsed += $amt;
            } else {
                $spentManualNew += $amt;
            }
            $manualThisWeek[] = $e;
        }

        $spent = $spentFromTransactions + $spentFromManual;
        $budget = (float) $week['budget'];
        $remaining = $budget - $spent;
        $pct = $budget > 0 ? min(100, ($spent / $budget) * 100) : 0;

        // Per-store spend chips — built from the SAME counted buys as the budget
        // (distributor New + collection Used), so the chips match the bars. Names
        // come from business_locations. (Sarah 2026-06-21: was a broad SUM that
        // included the excluded warehouse back-dating.)
        $locNames = DB::table('business_locations')
            ->where('business_id', $business_id)
            ->pluck('name', 'id');
        $perLocation = [];
        foreach ($perLocUsedNew as $lid => $un) {
            $perLocation[] = [
                'location_id' => (int) $lid,
                'name' => $locNames[$lid] ?? ('Location #' . $lid),
                'spent' => round((float) ($un['used'] + $un['new']), 2),
            ];
        }
        usort($perLocation, fn ($a, $b) => $b['spent'] <=> $a['spent']);

        // Resolve a readable store name onto each audited transaction.
        $locNameMap = [];
        foreach ($perLocation as $loc) {
            $locNameMap[$loc['location_id']] = $loc['name'];
        }
        foreach ($txnList as &$tx) {
            $tx['location'] = $locNameMap[$tx['location_id']] ?? ('Location #' . $tx['location_id']);
        }
        unset($tx);

        // ── Duplicate-entry detector (Sarah 2026-06-19) ───────────────
        // Same shipment entered twice double-counts real spend. Flag two ways
        // within the same store + same day:
        //   • exact — identical totals (clean re-entry)
        //   • near  — large totals (≥$500) within 3% of each other (the same
        //             buy re-keyed with a slightly different total, e.g. once
        //             typed in and once via mass-add)
        $byStoreDay = [];
        foreach ($txnList as $i => $tx) {
            $g = $tx['location_id'] . '|' . ($tx['date'] ?? '');
            $byStoreDay[$g][] = $i;
        }
        // Tight matching to avoid flagging coincidentally-similar buys: only
        // pair transactions ≥$100 on the same store+day when EITHER the totals
        // are near-identical (≤0.5% apart — a re-keyed shipment), OR they share
        // the same supplier and are within 2%. Two unrelated buys rarely land
        // within half a percent of each other.
        $dupeMin = 100.0;
        $dupeFlag = array_fill(0, count($txnList), false);
        foreach ($byStoreDay as $idxs) {
            if (count($idxs) < 2) continue;
            for ($a = 0; $a < count($idxs); $a++) {
                for ($b = $a + 1; $b < count($idxs); $b++) {
                    $ta = $txnList[$idxs[$a]]['total'];
                    $tb = $txnList[$idxs[$b]]['total'];
                    if (min($ta, $tb) < $dupeMin) continue;
                    $diff = abs($ta - $tb);
                    $supA = trim((string) ($txnList[$idxs[$a]]['supplier'] ?? ''));
                    $supB = trim((string) ($txnList[$idxs[$b]]['supplier'] ?? ''));
                    $sameSupplier = $supA !== '' && strcasecmp($supA, $supB) === 0;
                    $nearIdentical = $diff <= 0.005 * max($ta, $tb);
                    $sameSupplierClose = $sameSupplier && $diff <= 0.02 * max($ta, $tb);
                    if ($nearIdentical || $sameSupplierClose) {
                        $dupeFlag[$idxs[$a]] = true;
                        $dupeFlag[$idxs[$b]] = true;
                    }
                }
            }
        }
        // Redundant $ per store+day cluster = everything beyond the largest copy.
        $dupeGroups = 0;
        $dupeRedundantAmount = 0.0;
        foreach ($byStoreDay as $idxs) {
            $flagged = array_values(array_filter($idxs, fn ($i) => $dupeFlag[$i]));
            if (count($flagged) < 2) continue;
            $dupeGroups++;
            $totals = array_map(fn ($i) => $txnList[$i]['total'], $flagged);
            $dupeRedundantAmount += array_sum($totals) - max($totals);
        }
        foreach ($txnList as $i => &$tx) {
            $tx['maybe_dupe'] = $dupeFlag[$i];
        }
        unset($tx);

        // Used/New sub-budgets (35/65 mid-range of the 30-40 / 60-70 plan).
        $usedBudget = round($budget * 0.35, 2);
        $newBudget = round($budget - $usedBudget, 2); // exact complement
        $usedSpent = $spentTxnUsed + $spentManualUsed;
        $newSpent = $spentTxnNew + $spentManualNew;
        $usedPct = $usedBudget > 0 ? min(100, ($usedSpent / $usedBudget) * 100) : 0;
        $newPct = $newBudget > 0 ? min(100, ($newSpent / $newBudget) * 100) : 0;

        // ── Per-store sub-budgets (Sarah 2026-06-17) ──────────────────
        // Fixed share of the weekly budget per store, then each store's
        // share split 35% Used / 65% New like the store-wide cap. Spend
        // is the formal-purchase used/new actuals bucketed by t.location_id.
        // Manual "+ Log a buy" entries carry no location, so they're not
        // counted against any single store here.
        $subBucket = function ($budgetAmt, $spentAmt) {
            $budgetAmt = round($budgetAmt, 2);
            $spentAmt = round($spentAmt, 2);
            return [
                'budget' => $budgetAmt,
                'spent' => $spentAmt,
                'remaining' => round($budgetAmt - $spentAmt, 2),
                'pct_spent' => $budgetAmt > 0 ? round(min(100, ($spentAmt / $budgetAmt) * 100), 1) : 0,
                'over_budget' => $spentAmt > $budgetAmt,
            ];
        };
        // Only surface the store(s) the viewer is permitted for, so a
        // store-scoped cashier sees just their own store's sub-budget on the
        // buy form. Admins (permitted_locations = 'all') still see every store.
        $permittedNames = null;
        if ($permittedLocations !== 'all') {
            $permittedNames = BusinessLocation::whereIn('id', (array) $permittedLocations)
                ->pluck('name')
                ->map(fn ($n) => strtolower((string) $n))
                ->all();
        }
        $perStore = [];
        foreach ($this->storeBudgetSplits() as $split) {
            if ($permittedNames !== null) {
                $allowed = false;
                foreach ($permittedNames as $pn) {
                    if (stripos($pn, $split['match']) !== false) { $allowed = true; break; }
                }
                if (!$allowed) { continue; }
            }
            $storeBudget = round($budget * $split['pct'], 2);
            // Per-week override (Sarah 2026-06-23): a single week can pin a
            // store's New (or Used) dollar amount, with the other side taking
            // the remainder of that store's pot. Falls back to the standing
            // 35% Used / 65% New default when no override is set.
            $override = $this->storeBudgetOverrides()[$week['week_no']][$split['match']] ?? null;
            if ($override && isset($override['new'])) {
                $storeNewBudget = round((float) $override['new'], 2);
                $storeUsedBudget = round(max(0.0, $storeBudget - $storeNewBudget), 2);
            } elseif ($override && isset($override['used'])) {
                $storeUsedBudget = round((float) $override['used'], 2);
                $storeNewBudget = round(max(0.0, $storeBudget - $storeUsedBudget), 2);
            } else {
                $storeUsedBudget = round($storeBudget * 0.35, 2);
                $storeNewBudget = round($storeBudget - $storeUsedBudget, 2);
            }
            $uSpent = 0.0;
            $nSpent = 0.0;
            foreach ($perLocation as $loc) {
                if (stripos($loc['name'], $split['match']) !== false) {
                    $lid = $loc['location_id'];
                    $uSpent += $perLocUsedNew[$lid]['used'] ?? 0.0;
                    $nSpent += $perLocUsedNew[$lid]['new'] ?? 0.0;
                }
            }
            // The store budget is one shared pot: when New runs over its share,
            // that overspend eats into what's left for Used. Shrink the Used cap
            // by New's overage, floored at $0 (Sarah 2026-06-19).
            // One shared store pot: New's overspend eats into the room left for
            // Used. Keep the Used CAP at its honest 35% ($storeUsedBudget) so the
            // bar and the "35% cap" caption agree; instead shrink what's LEFT and
            // gray out the slice New consumed. The three pieces always reconcile:
            // spent + eaten-by-New + actually-left = full cap. Used only reads
            // "over" if it blows its OWN 35% cap, never from New's overspend.
            $newOverage = max(0.0, $nSpent - $storeNewBudget);
            $usedFullCap = round($storeUsedBudget, 2);
            $usedRoom = max(0.0, $usedFullCap - $uSpent);            // room before New eats
            $usedEaten = round(min($usedRoom, $newOverage), 2);      // New's bite into Used room
            $usedLeft = round(max(0.0, $usedRoom - $newOverage), 2); // what's actually left
            if ($uSpent > $usedFullCap) {
                $usedRemaining = round($usedFullCap - $uSpent, 2);   // genuine Used overspend (negative)
                $usedOver = true;
            } else {
                $usedRemaining = $usedLeft;                          // reduced by New, floored at 0
                $usedOver = false;
            }
            $perStore[] = [
                'label' => $split['label'],
                'pct_of_total' => $split['pct'],
                'budget' => $storeBudget,
                'used' => [
                    'budget' => $usedFullCap,
                    'spent' => round($uSpent, 2),
                    'remaining' => $usedRemaining,
                    'pct_spent' => $usedFullCap > 0 ? round(min(100, ($uSpent / $usedFullCap) * 100), 1) : 0,
                    'over_budget' => $usedOver,
                ],
                'used_cap_full' => $usedFullCap,
                'used_eaten' => $usedEaten,
                'new' => $subBucket($storeNewBudget, $nSpent),
            ];
        }

        // ── Category breakdown (Sarah 2026-06-19) ─────────────────────
        // So we can SEE what's landing in New vs Used this week, grouped by the
        // product's category (+ how it was added). Same classification as the
        // split above: a row counts as Used if its category name contains
        // "used" OR it came in through the buy-from-customer form.
        $breakdownQ = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->leftJoin('products as p', 'p.id', '=', 'pl.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->whereIn('t.status', $countableStatuses)
            ->whereBetween(DB::raw('date(t.transaction_date)'), [$week['start'], $week['end']]);
        if ($permittedLocations !== 'all') {
            $breakdownQ->whereIn('t.location_id', $permittedLocations);
        }
        $breakdownRows = $breakdownQ
            ->selectRaw("p.category_id as category_id, COALESCE(c.name, '(no category)') as category_name, p.added_via as added_via, SUM(pl.quantity * pl.purchase_price_inc_tax) as amount, COUNT(DISTINCT t.id) as txns")
            ->groupBy('p.category_id', 'c.name', 'p.added_via')
            ->get();
        $spendBreakdown = [];
        foreach ($breakdownRows as $r) {
            $isUsed = ($r->category_id !== null && in_array((int) $r->category_id, $usedCatIds, true))
                || $r->added_via === 'buy_from_customer';
            $spendBreakdown[] = [
                'category' => $r->category_name,
                'added_via' => $r->added_via ?: '—',
                'amount' => round((float) $r->amount, 2),
                'txns' => (int) $r->txns,
                'classified' => $isUsed ? 'used' : 'new',
            ];
        }
        usort($spendBreakdown, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'week_no' => $week['week_no'],
            'start' => $week['start'],
            'end' => $week['end'],
            'budget' => $budget,
            'spent' => $spent,
            'spent_from_transactions' => $spentFromTransactions,
            'spent_from_manual' => $spentFromManual,
            'manual_entries_this_week' => $manualThisWeek,
            'per_location' => $perLocation,
            'remaining' => $remaining,
            'pct_spent' => round($pct, 1),
            'over_budget' => $spent > $budget,
            // Used/New split (Sarah 2026-05-27).
            'used' => [
                'budget' => $usedBudget,
                'spent' => round($usedSpent, 2),
                'remaining' => round($usedBudget - $usedSpent, 2),
                'pct_spent' => round($usedPct, 1),
                'over_budget' => $usedSpent > $usedBudget,
            ],
            'new' => [
                'budget' => $newBudget,
                'spent' => round($newSpent, 2),
                'remaining' => round($newBudget - $newSpent, 2),
                'pct_spent' => round($newPct, 1),
                'over_budget' => $newSpent > $newBudget,
            ],
            'used_category_ids' => $usedCatIds,
            'per_store' => $perStore,
            'spend_breakdown' => $spendBreakdown,
            'transactions' => $txnList,
            'dupe_groups' => $dupeGroups,
            'dupe_redundant_amount' => round($dupeRedundantAmount, 2),
            'excluded_count' => $excludedCount,
            'excluded_total' => round($excludedTotal, 2),
        ];
    }

    /**
     * Fixed per-store share of the weekly purchasing budget (Sarah 2026-06-17:
     * Hollywood 75% / Pico 25%). `match` is compared case-insensitively
     * against business_locations.name. Each store's share is then split
     * 35% Used / 65% New, same as the store-wide cap. Edit here to retune —
     * percentages should sum to 1.0.
     */
    private function storeBudgetSplits(): array
    {
        return [
            ['match' => 'hollywood', 'label' => 'Hollywood', 'pct' => 0.75],
            ['match' => 'pico', 'label' => 'Pico', 'pct' => 0.25],
        ];
    }

    /**
     * Per-week, per-store budget overrides. Keyed by week_no (from
     * purchaseBudgetSchedule), then by the store's `match`. Pin an explicit
     * 'new' (or 'used') dollar amount for that store/week; the other side takes
     * the remainder of the store's pot. Weeks/stores not listed keep the
     * standing 35% Used / 65% New default. This is the place to retune a single
     * week without touching the default split.
     *
     * Week 6 (2026-06-22..06-28): Jon needs $6,800 of Hollywood's $8,428.50 pot
     * in New; Used takes the remaining $1,628.50. This week only.
     */
    private function storeBudgetOverrides(): array
    {
        return [
            6 => [
                'hollywood' => ['new' => 6800.0],
            ],
        ];
    }

    /**
     * Categories considered "Used" for purchasing-budget purposes —
     * any product category whose name contains "used" (case-insensitive
     * on MySQL LIKE). Mirrors the convention used by `hot_used_oos` in
     * config/inventory_check.php so the two stay in sync without an
     * explicit flag column. Cached per-request.
     */
    /**
     * Major distributors — purchases from these suppliers are real NEW weekly
     * spend. Add new distributors here (lowercase); matched as whole words
     * against the purchase's supplier/contact name. Anything that is neither a
     * distributor order nor a buy-from-customer collection is NOT counted as
     * weekly spend (e.g. clerks back-dating warehouse stock via mass-add).
     * Sarah 2026-06-21.
     */
    protected function distributorSuppliers(): array
    {
        return ['ams', 'shein', 'dee jay', 'deejay', 'dee-jay', 'vinyl future', 'vinylfuture', 'alliance', 'posters wholesale', 'poster wholesale'];
    }

    protected function isDistributorSupplier(?string $name): bool
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') return false;
        foreach ($this->distributorSuppliers() as $kw) {
            if (preg_match('/(^|[^a-z])' . preg_quote(strtolower($kw), '/') . '([^a-z]|$)/', $name)) {
                return true;
            }
        }
        return false;
    }

    protected function usedCategoryIds(int $business_id): array
    {
        static $cache = [];
        if (isset($cache[$business_id])) return $cache[$business_id];
        $ids = Category::where('business_id', $business_id)
            ->where('category_type', 'product')
            ->where('name', 'like', '%used%')
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $cache[$business_id] = $ids;
        return $ids;
    }

    /**
     * 13-week purchase budget. Source of truth lives in ReportController
     * (product purchase report); copied here so the ICA page doesn't need
     * to reach into the reports controller. Keep in sync when the cash
     * flow plan rolls forward.
     */
    private function purchaseBudgetSchedule(): array
    {
        return [
            ['week_no' => 1,  'start' => '2026-05-18', 'end' => '2026-05-24', 'budget' => 10954],
            ['week_no' => 2,  'start' => '2026-05-25', 'end' => '2026-05-31', 'budget' => 10954],
            ['week_no' => 3,  'start' => '2026-06-01', 'end' => '2026-06-07', 'budget' => 11238],
            ['week_no' => 4,  'start' => '2026-06-08', 'end' => '2026-06-14', 'budget' => 11238],
            ['week_no' => 5,  'start' => '2026-06-15', 'end' => '2026-06-21', 'budget' => 11238],
            ['week_no' => 6,  'start' => '2026-06-22', 'end' => '2026-06-28', 'budget' => 11238],
            ['week_no' => 7,  'start' => '2026-06-29', 'end' => '2026-07-05', 'budget' => 10954],
            ['week_no' => 8,  'start' => '2026-07-06', 'end' => '2026-07-12', 'budget' => 10954],
            ['week_no' => 9,  'start' => '2026-07-13', 'end' => '2026-07-19', 'budget' => 10954],
            ['week_no' => 10, 'start' => '2026-07-20', 'end' => '2026-07-26', 'budget' => 10954],
            ['week_no' => 11, 'start' => '2026-07-27', 'end' => '2026-08-02', 'budget' => 15000],
            ['week_no' => 12, 'start' => '2026-08-03', 'end' => '2026-08-09', 'budget' => 15000],
            ['week_no' => 13, 'start' => '2026-08-10', 'end' => '2026-08-16', 'budget' => 15000],
        ];
    }

    public function buildBuckets(int $business_id, array $input, $permittedLocations): array
    {
        $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
        if (!$locationId) {
            return ['buckets' => [], 'meta' => ['error' => 'location_required']];
        }

        $categoryIds = $this->resolveCategoryIds($input);
        $saleStart = $input['sale_start'] ?? Carbon::now()->subDays(90)->format('Y-m-d');
        $saleEnd = $input['sale_end'] ?? Carbon::now()->format('Y-m-d');

        // Sarah 2026-05-20: page kept hanging on "Building…". Root cause:
        // every secondary bucket ran sync inside buildBuckets, and many do
        // multi-second queries (long_oos_essentials = 365-day scan,
        // top_artists = 90-day scan + 4-way join, 3 chart_picks buckets
        // each iterate the week's picks doing product lookups). Now ONLY
        // fast_oos + customer_wants run sync — both are cheap. Everything
        // else is a lazy placeholder fetched by JS after the page paints.
        $buckets = [
            'fast_oos' => $this->bucketFastOos($business_id, $locationId, $permittedLocations),
            'customer_wants' => $this->bucketCustomerWants($business_id, $locationId),
            'street_pulse' => $this->lazyPlaceholder('Street Pulse picks'),
            'universal_top' => $this->lazyPlaceholder('Universal top'),
            'apple_music_top' => $this->lazyPlaceholder('Apple Music top 100'),
            'top_artist_new_releases' => $this->lazyPlaceholder('New releases from your top artists'),
            'events_upcoming' => $this->lazyPlaceholder('Upcoming events — stock up'),
            'long_oos_essentials' => $this->lazyPlaceholder('Long out-of-stock essentials'),
            'hot_used_oos' => $this->lazyPlaceholder('Hot used, currently out'),
            'manager_picks' => $this->lazyPlaceholder('Manager picks'),
            'ume_spotlights' => $this->lazyPlaceholder('UMe Update — release spotlights'),
            'abc_a_restock' => $this->lazyPlaceholder('A-class items — restock priority'),
            'seasonal' => $this->lazyPlaceholder('Seasonal — stock up ahead of the season'),
            'accessories_low' => $this->lazyPlaceholder('Accessories — restock cleaning kits'),
            'frozen_inventory' => $this->lazyPlaceholder('Frozen inventory — DO NOT reorder'),
        ];

        // Optionally filter buckets to categories if the user passed category_ids
        if (!empty($categoryIds)) {
            foreach ($buckets as $key => $bucket) {
                $buckets[$key]['items'] = array_values(array_filter($bucket['items'], function ($it) use ($categoryIds) {
                    return empty($it['category_id']) || in_array((int) $it['category_id'], $categoryIds, true);
                }));
                $buckets[$key]['count'] = count($buckets[$key]['items']);
            }
        }

        return [
            'buckets' => $buckets,
            'meta' => [
                'location_id' => $locationId,
                'category_ids' => $categoryIds,
                'sale_start' => $saleStart,
                'sale_end' => $saleEnd,
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
        ];
    }

    protected function lazyPlaceholder(string $label): array
    {
        return [
            'label' => $label,
            'why' => 'Loading…',
            'items' => [],
            'count' => 0,
            'lazy' => true,
        ];
    }

    /**
     * The slow buckets, computed in one server-side pass so JS only has
     * to fire one extra request after the initial fast_oos render.
     * Returns the same key/shape as buildBuckets so the caller can splice
     * results directly into lastResult.buckets[*].
     */
    public function buildSecondaryBuckets(int $business_id, int $locationId, $permittedLocations): array
    {
        // Cache for 5 min per (business, location) — same pattern as
        // fast_oos. Chart picks alone runs ~1000 per-row SQL lookups
        // and long_oos does a 365-day aggregation, so first build is
        // 20-40s but a re-click is instant.
        $cacheKey = 'ica_secondary_' . $business_id . '_' . $locationId;
        if (filter_var(request()->input('nocache'), FILTER_VALIDATE_BOOLEAN)) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($business_id, $locationId, $permittedLocations) {
                return $this->buildSecondaryBucketsUncached($business_id, $locationId, $permittedLocations);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA secondary cache failed', ['err' => $e->getMessage()]);
            return $this->buildSecondaryBucketsUncached($business_id, $locationId, $permittedLocations);
        }
    }

    protected function buildSecondaryBucketsUncached(int $business_id, int $locationId, $permittedLocations): array
    {
        // Wrap each bucket independently so a single failure doesn't take
        // out the rest — Sarah hit a "all stuck on Loading…" 2026-05-20
        // where one bucket exception cascaded.
        $topArtists = [];
        try {
            $topArtists = $this->getTopArtists($business_id, $locationId, $permittedLocations);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA getTopArtists failed', ['err' => $e->getMessage()]);
        }

        $safe = function (string $key, string $label, callable $fn) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('ICA bucket failed: ' . $key, [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
                return [
                    'label' => $label,
                    'why' => 'Failed to load: ' . $e->getMessage(),
                    'items' => [], 'count' => 0,
                    'empty_reason' => 'fetch_error',
                ];
            }
        };

        return [
            'street_pulse' => $safe('street_pulse', 'Street Pulse picks', fn () => $this->bucketChartPicks($business_id, $locationId, 'street_pulse', $topArtists, $permittedLocations)),
            'universal_top' => $safe('universal_top', 'Universal top', fn () => $this->bucketChartPicks($business_id, $locationId, 'universal_top', $topArtists, $permittedLocations)),
            'apple_music_top' => $safe('apple_music_top', 'Apple Music top 100', fn () => $this->bucketChartPicks($business_id, $locationId, 'apple_music_top', $topArtists, $permittedLocations)),
            'top_artist_new_releases' => $safe('top_artist_new_releases', 'New releases from your top artists', fn () => $this->bucketTopArtistNewReleases($business_id, $locationId, $topArtists, $permittedLocations)),
            'long_oos_essentials' => $safe('long_oos_essentials', 'Long out-of-stock essentials', fn () => $this->bucketLongOosEssentials($business_id, $locationId, $permittedLocations)),
            'hot_used_oos' => $safe('hot_used_oos', 'Hot used, currently out', fn () => $this->bucketHotUsedOos($business_id, $locationId, $permittedLocations)),
        ];
    }

    /** Public alias for the lazy ABC-restock endpoint. */
    public function bucketAbcARestockPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        $abcMap = $this->computeAbcMap($business_id);
        return $this->bucketAbcARestock($business_id, $locationId, $abcMap, $permittedLocations);
    }

    /** Public alias for the lazy frozen-inventory endpoint. */
    public function bucketFrozenInventoryPublic(int $business_id, int $locationId, $permittedLocations, ?int $daysOverride = null): array
    {
        return $this->bucketFrozenInventory($business_id, $locationId, $permittedLocations, $daysOverride);
    }

    public function loadFrozenCorrections(int $business_id): array
    {
        $path = storage_path('app/ica-frozen-corrections-' . $business_id . '.json');
        if (!is_file($path)) return [];
        try {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Public alias for the lazy manager-picks endpoint. */
    public function bucketManagerPicksPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketManagerPicks($business_id, $locationId, $permittedLocations);
    }

    // ── Supplier price feeds (AMS / Alliance / Secretly / Beggars / Red Eye / VP) ──

    public function knownSuppliers(): array
    {
        return (array) config('inventory_check.buckets.supplier_feeds', []);
    }

    // ── Per-supplier credentials (encrypted, stored on disk) ──────────
    // Sarah 2026-05-21: doesn't have SSH/Sohaib access — manages portal
    // logins from the UI instead. Each {biz, supplier_key} pair persists
    // as Crypt::encryptString(json) so the file at rest is gibberish.

    protected function supplierCredentialsPath(int $business_id, string $supplierKey): string
    {
        return storage_path('app/supplier-creds-' . $business_id . '-' . $supplierKey . '.enc');
    }

    /**
     * @return array<string,string> e.g. ['user' => '131715', 'pass' => '...']
     */
    public function loadSupplierCredentials(int $business_id, string $supplierKey): array
    {
        $path = $this->supplierCredentialsPath($business_id, $supplierKey);
        if (!is_file($path)) return [];
        try {
            $plain = \Illuminate\Support\Facades\Crypt::decryptString((string) file_get_contents($path));
            $json = json_decode($plain, true);
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Supplier creds decrypt failed', ['err' => $e->getMessage()]);
            return [];
        }
    }

    public function saveSupplierCredentials(int $business_id, string $supplierKey, array $creds): void
    {
        $path = $this->supplierCredentialsPath($business_id, $supplierKey);
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        // Drop any blank fields so partial updates don't wipe other keys.
        $clean = [];
        foreach ($creds as $k => $v) {
            if ($v === null || $v === '') continue;
            $clean[$k] = (string) $v;
        }
        $existing = $this->loadSupplierCredentials($business_id, $supplierKey);
        $merged = array_merge($existing, $clean);
        $merged['_updated_at'] = \Carbon\Carbon::now()->toIso8601String();
        $cipher = \Illuminate\Support\Facades\Crypt::encryptString(json_encode($merged));
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $cipher);
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }

    /**
     * Status snapshot for the UI — never returns the actual values.
     * Just reports which keys are configured + when last saved.
     */
    public function supplierCredentialsStatus(int $business_id, string $supplierKey): array
    {
        $creds = $this->loadSupplierCredentials($business_id, $supplierKey);
        $set = [];
        foreach ($creds as $k => $v) {
            if ($k === '_updated_at') continue;
            $set[$k] = $v !== null && $v !== '';
        }
        return [
            'configured_keys' => $set,
            'configured' => !empty($set),
            'updated_at' => $creds['_updated_at'] ?? null,
        ];
    }

    protected function supplierFeedPath(int $business_id, string $supplierKey): string
    {
        return storage_path('app/supplier-prices-' . $business_id . '-' . $supplierKey . '.json');
    }

    public function loadSupplierFeed(int $business_id, string $supplierKey): array
    {
        $path = $this->supplierFeedPath($business_id, $supplierKey);
        if (!is_file($path)) return [];
        try {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function saveSupplierFeed(int $business_id, string $supplierKey, array $payload): void
    {
        $path = $this->supplierFeedPath($business_id, $supplierKey);
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    /**
     * Summary of every uploaded supplier feed for a business: rows uploaded,
     * date imported, source filename. Used by the More Options panel to
     * show "AMS · 1,432 rows · loaded 2026-05-19" next to each upload slot.
     */
    public function supplierFeedSummary(int $business_id): array
    {
        $statusPath = storage_path('app/supplier-fetch-status-' . $business_id . '.json');
        $status = [];
        if (is_file($statusPath)) {
            try {
                $status = json_decode((string) file_get_contents($statusPath), true) ?: [];
            } catch (\Throwable $e) {
                $status = [];
            }
        }
        $out = [];
        foreach ($this->knownSuppliers() as $key => $meta) {
            $feed = $this->loadSupplierFeed($business_id, $key);
            $out[$key] = [
                'label' => $meta['label'] ?? $key,
                'notes' => $meta['notes'] ?? '',
                'imported_at' => $feed['imported_at'] ?? null,
                'source_file' => $feed['source_file'] ?? null,
                'row_count' => isset($feed['rows']) && is_array($feed['rows']) ? count($feed['rows']) : 0,
                'auto_fetch' => $status[$key] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Parse a supplier-supplied catalog file (xlsx/csv/tsv) into a flat
     * row list normalized to {artist, title, format, cost, upc}. Header
     * detection is fuzzy — different suppliers ship different column
     * names ("Cost", "Wholesale", "Net Price", "Price per CD", "List
     * Price"). Defaults to picking the cheapest numeric column when
     * multiple price-like headers are present.
     *
     * Returns ['rows' => [...], 'header_map' => [...], 'sample_rows' => N].
     */
    public function parseSupplierFeedFile(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = [];
        if (in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            $rows = $this->readSupplierCsv($path, $ext === 'tsv' ? "\t" : null);
        } else {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            } catch (\Throwable $e) {
                return ['rows' => [], 'error' => 'load_failed: ' . $e->getMessage()];
            }
            // Walk all sheets and merge — many supplier files are
            // single-sheet but some put format on separate tabs.
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                $sheetRows = $sheet->toArray(null, true, true, false);
                $defaultFormat = $this->guessFormatFromSheetName($sheetName);
                $parsed = $this->normalizeSupplierRows($sheetRows, $defaultFormat);
                foreach ($parsed as $r) $rows[] = $r;
            }
        }
        return ['rows' => $rows, 'row_count' => count($rows)];
    }

    protected function readSupplierCsv(string $path, ?string $delim): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) return [];
        if ($delim === null) {
            $sample = fread($fh, 4096);
            rewind($fh);
            if (substr_count($sample, "\t") > substr_count($sample, ',')) $delim = "\t";
            elseif (substr_count($sample, ';') > substr_count($sample, ',')) $delim = ';';
            else $delim = ',';
        }
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim)) !== false) $rows[] = $r;
        fclose($fh);
        return $this->normalizeSupplierRows($rows, null);
    }

    protected function guessFormatFromSheetName(string $name): ?string
    {
        $l = mb_strtolower($name);
        if (mb_strpos($l, 'vinyl') !== false || mb_strpos($l, 'lp') !== false) return 'LP';
        if (mb_strpos($l, 'cd') !== false) return 'CD';
        if (mb_strpos($l, 'cassette') !== false) return 'Cassette';
        return null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  raw 2D cell grid
     * @return array<int, array{artist:?string, title:?string, format:?string, cost:?float, upc:?string}>
     */
    protected function normalizeSupplierRows(array $rows, ?string $defaultFormat): array
    {
        $headerIdx = null;
        $limit = min(count($rows), 10);
        for ($r = 0; $r < $limit; $r++) {
            if (!is_array($rows[$r])) continue;
            $joined = mb_strtolower(implode('|', array_map(fn ($c) => (string) $c, $rows[$r])));
            $hasArtist = mb_strpos($joined, 'artist') !== false || mb_strpos($joined, 'performer') !== false;
            $hasTitle = mb_strpos($joined, 'title') !== false || mb_strpos($joined, 'album') !== false || mb_strpos($joined, 'description') !== false;
            $hasPriceish = mb_strpos($joined, 'price') !== false || mb_strpos($joined, 'cost') !== false || mb_strpos($joined, 'wholesale') !== false || mb_strpos($joined, 'net') !== false || mb_strpos($joined, 'dealer') !== false;
            if (($hasArtist || $hasTitle) && $hasPriceish) { $headerIdx = $r; break; }
        }
        if ($headerIdx === null) return [];

        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[$headerIdx]);
        $find = function (array $needles) use ($headers) {
            foreach ($headers as $i => $h) {
                foreach ($needles as $n) {
                    if ($h === $n || mb_strpos($h, $n) !== false) return $i;
                }
            }
            return null;
        };

        $cArtist = $find(['artist name', 'artist', 'performer']);
        $cTitle = $find(['title', 'album', 'name', 'description']);
        $cFormat = $find(['format', 'configuration', 'config']);
        // Prefer cost/wholesale/dealer over list price — Sarah cares
        // about the wholesale she pays, not retail.
        $cCost = $find(['cost', 'wholesale', 'dealer', 'net price', 'net', 'unit price', 'price']);
        $cUpc = $find(['upc', 'ean', 'barcode']);

        if ($cArtist === null && $cTitle === null) return [];

        $out = [];
        $count = count($rows);
        for ($r = $headerIdx + 1; $r < $count; $r++) {
            $row = $rows[$r];
            if (!is_array($row)) continue;
            $artist = $cArtist !== null ? trim((string) ($row[$cArtist] ?? '')) : '';
            $title = $cTitle !== null ? trim((string) ($row[$cTitle] ?? '')) : '';
            if ($artist === '' && $title === '') continue;
            $costRaw = $cCost !== null ? $row[$cCost] ?? null : null;
            $cost = null;
            if ($costRaw !== null && $costRaw !== '') {
                $clean = preg_replace('/[^0-9.\-]/', '', (string) $costRaw);
                if ($clean !== '' && is_numeric($clean)) $cost = (float) $clean;
            }
            $fmt = $cFormat !== null ? trim((string) ($row[$cFormat] ?? '')) ?: null : null;
            if ($fmt === null && $defaultFormat !== null) $fmt = $defaultFormat;
            $out[] = [
                'artist' => $artist !== '' ? $artist : null,
                'title' => $title !== '' ? $title : null,
                'format' => $fmt,
                'cost' => $cost,
                'upc' => $cUpc !== null ? (trim((string) ($row[$cUpc] ?? '')) ?: null) : null,
            ];
        }
        return $out;
    }

    /**
     * Walk every uploaded supplier feed for this business and return the
     * cheapest match for (artist, title) — returns ['supplier_key',
     * 'supplier_label', 'cost', 'upc'] or null. Builds an in-memory index
     * once per request via a static cache so it's only one JSON-decode
     * round trip even when iterating 100s of chart picks.
     */
    /**
     * Return EVERY supplier's match for this (artist, title), sorted
     * cheapest first. Lets the UI show AMS / Secretly / Beggars / Redeye /
     * VP prices side-by-side per row so Sarah can pick the cheapest at
     * a glance instead of trusting a single "best" badge.
     *
     * @return array<int, array{supplier_key:string, supplier_label:string, cost:float, upc:?string, format:?string}>
     */
    public function allSupplierPrices(int $business_id, ?string $artist, ?string $title, ?string $format = null, ?string $upc = null): array
    {
        // Build a per-request INDEX of every feed once (keyed by normalized
        // barcode and normalized title), then answer each product with O(1)
        // hash lookups. A linear scan of the feed per product was fine at
        // ~2,700 rows but the full AMS catalog is ~80k rows and the /products
        // page matches ~100 products per load — that would be millions of
        // string ops per page. The index makes it constant-time per product.
        $index = $this->supplierIndex($business_id);
        if (empty($index['suppliers'])) return [];

        // Collect the cheapest matching row per supplier.
        $hits = [];
        $consider = function (array $row) use (&$hits) {
            $cost = isset($row['cost']) ? (float) $row['cost'] : 0.0;
            if ($cost <= 0) return;
            $k = $row['supplier_key'];
            if (!isset($hits[$k]) || $cost < $hits[$k]['cost']) {
                $hits[$k] = $row;
            }
        };

        // 1) Barcode match — strongest. Any real UPC/EAN token in the SKU.
        foreach ($this->skuUpcCandidates($upc) as $u => $_) {
            if (!empty($index['byUpc'][$u])) {
                foreach ($index['byUpc'][$u] as $row) $consider($row);
            }
        }

        // 2) Name match — normalized (punctuation/&/the/and-insensitive) exact
        // title key, then verify the artist is compatible. Handles product
        // names stored either as just the title or as "ARTIST - TITLE".
        foreach ($this->titleKeyCandidates($artist, $title) as [$aNorm, $tKey]) {
            if ($tKey === '' || empty($index['byTitle'][$tKey])) continue;
            foreach ($index['byTitle'][$tKey] as $row) {
                if (!$this->artistMatches($aNorm, $row['artist_norm'] ?? '')) continue;
                $consider($row);
            }
        }

        $out = array_values($hits);
        usort($out, fn ($a, $b) => $a['cost'] <=> $b['cost']);
        return $out;
    }

    /**
     * Per-request index of all supplier feeds:
     *   suppliers: [key => label]
     *   byUpc:     [normUpc  => [row, ...]]
     *   byTitle:   [normTitle => [row, ...]]
     * Each row: supplier_key, supplier_label, cost, upc, format, url, artist_norm.
     */
    protected function supplierIndex(int $business_id): array
    {
        static $idx = [];
        if (isset($idx[$business_id])) return $idx[$business_id];

        $out = ['suppliers' => [], 'byUpc' => [], 'byTitle' => []];
        foreach ($this->knownSuppliers() as $key => $meta) {
            $feed = $this->loadSupplierFeed($business_id, $key);
            if (empty($feed['rows']) || !is_array($feed['rows'])) continue;
            $label = $meta['label'] ?? $key;
            $out['suppliers'][$key] = $label;
            foreach ($feed['rows'] as $row) {
                if (!is_array($row)) continue;
                $cost = isset($row['cost']) ? (float) $row['cost'] : 0.0;
                if ($cost <= 0) continue;
                $entry = [
                    'supplier_key' => $key,
                    'supplier_label' => $label,
                    'cost' => $cost,
                    'upc' => $row['upc'] ?? null,
                    'format' => $row['format'] ?? null,
                    'url' => $row['url'] ?? null,
                    'artist_norm' => $this->normalizeMatchText((string) ($row['artist'] ?? '')),
                ];
                $u = $this->normalizeUpc($row['upc'] ?? null);
                if ($u !== '') $out['byUpc'][$u][] = $entry;
                // Index under the CORE title (parentheticals + format/edition
                // suffixes stripped) AND the full normalized title, so
                // "THRILLER (140G/GATEFOLD)" is findable by "thriller".
                $rawTitle = (string) ($row['title'] ?? '');
                foreach (array_unique(array_filter([$this->coreTitleKey($rawTitle), $this->normalizeMatchText($rawTitle)])) as $tk) {
                    $out['byTitle'][$tk][] = $entry;
                }
            }
        }
        $idx[$business_id] = $out;
        return $out;
    }

    /**
     * Normalized title keys to try for a product, each paired with the
     * normalized artist to verify against. Handles a product name that is just
     * the title AND one baked as "ARTIST - TITLE" / "TITLE / ARTIST".
     *
     * @return array<int, array{0:string,1:string}> [artistNorm, titleKey]
     */
    protected function titleKeyCandidates(?string $artist, ?string $title): array
    {
        $artistNorm = $this->normalizeMatchText((string) $artist);
        $title = trim((string) $title);
        if ($title === '') return [];

        $keys = [];
        $add = function (string $a, string $t) use (&$keys) {
            $tk = $this->coreTitleKey($t);
            if ($tk !== '') $keys[$a . '||' . $tk] = [$a, $tk];
        };

        $add($artistNorm, $title); // name is just the title (artist in its own column)
        foreach ([' - ', ' / ', ' — ', ': '] as $sep) {
            if (mb_strpos($title, $sep) === false) continue;
            [$a, $b] = array_map('trim', explode($sep, $title, 2));
            if ($a === '' || $b === '') continue;
            $add($artistNorm, $b);                       // TITLE after "ARTIST - "
            $add($artistNorm, $a);                       // TITLE before " - ARTIST"
            $add($this->normalizeMatchText($a), $b);     // a=artist, b=title
            $add($this->normalizeMatchText($b), $a);     // reversed
        }
        return array_values($keys);
    }

    /**
     * Normalize free text for fuzzy title/artist matching: lowercase, drop all
     * punctuation (so "Earth, Wind & Fire" == "earth wind fire"), drop the
     * noise words the/and/a/an, collapse whitespace. Both sides are normalized
     * the same way so formatting differences stop blocking matches.
     */
    protected function normalizeMatchText($s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);   // punctuation/& → space
        $s = ' ' . preg_replace('/\s+/', ' ', $s) . ' ';
        foreach (['the', 'and', 'a', 'an'] as $w) {
            $s = str_replace(' ' . $w . ' ', ' ', $s);
        }
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * The "core" title for matching: drop parenthetical/bracketed asides and
     * trailing format/edition noise that distributors tack on
     * ("THRILLER (140G/GATEFOLD)" → "thriller", "TEN (150G VINYL)" → "ten"),
     * then normalize. Lets a clean product title match a suffixed feed title.
     */
    protected function coreTitleKey($title): string
    {
        $t = (string) $title;
        $t = preg_replace('/\([^)]*\)/u', ' ', $t); // (…) asides
        $t = preg_replace('/\[[^\]]*\]/u', ' ', $t); // […] asides
        $t = $this->normalizeMatchText($t);
        // Strip trailing format/edition tokens if any slipped through.
        $noise = ['lp', 'ep', 'cd', 'vinyl', 'gatefold', 'remaster', 'remastered',
            'reissue', 'deluxe', 'edition', 'expanded', 'mono', 'stereo', '7', '10', '12',
            '150g', '180g', '140g', '200g', 'coloured', 'colored', 'clear', 'black'];
        $toks = $t === '' ? [] : explode(' ', $t);
        while (!empty($toks) && in_array(end($toks), $noise, true)) array_pop($toks);
        return implode(' ', $toks);
    }

    /**
     * Order-independent artist match so "Michael Jackson" == feed
     * "JACKSON,MICHAEL" (→ "jackson michael"). True if either token set is a
     * subset of the other. Empty on either side can't disqualify (title-only).
     */
    protected function artistMatches(string $a, string $b): bool
    {
        if ($a === '' || $b === '') return true;
        if ($a === $b) return true;
        $ta = array_values(array_unique(array_filter(explode(' ', $a))));
        $tb = array_values(array_unique(array_filter(explode(' ', $b))));
        if (empty($ta) || empty($tb)) return true;
        $common = count(array_intersect($ta, $tb));
        return $common >= min(count($ta), count($tb)); // one set ⊆ the other
    }

    public function bestSupplierPrice(int $business_id, ?string $artist, ?string $title, ?string $format = null, ?string $upc = null): ?array
    {
        // allSupplierPrices() returns every supplier's match sorted cheapest
        // first, so the best price is just the head of that list. Sharing the
        // same matcher keeps "best badge" and the per-supplier columns in sync.
        $all = $this->allSupplierPrices($business_id, $artist, $title, $format, $upc);
        return $all[0] ?? null;
    }

    /** Normalize a UPC/EAN to digits-only with leading zeros stripped. */
    protected function normalizeUpc($raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        return ltrim((string) $digits, '0');
    }

    /**
     * Every barcode-shaped token in a product's SKU field, normalized, as a
     * lookup set [normUpc => true]. A SKU can be "PD-141108, 634904032418,
     * 9514" — we want the 12/13-digit EAN in there to match the feed's UPC,
     * not the internal SKU or Discogs id. Splits on comma/space/semicolon/pipe
     * and keeps tokens that are 8-14 digits (UPC-E/UPC-A/EAN).
     *
     * @return array<string,bool>
     */
    protected function skuUpcCandidates($raw): array
    {
        $set = [];
        foreach (preg_split('/[,\s;|]+/', (string) $raw) as $tok) {
            $digits = preg_replace('/\D+/', '', (string) $tok);
            $len = strlen($digits);
            if ($len < 8 || $len > 14) continue; // not a UPC/EAN
            $norm = ltrim($digits, '0');
            if ($norm !== '') $set[$norm] = true;
        }
        return $set;
    }

    /**
     * Build the (artist, title) needle pairs to try when matching an ERP
     * product against supplier feeds. Some products have a real artist
     * column + clean title; legacy records leave artist empty and bake both
     * into the product name as "Artist / Title" (or "Title / Artist", or
     * "Artist - Title"). Supplier feeds always store artist and title
     * separately, so the combined name never matches as-is — when the artist
     * column is empty we split the name and try both orientations.
     */
    protected function supplierMatchCandidates(?string $artist, ?string $title): array
    {
        $artist = trim((string) $artist);
        $title = trim((string) $title);
        if ($title === '') return [];
        $cands = [[$artist, $title]];
        if ($artist === '') {
            foreach ([' / ', ' - '] as $sep) {
                if (mb_strpos($title, $sep) === false) continue;
                [$a, $b] = array_map('trim', explode($sep, $title, 2));
                if ($a !== '' && $b !== '') {
                    $cands[] = [$a, $b];
                    $cands[] = [$b, $a];
                }
            }
        }
        return $cands;
    }

    /**
     * True if a supplier-feed row's artist/title (already lowercased)
     * satisfies any candidate needle pair: the title needle must be a
     * substring of the row title, and — when an artist needle is given — the
     * artist needle must be a substring of the row artist.
     */
    protected function rowMatchesCandidates(string $rArtist, string $rTitle, array $candidates): bool
    {
        foreach ($candidates as [$na, $nt]) {
            $nt = mb_strtolower((string) $nt);
            if ($nt === '' || $rTitle === '' || mb_strpos($rTitle, $nt) === false) continue;
            $na = mb_strtolower((string) $na);
            if ($na !== '' && ($rArtist === '' || mb_strpos($rArtist, $na) === false)) continue;
            return true;
        }
        return false;
    }

    /**
     * Attach distributor price columns to a bucket's item list — the same
     * supplier_prices/best_supplier pair bucketFastOos already adds, so the
     * per-distributor price columns light up on EVERY bucket that renders
     * them (top-artist new releases, ABC-A restock, long-OOS essentials,
     * hot-used, customer wants), not just fast-OOS + chart picks.
     *
     * Each item is matched on its artist/product/format. allSupplierPrices()
     * shares a per-request static index, so this is just a substring scan
     * over already-loaded supplier rows — cheap even at 500 items.
     *
     * @return int how many items got at least one supplier match.
     */
    protected function attachSupplierPrices(int $business_id, array &$items): int
    {
        $matched = 0;
        foreach ($items as $idx => $it) {
            $artist = $it['artist'] ?? '';
            $title = $it['product'] ?? '';
            $format = $it['format'] ?? null;
            $upc = $it['sku'] ?? null;
            if (($title === '' || $title === null) && empty($upc)) continue;
            $items[$idx]['supplier_prices'] = $this->allSupplierPrices($business_id, $artist, $title, $format, $upc);
            $items[$idx]['best_supplier'] = $this->bestSupplierPrice($business_id, $artist, $title, $format, $upc);
            if (!empty($items[$idx]['supplier_prices'])) $matched++;
        }
        return $matched;
    }

    /** Public alias for the lazy UMe spotlights endpoint. */
    public function bucketUmeSpotlightsPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketUmeSpotlights($business_id, $locationId, $permittedLocations);
    }

    /**
     * UMe Update spotlight releases. Reads
     * storage/app/ume-spotlights-{business_id}.json (curated each week
     * from the PDF). Each spotlight: artist, title, release date, format,
     * genre tag, overview. Cross-references psc by artist match to add a
     * "you already carry N" badge + a "Bin: <pos>" hint if in stock.
     */
    protected function bucketUmeSpotlights(int $business_id, int $locationId, $permittedLocations): array
    {
        // Prefer the per-business spotlights file (uploaded each week);
        // fall back to the seed shipped in database/seed_data/ so the
        // bucket isn't empty until Sarah uploads the next PDF.
        $path = storage_path('app/ume-spotlights-' . $business_id . '.json');
        $sourcePath = is_file($path) ? $path : base_path('database/seed_data/ume-spotlights-seed.json');
        if (!is_file($sourcePath)) {
            return [
                'label' => 'UMe Update — release spotlights',
                'why' => 'Upload the UMe Update PDF in More options to populate this. Curated weekly highlights from UMe.',
                'items' => [], 'count' => 0,
                'empty_reason' => 'not_imported',
            ];
        }
        try {
            $json = json_decode((string) file_get_contents($sourcePath), true);
        } catch (\Throwable $e) {
            return [
                'label' => 'UMe Update — release spotlights',
                'why' => 'Failed to read spotlights file: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'read_error',
            ];
        }
        $spotlights = is_array($json) ? ($json['spotlights'] ?? []) : [];
        if (empty($spotlights)) {
            return [
                'label' => 'UMe Update — release spotlights',
                'why' => 'No spotlights in the current file.',
                'items' => [], 'count' => 0, 'empty_reason' => 'empty',
            ];
        }

        $items = [];
        foreach ($spotlights as $s) {
            if (!is_array($s)) continue;
            $artist = trim((string) ($s['artist'] ?? ''));
            $title = trim((string) ($s['title'] ?? ''));
            if ($artist === '' && $title === '') continue;

            // Cross-reference: do we already carry this artist's titles
            // at this location? If so attach the top match's stock + bin.
            $stock = null; $bin = null; $variation_id = null; $product_id = null; $sku = null; $cost = null;
            if ($artist !== '') {
                $matches = $this->findProductsByArtist($business_id, $artist, 1);
                if ($matches->isNotEmpty()) {
                    $m = $matches->first();
                    $stock = (float) ($m->stock ?? 0);
                    $bin = $m->bin_position ?? null;
                    $variation_id = (int) ($m->variation_id ?? 0);
                    $product_id = (int) ($m->product_id ?? 0);
                    $sku = $m->sku ?? null;
                    $cost = isset($m->cost_price) ? (float) $m->cost_price : null;
                }
            }

            $items[] = [
                'bucket' => 'ume_spotlights',
                'variation_id' => $variation_id,
                'product_id' => $product_id,
                'sku' => $sku,
                'artist' => $artist,
                'product' => $title,
                'format' => $s['formats'] ?? null,
                'genre' => $s['genre_tag'] ?? null,
                'category_name' => null,
                'bin_position' => $bin,
                'stock' => $stock,
                'sold_qty_window' => null,
                'cost_price' => $cost,
                'suggested_qty' => 1,
                'reason' => 'release ' . ($s['release_date_label'] ?? $s['release_date'] ?? '') . ' · ' . mb_strimwidth((string) ($s['overview'] ?? ''), 0, 240, '…'),
                'release_date' => $s['release_date'] ?? null,
                'release_date_label' => $s['release_date_label'] ?? null,
                'overview' => $s['overview'] ?? '',
                'tags' => ['ume_spotlight'],
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['release_date'] ?? '', $b['release_date'] ?? ''));

        $sourceFile = is_array($json) ? ($json['source_file'] ?? '') : '';
        $updated = is_array($json) ? ($json['updated_at'] ?? '') : '';
        return [
            'label' => 'UMe Update — release spotlights',
            'why' => 'Curated upcoming releases from ' . ($sourceFile ?: 'UMe') . ($updated ? ' · loaded ' . substr($updated, 0, 10) : ''),
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Manager picks (Lashyn's suggestions, etc.) ────────────────────

    /**
     * Path to the JSON store for manager picks. Same JSON-on-disk pattern
     * as clover manual matches + universal anniversaries — no migration.
     */
    protected function managerPicksPath(int $business_id): string
    {
        return storage_path('app/ica-manager-picks-' . $business_id . '.json');
    }

    /**
     * Read manager picks. On first read seeds with Lashyn's standing
     * "get more sealed electronic" suggestion (Sarah 2026-05-20) so the
     * page surfaces something useful immediately.
     */
    public function loadManagerPicks(int $business_id): array
    {
        $path = $this->managerPicksPath($business_id);
        if (!is_file($path)) {
            $seed = [[
                'id' => $this->newPickId(),
                'note' => 'Get more sealed electronic',
                'category_pattern' => 'Sealed Electronic',
                'suggested_by' => 'Lashyn',
                'created_at' => Carbon::now()->toIso8601String(),
                'dismissed' => false,
                'dismissed_at' => null,
                'dismissed_by' => null,
            ]];
            $this->saveManagerPicks($business_id, $seed);
            return $seed;
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($json)) return [];
        return $json;
    }

    public function saveManagerPicks(int $business_id, array $picks): void
    {
        $path = $this->managerPicksPath($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(array_values($picks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public function newPickId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /*
    |--------------------------------------------------------------------------
    | Wizard step progress (step-by-step "Order for this week" flow)
    |--------------------------------------------------------------------------
    | Shared per-store checklist so Clyde / Jon / Ece (Hollywood) and Zak
    | (Pico) don't redo each other's steps. Keyed by ISO week so it resets
    | every Monday on its own. Plain JSON in storage/app — no migration,
    | same pattern as manager picks. Structure:
    |   { "2026-W23": { "hollywood_all": { "fast_oos": {state,by,at} } } }
    */
    protected function wizardProgressPath(int $business_id): string
    {
        return storage_path('app/ica-wizard-progress-' . $business_id . '.json');
    }

    /** ISO year-week, e.g. "2026-W23". Naturally rolls over Monday. */
    public function wizardWeekKey(): string
    {
        return Carbon::now()->format('o-\WW');
    }

    public function loadWizardProgress(int $business_id): array
    {
        $path = $this->wizardProgressPath($business_id);
        if (!is_file($path)) return [];
        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($json) ? $json : [];
    }

    public function saveWizardProgress(int $business_id, array $data): void
    {
        $path = $this->wizardProgressPath($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    /** This week's step map for one store, e.g. { fast_oos: {state:'done',...} }. */
    public function getWizardWeekSteps(int $business_id, string $store): array
    {
        $all = $this->loadWizardProgress($business_id);
        $week = $this->wizardWeekKey();
        return $all[$week][$store] ?? [];
    }

    /**
     * Merge fields into one step's record for a store this week, keeping any
     * fields not being changed (so saving a note doesn't wipe done-state and
     * vice-versa). Prunes stale weeks so the file never grows unbounded.
     * Returns the updated step map for the store.
     */
    protected function upsertWizardStep(int $business_id, string $store, string $step, array $fields): array
    {
        $all = $this->loadWizardProgress($business_id);
        $week = $this->wizardWeekKey();
        // Drop stale weeks — the checklist only ever cares about "this week".
        $all = array_filter($all, function ($k) use ($week) { return $k === $week; }, ARRAY_FILTER_USE_KEY);
        if (!isset($all[$week])) $all[$week] = [];
        if (!isset($all[$week][$store])) $all[$week][$store] = [];

        $existing = $all[$week][$store][$step] ?? [];
        $merged = array_merge($existing, $fields);
        // If the step has no meaningful content left, drop it entirely.
        $hasState = !empty($merged['state']);
        $hasNote = isset($merged['note']) && trim((string) $merged['note']) !== '';
        if (!$hasState && !$hasNote) {
            unset($all[$week][$store][$step]);
        } else {
            $all[$week][$store][$step] = $merged;
        }
        $this->saveWizardProgress($business_id, $all);
        return $all[$week][$store];
    }

    /** Mark one step done / skipped / reset for a store this week. */
    public function setWizardStep(int $business_id, string $store, string $step, string $state, string $by = ''): array
    {
        if ($state === 'reset') {
            // Clear the done/skipped flag but keep any note attached.
            return $this->upsertWizardStep($business_id, $store, $step, [
                'state' => null, 'by' => null, 'at' => null,
            ]);
        }
        return $this->upsertWizardStep($business_id, $store, $step, [
            'state' => $state, // 'done' | 'skipped'
            'by' => $by,
            'at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /** Save (or clear, when blank) the shared note on one step this week. */
    public function setWizardNote(int $business_id, string $store, string $step, string $note, string $by = ''): array
    {
        $note = trim($note);
        return $this->upsertWizardStep($business_id, $store, $step, [
            'note' => $note,
            'note_by' => $note === '' ? null : $by,
            'note_at' => $note === '' ? null : Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Bucket: for each active manager pick, find a handful of low-stock
     * candidates matching the suggested category. Reason text credits
     * the manager so cashiers know who flagged it.
     */
    protected function bucketManagerPicks(int $business_id, int $locationId, $permittedLocations): array
    {
        $picks = array_values(array_filter($this->loadManagerPicks($business_id), function ($p) {
            return is_array($p) && empty($p['dismissed']);
        }));

        if (empty($picks)) {
            return [
                'label' => 'Manager picks',
                'why' => 'No active manager picks. Managers can add one in More options.',
                'items' => [], 'count' => 0, 'empty_reason' => 'no_active_picks',
            ];
        }

        $perPickLimit = (int) config('inventory_check.buckets.manager_picks.per_pick_limit', 12);
        $maxStock = (int) config('inventory_check.buckets.manager_picks.max_stock', 1);
        $targetStock = (int) config('inventory_check.buckets.manager_picks.target_stock', 3);

        $items = [];
        $pickSummaries = [];
        foreach ($picks as $pick) {
            $by = trim((string) ($pick['suggested_by'] ?? 'Manager'));
            $note = trim((string) ($pick['note'] ?? ''));
            $pattern = trim((string) ($pick['category_pattern'] ?? ''));
            $pickId = (string) ($pick['id'] ?? '');
            $pickSummaries[] = $by . ': "' . $note . '"' . ($pattern ? ' [' . $pattern . ']' : '');

            // No category pattern → can't surface candidates automatically,
            // but the pick still shows in the summary banner above the
            // bucket so it's not invisible.
            if ($pattern === '') {
                continue;
            }

            $catIds = $this->categoryIdsMatching($business_id, $pattern);
            if (empty($catIds)) {
                continue;
            }

            $rows = $this->queryPscRows($business_id, $locationId, $catIds, $permittedLocations);
            $added = 0;
            foreach ($rows as $row) {
                if ($added >= $perPickLimit) break;
                $stock = (float) ($row->stock ?? 0);
                if ($stock > $maxStock) continue;

                $items[] = $this->rowToCandidate($row, $stock, (float) ($row->total_sold ?? 0), $targetStock, [
                    'bucket' => 'manager_picks',
                    'reason' => $by . ': ' . $note,
                    'pick_id' => $pickId,
                    'suggested_by' => $by,
                    'tags' => ['manager_pick'],
                ]);
                $added++;
            }
        }

        $items = $this->dedupeByVariation($items);

        return [
            'label' => 'Manager picks',
            'why' => count($picks) . ' active pick' . (count($picks) === 1 ? '' : 's') . ' · ' . implode(' · ', $pickSummaries),
            'items' => $items,
            'count' => count($items),
            'active_picks' => $picks,
        ];
    }

    protected function resolveCategoryIds(array $input): array
    {
        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            return array_map('intval', $input['category_ids']);
        }
        if (!empty($input['category_id'])) {
            return [(int) $input['category_id']];
        }
        return [];
    }

    // ── Fast-moving, out of stock ─────────────────────────────────────

    protected function bucketFastOos(int $business_id, int $locationId, $permittedLocations): array
    {
        // Cached 5 min per (business, location). The 3 avg-sell-days +
        // 2 sold-qty queries cross the full 90-day transaction window
        // (70k+ historical txs) so re-clicking the same store within a
        // few minutes shouldn't repay that cost. Cache is invalidated on
        // sale/purchase via the existing PSC refresh job; if Sarah needs
        // it now, the cache-bust ?nofocache=1 param skips it.
        // 2026-05-27 Sarah: bumped to v2 to invalidate the cache that
        // pre-dates the supplier_prices/best_supplier fields being attached
        // to every fast-OOS row. Without this bump the old (no-chip) data
        // would keep serving for up to 5 minutes after the new deploy.
        $cacheKey = 'ica_fast_oos_v2_' . $business_id . '_' . $locationId;
        // Request::boolean() doesn't exist on this Laravel version — use
        // filter_var. Without this, ?nocache=1 500s before the cache code
        // even runs (Sarah hit this 2026-05-20).
        if (filter_var(request()->input('nocache'), FILTER_VALIDATE_BOOLEAN)) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($business_id, $locationId, $permittedLocations) {
                return $this->buildFastOosUncached($business_id, $locationId, $permittedLocations);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA fast_oos cache failed', ['err' => $e->getMessage()]);
            return $this->buildFastOosUncached($business_id, $locationId, $permittedLocations);
        }
    }

    protected function buildFastOosUncached(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets', []);
        $items = [];

        foreach (['fast_oos_vinyl', 'fast_oos_cd'] as $key) {
            $rules = $cfg[$key] ?? null;
            if (!$rules) {
                continue;
            }
            $catIds = $this->categoryIdsMatching($business_id, $rules['category_pattern'] ?? '');
            if (empty($catIds)) {
                continue;
            }

            $saleStart = Carbon::now()->subDays((int) ($rules['sale_days'] ?? 60))->format('Y-m-d');
            $saleEnd = Carbon::now()->format('Y-m-d');

            // Drop the avg-sell-days query entirely (Sarah 2026-05-20).
            // Even scoped to ≤2000 variation IDs it was the bottleneck
            // making the page "take forever". The Sell Speed column was
            // Clyde's preference; current users (Sarah/Jon) just need
            // sold-qty + stock to decide a reorder.
            $rows = $this->queryPscRows($business_id, $locationId, $catIds, $permittedLocations);
            $sold = $this->getSoldQtyByVariation($business_id, $locationId, $saleStart, $saleEnd, $permittedLocations);

            foreach ($rows as $row) {
                $vid = (int) $row->variation_id;
                $stock = (float) ($row->stock ?? 0);
                $sold_in_window = $sold[$vid] ?? 0.0;

                if ($stock > ($rules['max_stock'] ?? 0)) {
                    continue;
                }
                if ($sold_in_window < ($rules['min_sold'] ?? 1)) {
                    continue;
                }

                $items[] = $this->rowToCandidate($row, $stock, $sold_in_window, $rules['target_stock'] ?? 3, [
                    'bucket' => 'fast_oos',
                    'reason' => 'sold ' . (int) $sold_in_window . ' in last ' . ($rules['sale_days'] ?? 60) . 'd, stock ' . (int) $stock,
                ]);
            }
        }

        // The old "fast_seller (any category)" sub-bucket was dropped
        // 2026-05-20 — it queried avg-sell-days across 2000 PSC rows in
        // any category and was the page's biggest single perf cost. The
        // vinyl/CD sub-buckets above cover the actual reorder candidates.

        $items = $this->dedupeByVariation($items);
        // Sort by recent sold-qty descending — items that moved the most
        // in the window land at the top. Simple, fast, no avg-days math.
        usort($items, function ($a, $b) {
            return ($b['sold_qty_window'] ?? 0) <=> ($a['sold_qty_window'] ?? 0);
        });

        // The "last ordered" enrichment was dropped 2026-05-20 — the
        // implementation did a per-item query (N+1) and was the new
        // bottleneck after avg-sell-days went away. Page must load first;
        // the previous-order feedback feature is parked until it can be
        // batched into one query or moved to a lazy endpoint.

        // 2026-05-27 Sarah: attach AMS / Secretly / Beggars / Redeye / VP
        // supplier prices to every fast-OOS row so the green "$X.XX via
        // <supplier>" chips appear in STEP 1 alongside chart picks.
        // allSupplierPrices() uses a per-request static cache so the
        // per-item cost is just a substring scan over already-loaded
        // supplier rows.
        $itemsWithMatch = 0;
        foreach ($items as $idx => $it) {
            $artist = $it['artist'] ?? '';
            $title = $it['product'] ?? '';
            $format = $it['format'] ?? null;
            $upc = $it['sku'] ?? null;
            if ($title === '' && empty($upc)) continue;
            $items[$idx]['supplier_prices'] = $this->allSupplierPrices($business_id, $artist, $title, $format, $upc);
            $items[$idx]['best_supplier'] = $this->bestSupplierPrice($business_id, $artist, $title, $format, $upc);
            if (!empty($items[$idx]['supplier_prices'])) $itemsWithMatch++;
        }

        // Supplier-feed diagnostics so Sarah can tell at a glance whether
        // the absence of chips means "no feeds uploaded" vs "no matches".
        $feedSummary = $this->supplierFeedSummary($business_id);
        $feedsLoaded = [];
        $totalSupplierRows = 0;
        foreach ($feedSummary as $sup) {
            $rows = (int) ($sup['rows'] ?? 0);
            if ($rows <= 0) continue;
            $feedsLoaded[] = ($sup['label'] ?? $sup['key'] ?? '?') . ' (' . number_format($rows) . ')';
            $totalSupplierRows += $rows;
        }
        $why = 'Sold fast in the last 60-90 days; we have zero or near-zero on shelf.';
        if (empty($feedsLoaded)) {
            $why .= ' · No supplier price feeds uploaded — open "More options → Supplier price feeds" to add AMS / Secretly / Beggars / Redeye / VP and prices will start appearing as chips on each row.';
        } else {
            $why .= ' · Supplier feeds loaded: ' . implode(' · ', $feedsLoaded) . '. Matched ' . $itemsWithMatch . ' / ' . count($items) . ' rows.';
        }

        return [
            'label' => 'Fast-moving, out of stock',
            'why' => $why,
            'items' => $items,
            'count' => count($items),
            'supplier_feeds_loaded' => $feedsLoaded,
            'supplier_rows_total' => $totalSupplierRows,
            'items_with_supplier_match' => $itemsWithMatch,
        ];
    }

    /**
     * Last purchase per variation at this location: returns
     * [variation_id => ['qty' => N, 'date' => 'YYYY-MM-DD']]
     * Scoped to a passed variation_id set so it stays cheap — no full
     * purchase_lines scan.
     */
    protected function getLastPurchaseByVariation(int $business_id, int $locationId, array $variationIds, $permittedLocations): array
    {
        if (empty($variationIds)) {
            return [];
        }
        $q = DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.location_id', $locationId)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->whereIn('pl.variation_id', $variationIds)
            ->select(
                'pl.variation_id',
                DB::raw('MAX(t.transaction_date) as last_date')
            )
            ->groupBy('pl.variation_id');
        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }
        $latest = $q->get()->keyBy('variation_id');

        if ($latest->isEmpty()) {
            return [];
        }

        // For each (variation, last_date) pair, pull the qty on that day.
        $datePairs = $latest->map(fn ($r) => ['variation_id' => (int) $r->variation_id, 'last_date' => $r->last_date]);
        $out = [];
        foreach ($datePairs as $p) {
            $row = DB::table('purchase_lines as pl')
                ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                ->where('t.business_id', $business_id)
                ->where('t.location_id', $locationId)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->where('pl.variation_id', $p['variation_id'])
                ->where('t.transaction_date', $p['last_date'])
                ->selectRaw('SUM(pl.quantity) as qty')
                ->first();
            $out[$p['variation_id']] = [
                'qty' => $row ? (float) $row->qty : 0.0,
                'date' => Carbon::parse($p['last_date'])->format('Y-m-d'),
            ];
        }
        return $out;
    }

    // ── Chart picks (Street Pulse / Universal Top) ────────────────────

    protected function bucketChartPicks(int $business_id, int $locationId, string $source, array $topArtists, $permittedLocations): array
    {
        $label = $this->chartSourceLabel($source);
        if (!Schema::hasTable('chart_picks')) {
            return [
                'label' => $label,
                'why' => 'chart_picks table not yet migrated — run php artisan migrate.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'migrations_missing',
            ];
        }

        $week = ChartPick::where('business_id', $business_id)
            ->where('source', $source)
            ->max('week_of');

        if (!$week) {
            $emptyMsg = $source === 'apple_music_top'
                ? 'Daily cron populates this at 09:00 PST. Or click "Run Apple Music pull" above.'
                : 'Paste this week\'s email to populate.';
            return [
                'label' => $label,
                'why' => $emptyMsg,
                'items' => [],
                'count' => 0,
                'empty_reason' => 'not_imported',
            ];
        }

        // Capped at 100 (was 500) 2026-05-20 — each pick fires a fuzzy
        // LIKE lookup in tryMatchChartPickToVariation, and 3 sources ×
        // 500 picks = 1500 sequential queries was the dominant cost in
        // secondaryBuckets. Top 100 covers everything cashiers care
        // about; raise via config if needed.
        $picks = ChartPick::where('business_id', $business_id)
            ->where('source', $source)
            ->whereDate('week_of', $week)
            ->orderBy('chart_rank')
            ->limit((int) config('inventory_check.chart_picks_per_source', 100))
            ->get();

        $topArtistsLower = array_map('mb_strtolower', $topArtists);
        $items = [];

        foreach ($picks as $pick) {
            $artistLower = mb_strtolower((string) $pick->artist);
            $isTopArtist = $this->isTopArtistMatch($artistLower, $topArtistsLower);

            $match = $this->tryMatchChartPickToVariation($business_id, $pick->artist, $pick->title);
            $stock = $match['stock'] ?? null;
            $items[] = [
                'bucket' => $source,
                'chart_rank' => $pick->chart_rank,
                'artist' => $pick->artist,
                'product' => $pick->title,
                'format' => $pick->format,
                'is_new_release' => (bool) $pick->is_new_release,
                'is_top_artist' => $isTopArtist,
                'variation_id' => $match['variation_id'] ?? null,
                'product_id' => $match['product_id'] ?? null,
                'sku' => $match['sku'] ?? null,
                'stock' => $stock,
                'sold_qty_window' => $match['sold_qty_window'] ?? 0,
                'location_name' => $match['location_name'] ?? null,
                'category_name' => $match['category_name'] ?? null,
                'category_id' => $match['category_id'] ?? null,
                'genre' => $match['genre'] ?? null,
                'bin_position' => $match['bin_position'] ?? null,
                'is_rsd' => $this->isRsdTitle((string) ($pick->title ?? '')),
                'best_supplier' => $this->bestSupplierPrice($business_id, $pick->artist, $pick->title, $pick->format),
                'supplier_prices' => $this->allSupplierPrices($business_id, $pick->artist, $pick->title, $pick->format),
                'suggested_qty' => $this->suggestedQtyForChartPick($pick, $stock, $isTopArtist),
                'reason' => $this->chartPickReason($pick, $isTopArtist, $match),
                'tags' => array_values(array_filter([
                    $source,
                    $pick->is_new_release ? 'new_release' : null,
                    $isTopArtist ? 'top_artist' : null,
                ])),
            ];
        }

        // Sort: top-artist + new release first, then top-artist, then new release, then rank
        usort($items, function ($a, $b) {
            $aScore = ($a['is_top_artist'] ? 2 : 0) + ($a['is_new_release'] ? 1 : 0);
            $bScore = ($b['is_top_artist'] ? 2 : 0) + ($b['is_new_release'] ? 1 : 0);
            if ($aScore !== $bScore) {
                return $bScore <=> $aScore;
            }
            return ($a['chart_rank'] ?? PHP_INT_MAX) <=> ($b['chart_rank'] ?? PHP_INT_MAX);
        });

        return [
            'label' => $label,
            'why' => 'From the most recent ' . $this->chartSourceFriendlyName($source) . ' chart (imported ' . $week . '). Rows tagged "top_artist" are artists already popular in-store.',
            'items' => $items,
            'count' => count($items),
            'week_of' => (string) $week,
        ];
    }

    protected function chartSourceLabel(string $source): string
    {
        switch ($source) {
            case 'street_pulse': return 'Street Pulse picks';
            case 'universal_top': return 'Universal top';
            case 'apple_music_top': return 'Apple Music top 100';
            default: return ucwords(str_replace('_', ' ', $source));
        }
    }

    protected function chartSourceFriendlyName(string $source): string
    {
        switch ($source) {
            case 'street_pulse': return 'Street Pulse';
            case 'universal_top': return 'Universal top';
            case 'apple_music_top': return 'Apple Music top 100';
            default: return str_replace('_', ' ', $source);
        }
    }

    protected function isTopArtistMatch(string $artistLower, array $topArtistsLower): bool
    {
        if ($artistLower === '') {
            return false;
        }
        foreach ($topArtistsLower as $top) {
            if ($top === '' || $artistLower === '') {
                continue;
            }
            if ($artistLower === $top) {
                return true;
            }
            // fuzzy: starts with, or Levenshtein ≤ 2 for short names
            if (mb_strlen($top) > 3 && (mb_strpos($artistLower, $top) !== false || mb_strpos($top, $artistLower) !== false)) {
                return true;
            }
        }
        return false;
    }

    protected function suggestedQtyForChartPick($pick, ?float $stock, bool $isTopArtist): int
    {
        $base = $isTopArtist ? 2 : 1;
        if ($pick->is_new_release) {
            $base = max($base, 2);
        }
        if ($stock !== null && $stock >= 3) {
            return 0; // already well-stocked
        }
        return $base;
    }

    protected function chartPickReason($pick, bool $isTopArtist, ?array $match): string
    {
        // Accept null match — tryMatchChartPickToVariation returns null
        // when nothing in the catalog matches the chart pick, and this
        // method gets called with that null directly. Treating it as []
        // keeps the rest of the logic happy (the empty checks all pass).
        $match = $match ?? [];
        $bits = [];
        if ($isTopArtist) {
            $bits[] = 'popular in-store';
        }
        if ($pick->is_new_release) {
            $bits[] = 'new release';
        }
        if (!empty($match['variation_id']) && ($match['stock'] ?? 0) <= 0) {
            $bits[] = 'out of stock';
        } elseif (empty($match['variation_id'])) {
            $bits[] = 'not yet in catalog';
        }
        if (empty($bits)) {
            $bits[] = 'chart pick';
        }
        return implode('; ', $bits);
    }

    protected function tryMatchChartPickToVariation(int $business_id, ?string $artist, ?string $title): ?array
    {
        if (!$title) {
            return null;
        }
        $q = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.product', 'like', '%' . $title . '%')
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.stock', 'psc.sku',
                'psc.location_name', 'psc.category_name', 'psc.category_id',
                'psc.total_sold', 'subcat.name as genre', 'p.bin_position',
                'v.default_purchase_price as cost_price',
            ])
            ->limit(10);

        if ($artist) {
            $q->where(function ($w) use ($artist) {
                $w->where('psc.product_custom_field1', 'like', '%' . $artist . '%')
                    ->orWhere('psc.product', 'like', '%' . $artist . '%');
            });
        }

        $rows = $q->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $row = $rows->first();

        return [
            'variation_id' => (int) $row->variation_id,
            'product_id' => (int) $row->product_id,
            'sku' => $row->sku,
            'stock' => (float) ($row->stock ?? 0),
            'sold_qty_window' => (float) ($row->total_sold ?? 0),
            'location_name' => $row->location_name,
            'category_name' => $row->category_name,
            'category_id' => $row->category_id ?? null,
            'genre' => $row->genre ?? null,
            'bin_position' => $row->bin_position ?? null,
            'cost_price' => isset($row->cost_price) ? (float) $row->cost_price : null,
        ];
    }

    // ── New releases from top artists (cross-reference) ───────────────

    protected function bucketTopArtistNewReleases(int $business_id, int $locationId, array $topArtists, $permittedLocations): array
    {
        if (!Schema::hasTable('chart_picks')) {
            return [
                'label' => 'New releases from your top artists',
                'why' => 'chart_picks table not yet migrated — run php artisan migrate.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'migrations_missing',
            ];
        }

        $latestWeeks = ChartPick::where('business_id', $business_id)
            ->selectRaw('source, MAX(week_of) as w')
            ->groupBy('source')
            ->pluck('w', 'source');

        if ($latestWeeks->isEmpty() || empty($topArtists)) {
            return [
                'label' => 'New releases from your top artists',
                'why' => 'Cross-references your top-selling artists with the week\'s charts. Populates once a chart is pasted.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'need_charts_and_sales',
            ];
        }

        $topLower = array_map('mb_strtolower', $topArtists);
        $items = [];
        foreach ($latestWeeks as $source => $week) {
            $picks = ChartPick::where('business_id', $business_id)
                ->where('source', $source)
                ->whereDate('week_of', $week)
                ->get();

            foreach ($picks as $pick) {
                $artistLower = mb_strtolower((string) $pick->artist);
                if (!$this->isTopArtistMatch($artistLower, $topLower)) {
                    continue;
                }
                if (!$pick->is_new_release) {
                    // Still include if we don't already carry this specific title
                    $match = $this->tryMatchChartPickToVariation($business_id, $pick->artist, $pick->title);
                    if ($match !== null) {
                        continue; // Already in catalog; Street Pulse section handles it
                    }
                }

                $match = $this->tryMatchChartPickToVariation($business_id, $pick->artist, $pick->title);
                $items[] = [
                    'bucket' => 'top_artist_new_releases',
                    'artist' => $pick->artist,
                    'product' => $pick->title,
                    'format' => $pick->format,
                    'is_new_release' => (bool) $pick->is_new_release,
                    'chart_source' => $source,
                    'chart_rank' => $pick->chart_rank,
                    'variation_id' => $match['variation_id'] ?? null,
                    'product_id' => $match['product_id'] ?? null,
                    'sku' => $match['sku'] ?? null,
                    'stock' => $match['stock'] ?? null,
                    'suggested_qty' => $pick->is_new_release ? 3 : 2,
                    'reason' => $pick->is_new_release
                        ? 'new release from top-selling artist'
                        : 'top artist; we don\'t carry this title',
                    'tags' => ['top_artist', $pick->is_new_release ? 'new_release' : 'missing_title'],
                ];
            }
        }

        // De-dupe by (artist,title) pair
        $seen = [];
        $deduped = [];
        foreach ($items as $it) {
            $key = mb_strtolower(($it['artist'] ?? '') . '|' . ($it['product'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $it;
        }

        $this->attachSupplierPrices($business_id, $deduped);

        return [
            'label' => 'New releases from your top artists',
            'why' => 'Artists already selling well in-store with a NEW release on this week\'s charts. Prioritize like fast-OOS: A-class artists first, then B; skip C. Tag "top_artist" means we already know fans want them.',
            'items' => $deduped,
            'count' => count($deduped),
        ];
    }

    // ── Upcoming events → stock-up ────────────────────────────────────

    /** Public alias used by the lazy-load endpoint (controller can't call protected). */
    public function bucketEventsUpcomingPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketEventsUpcoming($business_id, $locationId, $permittedLocations);
    }

    protected function bucketEventsUpcoming(int $business_id, int $locationId, $permittedLocations): array
    {
        $lookahead = (int) config('inventory_check.events_lookahead_days', 30);
        $events = $this->eventsFetcher->upcoming($lookahead);

        // Universal's "Key Anniversaries + Birthdays" tab — biopics, milestone
        // anniversaries, artist birthdays. Persisted on UMe xlsx import; we
        // surface them as synthetic events so a Michael Jackson biopic release
        // or a Drake milestone shows up alongside concerts.
        $annivEvents = $this->loadUniversalAnniversaryEvents($business_id, $lookahead);
        $events = array_merge($events, $annivEvents);

        if (empty($events)) {
            return [
                'label' => 'Upcoming events — stock up',
                'why' => 'Pulled from nivessa.com/events + UMe anniversaries. Set NIVESSA_EVENTS_API_URL in .env or import a UMe xlsx to enable.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_events',
            ];
        }

        $items = [];
        foreach ($events as $event) {
            foreach ($event['artists'] as $artistName) {
                $matches = $this->findProductsByArtist($business_id, $artistName, 3);
                foreach ($matches as $match) {
                    $stock = (float) ($match->stock ?? 0);
                    $isAnniversary = !empty($event['is_anniversary']);
                    $reason = $isAnniversary
                        ? ($event['name'] . ' — ' . $event['date'])
                        : ('event ' . $event['name'] . ' on ' . $event['date']);
                    $tags = $isAnniversary ? ['anniversary'] : ['event'];
                    // 2026-05-27: tag source so the JS can split listening
                    // parties (nivessa-hosted) from LA shows (Ticketmaster)
                    // from UMe artist moments (anniversaries).
                    $eventSource = $isAnniversary ? 'anniversary' : ($event['source'] ?? 'nivessa');
                    $items[] = [
                        'bucket' => 'events_upcoming',
                        'event_name' => $event['name'],
                        'event_date' => $event['date'],
                        'event_location' => $event['location'],
                        'event_source' => $eventSource,
                        'artist' => $artistName,
                        'product' => $match->product,
                        'sku' => $match->sku,
                        'format' => $match->product_format ?? null,
                        'variation_id' => (int) $match->variation_id,
                        'product_id' => (int) $match->product_id,
                        'stock' => $stock,
                        'sold_qty_window' => (float) ($match->total_sold ?? 0),
                        'location_name' => $match->location_name,
                        'category_name' => $match->category_name,
                        'genre' => $match->genre ?? null,
                        'bin_position' => $match->bin_position ?? null,
                        'cost_price' => isset($match->cost_price) ? (float) $match->cost_price : null,
                        'is_rsd' => $this->isRsdTitle((string) ($match->product ?? '')),
                        'suggested_qty' => max(1, 3 - (int) $stock),
                        'reason' => $reason,
                        'tags' => $tags,
                    ];
                }
            }
        }

        $concertCount = count($events) - count($annivEvents);

        return [
            'label' => 'Upcoming events — stock up',
            'why' => 'LA concerts + listening parties + UMe artist moments (biopics, anniversaries, birthdays) in the next ' . $lookahead . ' days.',
            'items' => $items,
            'count' => count($items),
            'events_loaded' => count($events),
            'concert_events' => $concertCount,
            'anniversary_events' => count($annivEvents),
            'all_events' => array_values(array_map(function ($e) {
                return [
                    'name' => $e['name'],
                    'date' => $e['date'],
                    'location' => $e['location'] ?? null,
                    'source' => $e['source'] ?? 'nivessa',
                    'is_anniversary' => !empty($e['is_anniversary']),
                ];
            }, $events)),
        ];
    }

    /**
     * Read storage/app/universal-anniversaries-{business_id}.json (written by
     * the UMe xlsx import) and return rows within the lookahead window in the
     * same shape NivessaEventsFetcher returns, so the events bucket can fold
     * them into its product-matching loop.
     */
    protected function loadUniversalAnniversaryEvents(int $business_id, int $lookaheadDays): array
    {
        $path = storage_path('app/universal-anniversaries-' . $business_id . '.json');
        if (!is_file($path)) {
            return [];
        }

        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($json) || empty($json['anniversaries']) || !is_array($json['anniversaries'])) {
            return [];
        }

        $today = Carbon::today();
        $cutoff = $today->copy()->addDays($lookaheadDays);
        $out = [];

        foreach ($json['anniversaries'] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $artist = trim((string) ($a['artist'] ?? ''));
            $dateStr = (string) ($a['event_date'] ?? '');
            if ($artist === '' || $dateStr === '') {
                continue;
            }
            try {
                $d = Carbon::parse($dateStr);
            } catch (\Throwable $ignore) {
                continue;
            }
            if ($d->lt($today) || $d->gt($cutoff)) {
                continue;
            }

            // Build a human label: "Michael Jackson — Thriller 45th biopic"
            $album = trim((string) ($a['album_or_track'] ?? ''));
            $moment = trim((string) ($a['moment'] ?? ''));
            $years = $a['years'] ?? null;
            $parts = [$artist];
            if ($album !== '') {
                $parts[] = $album;
            }
            if ($years) {
                $parts[] = $years . 'th';
            }
            if ($moment !== '') {
                $parts[] = $moment;
            }
            $name = implode(' — ', $parts);

            $out[] = [
                'name' => $name,
                'date' => $d->format('Y-m-d'),
                'location' => null,
                'artists' => [$artist],
                'is_anniversary' => true,
                'raw' => $a,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    protected function findProductsByArtist(int $business_id, string $artist, int $limit = 5)
    {
        if (trim($artist) === '') {
            return collect([]);
        }
        return DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->where('psc.business_id', $business_id)
            ->where(function ($q) use ($artist) {
                $q->where('psc.product_custom_field1', 'like', '%' . $artist . '%')
                    ->orWhere('psc.product', 'like', '%' . $artist . '%');
            })
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.stock', 'psc.sku', 'psc.product',
                'psc.location_name', 'psc.category_name', 'psc.total_sold',
                'subcat.name as genre',
                'p.format as product_format', 'p.bin_position',
                'v.default_purchase_price as cost_price',
            ])
            ->orderByDesc('psc.total_sold')
            ->limit($limit)
            ->get();
    }

    // ── ABC analysis (by inventory value) ─────────────────────────────

    /**
     * Build a [product_id => 'A'|'B'|'C'] map using the same Pareto-style
     * classification as /reports/abc-inventory-classification:
     *   A = cumulative top 80% of inventory value
     *   B = next 15%
     *   C = bottom 5%
     * Cached for 15 min — values change slowly and the underlying scan
     * touches every stocked product.
     */
    protected function computeAbcMap(int $business_id): array
    {
        // Externally-computed ABC (sales-based, uploaded at /admin/abc-import)
        // wins when present. Falls back to the live inventory-value computation
        // if no file is active.
        try {
            $imported = (new \App\Services\AbcImportService())->loadGlobalMap();
            if (!empty($imported)) {
                return $imported;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA imported ABC load failed', ['err' => $e->getMessage()]);
        }

        $cacheKey = 'ica_abc_map_' . $business_id;
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(15), function () use ($business_id) {
                return $this->computeAbcMapUncached($business_id);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA computeAbcMap failed', ['err' => $e->getMessage()]);
            return [];
        }
    }

    protected function computeAbcMapUncached(int $business_id): array
    {
        $rows = DB::table('product_stock_cache as psc')
            ->where('psc.business_id', $business_id)
            ->where('psc.enable_stock', 1)
            ->select(
                'psc.product_id',
                DB::raw('SUM(psc.stock_price) as inventory_value')
            )
            ->groupBy('psc.product_id')
            ->orderByDesc('inventory_value')
            ->get();

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r->inventory_value ?? 0);
        }
        if ($total <= 0) {
            return [];
        }

        $map = [];
        $running = 0.0;
        foreach ($rows as $r) {
            $running += (float) ($r->inventory_value ?? 0);
            $pct = ($running / $total) * 100;
            $map[(int) $r->product_id] = $pct <= 80 ? 'A' : ($pct <= 95 ? 'B' : 'C');
        }
        return $map;
    }

    /**
     * A-class items running low. These are the inventory dollars that drive
     * most of the store's value — being out of stock on them is the biggest
     * miss. Stock ≤ 1 with the A label.
     */
    protected function bucketAbcARestock(int $business_id, int $locationId, array $abcMap, $permittedLocations): array
    {
        if (empty($abcMap)) {
            return [
                'label' => 'A-class items — restock priority',
                'why' => 'ABC classification empty — no stocked products with value.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_abc',
            ];
        }

        // Hash-set lookup — in_array on a 1000+ element array against 2000
        // PSC rows was the other reason "Building…" hung.
        $aPidsSet = [];
        foreach ($abcMap as $pid => $cls) {
            if ($cls === 'A') {
                $aPidsSet[(int) $pid] = true;
            }
        }
        if (empty($aPidsSet)) {
            return [
                'label' => 'A-class items — restock priority',
                'why' => 'No A-class products at this location.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_a_class',
            ];
        }

        $maxStock = (int) config('inventory_check.buckets.abc_a_restock.max_stock', 1);
        $targetStock = (int) config('inventory_check.buckets.abc_a_restock.target_stock', 3);

        $rows = $this->queryPscRows($business_id, $locationId, [], $permittedLocations);
        $items = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (!isset($aPidsSet[$pid])) {
                continue;
            }
            $stock = (float) ($row->stock ?? 0);
            if ($stock > $maxStock) {
                continue;
            }
            // 2026-06-03 Sarah: RSD-exclusive titles aren't routine restocks —
            // keep them out of the A-class restock list.
            if ($this->isRsdTitle((string) ($row->product ?? ''))) {
                continue;
            }
            $items[] = $this->rowToCandidate($row, $stock, 0, $targetStock, [
                'bucket' => 'abc_a_restock',
                'reason' => 'A-class (top 80% of inventory value), stock ' . (int) $stock,
                'tags' => ['abc_A'],
            ]);
        }

        $items = $this->dedupeByVariation($items);

        $this->attachSupplierPrices($business_id, $items);

        return [
            'label' => 'A-class items — restock priority',
            'why' => 'Items in the top 80% of inventory value (ABC class A) that are low or out of stock here. These drive most of the store\'s value — being out hurts the most.',
            'items' => $items,
            'count' => count($items),
            // Full A/B/C map by product_id so JS can paint the ABC pill
            // on rows in OTHER buckets (fast sellers, chart picks, etc.)
            // — Sarah 2026-05-20: "add A, B, or C product for the fast
            // sellers".
            'abc_map' => $abcMap,
        ];
    }

    // ── Frozen inventory (DO NOT REORDER) ─────────────────────────────

    /**
     * Items at this location with stock > 0 but no sale in the configured
     * window (default 180 days). Mirrors /reports/dead-stock but scoped to
     * the current location so Sarah can see "what's already sitting here
     * that I shouldn't reorder more of." suggested_qty is forced to 0 so
     * accidentally checking the row + exporting can't bulk-reorder dead
     * stock.
     */
    protected function bucketFrozenInventory(int $business_id, int $locationId, $permittedLocations, ?int $daysOverride = null): array
    {
        $defaultDays = (int) config('inventory_check.buckets.frozen_inventory.frozen_days', 180);
        $frozenDays = $daysOverride !== null ? $daysOverride : $defaultDays;
        $limit = (int) config('inventory_check.buckets.frozen_inventory.max_items', 200);
        $cutoff = Carbon::now()->subDays($frozenDays)->format('Y-m-d H:i:s');

        // Two-step query to avoid scanning the entire transaction history:
        //   1) Pull stocked variations at this location (small set — ≤ a few
        //      thousand rows at most).
        //   2) Look up last_sold for ONLY those variations.
        // Doing it inline as a leftJoinSub forced MySQL to compute the
        // MAX(transaction_date) over every variation in the business (70k+
        // historical txs imported 2026-04-23), which was the spinner
        // Sarah saw stuck on "Building…".
        $pscQuery = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->where('psc.stock', '>', 0);
        if ($permittedLocations !== 'all') {
            $pscQuery->whereIn('psc.location_id', $permittedLocations);
        }
        // last_updated_at = newest of products.updated_at / variations.updated_at,
        // ignoring future timestamps (corrupt rows from bad-clock syncs — same
        // guard ProductController uses for its real_updated_at column).
        $stocked = $pscQuery->select([
            'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
            'psc.product', 'psc.type', 'psc.product_variation', 'psc.variation_name',
            'psc.location_name', 'psc.category_name', 'psc.category_id',
            'psc.sub_category_id', 'subcat.name as genre',
            'psc.product_custom_field1', 'psc.total_sold', 'psc.stock_price',
            'psc.unit_price as sell_price',
            // Per-unit purchase price stored on the variation. dpp_inc_tax is
            // the inc-tax counterpart of psc.unit_price (sell_price_inc_tax)
            // so the two columns are apples-to-apples. Earlier we computed
            // cost as stock_price / stock, but that legacy aggregation across
            // purchase_lines is corrupt on old rows (Jon 2026-05-24: $1788
            // on a $44 vinyl) — falling back to default_purchase_price
            // when dpp_inc_tax is null/zero (older imports).
            DB::raw('COALESCE(NULLIF(v.dpp_inc_tax, 0), v.default_purchase_price, 0) as cost_per_unit'),
            'p.format as product_format', 'p.bin_position',
            'p.created_at as product_created_at',
            DB::raw('GREATEST(
                COALESCE(IF(p.updated_at > NOW(), NULL, p.updated_at), "1970-01-01"),
                COALESCE(IF(v.updated_at > NOW(), NULL, v.updated_at), "1970-01-01")
            ) as last_updated_at'),
        ])->orderByRaw('(psc.stock * COALESCE(NULLIF(v.dpp_inc_tax, 0), v.default_purchase_price, 0)) DESC')->get();

        if ($stocked->isEmpty()) {
            return [
                'label' => 'Frozen inventory — DO NOT reorder',
                'why' => 'No stocked items at this location.',
                'items' => [], 'count' => 0, 'frozen_days' => $frozenDays,
            ];
        }

        $variationIds = $stocked->pluck('variation_id')->map(fn ($v) => (int) $v)->all();
        $lastSold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereIn('tsl.variation_id', $variationIds)
            ->select('tsl.variation_id', DB::raw('MAX(t.transaction_date) as last_sold'))
            ->groupBy('tsl.variation_id')
            ->pluck('last_sold', 'variation_id');

        $rows = $stocked->filter(function ($r) use ($lastSold, $cutoff) {
            $ls = $lastSold[$r->variation_id] ?? null;
            return $ls === null || $ls < $cutoff;
        })->take($limit)->map(function ($r) use ($lastSold) {
            $r->last_sold = $lastSold[$r->variation_id] ?? null;
            return $r;
        });

        // Load any prior in-place stock corrections done from this page —
        // Sarah wants the most recent "updated by who, when" on each row.
        $corrections = $this->loadFrozenCorrections($business_id);
        $lastCorrectionByVid = [];
        foreach ($corrections as $c) {
            if (!is_array($c) || empty($c['variation_id'])) continue;
            $vid = (int) $c['variation_id'];
            if (!isset($lastCorrectionByVid[$vid]) || $c['when'] > $lastCorrectionByVid[$vid]['when']) {
                $lastCorrectionByVid[$vid] = $c;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $stock = (float) ($row->stock ?? 0);
            // Dates: ISO ("YYYY-MM-DD") shipped to JS for both display + sort,
            // JS reformats to mm/dd/yy on screen. Reason text bakes the
            // display format in directly since it's a pre-built string.
            $lastSoldIso = $row->last_sold ? Carbon::parse($row->last_sold)->format('Y-m-d') : null;
            $daysSince = $lastSoldIso ? Carbon::parse($lastSoldIso)->diffInDays(Carbon::now()) : null;
            $lastSoldDisplay = $lastSoldIso ? Carbon::parse($lastSoldIso)->format('m/d/y') : null;

            // Cost / Price come straight from the variation: cost_per_unit =
            // v.dpp_inc_tax (purchase price inc tax) and sell_price =
            // psc.unit_price (sell price inc tax). Tied-up $ is just
            // cost × stock so the Reason line matches what Cost shows.
            $costPerUnit = isset($row->cost_per_unit) ? round((float) $row->cost_per_unit, 2) : null;
            $sellPrice = isset($row->sell_price) ? (float) $row->sell_price : null;
            $tiedUp = ($costPerUnit !== null) ? round($costPerUnit * $stock, 2) : 0.0;

            $lastEditedIso = null;
            if (!empty($row->last_updated_at) && $row->last_updated_at !== '1970-01-01 00:00:00') {
                $lastEditedIso = Carbon::parse($row->last_updated_at)->format('Y-m-d');
            }
            $createdIso = null;
            if (!empty($row->product_created_at) && $row->product_created_at !== '1970-01-01 00:00:00') {
                $createdIso = Carbon::parse($row->product_created_at)->format('Y-m-d');
            }

            $candidate = $this->rowToCandidate($row, $stock, 0, 0, [
                'bucket' => 'frozen_inventory',
                'reason' => $lastSoldDisplay
                    ? ('last sold ' . $lastSoldDisplay . ' (' . $daysSince . 'd ago) · $' . number_format($tiedUp, 0) . ' tied up')
                    : ('never sold · $' . number_format($tiedUp, 0) . ' tied up'),
                'last_sold' => $lastSoldIso,
                'days_since_sold' => $daysSince,
                'tied_up_value' => $tiedUp,
                'cost_price' => $costPerUnit,
                'sell_price' => $sellPrice,
                'last_updated_at' => $lastEditedIso,
                'created_at' => $createdIso,
                'tags' => ['frozen', 'do_not_reorder'],
            ]);

            // Annotate with the most recent in-place correction (if any).
            $vid = (int) ($candidate['variation_id'] ?? 0);
            if ($vid && !empty($lastCorrectionByVid[$vid])) {
                $c = $lastCorrectionByVid[$vid];
                $candidate['last_correction'] = [
                    'when' => $c['when'] ?? null,
                    'by' => $c['user_name'] ?? '',
                    'before' => $c['before'] ?? null,
                    'after' => $c['after'] ?? null,
                ];
            }

            // Force suggested_qty to 0 — this bucket is a warning list, not
            // a reorder list. rowToCandidate may have nudged it to 1 if a
            // small sold-window was passed in some future call path.
            $candidate['suggested_qty'] = 0;
            $items[] = $candidate;
        }

        $totalTied = 0.0;
        foreach ($items as $it) {
            $totalTied += (float) ($it['tied_up_value'] ?? 0);
        }

        return [
            'label' => 'Frozen inventory — DO NOT reorder',
            'why' => 'Stock-on-shelf with no sale in ' . $frozenDays . '+ days. Total $' . number_format($totalTied, 0) . ' tied up here. Cross-reference: rows in other buckets that match these are tagged "frozen_dupe".',
            'items' => $items,
            'count' => count($items),
            'frozen_days' => $frozenDays,
            'tied_up_value_total' => round($totalTied, 2),
        ];
    }

    // ── Long OOS essentials (auto-detected) ───────────────────────────

    protected function bucketLongOosEssentials(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets.long_oos_essentials', [
            'lookback_days' => 365,
            'min_lifetime_sold' => 12,
            'min_oos_days' => 14,
            'target_stock' => 2,
        ]);

        $lookbackStart = Carbon::now()->subDays((int) $cfg['lookback_days'])->format('Y-m-d');
        $today = Carbon::now()->format('Y-m-d');

        // Sum sales per variation at location over lookback
        $sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$lookbackStart, $today])
            ->groupBy('tsl.variation_id')
            ->havingRaw('SUM(tsl.quantity - tsl.quantity_returned) >= ?', [(float) $cfg['min_lifetime_sold']])
            ->select('tsl.variation_id', DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty'),
                DB::raw('MAX(t.transaction_date) as last_sold_at'))
            ->get();

        if ($sold->isEmpty()) {
            return [
                'label' => '⚠️ Long out-of-stock essentials',
                'why' => 'Sold ' . $cfg['min_lifetime_sold'] . '+ in the last ' . $cfg['lookback_days'] . 'd; currently OOS for ' . $cfg['min_oos_days'] . '+ days.',
                'items' => [],
                'count' => 0,
            ];
        }

        $soldMap = [];
        $lastSoldMap = [];
        foreach ($sold as $row) {
            $soldMap[(int) $row->variation_id] = (float) $row->sold_qty;
            $lastSoldMap[(int) $row->variation_id] = $row->last_sold_at;
        }

        // Pull stock cache for these variations, filter by stock=0
        $variationIds = array_keys($soldMap);
        $minOosDate = Carbon::now()->subDays((int) $cfg['min_oos_days'])->format('Y-m-d H:i:s');

        $rows = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->whereIn('psc.variation_id', $variationIds)
            ->where('psc.stock', '<=', 0)
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
                'psc.product', 'psc.product_variation', 'psc.variation_name', 'psc.location_name',
                'psc.category_name', 'psc.product_custom_field1', 'psc.total_sold', 'psc.type',
                'psc.category_id', 'psc.sub_category_id', 'subcat.name as genre',
                'p.format as product_format', 'p.bin_position',
            ])
            ->limit(500)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $vid = (int) $row->variation_id;
            $lastSold = $lastSoldMap[$vid] ?? null;
            if ($lastSold && Carbon::parse($lastSold)->gt(Carbon::parse($minOosDate))) {
                // sold within the OOS window — not "long out of stock"
                continue;
            }

            $items[] = $this->rowToCandidate(
                $row,
                (float) $row->stock,
                $soldMap[$vid] ?? 0,
                (int) $cfg['target_stock'],
                [
                    'bucket' => 'long_oos_essentials',
                    'reason' => 'sold ' . (int) $soldMap[$vid] . ' in last ' . $cfg['lookback_days'] . 'd; OOS since ~' . ($lastSold ? substr($lastSold, 0, 10) : 'unknown'),
                ]
            );
        }

        usort($items, fn ($a, $b) => $b['sold_qty_window'] <=> $a['sold_qty_window']);

        $this->attachSupplierPrices($business_id, $items);

        return [
            'label' => '⚠️ Long out-of-stock essentials',
            'why' => 'Core titles: sold ' . $cfg['min_lifetime_sold'] . '+ in the last ' . $cfg['lookback_days'] . 'd, currently OOS for ' . $cfg['min_oos_days'] . '+ days.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Hot used, currently out (watchlist, not reorderable) ──────

    /**
     * Used titles that have sold N+ copies in the last 90 days but we
     * have 0 on hand. Unlike sealed, you can't order a used copy from
     * AMS — these come from customer trade-ins / Discogs. The bucket
     * is advisory: "when a copy walks in, prioritize it".
     */
    protected function bucketHotUsedOos(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets.hot_used_oos', [
            'category_patterns' => ['Used Vinyl', 'Used CD'],
            'sale_days' => 90,
            'min_sold' => 3,
            'max_stock' => 0,
        ]);

        $catIds = [];
        foreach ((array) ($cfg['category_patterns'] ?? []) as $pattern) {
            foreach ($this->categoryIdsMatching($business_id, $pattern) as $id) {
                $catIds[] = $id;
            }
        }
        $catIds = array_values(array_unique($catIds));

        if (empty($catIds)) {
            return [
                'label' => 'Hot used, currently out',
                'why' => 'No categories matched "Used Vinyl" or "Used CD" — check your ERP category names in config/inventory_check.php.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_used_categories',
            ];
        }

        $saleDays = (int) ($cfg['sale_days'] ?? 90);
        $minSold = (float) ($cfg['min_sold'] ?? 2);
        $maxStock = (float) ($cfg['max_stock'] ?? 0);
        $saleStart = Carbon::now()->subDays($saleDays)->format('Y-m-d');
        $saleEnd = Carbon::now()->format('Y-m-d');

        // Aggregate sold qty by PRODUCT (title), not variation. Used
        // items are typically one variation per physical copy, so a
        // single title sells across many variations (different grades,
        // copies, etc). Summing at the product level is the right
        // semantic for "did we move 2+ copies of this album used?".
        $soldByProduct = $this->getSoldQtyByProduct($business_id, $locationId, $catIds, $saleStart, $saleEnd, $permittedLocations);
        if (empty($soldByProduct)) {
            return [
                'label' => 'Hot used, currently out',
                'why' => 'No used sales in the last ' . $saleDays . ' days at this location.',
                'items' => [],
                'count' => 0,
            ];
        }

        // Pull current stock aggregated by product for the same categories
        $stockByProduct = $this->getCurrentStockByProduct($business_id, $locationId, $catIds, $permittedLocations);
        $productMeta = $this->getProductMeta($business_id, array_keys($soldByProduct));

        $items = [];
        foreach ($soldByProduct as $productId => $soldWindow) {
            if ($soldWindow < $minSold) {
                continue;
            }
            $stock = (float) ($stockByProduct[$productId] ?? 0);
            if ($stock > $maxStock) {
                continue;
            }
            $meta = $productMeta[$productId] ?? null;
            if (!$meta) {
                continue;
            }
            $items[] = [
                'bucket' => 'hot_used_oos',
                'variation_id' => null,
                'product_id' => (int) $productId,
                'location_id' => $locationId,
                'sku' => $meta->sku ?? null,
                'product' => $meta->name ?? '—',
                'artist' => $meta->product_custom_field1 ?? '',
                'format' => $meta->format ?? null,
                'category_name' => $meta->category_name ?? null,
                'category_id' => $meta->category_id ?? null,
                'genre' => $meta->genre ?? null,
                'bin_position' => $meta->bin_position ?? null,
                'is_rsd' => $this->isRsdTitle((string) ($meta->name ?? '')),
                'location_name' => null,
                'stock' => $stock,
                'sold_qty_window' => round($soldWindow, 2),
                'suggested_qty' => 0,
                'reason' => 'sold ' . (int) $soldWindow . ' used in last ' . $saleDays . 'd; none in stock',
                'tags' => ['used', 'watchlist'],
            ];
        }

        usort($items, fn ($a, $b) => $b['sold_qty_window'] <=> $a['sold_qty_window']);

        $this->attachSupplierPrices($business_id, $items);

        return [
            'label' => 'Hot used, currently out',
            'why' => 'Used titles that sold ' . (int) $minSold . '+ copies in the last ' . $saleDays . 'd but are now gone. Watch for these on customer trade-ins and Discogs — no AMS order needed.',
            'items' => $items,
            'count' => count($items),
            'advisory_only' => true,
        ];
    }

    // ── Seasonal restock ──────────────────────────────────────────────

    /** Public alias for the lazy seasonal-restock endpoint. */
    public function bucketSeasonalPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketSeasonal($business_id, $locationId, $permittedLocations);
    }

    /** Public alias for the lazy accessories-restock endpoint. */
    public function bucketAccessoriesLowPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketAccessoriesLow($business_id, $locationId, $permittedLocations);
    }

    /**
     * Accessories (cleaning kits, brushes, inner/outer sleeves, etc.) that
     * are low or out of stock — a "do we need to order it?" stage for the
     * non-music consumables that should always be on the shelf. Unlike the
     * music buckets this isn't sales-velocity driven: anything in an
     * Accessories category at or below max_stock surfaces so it gets
     * reordered. Config-driven (buckets.accessories_low), no migration.
     */
    protected function bucketAccessoriesLow(int $business_id, int $locationId, $permittedLocations): array
    {
        $label = 'Accessories — restock cleaning kits';
        $cfg = config('inventory_check.buckets.accessories_low', []);
        $patterns = (array) ($cfg['category_patterns'] ?? ['Accessories']);
        $maxStock = (float) ($cfg['max_stock'] ?? 2);
        $targetStock = (int) ($cfg['target_stock'] ?? 4);
        $maxItems = (int) ($cfg['max_items'] ?? 100);

        $catIds = [];
        foreach ($patterns as $pattern) {
            foreach ($this->categoryIdsMatching($business_id, (string) $pattern) as $id) {
                $catIds[(int) $id] = true;
            }
        }
        $catIds = array_keys($catIds);

        if (empty($catIds)) {
            return [
                'label' => $label,
                'why' => 'No product category matched "' . implode('", "', $patterns) . '". Set the right category name under buckets.accessories_low in config/inventory_check.php.',
                'items' => [], 'count' => 0,
                'empty_reason' => 'no_categories',
            ];
        }

        $rows = $this->queryPscRows($business_id, $locationId, $catIds, $permittedLocations);
        $items = [];
        foreach ($rows as $row) {
            $stock = (float) ($row->stock ?? 0);
            if ($stock > $maxStock) {
                continue;
            }
            $items[] = $this->rowToCandidate($row, $stock, 0, $targetStock, [
                'bucket' => 'accessories_low',
                'reason' => 'accessory low — ' . (int) $stock . ' on hand, keep ' . $targetStock,
                'tags' => ['accessories'],
            ]);
        }

        $items = $this->dedupeByVariation($items);
        // Lowest stock first so the truly-out items lead the list.
        usort($items, fn ($a, $b) => $a['stock'] <=> $b['stock']);
        if (count($items) > $maxItems) {
            $items = array_slice($items, 0, $maxItems);
        }

        return [
            'label' => $label,
            'why' => 'Accessories (cleaning kits, sleeves, brushes) at or below ' . (int) $maxStock . ' on hand — reorder so they\'re never out.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    /**
     * Seasonal titles to stock up on AHEAD of the season. Each configured
     * season (Holiday, Valentine's, Halloween, plus an evergreen "Seasonal"
     * category) carries an order_months lead-time window — the bucket only
     * surfaces that season's low/OOS titles during those months so they land
     * on the shelf in time. A season with empty order_months is always active.
     *
     * A row matches a season by category-name pattern OR a title keyword.
     * Config-driven + tunable in config/inventory_check.php, no migration
     * (Sarah 2026-06-17, "we have seasonal products").
     */
    protected function bucketSeasonal(int $business_id, int $locationId, $permittedLocations): array
    {
        $label = 'Seasonal — stock up ahead of the season';
        $cfg = config('inventory_check.buckets.seasonal', []);
        $seasons = (array) ($cfg['seasons'] ?? []);
        $maxItems = (int) ($cfg['max_items'] ?? 100);
        $month = (int) Carbon::now()->month;

        if (empty($seasons)) {
            return [
                'label' => $label,
                'why' => 'No seasons configured — add them under inventory_check.buckets.seasonal in config/inventory_check.php.',
                'items' => [], 'count' => 0,
                'empty_reason' => 'no_seasons',
            ];
        }

        // Resolve which seasons are in their ordering window this month and
        // pre-compute their matching category ids + lowercased keywords.
        $active = [];
        foreach ($seasons as $key => $s) {
            $months = array_map('intval', (array) ($s['order_months'] ?? []));
            if (!empty($months) && !in_array($month, $months, true)) {
                continue;
            }
            $catIds = [];
            foreach ((array) ($s['category_patterns'] ?? []) as $pattern) {
                foreach ($this->categoryIdsMatching($business_id, (string) $pattern) as $id) {
                    $catIds[(int) $id] = true;
                }
            }
            $keywords = array_values(array_filter(array_map(
                fn ($k) => mb_strtolower(trim((string) $k)),
                (array) ($s['title_keywords'] ?? [])
            )));
            $active[(string) $key] = [
                'label' => (string) ($s['label'] ?? $key),
                'cat_ids' => $catIds,
                'keywords' => $keywords,
                'max_stock' => (float) ($s['max_stock'] ?? 1),
                'target_stock' => (int) ($s['target_stock'] ?? 3),
            ];
        }

        if (empty($active)) {
            $next = $this->seasonalNextWindow($seasons, $month);
            return [
                'label' => $label,
                'why' => $next
                    ? ('Nothing seasonal to order this week. Next up: ' . $next['label'] . ' — ordering opens in ' . $next['month_name'] . '.')
                    : 'Nothing seasonal to order this week.',
                'items' => [], 'count' => 0,
                'empty_reason' => 'no_active_season',
            ];
        }

        $rows = $this->queryPscRows($business_id, $locationId, [], $permittedLocations);
        $items = [];
        foreach ($rows as $row) {
            $catId = (int) ($row->category_id ?? 0);
            $nameLower = mb_strtolower((string) ($row->product ?? ''));
            $matchKey = null;
            $match = null;
            foreach ($active as $sk => $s) {
                $hit = ($catId !== 0 && isset($s['cat_ids'][$catId]));
                if (!$hit) {
                    foreach ($s['keywords'] as $kw) {
                        if ($kw !== '' && mb_strpos($nameLower, $kw) !== false) { $hit = true; break; }
                    }
                }
                if ($hit) { $matchKey = $sk; $match = $s; break; }
            }
            if ($match === null) {
                continue;
            }
            $stock = (float) ($row->stock ?? 0);
            if ($stock > $match['max_stock']) {
                continue;
            }
            // RSD-exclusive titles aren't routine restocks — keep them out.
            if ($this->isRsdTitle((string) ($row->product ?? ''))) {
                continue;
            }
            $items[] = $this->rowToCandidate($row, $stock, 0, $match['target_stock'], [
                'bucket' => 'seasonal',
                'reason' => $match['label'] . ' — stock ' . (int) $stock . ', order ahead of the season',
                'tags' => ['seasonal', $matchKey],
            ]);
        }

        $items = $this->dedupeByVariation($items);
        // Lowest stock first, then biggest historical seller.
        usort($items, function ($a, $b) {
            return ($a['stock'] <=> $b['stock']) ?: ($b['sold_qty_window'] <=> $a['sold_qty_window']);
        });
        if (count($items) > $maxItems) {
            $items = array_slice($items, 0, $maxItems);
        }

        $names = implode(', ', array_map(fn ($s) => $s['label'], $active));

        return [
            'label' => $label,
            'why' => 'Low or out-of-stock titles for the season(s) coming up (' . $names . '). Order these now so they\'re on the shelf in time.',
            'items' => $items,
            'count' => count($items),
            'active_seasons' => array_values(array_map(fn ($s) => $s['label'], $active)),
        ];
    }

    /** Soonest upcoming season window, for the empty-state "next up" hint. */
    protected function seasonalNextWindow(array $seasons, int $month): ?array
    {
        $monthNames = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        $best = null;
        $bestDist = 13;
        foreach ($seasons as $key => $s) {
            $months = array_map('intval', (array) ($s['order_months'] ?? []));
            foreach ($months as $m) {
                $dist = (($m - $month) + 12) % 12;
                if ($dist > 0 && $dist < $bestDist) {
                    $bestDist = $dist;
                    $best = [
                        'label' => (string) ($s['label'] ?? $key),
                        'month' => $m,
                        'month_name' => $monthNames[$m] ?? ('month ' . $m),
                    ];
                }
            }
        }
        return $best;
    }

    // ── Customer wants ────────────────────────────────────────────────

    protected function bucketCustomerWants(int $business_id, int $locationId): array
    {
        // Sarah 2026-05-26: this was the main-/buckets hang. The per-row
        // tryMatchChartPickToVariation() call was firing one PSC LIKE
        // scan per customer want, up to 200 queries on the synchronous
        // path. Removed — the bucket now reports artist/title/priority
        // only and doesn't attempt to attach a variation match. The
        // /buckets call goes back to milliseconds.
        $wants = CustomerWant::where('business_id', $business_id)
            ->where('status', 'active')
            ->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)->orWhereNull('location_id');
            })
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $items = [];
        foreach ($wants as $w) {
            $items[] = [
                'bucket' => 'customer_wants',
                'customer_want_id' => $w->id,
                'artist' => $w->artist,
                'product' => $w->title,
                'format' => $w->format,
                'priority' => $w->priority,
                'notes' => $w->notes,
                'variation_id' => null,
                'product_id' => null,
                'sku' => null,
                'stock' => null,
                'suggested_qty' => $w->priority === 'high' ? 2 : 1,
                'reason' => 'customer request' . ($w->priority === 'high' ? ' (high priority)' : ''),
                'tags' => ['customer_request', 'priority_' . $w->priority],
            ];
        }

        $this->attachSupplierPrices($business_id, $items);

        return [
            'label' => '💚 Customer wants',
            'why' => 'Active "call-me-when-it-comes-in" requests from customers.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Top artists (for cross-referencing chart data) ────────────────

    /** @return array<int,string> artist names */
    public function getTopArtists(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets.top_artists', ['lookback_days' => 90, 'top_n' => 50]);
        $saleStart = Carbon::now()->subDays((int) $cfg['lookback_days'])->format('Y-m-d');
        $saleEnd = Carbon::now()->format('Y-m-d');

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->whereNotNull('p.product_custom_field1')
            ->where('p.product_custom_field1', '!=', '')
            ->groupBy('p.product_custom_field1')
            ->orderByRaw('SUM(tsl.quantity - tsl.quantity_returned) DESC')
            ->limit((int) $cfg['top_n'])
            ->select('p.product_custom_field1 as artist');

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $dataDriven = $q->pluck('artist')->filter()->map(fn ($a) => trim($a))->all();

        // Overlay Sarah's must-have display lists for the matching location.
        // These guarantee chart picks for store-wall artists tag as
        // "popular in-store" even during a slow month.
        $mustHave = $this->getMustHaveArtistsForLocation($locationId);

        $merged = array_merge($dataDriven, $mustHave);
        return collect($merged)
            ->filter()
            ->map(fn ($a) => trim($a))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function getMustHaveArtistsForLocation(int $locationId): array
    {
        $byLocation = config('inventory_check.must_have_artists_by_location', []);
        if (empty($byLocation)) {
            return [];
        }

        $loc = BusinessLocation::find($locationId);
        if (!$loc) {
            return [];
        }
        $name = mb_strtolower((string) $loc->name);

        foreach ($byLocation as $pattern => $artists) {
            // mb_strpos for PHP 7.x compat — str_contains is PHP 8.0+ and
            // this Laravel pairs with older PHP on the prod server.
            if ($pattern !== '' && mb_strpos($name, mb_strtolower($pattern)) !== false) {
                return is_array($artists) ? $artists : [];
            }
        }
        return [];
    }

    // ── Shared helpers (sold qty, sell speed, category lookup, row mapper) ───

    public function getSoldQtyByVariation(int $business_id, int $locationId, string $saleStart, string $saleEnd, $permittedLocations): array
    {
        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->groupBy('tsl.variation_id')
            ->select(
                'tsl.variation_id',
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty')
            );

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $rows = $q->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->variation_id] = (float) $row->sold_qty;
        }

        return $map;
    }

    /**
     * Aggregate sold qty by product (across all variations) for a
     * specific set of categories at a location/window. Used by the
     * "Hot used OOS" bucket where each physical copy is its own
     * variation but we care about title-level movement.
     *
     * @return array<int,float> product_id => qty sold in window
     */
    public function getSoldQtyByProduct(int $business_id, int $locationId, array $categoryIds, string $saleStart, string $saleEnd, $permittedLocations): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereIn('p.category_id', $categoryIds)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->groupBy('p.id')
            ->select('p.id as product_id', DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty'));

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->product_id] = (float) $row->sold_qty;
        }
        return $out;
    }

    /**
     * Current on-hand stock aggregated by product (across variations)
     * at a single location for a set of categories.
     *
     * @return array<int,float> product_id => current stock
     */
    public function getCurrentStockByProduct(int $business_id, int $locationId, array $categoryIds, $permittedLocations): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        $q = DB::table('product_stock_cache as psc')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->whereIn('psc.category_id', $categoryIds)
            ->groupBy('psc.product_id')
            ->select('psc.product_id', DB::raw('SUM(psc.stock) as stock'));

        if ($permittedLocations !== 'all') {
            $q->whereIn('psc.location_id', $permittedLocations);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->product_id] = (float) $row->stock;
        }
        return $out;
    }

    /**
     * Fetch display metadata (name, sku, artist, format, category) for
     * a list of product IDs. Used to dress up Hot Used rows for the UI.
     */
    public function getProductMeta(int $business_id, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $rows = DB::table('products as p')
            ->leftJoin('variations as v', function ($j) {
                $j->on('v.product_id', '=', 'p.id')->where('v.deleted_at', null);
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $business_id)
            ->whereIn('p.id', $productIds)
            ->groupBy('p.id')
            ->select([
                'p.id', 'p.name', 'p.format', 'p.product_custom_field1',
                'p.category_id', 'c.name as category_name',
                'p.sub_category_id', 'subcat.name as genre',
                'p.bin_position',
                DB::raw('MIN(v.sub_sku) as sku'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = $r;
        }
        return $out;
    }

    public function getAvgSellDaysByVariation(
        int $business_id,
        int $locationId,
        string $saleStart,
        string $saleEnd,
        ?int $supplierId,
        bool $excludeZeroDay,
        $permittedLocations,
        ?array $variationIds = null
    ): array {
        // If caller passes variationIds, scope the join to only those.
        // bucketFastOos was the slowest call on the page because this
        // query joined the full 90-day sell-lines × purchase-lines set
        // (millions of row pairs) and built avg_sell_days for variations
        // that weren't even in the candidate PSC list. Scoping with
        // whereIn drops the work by an order of magnitude (Sarah hit a
        // 30s+ "Loading…" 2026-05-20).
        if ($variationIds !== null && empty($variationIds)) {
            return [];
        }

        $q = DB::table('transaction_sell_lines_purchase_lines as tslp')
            ->join('purchase_lines as pl', 'pl.id', '=', 'tslp.purchase_line_id')
            ->join('transactions as purchase', 'purchase.id', '=', 'pl.transaction_id')
            ->leftJoin('transaction_sell_lines as sl', 'sl.id', '=', 'tslp.sell_line_id')
            ->leftJoin('transactions as sale', 'sale.id', '=', 'sl.transaction_id')
            ->where('purchase.business_id', $business_id)
            ->where('purchase.location_id', $locationId)
            ->whereNotNull('purchase.transaction_date')
            ->whereNotNull('sale.transaction_date')
            ->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$saleStart, $saleEnd]);

        if ($variationIds !== null) {
            $q->whereIn('pl.variation_id', $variationIds);
        }
        if ($supplierId) {
            $q->where('purchase.contact_id', $supplierId);
        }
        if ($permittedLocations !== 'all') {
            $q->whereIn('purchase.location_id', $permittedLocations);
        }

        $rows = $q->select(
            'pl.variation_id',
            DB::raw('DATEDIFF(sale.transaction_date, purchase.transaction_date) as sell_days')
        )->get();

        $sums = [];
        foreach ($rows as $row) {
            $days = max(0.0, (float) $row->sell_days);
            if ($excludeZeroDay && $days <= 0) {
                continue;
            }
            $vid = (int) $row->variation_id;
            if (!isset($sums[$vid])) {
                $sums[$vid] = ['sum' => 0.0, 'count' => 0];
            }
            $sums[$vid]['sum'] += $days;
            $sums[$vid]['count']++;
        }

        $out = [];
        foreach ($sums as $vid => $agg) {
            if ($agg['count'] > 0) {
                $out[$vid] = [
                    'avg_days' => $agg['sum'] / $agg['count'],
                    'count' => $agg['count'],
                ];
            }
        }

        return $out;
    }

    protected function categoryIdsMatching(int $business_id, string $pattern): array
    {
        if ($pattern === '') {
            return [];
        }
        return Category::where('business_id', $business_id)
            ->where('category_type', 'product')
            ->where('name', 'like', '%' . $pattern . '%')
            ->pluck('id')
            ->all();
    }

    protected function queryPscRows(int $business_id, int $locationId, array $categoryIds, $permittedLocations)
    {
        // Sarah 2026-05-20: pull sub-category as `genre` so the buckets
        // can be filtered by genre. PSC has sub_category_id but no name —
        // LEFT JOIN categories to get the label.
        $q = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId);

        if ($permittedLocations !== 'all') {
            $q->whereIn('psc.location_id', $permittedLocations);
        }
        if (!empty($categoryIds)) {
            $q->whereIn('psc.category_id', $categoryIds);
        }

        return $q->select([
            'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
            'psc.product', 'psc.type', 'psc.product_variation', 'psc.variation_name',
            'psc.location_name', 'psc.category_name', 'psc.category_id',
            'psc.sub_category_id', 'subcat.name as genre',
            'psc.product_custom_field1', 'psc.total_sold',
            'p.format as product_format',
            'p.bin_position',
            'v.default_purchase_price as cost_price',
        ])
            ->orderByDesc('psc.total_sold')
            ->limit((int) config('inventory_check.max_candidate_rows', 2000))
            ->get();
    }

    protected function rowToCandidate($row, float $stock, float $soldWindow, int $targetStock, array $extra = []): array
    {
        $maxLine = (int) config('inventory_check.max_order_line_qty', 25);
        $suggested = max(0, $targetStock - (int) $stock);
        $suggested = min($maxLine, $suggested);
        if ($suggested < 1 && $soldWindow > 0) {
            $suggested = 1;
        }

        $artist = $row->product_custom_field1 ?? '';

        return array_merge([
            'variation_id' => (int) $row->variation_id,
            'product_id' => (int) $row->product_id,
            'location_id' => (int) $row->location_id,
            'sku' => $row->sku,
            'product' => $row->product,
            'artist' => $artist,
            'format' => $row->product_format ?? null,
            'category_name' => $row->category_name,
            'category_id' => $row->category_id ?? null,
            'genre' => $row->genre ?? null,
            'sub_category_id' => $row->sub_category_id ?? null,
            'location_name' => $row->location_name,
            'bin_position' => $row->bin_position ?? null,
            'cost_price' => isset($row->cost_price) ? (float) $row->cost_price : null,
            'is_rsd' => $this->isRsdTitle($row->product ?? ''),
            'stock' => $stock,
            'sold_qty_window' => round($soldWindow, 2),
            'suggested_qty' => (int) $suggested,
            'variation_label' => ($row->type ?? '') === 'variable'
                ? trim(($row->product_variation ?? '') . ' — ' . ($row->variation_name ?? ''), ' —')
                : '',
            'tags' => [],
        ], $extra);
    }

    /**
     * Detect Record Store Day titles by name. No structured RSD flag
     * exists in the schema (Sarah 2026-05-20) so we look for the
     * common markers cashiers + AMS put in the title.
     */
    protected function isRsdTitle(string $name): bool
    {
        if ($name === '') return false;
        $lower = mb_strtolower($name);
        if (mb_strpos($lower, 'rsd') !== false) return true;
        if (mb_strpos($lower, 'record store day') !== false) return true;
        if (mb_strpos($lower, 'black friday rsd') !== false) return true;
        return false;
    }

    protected function dedupeByVariation(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $key = (int) ($it['variation_id'] ?? 0);
            if ($key === 0) {
                $out[] = $it;
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $it;
        }
        return $out;
    }
}
