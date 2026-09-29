<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buy results: for every accepted buy-from-customer line, what we paid vs.
 * what it has actually sold for and how fast. Read-only.
 *
 * Bought items are followed to sales two ways (see build()): the placeholder
 * products the buy form creates on accept, and products listed on Mass Add
 * with the buy's record # — the path staff actually use. Items that haven't
 * been listed yet count as bought-but-unsold, so the report also shows how
 * much of the buying it can see ("coverage").
 *
 * Used to check the calculator's multipliers against reality: "paid % of sale
 * price" per group is the number the standard/grade multipliers are trying
 * to hit.
 */
class BuyResultsReportService
{
    const GROUPS = [
        'item_type' => 'Item type',
        'price_tier' => 'Discogs median tier (individual vinyl)',
        'grade' => 'Grade',
        'disposition' => 'Destination',
        'store' => 'Store bought at',
    ];

    const DISPOSITIONS = [
        'store' => 'Store',
        'discogs' => 'Discogs',
        'ebay' => 'eBay',
        'hollywood' => 'Hollywood',
        'trash' => 'Trash',
        'clearance_bin' => 'Clearance Bin',
    ];

    /** @var BuyOfferCalculatorService */
    protected $calculator;

    public function __construct(BuyOfferCalculatorService $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * @param  int  $business_id
     * @param  string  $start  Y-m-d, accepted on/after
     * @param  string  $end  Y-m-d, accepted on/before
     * @param  int|null  $location_id  store the buy happened at
     * @param  string  $groupBy  one of self::GROUPS
     */
    public function build($business_id, $start, $end, $location_id, $groupBy)
    {
        if (!isset(self::GROUPS[$groupBy])) {
            $groupBy = 'item_type';
        }

        // Two ways a bought item reaches a sale:
        //  1. the placeholder product the buy form creates on accept (offer
        //     line → purchase_line_id → the POS's sale-to-purchase mapping);
        //  2. a product listed on Mass Add with this buy's record # (tagged
        //     products.buy_offer_line_id) — how boxes actually get processed.
        // Revenue is after discount and before tax (unit_price_inc_tax −
        // item_tax, both per unit), net of returns. Days are carried as a
        // quantity-weighted sum of TO_DAYS(sale date) so the accept day can be
        // subtracted per line afterwards.
        $placeholderSales = DB::table('transaction_sell_lines_purchase_lines as tslp')
            ->join('transaction_sell_lines as tsl', 'tsl.id', '=', 'tslp.sell_line_id')
            ->join('transactions as sale', 'sale.id', '=', 'tsl.transaction_id')
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->groupBy('tslp.purchase_line_id')
            ->selectRaw('tslp.purchase_line_id,
                SUM(tslp.quantity - COALESCE(tslp.qty_returned, 0)) as sold_qty,
                SUM((tslp.quantity - COALESCE(tslp.qty_returned, 0)) * (COALESCE(tsl.unit_price_inc_tax, 0) - COALESCE(tsl.item_tax, 0))) as revenue,
                SUM((tslp.quantity - COALESCE(tslp.qty_returned, 0)) * TO_DAYS(sale.transaction_date)) as sale_day_sum');

        $q = DB::table('buy_customer_offer_lines as l')
            ->join('buy_customer_offers as o', 'o.id', '=', 'l.offer_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'o.location_id')
            ->leftJoinSub($placeholderSales, 's', 's.purchase_line_id', '=', 'l.purchase_line_id')
            ->where('o.business_id', $business_id)
            ->where('o.status', 'accepted')
            ->whereBetween(DB::raw('DATE(COALESCE(o.accepted_at, o.created_at))'), [$start, $end]);
        if (!empty($location_id)) {
            $q->where('o.location_id', $location_id);
        }

        $select = 'l.id as line_id, l.offer_id, l.item_type, l.condition_grade, l.disposition, l.discogs_median_price,
                l.quantity as line_qty, l.purchase_line_id, l.line_cash_total, l.line_credit_total,
                o.payout_type, o.calculated_cash_total, o.calculated_credit_total, o.final_offer_cash, o.final_offer_credit,
                bl.name as store_name,
                COALESCE(s.sold_qty, 0) as sold_qty,
                COALESCE(s.revenue, 0) as revenue,
                COALESCE(s.sale_day_sum, 0) as sale_day_sum,
                TO_DAYS(COALESCE(o.accepted_at, o.created_at)) as accept_day';

        if (Schema::hasColumn('products', 'buy_offer_line_id')) {
            $taggedSales = DB::table('transaction_sell_lines as tsl')
                ->join('products as p', 'p.id', '=', 'tsl.product_id')
                ->join('transactions as sale', 'sale.id', '=', 'tsl.transaction_id')
                ->whereNotNull('p.buy_offer_line_id')
                ->where('sale.type', 'sell')
                ->where('sale.status', 'final')
                ->groupBy('p.buy_offer_line_id')
                ->selectRaw('p.buy_offer_line_id,
                    SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) as sold_qty,
                    SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (COALESCE(tsl.unit_price_inc_tax, 0) - COALESCE(tsl.item_tax, 0))) as revenue,
                    SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * TO_DAYS(sale.transaction_date)) as sale_day_sum');
            $taggedListed = DB::table('products')
                ->whereNotNull('buy_offer_line_id')
                ->groupBy('buy_offer_line_id')
                ->selectRaw('buy_offer_line_id, COUNT(*) as listed');
            $q->leftJoinSub($taggedSales, 'ts', 'ts.buy_offer_line_id', '=', 'l.id')
                ->leftJoinSub($taggedListed, 'tl', 'tl.buy_offer_line_id', '=', 'l.id');
            $select .= ', COALESCE(ts.sold_qty, 0) as tagged_sold_qty,
                COALESCE(ts.revenue, 0) as tagged_revenue,
                COALESCE(ts.sale_day_sum, 0) as tagged_sale_day_sum,
                COALESCE(tl.listed, 0) as listed';
        } else {
            $select .= ', 0 as tagged_sold_qty, 0 as tagged_revenue, 0 as tagged_sale_day_sum, 0 as listed';
        }

        $rows = $q->selectRaw($select)->get();

        $today = (int) DB::selectOne('SELECT TO_DAYS(CURDATE()) as d')->d;
        $labels = $this->itemTypeLabels();
        $unitCosts = $this->unitCosts($rows);

        $groups = [];
        $coverage = ['units' => 0.0, 'followed_units' => 0.0, 'listed_units' => 0.0];
        $totals = $this->emptyGroup('All accepted buys');

        foreach ($rows as $r) {
            $lineQty = (float) $r->line_qty;
            $coverage['units'] += $lineQty;
            $coverage['listed_units'] += min($lineQty, (float) $r->listed);
            if (empty($r->purchase_line_id) && (int) $r->listed === 0) {
                continue;
            }
            $coverage['followed_units'] += $lineQty;

            $r->unit_cost = $unitCosts[$r->line_id] ?? 0.0;
            $r->sold_qty = (float) $r->sold_qty + (float) $r->tagged_sold_qty;
            $r->revenue = (float) $r->revenue + (float) $r->tagged_revenue;
            $r->sale_day_sum = (float) $r->sale_day_sum + (float) $r->tagged_sale_day_sum;

            $key = $this->groupKey($groupBy, $r, $labels);
            if (!isset($groups[$key])) {
                $groups[$key] = $this->emptyGroup($key);
            }
            $this->addRow($groups[$key], $r, $today);
            $this->addRow($totals, $r, $today);
        }

        $groups = array_map([$this, 'finish'], $groups);
        uasort($groups, function ($a, $b) {
            return $b['paid'] <=> $a['paid'];
        });
        if ($groupBy === 'price_tier') {
            // Tier order reads better low → high than by spend.
            uksort($groups, function ($a, $b) {
                return $this->tierSort($a) <=> $this->tierSort($b);
            });
        }

        return [
            'group_by' => $groupBy,
            'groups' => array_values($groups),
            'totals' => $this->finish($totals),
            'coverage' => $coverage,
            'buyers' => $this->buyerSummary($business_id, $start, $end, $location_id),
        ];
    }

    /**
     * Who pays what, per employee who wrote the buy. Everything is in cash
     * terms (final_offer_cash is stored for store-credit buys too; credit is
     * always 1.5x of it), so buyers who pay in credit aren't penalized.
     * "Paid % of calculator" only uses offers the calculator priced; the form's
     * default final offer is 95%, so lower = negotiated down, over 100% = paid
     * above the sheet. Avg per item depends on what they buy (CD lots vs.
     * turntables), so the calculator % is the fairer comparison.
     */
    public function buyerSummary($business_id, $start, $end, $location_id)
    {
        $qty = DB::table('buy_customer_offer_lines')
            ->groupBy('offer_id')
            ->selectRaw('offer_id, SUM(quantity) as items');

        $q = DB::table('buy_customer_offers as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->leftJoinSub($qty, 'lq', 'lq.offer_id', '=', 'o.id')
            ->where('o.business_id', $business_id)
            ->where('o.status', 'accepted')
            ->whereBetween(DB::raw('DATE(COALESCE(o.accepted_at, o.created_at))'), [$start, $end]);
        if (!empty($location_id)) {
            $q->where('o.location_id', $location_id);
        }
        $rows = $q->groupBy('o.created_by', 'u.first_name', 'u.last_name', 'u.username')
            ->selectRaw("o.created_by,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as name, u.username,
                COUNT(*) as offers,
                SUM(COALESCE(lq.items, 0)) as items,
                SUM(o.final_offer_cash) as paid,
                SUM(CASE WHEN o.calculated_cash_total > 0 THEN o.final_offer_cash ELSE 0 END) as priced_paid,
                SUM(CASE WHEN o.calculated_cash_total > 0 THEN o.calculated_cash_total ELSE 0 END) as priced_calc,
                SUM(CASE WHEN o.calculated_cash_total > 0 THEN 1 ELSE 0 END) as priced_offers,
                SUM(CASE WHEN o.calculated_cash_total > 0 AND o.final_offer_cash > o.calculated_cash_total * 1.001 THEN 1 ELSE 0 END) as over_calc_offers")
            ->get();

        return $rows->map(function ($r) {
            $items = (float) $r->items;
            $paid = (float) $r->paid;
            return [
                'name' => trim($r->name) !== '' ? trim($r->name) : ($r->username ?: 'User #' . $r->created_by),
                'offers' => (int) $r->offers,
                'items' => $items,
                'paid' => $paid,
                'avg_per_item' => $items > 0 ? $paid / $items : null,
                'avg_per_offer' => $r->offers > 0 ? $paid / $r->offers : null,
                'pct_of_calc' => (float) $r->priced_calc > 0 ? (float) $r->priced_paid / (float) $r->priced_calc : null,
                'priced_offers' => (int) $r->priced_offers,
                'over_calc_offers' => (int) $r->over_calc_offers,
            ];
        })->sortBy(function ($b) {
            return $b['pct_of_calc'] ?? 99;
        })->values()->all();
    }

    /**
     * Per-unit cost of each line: its share of the negotiated payout, the same
     * split BuyCustomerOffer::lineUnitCosts() and the accept step use. Offers
     * the calculator never priced spread the payout evenly over all units.
     *
     * @return array<int, float>  keyed by line id
     */
    protected function unitCosts($rows)
    {
        $offerQty = [];
        foreach ($rows as $r) {
            $offerQty[$r->offer_id] = ($offerQty[$r->offer_id] ?? 0) + max(0, (float) $r->line_qty);
        }
        $out = [];
        foreach ($rows as $r) {
            $qty = (float) $r->line_qty;
            if ($qty <= 0) {
                $out[$r->line_id] = 0.0;
                continue;
            }
            $isCredit = $r->payout_type === 'store_credit';
            $calc = (float) ($isCredit ? $r->calculated_credit_total : $r->calculated_cash_total);
            $final = (float) ($isCredit ? $r->final_offer_credit : $r->final_offer_cash);
            if ($calc > 0) {
                $line = (float) ($isCredit ? $r->line_credit_total : $r->line_cash_total);
                $out[$r->line_id] = $line * ($final / $calc) / $qty;
            } else {
                $out[$r->line_id] = $offerQty[$r->offer_id] > 0 ? $final / $offerQty[$r->offer_id] : 0.0;
            }
        }
        return $out;
    }

    protected function emptyGroup($label)
    {
        return [
            'label' => $label,
            'lines' => 0,
            'units' => 0.0,
            'listed' => 0.0,
            'paid' => 0.0,
            'sold_units' => 0.0,
            'sold_cost' => 0.0,
            'revenue' => 0.0,
            'sell_day_total' => 0.0,
            'unsold_age_total' => 0.0,
        ];
    }

    protected function addRow(array &$g, $r, $today)
    {
        $units = (float) $r->line_qty;
        $unitPaid = (float) $r->unit_cost;
        $sold = min($units, max(0.0, (float) $r->sold_qty));
        $acceptDay = (int) $r->accept_day;

        $g['lines']++;
        $g['units'] += $units;
        $g['listed'] += min($units, (float) $r->listed);
        $g['paid'] += $units * $unitPaid;
        $g['sold_units'] += $sold;
        $g['sold_cost'] += $sold * $unitPaid;
        $g['revenue'] += (float) $r->revenue;
        if ((float) $r->sold_qty > 0) {
            // Σ qty×(sale day − accept day), rescaled if returns/over-mapping
            // pushed sold_qty past the units we bought.
            $scale = $sold / (float) $r->sold_qty;
            $g['sell_day_total'] += max(0.0, ((float) $r->sale_day_sum - (float) $r->sold_qty * $acceptDay) * $scale);
        }
        $g['unsold_age_total'] += ($units - $sold) * max(0, $today - $acceptDay);
    }

    protected function finish(array $g)
    {
        $unsold = $g['units'] - $g['sold_units'];
        $g['sell_through'] = $g['units'] > 0 ? $g['sold_units'] / $g['units'] : null;
        $g['profit_on_sold'] = $g['revenue'] - $g['sold_cost'];
        $g['avg_paid'] = $g['units'] > 0 ? $g['paid'] / $g['units'] : null;
        $g['avg_sale'] = $g['sold_units'] > 0 ? $g['revenue'] / $g['sold_units'] : null;
        // What share of the eventual sale price we paid, on the units that sold.
        $g['paid_pct_of_sale'] = $g['revenue'] > 0 ? $g['sold_cost'] / $g['revenue'] : null;
        // Every $1 paid for this group has brought back $X so far (sold or not).
        $g['return_per_dollar'] = $g['paid'] > 0 ? $g['revenue'] / $g['paid'] : null;
        $g['avg_days_to_sell'] = $g['sold_units'] > 0 ? $g['sell_day_total'] / $g['sold_units'] : null;
        $g['unsold_units'] = $unsold;
        $g['unsold_avg_age'] = $unsold > 0 ? $g['unsold_age_total'] / $unsold : null;
        return $g;
    }

    protected function groupKey($groupBy, $r, array $labels)
    {
        switch ($groupBy) {
            case 'price_tier':
                return $this->tierLabel($r);
            case 'grade':
                return $r->condition_grade ?: 'Not graded';
            case 'disposition':
                return self::DISPOSITIONS[$r->disposition] ?? 'Not set';
            case 'store':
                return $r->store_name ?: 'Unknown store';
            case 'item_type':
            default:
                return $labels[$r->item_type] ?? $r->item_type;
        }
    }

    /**
     * Same breakpoints as computeStdMult() on the buy form, so each row maps
     * to exactly one standard-multiplier step.
     */
    protected function tierLabel($r)
    {
        if ($r->item_type !== 'individual_vinyl') {
            return 'Not individual vinyl';
        }
        $p = (float) $r->discogs_median_price;
        if ($p <= 0) return 'No median entered';
        if ($p < 5) return 'Under $5 (10%)';
        if ($p < 10) return '$5–9.99 (20%)';
        if ($p < 15) return '$10–14.99 (22%)';
        if ($p < 20) return '$15–19.99 (25%)';
        if ($p < 30) return '$20–29.99 (26%)';
        if ($p < 375) return '$30–374.99 (27%)';
        return '$375+ (31%)';
    }

    protected function tierSort($label)
    {
        $order = ['No median', 'Under $5', '$5–', '$10–', '$15–', '$20–', '$30–', '$375+', 'Not individual'];
        foreach ($order as $i => $prefix) {
            if (strpos($label, $prefix) === 0) {
                return $i;
            }
        }
        return 99;
    }

    protected function itemTypeLabels()
    {
        $out = [];
        foreach ($this->calculator->getRules()['item_types'] as $key => $cfg) {
            $out[$key] = $cfg['label'];
        }
        return $out;
    }
}
