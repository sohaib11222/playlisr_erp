<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Nivessa Intelligence — one admin page that reads the whole ERP and points at
 * where money is being made, lost or missed (dead capital, pricing errors,
 * store-to-store transfer gaps, what customers ask for that we don't carry,
 * and gross profit by category per store). Plus the roadmap of the bigger AI
 * ideas, linking the ones that already exist elsewhere in the ERP.
 *
 * Every number comes from ERP tables; cost = the variation's purchase price,
 * so profit figures are estimates. Results cache for an hour (?refresh=1).
 */
class NivessaIntelligenceController extends Controller
{
    const DEAD_DAYS = 180;
    const TRANSFER_SALES_DAYS = 90;
    const CACHE_MINUTES = 60;

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        if (!auth()->user()->hasRole('Admin#' . $business_id)) {
            abort(403, 'Nivessa Intelligence is admin-only.');
        }

        $key = 'nivessa_intel_v1_' . $business_id;
        if ($request->get('refresh')) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($business_id) {
            $locations = DB::table('business_locations')->where('business_id', $business_id)
                ->pluck('name', 'id')->all();
            $out = ['locations' => $locations, 'generated_at' => now()->toDateTimeString()];
            foreach (['dead' => 'deadCapital', 'pricing' => 'pricingErrors', 'transfers' => 'transfers',
                      'missed' => 'missedDemand', 'profit' => 'profitByCategory'] as $k => $fn) {
                try {
                    $out[$k] = $this->$fn($business_id, $locations);
                } catch (\Throwable $e) {
                    \Log::warning('NivessaIntelligence ' . $fn . ': ' . $e->getMessage());
                    $out[$k] = ['error' => true];
                }
            }
            return $out;
        });

        return view('nivessa_intelligence.index', ['d' => $data, 'deadDays' => self::DEAD_DAYS, 'transferDays' => self::TRANSFER_SALES_DAYS]);
    }

    /** Variations sold (final sells) since $since, as a subquery builder. */
    protected function soldSince($business_id, $since)
    {
        return DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since);
    }

    /** In-stock items older than DEAD_DAYS that haven't sold in DEAD_DAYS: $ tied up, by store + category. */
    protected function deadCapital($business_id, $locations)
    {
        $since = now()->subDays(self::DEAD_DAYS);
        $sold = $this->soldSince($business_id, $since)->select('tsl.variation_id')->distinct();

        $base = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $business_id)
            ->where('vld.qty_available', '>', 0)
            ->where('p.created_at', '<', $since)
            ->whereNull('v.deleted_at')
            ->whereNotIn('vld.variation_id', $sold);

        $byCat = (clone $base)
            ->selectRaw("vld.location_id, COALESCE(c.name, '(uncategorized)') as category,
                COUNT(*) as items, SUM(vld.qty_available) as units,
                SUM(vld.qty_available * COALESCE(v.default_purchase_price, 0)) as cost,
                SUM(vld.qty_available * COALESCE(v.sell_price_inc_tax, 0)) as retail")
            ->groupBy('vld.location_id', 'c.name')
            ->orderByDesc('cost')->get();

        $totals = ['cost' => 0, 'retail' => 0, 'units' => 0];
        $byLoc = [];
        foreach ($byCat as $r) {
            $totals['cost'] += $r->cost; $totals['retail'] += $r->retail; $totals['units'] += $r->units;
            $loc = $locations[$r->location_id] ?? 'Unknown';
            $byLoc[$loc] = ($byLoc[$loc] ?? 0) + $r->cost;
        }
        arsort($byLoc);

        $top = (clone $base)
            ->selectRaw("p.id as product_id, p.name, p.artist, p.sku, vld.location_id, COALESCE(c.name, '') as category,
                vld.qty_available as qty, v.default_purchase_price as cost, v.sell_price_inc_tax as price,
                vld.qty_available * COALESCE(v.default_purchase_price, 0) as tied_up")
            ->orderByDesc('tied_up')->limit(40)->get();

        return ['totals' => $totals, 'by_location' => $byLoc, 'by_category' => $byCat->take(25), 'top' => $top];
    }

    /** In-stock items priced below cost, or at $1 or less. */
    protected function pricingErrors($business_id, $locations)
    {
        $rows = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $business_id)
            ->where('vld.qty_available', '>', 0)
            ->whereNull('v.deleted_at')
            ->where(function ($w) {
                $w->whereRaw('v.sell_price_inc_tax <= 1')
                  ->orWhereRaw('(v.default_purchase_price > 0 AND v.sell_price_inc_tax < v.default_purchase_price)');
            })
            ->selectRaw("p.id as product_id, p.name, p.artist, p.sku, vld.location_id, COALESCE(c.name, '') as category,
                vld.qty_available as qty, v.default_purchase_price as cost, v.sell_price_inc_tax as price,
                CASE WHEN v.sell_price_inc_tax <= 1 THEN 'At $1 or less' ELSE 'Below cost' END as issue")
            ->orderByRaw('(COALESCE(v.default_purchase_price,0) - COALESCE(v.sell_price_inc_tax,0)) DESC')
            ->limit(60)->get();

        $count = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $business_id)->where('vld.qty_available', '>', 0)->whereNull('v.deleted_at')
            ->where(function ($w) {
                $w->whereRaw('v.sell_price_inc_tax <= 1')
                  ->orWhereRaw('(v.default_purchase_price > 0 AND v.sell_price_inc_tax < v.default_purchase_price)');
            })->count();

        return ['rows' => $rows, 'count' => $count];
    }

    /**
     * Items that sold at one store in the last TRANSFER_SALES_DAYS but are out
     * there now, while another store has a copy that hasn't sold in DEAD_DAYS.
     */
    protected function transfers($business_id, $locations)
    {
        $sales = $this->soldSince($business_id, now()->subDays(self::TRANSFER_SALES_DAYS))
            ->selectRaw('tsl.variation_id, t.location_id, SUM(tsl.quantity) as units')
            ->groupBy('tsl.variation_id', 't.location_id')->get();
        if ($sales->isEmpty()) {
            return ['rows' => []];
        }
        $soldAt = [];
        foreach ($sales as $s) {
            $soldAt[$s->variation_id][$s->location_id] = (float) $s->units;
        }

        $recentSold = [];
        foreach ($this->soldSince($business_id, now()->subDays(self::DEAD_DAYS))
                     ->selectRaw('tsl.variation_id, t.location_id')->whereIn('tsl.variation_id', array_keys($soldAt))
                     ->groupBy('tsl.variation_id', 't.location_id')->get() as $r) {
            $recentSold[$r->variation_id][$r->location_id] = true;
        }

        $stock = [];
        foreach (array_chunk(array_keys($soldAt), 2000) as $chunk) {
            foreach (DB::table('variation_location_details')->whereIn('variation_id', $chunk)
                         ->get(['variation_id', 'location_id', 'qty_available']) as $r) {
                $stock[$r->variation_id][$r->location_id] = (float) $r->qty_available;
            }
        }

        $cands = [];
        foreach ($soldAt as $vid => $locs) {
            foreach ($locs as $toLoc => $units) {
                if (($stock[$vid][$toLoc] ?? 0) > 0) {
                    continue;
                }
                foreach ($stock[$vid] ?? [] as $fromLoc => $qty) {
                    if ($fromLoc == $toLoc || $qty <= 0 || !empty($recentSold[$vid][$fromLoc])) {
                        continue;
                    }
                    $cands[] = ['variation_id' => $vid, 'from' => $fromLoc, 'to' => $toLoc, 'units_sold' => $units, 'qty_from' => $qty];
                    break;
                }
            }
        }
        usort($cands, function ($a, $b) { return $b['units_sold'] <=> $a['units_sold']; });
        $total = count($cands);
        $cands = array_slice($cands, 0, 60);

        $info = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('v.id', array_column($cands, 'variation_id'))
            ->get(['v.id', 'p.id as product_id', 'p.name', 'p.artist', 'p.sku', 'v.sell_price_inc_tax as price', 'c.name as category'])
            ->keyBy('id');

        $rows = [];
        foreach ($cands as $c) {
            $i = $info[$c['variation_id']] ?? null;
            if (!$i) {
                continue;
            }
            $rows[] = (object) array_merge($c, [
                'product_id' => $i->product_id, 'name' => $i->name, 'artist' => $i->artist, 'sku' => $i->sku,
                'price' => $i->price, 'category' => $i->category,
                'from_name' => $locations[$c['from']] ?? 'Unknown', 'to_name' => $locations[$c['to']] ?? 'Unknown',
            ]);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** What customers ask for that the POS couldn't find, plus open customer wants. */
    protected function missedDemand($business_id, $locations)
    {
        $out = ['searches' => collect(), 'wants' => collect()];
        if (\Schema::hasTable('pos_search_misses')) {
            $out['searches'] = DB::table('pos_search_misses')
                ->where('business_id', $business_id)->where('is_scan', 0)
                ->where('created_at', '>=', now()->subDays(60))
                ->selectRaw('term, COUNT(*) as times, COUNT(DISTINCT location_id) as stores, MAX(created_at) as last_at')
                ->groupBy('term')->havingRaw('COUNT(*) >= 2')
                ->orderByDesc('times')->limit(30)->get();
        }
        if (\Schema::hasTable('customer_wants')) {
            $out['wants'] = DB::table('customer_wants')
                ->where('business_id', $business_id)->where('status', 'active')
                ->whereNotNull('artist')->where('artist', '<>', '')
                ->selectRaw('artist, COUNT(*) as wants, MAX(created_at) as last_at')
                ->groupBy('artist')->orderByDesc('wants')->limit(20)->get();
        }
        return $out;
    }

    /** Last 30 days: revenue, est. cost and gross profit by category per store. */
    protected function profitByCategory($business_id, $locations)
    {
        $rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where('t.transaction_date', '>=', now()->subDays(30))
            ->selectRaw("t.location_id, COALESCE(c.name, '(uncategorized)') as category,
                SUM(tsl.quantity) as units,
                SUM(tsl.quantity * tsl.unit_price_inc_tax) as revenue,
                SUM(tsl.quantity * COALESCE(v.default_purchase_price, 0)) as cost")
            ->groupBy('t.location_id', 'c.name')->get();

        $byLoc = [];
        $all = [];
        foreach ($rows as $r) {
            $r->profit = $r->revenue - $r->cost;
            $loc = $locations[$r->location_id] ?? 'Unknown';
            $byLoc[$loc][] = $r;
            $a = &$all[$r->category];
            $a = $a ?? (object) ['category' => $r->category, 'units' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0];
            $a->units += $r->units; $a->revenue += $r->revenue; $a->cost += $r->cost; $a->profit += $r->profit;
            unset($a);
        }
        foreach ($byLoc as $loc => $list) {
            usort($list, function ($x, $y) { return $y->profit <=> $x->profit; });
            $byLoc[$loc] = array_slice($list, 0, 12);
        }
        uasort($byLoc, function ($x, $y) {
            return array_sum(array_column($y, 'profit')) <=> array_sum(array_column($x, 'profit'));
        });
        $all = array_values($all);
        usort($all, function ($x, $y) { return $y->profit <=> $x->profit; });

        return ['all' => array_slice($all, 0, 15), 'by_location' => $byLoc];
    }
}
