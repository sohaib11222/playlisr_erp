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
     * What can be counted: the 4 music kinds plus every other top-level
     * category (Books, VHS, DVD, Blu-ray, Video Games, Magazines, Audio Gear,
     * Pokemon, Sports Cards, T-shirts, Toys, Drinks, Posters...; Sarah 10/14).
     * Bins inside a category are its sub-categories; titles, stars, bands or
     * designs are the rows you tap inside a bin.
     */
    protected function kinds(int $business_id): array
    {
        $k = ['vinyl-new' => 'New vinyl', 'vinyl-used' => 'Used vinyl', 'cd-new' => 'New CDs', 'cd-used' => 'Used CDs'];
        foreach (\DB::table('categories')->where('business_id', $business_id)->where('parent_id', 0)->whereNull('deleted_at')
            ->where('category_type', 'product')->orderBy('name')->pluck('name', 'id') as $id => $name) {
            if (preg_match('/vinyl|\\bcds?\\b/i', $name)) continue;
            $k['cat-' . $id] = $name;
        }
        return $k;
    }

    protected function pickKind(Request $request, int $business_id): string
    {
        $kinds = $this->kinds($business_id);
        return isset($kinds[$request->input('kind')]) ? $request->input('kind') : 'vinyl-new';
    }

    protected function kindQuery($q, string $kind)
    {
        if (strpos($kind, 'cat-') === 0) { $q->where('p.category_id', (int) substr($kind, 4)); return; }
        [$fmt, $cond] = explode('-', $kind);
        $q->whereRaw('LOWER(c.name) LIKE ?', ['%' . ($fmt === 'cd' ? 'cd' : 'vinyl') . '%'])
          ->whereRaw('LOWER(c.name) LIKE ?', ['%' . ($cond === 'new' ? 'sealed' : 'used') . '%']);
    }

    /**
     * Pick a bin (Sarah 10/14: "she'll be running everywhere, it needs to be
     * efficient"). A bin = format + genre at one store, e.g. New vinyl /
     * Rock. Shows how many titles the ERP expects in each and when it was
     * last counted; the oldest are on top.
     */
    public function index(Request $request)
    {
        if (!auth()->user()->can('product.view')) abort(403);
        [$business_id, $locations, $loc] = $this->location($request);
        $kind = $this->pickKind($request, $business_id);
        $counted = $this->lastCounted($business_id, $loc);
        $rows = \DB::table('products as p')
            ->join('variation_location_details as v', function ($j) use ($loc) { $j->on('v.product_id', '=', 'p.id')->where('v.location_id', '=', $loc); })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->where('v.qty_available', '>', 0)
            ->where(function ($q) use ($kind) { $this->kindQuery($q, $kind); })
            ->select('p.id', 'p.sub_category_id', 'sc.name as sub', 'v.qty_available as qty')->get();
        $bins = [];
        foreach ($rows as $r) {
            $k = (int) $r->sub_category_id;
            $b = &$bins[$k];
            $b['sub'] = $k; $b['name'] = $r->sub ?: 'No sub-category';
            $b['titles'] = ($b['titles'] ?? 0) + 1; $b['units'] = ($b['units'] ?? 0) + (float) $r->qty;
            $c = $counted[$r->id] ?? null;
            if ($c && (!isset($b['last']) || $c['at'] > $b['last'])) $b['last'] = $c['at'];
            unset($b);
        }
        $bins = array_values($bins);
        usort($bins, function ($a, $b) { return [$a['last'] ?? '', $a['name']] <=> [$b['last'] ?? '', $b['name']]; });
        return view('report.shelf_count', ['locations' => $locations, 'loc' => $loc, 'kind' => $kind, 'kinds' => $this->kinds($business_id), 'bins' => $bins, 'total' => $rows->count()]);
    }

    /** Scan one bin: what the ERP expects there, then scan or search each record. */
    public function bin(Request $request)
    {
        if (!auth()->user()->can('product.view')) abort(403);
        [$business_id, $locations, $loc] = $this->location($request);
        $kind = $this->pickKind($request, $business_id);
        $sub = (int) $request->input('sub');
        $items = \DB::table('products as p')
            ->join('variation_location_details as v', function ($j) use ($loc) { $j->on('v.product_id', '=', 'p.id')->where('v.location_id', '=', $loc); })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->where('v.qty_available', '>', 0)
            ->where(function ($q) use ($kind) { $this->kindQuery($q, $kind); })
            ->where(function ($q) use ($sub) { $sub ? $q->where('p.sub_category_id', $sub) : $q->whereNull('p.sub_category_id')->orWhere('p.sub_category_id', 0); })
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', \DB::raw('SUM(v.qty_available) as qty'))
            ->groupBy('p.id', 'p.name', 'p.artist', 'p.sku')
            ->orderByRaw("LOWER(COALESCE(NULLIF(p.artist, ''), p.name))")->get();
        $name = $sub ? (string) \DB::table('categories')->where('id', $sub)->value('name') : 'No sub-category';
        $labels = $this->kinds($business_id);
        return view('report.shelf_count_bin', ['locations' => $locations, 'loc' => $loc, 'kind' => $kind, 'kindLabel' => $labels[$kind],
            'name' => $name, 'items' => $items->map(function ($i) { return ['id' => (int) $i->id, 'name' => $i->name, 'artist' => $i->artist, 'sku' => $i->sku, 'erp' => (float) $i->qty]; })->values()]);
    }

    /** Barcode (or words) -> product, for the scan box. */
    public function lookup(Request $request)
    {
        if (!auth()->user()->can('product.view')) abort(403);
        $business_id = (int) $request->session()->get('user.business_id');
        $q = trim((string) $request->input('q'));
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) >= 8 && strlen($digits) === strlen(preg_replace('/[\s-]+/', '', $q))) {
            $key = ltrim($digits, '0');
            $rows = \DB::table('products as p')->join('variations as va', 'va.product_id', '=', 'p.id')
                ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->whereNull('va.deleted_at')
                ->where(function ($x) use ($key) {
                    $x->whereRaw("TRIM(LEADING '0' FROM REPLACE(REPLACE(p.sku,' ',''),'-','')) = ?", [$key])
                      ->orWhereRaw("TRIM(LEADING '0' FROM REPLACE(REPLACE(va.sub_sku,' ',''),'-','')) = ?", [$key]);
                })->select('p.id', 'p.name', 'p.artist', 'p.sku')->distinct()->limit(5)->get();
        } else {
            $words = array_filter(preg_split('/\s+/', mb_strtolower($q)), function ($w) { return mb_strlen($w) >= 2; });
            if (!$words) return response()->json(['items' => []]);
            $x = \DB::table('products as p')->where('p.business_id', $business_id)->where('p.is_inactive', 0);
            foreach ($words as $w) $x->where(function ($y) use ($w) { $y->whereRaw('LOWER(p.name) LIKE ?', ['%' . $w . '%'])->orWhereRaw('LOWER(p.artist) LIKE ?', ['%' . $w . '%']); });
            $rows = $x->select('p.id', 'p.name', 'p.artist', 'p.sku')->limit(10)->get();
        }
        return response()->json(['items' => $rows]);
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
