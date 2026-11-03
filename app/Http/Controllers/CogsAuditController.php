<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Real cost of goods sold, per channel (Sarah, 2026-11-02).
 *
 * The Sales by Channel report costs a sold unit at the purchase price on the
 * purchase line it was drawn from. Units sold without a purchase line attached
 * contribute no cost at all there, which quietly overstates margin (and, where
 * returns are involved, produces nonsense like Whatnot Hollywood's -671%).
 *
 * This page fills those gaps the way Sarah asked for them to be filled:
 *   1. the purchase price on the sold unit's own purchase line,
 *   2. else the price that product was last actually invoiced at,
 *   3. else the standard category cost rule.
 *
 * Read-only. It changes nothing; it only shows what the cost would be and how
 * much of it rests on each of the three sources.
 */
class CogsAuditController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        if (!$user || !$user->can('purchase.view')) {
            abort(403, 'Unauthorized action.');
        }
        $businessId = (int) request()->session()->get('user.business_id');
        $start = request()->get('start_date', date('Y-01-01'));
        $end   = request()->get('end_date', date('Y-m-d'));

        // Sold quantity and revenue per channel and variation.
        $sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as s', 'tsl.transaction_id', '=', 's.id')
            ->where('s.business_id', $businessId)
            ->where('s.type', 'sell')
            ->where('s.status', 'final')
            ->where('tsl.children_type', '!=', 'combo')
            ->whereDate('s.transaction_date', '>=', $start)
            ->whereDate('s.transaction_date', '<=', $end)
            ->groupBy('s.location_id', 's.channel', 'tsl.variation_id')
            ->selectRaw("s.location_id, s.channel, tsl.variation_id,
                SUM(tsl.quantity - tsl.quantity_returned) as qty,
                SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) as revenue")
            ->get();

        // Quantity and cost that a purchase line actually accounts for.
        $mapped = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as s', 'tsl.transaction_id', '=', 's.id')
            ->join('transaction_sell_lines_purchase_lines as tspl', 'tsl.id', '=', 'tspl.sell_line_id')
            ->join('purchase_lines as pl', 'tspl.purchase_line_id', '=', 'pl.id')
            ->where('s.business_id', $businessId)
            ->where('s.type', 'sell')
            ->where('s.status', 'final')
            ->where('tsl.children_type', '!=', 'combo')
            ->whereDate('s.transaction_date', '>=', $start)
            ->whereDate('s.transaction_date', '<=', $end)
            ->groupBy('s.location_id', 's.channel', 'tsl.variation_id')
            ->selectRaw("s.location_id, s.channel, tsl.variation_id,
                SUM(tspl.quantity - tspl.qty_returned) as qty,
                SUM((tspl.quantity - tspl.qty_returned) * pl.purchase_price_inc_tax) as cost")
            ->get()
            ->keyBy(function ($r) { return $r->location_id . '|' . $r->channel . '|' . $r->variation_id; });

        // Variations still short of a cost after the mapping.
        $shortfall = [];
        foreach ($sold as $r) {
            $k = $r->location_id . '|' . $r->channel . '|' . $r->variation_id;
            $mappedQty = isset($mapped[$k]) ? (float) $mapped[$k]->qty : 0.0;
            $gap = (float) $r->qty - $mappedQty;
            if ($gap > 0.0001) {
                $shortfall[$r->variation_id] = true;
            }
        }
        $gapVariations = array_keys($shortfall);

        // Source 2: what the product was last genuinely invoiced at. Latest
        // purchase line wins, and a zero is not a price.
        $lastInvoiced = [];
        foreach (array_chunk($gapVariations, 2000) as $chunk) {
            $rows = DB::table('purchase_lines')
                ->whereIn('variation_id', $chunk)
                ->where('purchase_price_inc_tax', '>', 0)
                ->orderBy('id')
                ->get(['variation_id', 'purchase_price_inc_tax']);
            foreach ($rows as $row) {
                $lastInvoiced[$row->variation_id] = (float) $row->purchase_price_inc_tax;
            }
        }

        // Source 3: the variation's own default, else the category rule.
        $defaults = [];
        $categoryOf = [];
        foreach (array_chunk($gapVariations, 2000) as $chunk) {
            $rows = DB::table('variations as v')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->whereIn('v.id', $chunk)
                ->get(['v.id', 'v.dpp_inc_tax', 'v.default_purchase_price', 'p.category_id']);
            foreach ($rows as $row) {
                $dpp = (float) ($row->dpp_inc_tax ?: $row->default_purchase_price);
                if ($dpp > 0) {
                    $defaults[$row->id] = $dpp;
                }
                $categoryOf[$row->id] = (int) $row->category_id;
            }
        }
        $ruleCost = CostPriceRulesController::defaultCostsByCategoryId($businessId);

        // Roll the three sources up per channel.
        $rows = [];
        foreach ($sold as $r) {
            $k = $r->location_id . '|' . $r->channel . '|' . $r->variation_id;
            $channelKey = $r->location_id . '|' . ($r->channel ?: 'in_store');
            if (!isset($rows[$channelKey])) {
                $rows[$channelKey] = [
                    'location_id'   => $r->location_id,
                    'channel'       => $r->channel ?: 'in_store',
                    'revenue'       => 0.0,
                    'qty'           => 0.0,
                    'cost_purchase' => 0.0,
                    'cost_invoice'  => 0.0,
                    'cost_rule'     => 0.0,
                    'qty_purchase'  => 0.0,
                    'qty_invoice'   => 0.0,
                    'qty_rule'      => 0.0,
                    'qty_nocost'    => 0.0,
                ];
            }
            $row = &$rows[$channelKey];
            $row['revenue'] += (float) $r->revenue;
            $row['qty']     += (float) $r->qty;

            $mappedQty = isset($mapped[$k]) ? (float) $mapped[$k]->qty : 0.0;
            $row['cost_purchase'] += isset($mapped[$k]) ? (float) $mapped[$k]->cost : 0.0;
            $row['qty_purchase']  += $mappedQty;

            $gap = (float) $r->qty - $mappedQty;
            if ($gap <= 0.0001) {
                unset($row);
                continue;
            }
            $vid = $r->variation_id;
            if (isset($lastInvoiced[$vid])) {
                $row['cost_invoice'] += $gap * $lastInvoiced[$vid];
                $row['qty_invoice']  += $gap;
            } elseif (isset($defaults[$vid])) {
                $row['cost_rule'] += $gap * $defaults[$vid];
                $row['qty_rule']  += $gap;
            } elseif (!empty($categoryOf[$vid]) && isset($ruleCost[$categoryOf[$vid]])) {
                $row['cost_rule'] += $gap * $ruleCost[$categoryOf[$vid]];
                $row['qty_rule']  += $gap;
            } else {
                $row['qty_nocost'] += $gap;
            }
            unset($row);
        }

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->pluck('name', 'id');

        foreach ($rows as $k => $row) {
            $rows[$k]['cogs'] = $row['cost_purchase'] + $row['cost_invoice'] + $row['cost_rule'];
            $rows[$k]['gross_profit'] = $row['revenue'] - $rows[$k]['cogs'];
            $rows[$k]['margin'] = $row['revenue'] > 0
                ? ($rows[$k]['gross_profit'] / $row['revenue']) * 100 : 0;
            $rows[$k]['label'] = $this->label($row['channel'], $locations[$row['location_id']] ?? '');
        }
        usort($rows, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });

        $totals = [
            'revenue'       => array_sum(array_column($rows, 'revenue')),
            'cogs'          => array_sum(array_column($rows, 'cogs')),
            'cost_purchase' => array_sum(array_column($rows, 'cost_purchase')),
            'cost_invoice'  => array_sum(array_column($rows, 'cost_invoice')),
            'cost_rule'     => array_sum(array_column($rows, 'cost_rule')),
            'qty_purchase'  => array_sum(array_column($rows, 'qty_purchase')),
            'qty_invoice'   => array_sum(array_column($rows, 'qty_invoice')),
            'qty_rule'      => array_sum(array_column($rows, 'qty_rule')),
            'qty_nocost'    => array_sum(array_column($rows, 'qty_nocost')),
        ];
        $totals['gross_profit'] = $totals['revenue'] - $totals['cogs'];
        $totals['margin'] = $totals['revenue'] > 0
            ? ($totals['gross_profit'] / $totals['revenue']) * 100 : 0;

        return view('reports.cogs_audit', compact('rows', 'totals', 'start', 'end'));
    }

    private function label($channel, $locationName)
    {
        $store = trim(str_ireplace(['nivessa', 'records'], '', $locationName)) ?: $locationName;
        switch ($channel) {
            case 'whatnot':  return 'Whatnot ' . $store;
            case 'discogs':  return 'Discogs';
            case 'ebay':     return 'eBay';
            case 'web':      return 'Website';
            case 'space_rental': return 'Space Rentals';
            default:         return $store . ' Store';
        }
    }
}
