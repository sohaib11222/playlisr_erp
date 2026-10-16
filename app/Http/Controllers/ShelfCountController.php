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
        $kind = ($request->input('kind') && $request->input('kind') !== 'all') ? $this->pickKind($request, $business_id) : 'all';
        $counted = $this->lastCounted($business_id, $loc);
        if ($kind === 'all') return $this->overview($request, $business_id, $locations, $loc, $counted);
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

    /**
     * Every shelf at the store on one page (Sarah 10/14: "which shelves we
     * didn't count yet and the last date we counted it"). Never counted on
     * top, then oldest first.
     */
    protected function overview(Request $request, int $business_id, array $locations, int $loc, array $counted)
    {
        $kinds = $this->kinds($business_id);
        $rows = \DB::table('products as p')
            ->join('variation_location_details as v', function ($j) use ($loc) { $j->on('v.product_id', '=', 'p.id')->where('v.location_id', '=', $loc); })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->where('v.qty_available', '>', 0)
            ->select('p.id', 'p.category_id', 'p.sub_category_id', 'c.name as cat', 'sc.name as sub')->get();
        $bins = [];
        foreach ($rows as $r) {
            $cat = (string) $r->cat;
            $isV = preg_match('/vinyl/i', $cat); $isC = preg_match('/\\bcds?\\b/i', $cat);
            $new = stripos($cat, 'sealed') !== false; $used = stripos($cat, 'used') !== false;
            if (($isV || $isC) && ($new || $used)) { $kind = ($isV ? 'vinyl' : 'cd') . '-' . ($new ? 'new' : 'used'); }
            elseif (isset($kinds['cat-' . (int) $r->category_id])) { $kind = 'cat-' . (int) $r->category_id; }
            else continue;
            $k = $kind . '|' . (int) $r->sub_category_id;
            $b = &$bins[$k];
            $b['kind'] = $kind; $b['sub'] = (int) $r->sub_category_id;
            $b['name'] = ($kinds[$kind] ?? $cat) . ' / ' . ($r->sub ?: 'No sub-category');
            $b['titles'] = ($b['titles'] ?? 0) + 1;
            $c = $counted[$r->id] ?? null;
            if ($c) { $b['counted'] = ($b['counted'] ?? 0) + 1; if (!isset($b['last']) || $c['at'] > $b['last']) $b['last'] = $c['at']; }
            unset($b);
        }
        $bins = array_values($bins);
        usort($bins, function ($a, $b) { return [isset($a['last']), $a['last'] ?? '', $a['name']] <=> [isset($b['last']), $b['last'] ?? '', $b['name']]; });
        if ($request->input('kind') === 'all') {
            return view('report.shelf_count', ['locations' => $locations, 'loc' => $loc, 'kind' => 'all', 'kinds' => ['all' => 'All shelves'] + $kinds,
                'bins' => $bins, 'total' => $rows->count(), 'overview' => true]);
        }
        // Home (Sarah 10/14 "count shelves is overwhelming"): one tile per
        // type of stock with how many of its shelves were counted this month.
        $cut = now()->subDays(self::DUE_DAYS)->toDateTimeString();
        $tiles = [];
        foreach ($bins as $b) {
            $t = &$tiles[$b['kind']];
            $t['kind'] = $b['kind']; $t['name'] = $kinds[$b['kind']] ?? $b['kind'];
            $t['shelves'] = ($t['shelves'] ?? 0) + 1; $t['titles'] = ($t['titles'] ?? 0) + $b['titles'];
            if (!empty($b['last']) && $b['last'] >= $cut) $t['done'] = ($t['done'] ?? 0) + 1;
            if (!empty($b['last']) && (!isset($t['last']) || $b['last'] > $t['last'])) $t['last'] = $b['last'];
            unset($t);
        }
        // Comics and magazines are counted by series on their own page, so
        // their tiles here would always say "never". One tile with the
        // series counts instead (Sarah 10/14: "comics and mags were counted today").
        foreach ($tiles as $k => $t) { if (preg_match('/comic|magazine/i', $t['name'])) unset($tiles[$k]); }
        $fam = app(ReorderService::class)->loadState($business_id)['family_counts'][$loc] ?? [];
        $famLast = null; $famRecent = 0;
        foreach ($fam as $c) {
            if (!empty($c['at']) && $c['at'] >= $cut) $famRecent++;
            if (!empty($c['at']) && (!$famLast || $c['at'] > $famLast)) $famLast = $c['at'];
        }
        $order = array_keys($kinds);
        uasort($tiles, function ($a, $b) use ($order) { return array_search($a['kind'], $order) <=> array_search($b['kind'], $order); });
        $allDone = array_sum(array_map(function ($t) { return $t['done'] ?? 0; }, $tiles));
        return view('report.shelf_count_home', ['locations' => $locations, 'loc' => $loc, 'tiles' => array_values($tiles),
            'shelves' => count($bins), 'done' => $allDone, 'dueDays' => self::DUE_DAYS,
            'comics' => ['series' => count(\App\Services\ReorderService::COMIC_FAMILIES) + count(\App\Services\ReorderService::MAGAZINE_FAMILIES), 'recent' => $famRecent, 'last' => $famLast]]);
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
        $rows = $this->netMissing($business_id, $loc, (string) \DB::table('business_locations')->where('id', $loc)->value('name'), $rows);
        usort($rows, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        $byArtist = [];
        foreach ($rows as $r) {
            if ($r['units'] <= 0) continue;
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

    /**
     * Take out copies that weren't really missing (Sarah 10/16: "doesnt check
     * out. what about manual orders?"):
     *  - found on a later count of the same title (Axis Mundi: 9 short on
     *    10/15, 12 found 10/16), most recent shortfall first;
     *  - sold at this store as a manual register item in the 60 days before
     *    the count, so the ERP never took that copy off.
     * Also fills the artist from the title ("AVICII / TRUE") when the
     * product has none.
     */
    protected function netMissing(int $business_id, int $loc, string $locName, array $rows): array
    {
        if (!$rows) return $rows;
        $svc = app(ReorderService::class);
        foreach ($rows as &$r) {
            $r['found'] = 0; $r['manual'] = 0; $r['short'] = $r['units'];
            $k = $svc->albumKey($r['artist'] ?? null, $r['name'] ?? '');
            $r['key'] = $k;
            if (trim((string) ($r['artist'] ?? '')) === '' && strpos($k, '|') > 0) {
                foreach ([' / ', ' - ', ' – '] as $sep) {
                    $p = mb_strpos((string) $r['name'], $sep);
                    if ($p !== false) { $r['artist'] = trim(mb_substr($r['name'], 0, $p)); break; }
                }
            }
        }
        unset($r);

        // Later counts that went up, per product at this store.
        $pids = array_values(array_unique(array_map(function ($r) { return (int) $r['product_id']; }, $rows)));
        $ups = [];
        $acts = \DB::table('activity_log')->where('subject_type', 'App\\Product')->whereIn('subject_id', $pids)
            ->where('description', 'stock_changed')->where('properties', 'like', '%bin count%')
            ->where('created_at', '>=', min(array_column($rows, 'at')))->orderBy('created_at')->get(['subject_id', 'properties', 'created_at']);
        foreach ($acts as $a) {
            $note = (string) (json_decode($a->properties, true)['update_note'] ?? '');
            if (!preg_match('/bin count: (.*): ([\d.]+) -> (\d+)\s*$/', $note, $m)) continue;
            if ($locName !== '' && strcasecmp(trim($m[1]), $locName) !== 0) continue;
            if ((float) $m[3] > (float) $m[2]) $ups[(int) $a->subject_id][] = ['at' => (string) $a->created_at, 'n' => (float) $m[3] - (float) $m[2]];
        }
        $idx = [];
        foreach ($rows as $i => $r) $idx[(int) $r['product_id']][] = $i;
        foreach ($ups as $pid => $list) {
            foreach ($list as $u) {
                $left = $u['n'];
                $before = array_filter($idx[$pid] ?? [], function ($i) use ($rows, $u) { return $rows[$i]['at'] <= $u['at']; });
                usort($before, function ($x, $y) use ($rows) { return strcmp($rows[$y]['at'], $rows[$x]['at']); });
                foreach ($before as $i) {
                    if ($left <= 0) break;
                    $take = min($left, $rows[$i]['units']);
                    $rows[$i]['units'] -= $take; $rows[$i]['found'] += $take; $left -= $take;
                }
            }
        }

        // Manual register items for the same album at this store.
        $first = \Carbon\Carbon::parse(min(array_column($rows, 'at')))->subDays(60)->toDateTimeString();
        $manual = \DB::table('transaction_sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')->where('t.location_id', $loc)
            ->where('t.transaction_date', '>=', $first)
            ->where(function ($w) { $w->whereNull('l.product_id')->orWhere('l.product_id', 0); })->whereNotNull('l.product_name')
            ->select('l.product_name', 'l.product_artist', 't.transaction_date', \DB::raw('l.quantity - l.quantity_returned as q'))->get();
        $sold = [];
        foreach ($manual as $m) {
            $k = $svc->albumKey($m->product_artist, $m->product_name);
            if (strpos($k, '|') > 0 && (float) $m->q > 0) $sold[$k][] = ['at' => (string) $m->transaction_date, 'q' => (float) $m->q];
        }
        $order = array_keys($rows);
        usort($order, function ($x, $y) use ($rows) { return strcmp($rows[$x]['at'], $rows[$y]['at']); });
        foreach ($order as $i) {
            $k = $rows[$i]['key'];
            if ($rows[$i]['units'] <= 0 || empty($sold[$k])) continue;
            $from = \Carbon\Carbon::parse($rows[$i]['at'])->subDays(60)->toDateTimeString();
            foreach ($sold[$k] as &$s) {
                if ($rows[$i]['units'] <= 0) break;
                if ($s['q'] <= 0 || $s['at'] < $from || $s['at'] > $rows[$i]['at']) continue;
                $take = min($s['q'], $rows[$i]['units']);
                $rows[$i]['units'] -= $take; $rows[$i]['manual'] += $take; $s['q'] -= $take;
            }
            unset($s);
        }
        return $rows;
    }
}
