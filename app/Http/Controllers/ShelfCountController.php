<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\ReorderService;
use Illuminate\Http\Request;

/**
 * Shelf counts (Sarah 2026-10-14: "people steal a lot from us so we need to
 * count everything in the bins").
 *
 * The store is split into sections (format + genre, e.g. "CD - Sealed /
 * Hip-Hop"). Each section is due for a count every DUE_DAYS days; the page
 * lists what's due at each store. Counting a title saves through the Weekly
 * Reorder count (ReorderService: count + ERP stock + website push), so both
 * pages share one set of counts. A count lower than the ERP lands on the
 * Missing list (ReorderService::logMissing) with units and cost.
 */
class ShelfCountController extends Controller
{
    const DUE_DAYS = 28;

    protected function location(Request $request): array
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id, false)->toArray();
        $permitted = auth()->user()->permitted_locations();
        if ($permitted !== 'all') $locations = array_intersect_key($locations, array_flip($permitted));
        $locations = array_filter($locations, function ($n) { return stripos($n, 'warehouse') === false; });
        $loc = (int) $request->input('location_id');
        if (!isset($locations[$loc])) {
            $loc = (int) (array_search(true, array_map(function ($n) { return stripos($n, 'hollywood') !== false; }, $locations)) ?: array_key_first($locations));
        }
        return [$business_id, $locations, $loc];
    }

    /** Last count time per product at this store. */
    protected function lastCounted(int $business_id, int $loc): array
    {
        $out = [];
        foreach (app(ReorderService::class)->loadState($business_id)['counts'] as $key => $c) {
            [$l, $pid] = array_map('intval', explode(':', $key));
            if ($l === $loc) $out[$pid] = $c;
        }
        return $out;
    }

    /**
     * One list for the week instead of sections (Sarah 10/14: "310 sections
     * are u joking"). Titles not counted in DUE_DAYS, sealed first, then the
     * priciest, capped at ?n= (default 250), laid out bin by bin (format,
     * genre, artist) so it's one walk through the store.
     */
    public function index(Request $request)
    {
        if (!auth()->user()->can('product.view')) abort(403);
        [$business_id, $locations, $loc] = $this->location($request);
        $n = min(1000, max(25, (int) $request->input('n', 600)));
        $kind = in_array($request->input('kind'), ['vinyl-new', 'vinyl-used', 'cd-new', 'cd-used'], true) ? $request->input('kind') : 'all';
        $counted = $this->lastCounted($business_id, $loc);
        $cut = now()->subDays(self::DUE_DAYS)->toDateTimeString();

        $rows = \DB::table('products as p')
            ->join('variation_location_details as v', function ($j) use ($loc) { $j->on('v.product_id', '=', 'p.id')->where('v.location_id', '=', $loc); })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->where('v.qty_available', '>', 0)
            ->when($kind !== 'all', function ($q) use ($kind) {
                [$fmt, $cond] = explode('-', $kind);
                $q->whereRaw('LOWER(c.name) LIKE ?', ['%' . ($fmt === 'cd' ? 'cd' : 'vinyl') . '%'])
                  ->whereRaw('LOWER(c.name) LIKE ?', ['%' . ($cond === 'new' ? 'sealed' : 'used') . '%']);
            })
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'c.name as cat', 'sc.name as sub', 'v.qty_available as qty',
                \DB::raw('(select max(sell_price_inc_tax) from variations va where va.product_id = p.id and va.deleted_at is null) as price'))
            ->get();
        $total = $rows->count();
        $fresh = 0; $due = [];
        foreach ($rows as $r) {
            $c = $counted[$r->id] ?? null;
            if ($c && $c['at'] >= $cut) { $fresh++; continue; }
            $due[] = $r;
        }
        usort($due, function ($a, $b) {
            $sa = stripos((string) $a->cat, 'sealed') !== false; $sb = stripos((string) $b->cat, 'sealed') !== false;
            // Sealed first, then straight through the bins (genre, artist) so
            // the day's list is one continuous walk, picking up tomorrow
            // where today stopped.
            return [$sb, (string) $a->cat, (string) $a->sub, mb_strtolower((string) ($a->artist ?: $a->name))]
                <=> [$sa, (string) $b->cat, (string) $b->sub, mb_strtolower((string) ($b->artist ?: $b->name))];
        });
        $week = array_slice($due, 0, $n);
        usort($week, function ($a, $b) {
            return [(string) $a->cat, (string) $a->sub, mb_strtolower((string) ($a->artist ?: $a->name)), (string) $a->name]
                <=> [(string) $b->cat, (string) $b->sub, mb_strtolower((string) ($b->artist ?: $b->name)), (string) $b->name];
        });
        $groups = [];
        foreach ($week as $r) $groups[trim(($r->cat ?: 'Other') . ($r->sub ? ' / ' . $r->sub : ''))][] = $r;
        return view('report.shelf_count', ['locations' => $locations, 'loc' => $loc, 'groups' => $groups, 'counted' => $counted,
            'total' => $total, 'fresh' => $fresh, 'dueCount' => count($due), 'n' => $n, 'dueDays' => self::DUE_DAYS, 'kind' => $kind]);
    }

    public function section(Request $request)
    {
        if (!auth()->user()->can('product.view')) abort(403);
        [$business_id, $locations, $loc] = $this->location($request);
        $cat = (int) $request->input('cat'); $sub = (int) $request->input('sub');
        $counted = $this->lastCounted($business_id, $loc);
        $items = \DB::table('products as p')
            ->join('variation_location_details as v', function ($j) use ($loc) { $j->on('v.product_id', '=', 'p.id')->where('v.location_id', '=', $loc); })
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)
            ->where('p.category_id', $cat)->where(function ($q) use ($sub) { $sub ? $q->where('p.sub_category_id', $sub) : $q->whereNull('p.sub_category_id')->orWhere('p.sub_category_id', 0); })
            ->where('v.qty_available', '>', 0)
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', \DB::raw('SUM(v.qty_available) as qty'))
            ->groupBy('p.id', 'p.name', 'p.artist', 'p.sku')
            ->orderByRaw("LOWER(COALESCE(NULLIF(p.artist, ''), p.name))")->orderBy('p.name')
            ->get();
        $name = trim((string) \DB::table('categories')->where('id', $cat)->value('name') . ($sub ? ' / ' . \DB::table('categories')->where('id', $sub)->value('name') : ''));
        return view('report.shelf_count_section', ['locations' => $locations, 'loc' => $loc, 'items' => $items, 'counted' => $counted, 'name' => $name]);
    }

    public function missing(Request $request)
    {
        if (!auth()->user()->can('product.view')) abort(403);
        [$business_id, $locations, $loc] = $this->location($request);
        $days = max(1, (int) $request->input('days', 90));
        $cut = now()->subDays($days)->toDateTimeString();
        $rows = array_values(array_filter(app(ReorderService::class)->loadMissing($business_id), function ($r) use ($loc, $cut) {
            return (int) $r['location_id'] === $loc && $r['at'] >= $cut;
        }));
        usort($rows, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        $byArtist = [];
        foreach ($rows as $r) {
            $a = trim((string) ($r['artist'] ?: '')) ?: '(no artist)';
            $x = &$byArtist[mb_strtolower($a)];
            $x['artist'] = $x['artist'] ?? $a;
            $x['units'] = ($x['units'] ?? 0) + $r['units'];
            $x['cost'] = ($x['cost'] ?? 0) + $r['units'] * $r['unit_cost'];
            $x['times'] = ($x['times'] ?? 0) + 1;
            unset($x);
        }
        usort($byArtist, function ($a, $b) { return $b['cost'] <=> $a['cost']; });
        return view('report.shelf_count_missing', ['locations' => $locations, 'loc' => $loc, 'rows' => $rows, 'byArtist' => $byArtist, 'days' => $days]);
    }
}
