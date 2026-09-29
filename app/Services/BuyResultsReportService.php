<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Buy results: for every accepted buy-from-customer line, what we paid vs.
 * what it has actually sold for and how fast. Read-only.
 *
 * The chain is offer line → purchase_line_id (set when the offer is accepted
 * and materialized) → transaction_sell_lines_purchase_lines (the POS's own
 * sale-to-purchase mapping, same one the Fastest Selling Genres widget uses)
 * → the sale. Lines with no title never get a purchase line, and a purchase
 * that is still draft has no stock to sell yet, so the report also shows how
 * much of the buying it can actually see ("coverage").
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

        // Sales per purchase line, net of returns. Revenue is after discount
        // and before tax (unit_price_inc_tax - item_tax, both per unit). Days
        // are carried as a quantity-weighted sum of TO_DAYS(sale date) so the
        // outer query can subtract the accept day without a second join.
        $sales = DB::table('transaction_sell_lines_purchase_lines as tslp')
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
            ->leftJoin('purchase_lines as pl', 'pl.id', '=', 'l.purchase_line_id')
            ->leftJoin('transactions as pt', 'pt.id', '=', 'pl.transaction_id')
            ->leftJoinSub($sales, 's', 's.purchase_line_id', '=', 'l.purchase_line_id')
            ->where('o.business_id', $business_id)
            ->where('o.status', 'accepted')
            ->whereBetween(DB::raw('DATE(COALESCE(o.accepted_at, o.created_at))'), [$start, $end]);
        if (!empty($location_id)) {
            $q->where('o.location_id', $location_id);
        }

        $rows = $q->selectRaw('l.item_type, l.condition_grade, l.disposition, l.discogs_median_price,
                l.quantity as line_qty, l.purchase_line_id,
                bl.name as store_name, pt.status as purchase_status,
                pl.quantity as pl_qty, pl.purchase_price,
                COALESCE(s.sold_qty, 0) as sold_qty,
                COALESCE(s.revenue, 0) as revenue,
                COALESCE(s.sale_day_sum, 0) as sale_day_sum,
                TO_DAYS(COALESCE(o.accepted_at, o.created_at)) as accept_day')
            ->get();

        $today = (int) DB::selectOne('SELECT TO_DAYS(CURDATE()) as d')->d;
        $labels = $this->itemTypeLabels();

        $groups = [];
        $coverage = ['units' => 0.0, 'linked_units' => 0.0, 'received_units' => 0.0];
        $totals = $this->emptyGroup('All accepted buys');

        foreach ($rows as $r) {
            $lineQty = (float) $r->line_qty;
            $coverage['units'] += $lineQty;
            if (empty($r->purchase_line_id)) {
                continue;
            }
            $coverage['linked_units'] += $lineQty;
            if ($r->purchase_status === 'received') {
                $coverage['received_units'] += $lineQty;
            }

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
        ];
    }

    protected function emptyGroup($label)
    {
        return [
            'label' => $label,
            'lines' => 0,
            'units' => 0.0,
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
        $units = (float) $r->pl_qty;
        $unitPaid = (float) $r->purchase_price;
        $sold = min($units, max(0.0, (float) $r->sold_qty));
        $acceptDay = (int) $r->accept_day;

        $g['lines']++;
        $g['units'] += $units;
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
