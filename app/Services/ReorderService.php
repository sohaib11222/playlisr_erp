<?php

namespace App\Services;

use App\Category;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly Reorder: one store + one format (sealed vinyl or sealed CD) ->
 * what to order from AMS/RedEye this week, by genre.
 *
 * Replaces the spreadsheet Jon and Clarissa rebuilt ~15 times since Aug 25
 * (Jon's "Inventory Ordering: What Jon Asks For", 2026-10-07). Every rule in
 * that doc lives here: sold since last order, ABC/XYZ-weighted cover,
 * core "check bins" titles, overdue titles, bought-once fast sellers, used
 * titles worth buying sealed, skip walk-in/manual items, bin counts next to
 * order qty, and the order just placed counted as "on order".
 *
 * State (bin counts, orders marked placed, settings) is a JSON sidecar like
 * AmsPurchaseOrders, so nothing touches ERP stock and no migration is needed.
 */
class ReorderService
{
    const DEFAULT_SETTINGS = [
        'cover_months_vinyl' => 1.0,   // vinyl ordered less often: ~1 month of cover
        'cover_months_cd'    => 0.5,   // CDs ordered weekly: ~half a month
        'cover_months_cassette' => 3.0, // Clarissa's cassette sheet keeps 3 months
        'abc_factor'         => ['A' => 1.25, 'B' => 1.0, 'C' => 0.75],
        'xyz_factor'         => ['X' => 1.0, 'Y' => 0.9, 'Z' => 0.75],
        'pace_weight_recent' => 0.4,   // 40% last 10 days, 60% this year's monthly avg
        'bought_once_days'   => 90,    // bought once + sold within this -> reorder 1
        'used_fast_days'     => 45,    // used sold within this of purchase -> buy sealed
        'used_min_price'     => 15,    // or used sold for at least this
        'blank_count'        => 'sold', // sold | erp | zero
        'max_line_qty'       => 10,
        'count_fresh_days'   => 21,    // a bin count older than this is ignored
    ];

    protected $inv;
    protected $abc;

    public function __construct(InventoryCheckService $inv, AbcImportService $abc)
    {
        $this->inv = $inv;
        $this->abc = $abc;
    }

    // ---------------------------------------------------------------- state

    protected function path(int $business_id): string
    {
        return storage_path('app/reorder-' . $business_id . '.json');
    }

    public function loadState(int $business_id): array
    {
        $state = [];
        $path = $this->path($business_id);
        if (is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            $state = is_array($json) ? $json : [];
        }
        $state['counts'] = $state['counts'] ?? [];
        $state['orders'] = $state['orders'] ?? [];
        $state['settings'] = array_replace_recursive(self::DEFAULT_SETTINGS, $state['settings'] ?? []);
        return $state;
    }

    protected function saveState(int $business_id, array $state): void
    {
        $path = $this->path($business_id);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    /** Record a bin count (null clears it). Keyed by location + product. */
    public function saveCount(int $business_id, int $locationId, int $productId, ?int $qty, string $by, array $alsoClear = []): void
    {
        $state = $this->loadState($business_id);
        // A merged album row is counted once: drop counts on its other copies.
        foreach ($alsoClear as $other) {
            if ((int) $other !== $productId) unset($state['counts'][$locationId . ':' . (int) $other]);
        }
        $key = $locationId . ':' . $productId;
        if ($qty === null) {
            unset($state['counts'][$key]);
        } else {
            $state['counts'][$key] = ['qty' => max(0, $qty), 'at' => now()->toDateTimeString(), 'by' => $by];
        }
        $this->saveState($business_id, $state);
    }

    /**
     * Load a counted sheet (columns upc, qty) for one store. Matches each UPC
     * to the product whose SKU carries it. Returns [matched, unmatched rows].
     */
    public function importCounts(int $business_id, int $locationId, array $pairs, string $countedAt, string $by): array
    {
        $state = $this->loadState($business_id);
        $byUpc = [];
        $skus = DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $business_id)->whereNull('v.deleted_at')
            ->where('v.sub_sku', 'regexp', '[0-9]{11,14}')
            ->select('v.product_id', 'v.sub_sku')->get();
        foreach ($skus as $r) {
            foreach (preg_split('/[\s,;|\/]+/', (string) $r->sub_sku) as $tok) {
                if (preg_match('/^\d{11,14}$/', $tok)) $byUpc[ltrim($tok, '0')][] = (int) $r->product_id;
            }
        }
        $matched = 0;
        $unmatched = [];
        foreach ($pairs as $p) {
            $u = ltrim(preg_replace('/\D+/', '', (string) $p['upc']), '0');
            $pids = $u !== '' ? array_unique($byUpc[$u] ?? []) : [];
            if (empty($pids)) { $unmatched[] = $p; continue; }
            foreach ($pids as $pid) {
                $state['counts'][$locationId . ':' . $pid] = ['qty' => max(0, (int) $p['qty']), 'at' => $countedAt, 'by' => $by];
            }
            $matched++;
        }
        $this->saveState($business_id, $state);
        return [$matched, $unmatched];
    }

    /** Mark this week's order as placed. Lines: [{product_id, upc, qty, title}]. */
    public function markOrdered(int $business_id, int $locationId, string $format, array $lines, string $by, string $note = ''): array
    {
        $state = $this->loadState($business_id);
        $order = [
            'id' => substr(md5(uniqid('', true)), 0, 10),
            'location_id' => $locationId,
            'format' => $format,
            'at' => now()->toDateTimeString(),
            'by' => $by,
            'note' => $note,
            'lines' => array_values($lines),
        ];
        $state['orders'][] = $order;
        $this->saveState($business_id, $state);
        return $order;
    }

    public function deleteOrder(int $business_id, string $orderId): void
    {
        $state = $this->loadState($business_id);
        $state['orders'] = array_values(array_filter($state['orders'], function ($o) use ($orderId) {
            return ($o['id'] ?? '') !== $orderId;
        }));
        $this->saveState($business_id, $state);
    }

    public function saveSettings(int $business_id, array $settings): void
    {
        $state = $this->loadState($business_id);
        $state['settings'] = array_replace_recursive(self::DEFAULT_SETTINGS, $settings);
        $this->saveState($business_id, $state);
    }

    public function ordersFor(int $business_id, int $locationId, string $format): array
    {
        $state = $this->loadState($business_id);
        $out = array_values(array_filter($state['orders'], function ($o) use ($locationId, $format) {
            return (int) $o['location_id'] === $locationId && $o['format'] === $format;
        }));
        usort($out, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        return $out;
    }

    // ---------------------------------------------------------------- build

    /**
     * Product categories for one condition + format. Names vary ("Sealed
     * Vinyl", "Vinyl - Sealed", "CD (Sealed)", "Sealed LP"), so match words.
     */
    public function categoryIds(int $business_id, string $condition, string $format): array
    {
        $fmt = $format === 'cd' ? '/\bcds?\b|compact disc/i'
            : ($format === 'cassette' ? '/cassette|\btapes?\b/i' : '/vinyl|\blps?\b/i');
        $out = [];
        foreach (Category::where('business_id', $business_id)->where('category_type', 'product')->get(['id', 'name']) as $c) {
            if (stripos($c->name, $condition) !== false && preg_match($fmt, $c->name) && ($format === 'cassette' || stripos($c->name, 'cassette') === false)) {
                $out[(int) $c->id] = $c->name;
            }
        }
        return $out;
    }

    /**
     * @param string $format 'vinyl' | 'cd' | 'cassette'
     * @param string|null $since override "last order" date (Y-m-d)
     */
    public function build(int $business_id, int $locationId, string $format, ?string $since = null): array
    {
        $state = $this->loadState($business_id);
        $s = $state['settings'];
        $label = $format === 'cd' ? 'CD' : ($format === 'cassette' ? 'Cassette' : 'Vinyl');
        $sealedNames = $this->categoryIds($business_id, 'sealed', $format);
        $usedNames = $this->categoryIds($business_id, 'used', $format);
        $sealedCats = array_keys($sealedNames);
        $usedCats = array_keys($usedNames);
        $today = Carbon::today();
        $yearStart = $today->copy()->startOfYear();
        $monthsElapsed = max(1.0, $yearStart->diffInDays($today) / 30.4);

        // Last order: the newest order marked placed here, else the newest
        // distributor purchase for this store + format, else 7 days ago.
        $orders = $this->ordersFor($business_id, $locationId, $format);
        $lastOrder = $orders[0] ?? null;
        $sinceSource = 'order marked placed on this page';
        if ($since) {
            $sinceDate = Carbon::parse($since)->startOfDay();
            $sinceSource = 'picked by hand';
        } elseif ($lastOrder) {
            $sinceDate = Carbon::parse($lastOrder['at']);
        } else {
            $last = $this->lastDistributorPurchase($business_id, $locationId, $sealedCats);
            if ($last) {
                $sinceDate = Carbon::parse($last);
                $sinceSource = 'last distributor purchase in the ERP';
            } else {
                $sinceDate = $today->copy()->subDays(7);
                $sinceSource = 'default, 7 days ago';
            }
        }

        // ---- sales by product per day, this year, sealed, this store
        $daily = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereIn('p.category_id', $sealedCats ?: [0])
            ->where('t.transaction_date', '>=', $yearStart->toDateTimeString())
            ->groupBy('p.id', DB::raw('DATE(t.transaction_date)'))
            ->select('p.id as pid', DB::raw('DATE(t.transaction_date) as d'), DB::raw('MAX(t.transaction_date) as last_at'),
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as qty'))
            ->get();

        $agg = [];
        $tenAgo = $today->copy()->subDays(10)->toDateString();
        foreach ($daily as $r) {
            $q = (float) $r->qty;
            if ($q <= 0) continue;
            $pid = (int) $r->pid;
            $a = $agg[$pid] ?? ['ytd' => 0, 'd10' => 0, 'since' => 0, 'months' => [], 'dates' => [], 'last' => null];
            $a['ytd'] += $q;
            if ($r->d >= $tenAgo) $a['d10'] += $q;
            if ($r->last_at >= $sinceDate->toDateTimeString()) $a['since'] += $q;
            $a['months'][substr($r->d, 0, 7)] = true;
            $a['dates'][] = $r->d;
            $a['daily'][$r->d] = ($a['daily'][$r->d] ?? 0) + $q;
            if ($a['last'] === null || $r->d > $a['last']) $a['last'] = $r->d;
            $agg[$pid] = $a;
        }

        $abcData = $this->abc->load() ?: [];
        $locClass = $abcData['location_map'][$locationId] ?? $abcData['location_map'][(string) $locationId] ?? [];
        $abcxyz = $this->abc->loadAbcXyzMap();

        // Candidates: anything sold this year + every A-grade sealed title here.
        $pids = array_keys($agg);
        foreach ($locClass as $pid => $cls) {
            if ($cls === 'A') $pids[] = (int) $pid;
        }
        $pids = array_values(array_unique(array_map('intval', $pids)));

        $meta = $this->productMeta($business_id, $pids, $sealedCats);
        $pids = array_keys($meta); // drops A-grades that aren't sealed $label
        $erpStock = $this->stockByProduct($business_id, $locationId, $pids);
        $sellDays = $this->daysToSell($business_id, $locationId, $pids);
        $onOrder = $this->onOrder($business_id, $locationId, $format, $orders, $pids);

        // One row per album, like Clarissa's sheet: the same title is often
        // in the ERP several times (manual entry, alternate cover, typo).
        // Pool their sales, stock and orders under the copy with a barcode
        // that sells best, so a title isn't under-ordered across 3 rows.
        $byKey = [];
        foreach ($pids as $pid) {
            $k = $this->albumKey($meta[$pid]->artist, $meta[$pid]->name);
            $byKey[$k === '' ? 'pid:' . $pid : $k][] = $pid;
        }
        // Rows with no artist join the one keyed album with that title.
        $byTitle = [];
        foreach (array_keys($byKey) as $k) {
            if (strpos($k, '|') !== false && strpos($k, '|') > 0) $byTitle[substr($k, strpos($k, '|'))][] = $k;
        }
        foreach (array_keys($byKey) as $k) {
            if (strpos($k, '|') === 0 && count($byTitle[$k] ?? []) === 1) {
                $byKey[$byTitle[$k][0]] = array_merge($byKey[$byTitle[$k][0]], $byKey[$k]);
                unset($byKey[$k]);
            }
        }
        $rank = ['A' => 3, 'B' => 2, 'C' => 1];
        $members = [];
        foreach ($byKey as $group) {
            usort($group, function ($x, $y) use ($meta, $agg) {
                return [!empty($meta[$y]->upc), $agg[$y]['ytd'] ?? 0] <=> [!empty($meta[$x]->upc), $agg[$x]['ytd'] ?? 0];
            });
            $primary = $group[0];
            $members[$primary] = $group;
            if (count($group) === 1) continue;
            $m = ['ytd' => 0, 'd10' => 0, 'since' => 0, 'months' => [], 'dates' => [], 'last' => null, 'daily' => []];
            $sd = ['sum' => 0, 'n' => 0, 'sold' => 0, 'purchased' => 0];
            foreach ($group as $g) {
                $a = $agg[$g] ?? null;
                if ($a) {
                    foreach (['ytd', 'd10', 'since'] as $f) $m[$f] += $a[$f];
                    $m['months'] += $a['months'];
                    foreach ($a['daily'] ?? [] as $d => $q) $m['daily'][$d] = ($m['daily'][$d] ?? 0) + $q;
                    if ($a['last'] && $a['last'] > $m['last']) $m['last'] = $a['last'];
                }
                if ($g !== $primary) {
                    $erpStock[$primary] = ($erpStock[$primary] ?? 0) + ($erpStock[$g] ?? 0);
                    $onOrder[$primary] = ($onOrder[$primary] ?? 0) + ($onOrder[$g] ?? 0);
                    $cg = $locClass[$g] ?? '';
                    if (($rank[$cg] ?? 0) > ($rank[$locClass[$primary] ?? ''] ?? 0)) {
                        $locClass[$primary] = $cg;
                        $abcxyz[$primary] = $abcxyz[$g] ?? ($abcxyz[$primary] ?? '');
                    }
                }
                if (isset($sellDays[$g])) {
                    if ($sellDays[$g]['avg'] !== null) {
                        $sd['sum'] += $sellDays[$g]['avg'] * $sellDays[$g]['sold'];
                        $sd['n'] += $sellDays[$g]['sold'];
                    }
                    $sd['sold'] += $sellDays[$g]['sold'];
                    $sd['purchased'] += $sellDays[$g]['purchased'];
                }
            }
            $m['dates'] = array_keys($m['daily']);
            $agg[$primary] = $m;
            $sellDays[$primary] = ['avg' => $sd['n'] ? round($sd['sum'] / $sd['n']) : null, 'sold' => $sd['sold'], 'purchased' => $sd['purchased']];
        }
        $pids = array_keys($members);

        $cover = (float) ($s['cover_months_' . $format] ?? $s['cover_months_vinyl']);
        $rows = [];
        foreach ($pids as $pid) {
            $m = $meta[$pid];
            $a = $agg[$pid] ?? ['ytd' => 0, 'd10' => 0, 'since' => 0, 'months' => [], 'dates' => [], 'last' => null];
            $abcCls = $locClass[$pid] ?? $locClass[(string) $pid] ?? '';
            $xyz = substr($abcxyz[$pid] ?? '', 1, 1);
            $grade = $abcCls . $xyz;

            $monthly = $s['pace_weight_recent'] * ($a['d10'] / 10 * 30)
                + (1 - $s['pace_weight_recent']) * ($a['ytd'] / $monthsElapsed);
            $factor = ($s['abc_factor'][$abcCls] ?? 1.0) * ($s['xyz_factor'][$xyz] ?? 1.0);
            $suggested = (int) ceil(round($monthly * $cover * $factor, 2));
            $isCore = $grade === 'AX' || ($a['ytd'] >= 5 && count($a['months']) >= 3) || $a['ytd'] >= 8;
            if ($suggested < 1 && ($a['ytd'] > 0 || $isCore)) $suggested = 1;
            $suggested = min($suggested, (int) $s['max_line_qty']);

            // Overdue: no sale for longer than max(14 days, 2x its usual gap).
            $gap = null;
            $dates = $a['dates'];
            sort($dates);
            if (count($dates) >= 2) {
                $gap = Carbon::parse($dates[0])->diffInDays(Carbon::parse(end($dates))) / (count($dates) - 1);
            }
            $daysSinceSale = $a['last'] ? Carbon::parse($a['last'])->diffInDays($today) : null;
            $overdue = $isCore && $daysSinceSale !== null && $daysSinceSale > max(14, 2 * ($gap ?? 7));

            $sd = $sellDays[$pid] ?? null;
            $boughtOnceFast = $sd && $sd['purchased'] <= 1 && $sd['sold'] >= 1
                && $sd['avg'] !== null && $sd['avg'] < $s['bought_once_days'];

            $why = [];
            if ($a['since'] > 0) $why[] = 'sold';
            if ($isCore && $a['since'] == 0) $why[] = 'core';
            if ($overdue) $why[] = 'overdue';
            if ($boughtOnceFast) $why[] = 'once';
            if (empty($why)) $why[] = 'other';

            $cost = (float) ($m->cost ?? 0);
            $cantOrder = null;
            if ($cost > 0 && $cost < 0.5) $cantOrder = 'walk-in buy';
            elseif (!$m->upc) $cantOrder = 'no barcode';

            $rows[] = $this->finishRow([
                'product_id' => $pid,
                'product_ids' => $members[$pid],
                'merged' => count($members[$pid]) > 1 ? array_values(array_unique(array_map(function ($g) use ($meta) { return $meta[$g]->name; }, $members[$pid]))) : [],
                'genre' => $this->genre($m->genre),
                'artist' => $m->artist,
                'title' => $m->name,
                'upc' => $m->upc,
                'sku' => $m->sku,
                'grade' => $grade,
                'why' => $why,
                'sold_since' => $a['since'],
                'sold_10d' => $a['d10'],
                'sold_ytd' => $a['ytd'],
                'months_sold' => count($a['months']),
                'avg_days_to_sell' => $sd['avg'] ?? null,
                'last_sold' => $a['last'],
                'days_since_sale' => $daysSinceSale,
                'erp_stock' => $erpStock[$pid] ?? 0,
                'on_order' => $onOrder[$pid] ?? 0,
                'suggested' => $suggested,
                'cant_order' => $cantOrder,
                'erp_cost' => $cost ?: null,
                'used_note' => null,
                'daily' => $a['daily'] ?? [],
            ], $business_id, $locationId, $state, $m->category_name);
        }

        // ---- used titles selling fast -> buy sealed (title level)
        foreach ($this->usedToSealed($business_id, $locationId, $usedCats, $s, $meta, $rows, $format) as $u) {
            $rows[] = $this->finishRow($u, $business_id, $locationId, $state, 'Sealed ' . $label);
        }

        usort($rows, function ($x, $y) {
            return [$x['genre'], mb_strtolower($x['artist'] ?: $x['title']), mb_strtolower($x['title'])]
                <=> [$y['genre'], mb_strtolower($y['artist'] ?: $y['title']), mb_strtolower($y['title'])];
        });

        return [
            'rows' => $rows,
            'since' => $sinceDate,
            'since_source' => $sinceSource,
            'orders' => array_slice($orders, 0, 8),
            'settings' => $s,
            'abc_loaded' => !empty($locClass),
            'categories' => array_merge(array_values($sealedNames), array_values($usedNames)),
        ];
    }

    /**
     * Everything that isn't sealed vinyl/CD/cassette (apparel, toys, cards,
     * books, DVDs, posters, used music...): per category, what sold in the
     * last 90 days, what the ERP thinks is on hand, and whether that stock
     * looks believable. Plus each category's top sellers.
     */
    public function otherAreas(int $business_id, int $locationId): array
    {
        $start = Carbon::now()->subDays(90)->toDateTimeString();
        $sealedMusic = array_keys($this->categoryIds($business_id, 'sealed', 'vinyl')
            + $this->categoryIds($business_id, 'sealed', 'cd') + $this->categoryIds($business_id, 'sealed', 'cassette'));
        $cats = Category::where('business_id', $business_id)->where('category_type', 'product')
            ->where('parent_id', 0)->whereNotIn('id', $sealedMusic ?: [0])->pluck('name', 'id')->all();
        if (empty($cats)) return [];

        $sales = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->where('t.transaction_date', '>=', $start)
            ->whereIn('p.category_id', array_keys($cats))
            ->groupBy('p.id')
            ->select('p.id', 'p.category_id', 'p.name', 'p.product_custom_field1 as artist', DB::raw('MIN(v.sub_sku) as sku'),
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as qty'),
                DB::raw('SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) as rev'),
                DB::raw('MAX(t.transaction_date) as last_at'))
            ->get();
        $stock = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('vld.location_id', $locationId)
            ->where('p.business_id', $business_id)
            ->whereIn('p.category_id', array_keys($cats))
            ->groupBy('p.category_id')
            ->select('p.category_id', DB::raw('SUM(GREATEST(vld.qty_available,0)) as q'),
                DB::raw('SUM(CASE WHEN vld.qty_available > 0 THEN 1 ELSE 0 END) as titles'))
            ->get()->keyBy('category_id');
        $pidStock = [];
        $pids = $sales->pluck('id')->all();
        foreach (array_chunk($pids, 2000) as $chunk) {
            foreach (DB::table('variation_location_details as vld')->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('vld.location_id', $locationId)->whereIn('v.product_id', $chunk)
                ->groupBy('v.product_id')->select('v.product_id', DB::raw('SUM(vld.qty_available) as q'))->get() as $r) {
                $pidStock[(int) $r->product_id] = (int) round((float) $r->q);
            }
        }

        $out = [];
        foreach ($cats as $cid => $name) {
            $lines = $sales->where('category_id', $cid);
            $sold = (float) $lines->sum('qty');
            $onHand = (int) round((float) ($stock[$cid]->q ?? 0));
            if ($sold <= 0 && $onHand <= 0) continue;
            $weekly = $sold / 13;
            $weeks = $weekly > 0 ? $onHand / $weekly : null;
            $isUsed = stripos($name, 'used') !== false;
            if ($onHand >= 50 && ($weeks === null || $weeks > 104)) {
                $flag = ['wrong', 'Stock looks wrong: more than 2 years of sales on hand. Spot check it.'];
            } elseif ($sold > 0 && $weeks !== null && $weeks < 4) {
                $flag = ['low', 'Running low: under 4 weeks left.'];
            } else {
                $flag = ['ok', ''];
            }
            $top = [];
            foreach ($lines->sortByDesc('qty')->take(15) as $l) {
                $have = $pidStock[(int) $l->id] ?? 0;
                $top[] = [
                    'name' => trim(($l->artist ? $l->artist . ' / ' : '') . $l->name),
                    'sku' => $l->sku,
                    'sold' => (float) $l->qty,
                    'stock' => $have,
                    'last' => substr($l->last_at, 0, 10),
                    'restock' => !$isUsed && $have <= 0,
                ];
            }
            $out[] = [
                'name' => $name,
                'sold' => $sold,
                'revenue' => (float) $lines->sum('rev'),
                'on_hand' => $onHand,
                'titles_in_stock' => (int) ($stock[$cid]->titles ?? 0),
                'weeks' => $weeks,
                'flag' => $flag,
                'used' => $isUsed,
                'top' => $top,
            ];
        }
        usort($out, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
        return $out;
    }

    /** Bin count, order qty and distributor price for one row. */
    protected function finishRow(array $r, int $business_id, int $locationId, array $state, ?string $categoryName): array
    {
        $s = $state['settings'];
        $count = null;
        $countAt = null;
        $countRaw = null;
        foreach ($r['product_ids'] ?? [] as $cpid) {
            $c = $state['counts'][$locationId . ':' . $cpid] ?? null;
            if ($c && Carbon::parse($c['at'])->gt(Carbon::now()->subDays((int) $s['count_fresh_days']))) {
                // Copies sold after the count have left the bin.
                $countRaw = (int) $countRaw + (int) $c['qty'];
                $soldAfter = 0;
                foreach ($r['daily'] ?? [] as $d => $q) {
                    if ($d > substr($c['at'], 0, 10)) $soldAfter += $q;
                }
                $count = max(0, (int) $countRaw - (int) $soldAfter);
                $countAt = max((string) $countAt, $c['at']);
            }
        }
        unset($r['daily']);
        $r['count'] = $count;
        $r['count_raw'] = $countRaw;
        $r['count_at'] = $countAt;

        $r['order_qty'] = $this->orderQty($r, $s['blank_count']);
        if ($r['cant_order']) $r['order_qty'] = 0;

        $prices = $this->inv->allSupplierPrices($business_id, $r['artist'], $r['title'], $categoryName, $r['upc'] ?: $r['sku']);
        $best = null;
        $ams = null;
        foreach ($prices as $p) {
            if (($p['supplier_key'] ?? '') === 'ams' && $ams === null) $ams = $p;
            if ($best === null && ($p['in_stock'] ?? null) !== false) $best = $p;
        }
        $best = $best ?: ($prices[0] ?? null);
        $r['best_supplier'] = $best ? ($best['supplier_label'] ?? $best['supplier_key']) : null;
        $r['best_cost'] = $best ? round((float) $best['cost'], 2) : null;
        $r['ams_cost'] = $ams ? round((float) $ams['cost'], 2) : null;
        $r['supplier_upc'] = $r['upc'] ?: ($best['upc'] ?? ($ams['upc'] ?? null));
        if (!$r['upc'] && $r['supplier_upc'] && $r['cant_order'] === 'no barcode') {
            $r['cant_order'] = null;
            $r['order_qty'] = $this->orderQty($r, $s['blank_count']);
        }
        return $r;
    }

    /**
     * Order Qty = Suggested - In stock - On order, never below 0.
     * No bin count: 'sold' orders back what sold since the last order,
     * 'erp' trusts ERP stock, 'zero' assumes the bin is empty.
     */
    public function orderQty(array $r, string $blankMode): int
    {
        $sug = (int) $r['suggested'];
        if ($r['count'] !== null) {
            return max(0, $sug - (int) $r['count'] - (int) $r['on_order']);
        }
        if ($blankMode === 'erp') {
            return max(0, $sug - max(0, (int) $r['erp_stock']) - (int) $r['on_order']);
        }
        if ($blankMode === 'zero') {
            return max(0, $sug - (int) $r['on_order']);
        }
        // 'sold': replace what left the bin, never more than the target.
        $base = in_array('once', $r['why'], true) || in_array('used', $r['why'], true)
            ? max(1, (int) $r['sold_since']) : (int) $r['sold_since'];
        return max(0, min($base, max($sug, 1)));
    }

    // ---------------------------------------------------------------- data

    protected function productMeta(int $business_id, array $pids, array $sealedCats): array
    {
        if (empty($pids)) return [];
        $out = [];
        foreach (array_chunk($pids, 2000) as $chunk) {
            $rows = DB::table('products as p')
                ->leftJoin('variations as v', function ($j) { $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at'); })
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->leftJoin('categories as sub', 'sub.id', '=', 'p.sub_category_id')
                ->where('p.business_id', $business_id)
                ->whereIn('p.id', $chunk)
                ->whereIn('p.category_id', $sealedCats ?: [0])
                ->groupBy('p.id')
                ->select('p.id', 'p.name', 'p.product_custom_field1 as artist', 'c.name as category_name',
                    'sub.name as genre', DB::raw('MIN(v.sub_sku) as sku'), DB::raw('MAX(v.default_purchase_price) as cost'))
                ->get();
            foreach ($rows as $r) {
                $r->upc = $this->upcFrom($r->sku);
                $out[(int) $r->id] = $r;
            }
        }
        return $out;
    }

    /** First 11-14 digit token in a SKU: a real UPC/EAN, not a catalog #. */
    public function upcFrom(?string $sku): ?string
    {
        foreach (preg_split('/[\s,;|\/]+/', (string) $sku) as $tok) {
            if (preg_match('/^\d{11,14}$/', $tok)) return $tok;
        }
        return null;
    }

    protected function genre(?string $g): string
    {
        $g = trim((string) $g);
        return $g === '' ? 'No genre' : mb_convert_case(mb_strtolower($g), MB_CASE_TITLE);
    }

    protected function stockByProduct(int $business_id, int $locationId, array $pids): array
    {
        if (empty($pids)) return [];
        $out = [];
        foreach (array_chunk($pids, 2000) as $chunk) {
            foreach (DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('vld.location_id', $locationId)
                ->whereIn('v.product_id', $chunk)
                ->groupBy('v.product_id')
                ->select('v.product_id', DB::raw('SUM(vld.qty_available) as q'))
                ->get() as $r) {
                $out[(int) $r->product_id] = (int) round((float) $r->q);
            }
        }
        return $out;
    }

    /**
     * Days to sell = purchase date -> sale date (not how recently it sold),
     * plus how many copies were ever bought here, for "bought once".
     */
    protected function daysToSell(int $business_id, int $locationId, array $pids): array
    {
        if (empty($pids)) return [];
        $out = [];
        foreach (array_chunk($pids, 1500) as $chunk) {
            $rows = DB::table('transaction_sell_lines_purchase_lines as tslp')
                ->join('purchase_lines as pl', 'pl.id', '=', 'tslp.purchase_line_id')
                ->join('transactions as pur', 'pur.id', '=', 'pl.transaction_id')
                ->join('variations as v', 'v.id', '=', 'pl.variation_id')
                ->join('transaction_sell_lines as sl', 'sl.id', '=', 'tslp.sell_line_id')
                ->join('transactions as sale', 'sale.id', '=', 'sl.transaction_id')
                ->where('pur.business_id', $business_id)
                ->where('pur.location_id', $locationId)
                ->where('pur.type', 'purchase')
                ->whereIn('v.product_id', $chunk)
                ->groupBy('v.product_id')
                ->select('v.product_id',
                    DB::raw('AVG(GREATEST(DATEDIFF(sale.transaction_date, pur.transaction_date),0)) as avg_days'),
                    DB::raw('SUM(tslp.quantity) as sold'))
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->product_id] = ['avg' => round((float) $r->avg_days), 'sold' => (float) $r->sold, 'purchased' => 0];
            }
            foreach (DB::table('purchase_lines as pl')
                ->join('transactions as pur', 'pur.id', '=', 'pl.transaction_id')
                ->join('variations as v', 'v.id', '=', 'pl.variation_id')
                ->where('pur.business_id', $business_id)
                ->where('pur.location_id', $locationId)
                ->where('pur.type', 'purchase')
                ->whereIn('v.product_id', $chunk)
                ->groupBy('v.product_id')
                ->select('v.product_id', DB::raw('SUM(pl.quantity) as q'))
                ->get() as $r) {
                $pid = (int) $r->product_id;
                $out[$pid] = $out[$pid] ?? ['avg' => null, 'sold' => 0, 'purchased' => 0];
                $out[$pid]['purchased'] = (float) $r->q;
            }
        }
        return $out;
    }

    /**
     * Units on the way: lines of orders marked placed on this page in the
     * last 21 days, plus ERP purchases still in "ordered"/"pending" status
     * (how Alliance orders are logged) from the last 45 days.
     */
    protected function onOrder(int $business_id, int $locationId, string $format, array $orders, array $pids): array
    {
        $out = [];
        $cut = Carbon::now()->subDays(21);
        foreach ($orders as $i => $o) {
            // The newest order is the "since" boundary: its lines are already
            // replacing what sold before it, so they count as on order.
            if (Carbon::parse($o['at'])->lt($cut)) continue;
            foreach ($o['lines'] as $l) {
                $pid = (int) ($l['product_id'] ?? 0);
                if ($pid) $out[$pid] = ($out[$pid] ?? 0) + (int) $l['qty'];
            }
        }
        if (!empty($pids)) {
            foreach (array_chunk($pids, 2000) as $chunk) {
                foreach (DB::table('purchase_lines as pl')
                    ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                    ->join('variations as v', 'v.id', '=', 'pl.variation_id')
                    ->where('t.business_id', $business_id)
                    ->where('t.location_id', $locationId)
                    ->where('t.type', 'purchase')
                    ->whereIn('t.status', ['ordered', 'pending'])
                    ->where('t.transaction_date', '>=', Carbon::now()->subDays(45)->toDateTimeString())
                    ->whereIn('v.product_id', $chunk)
                    ->groupBy('v.product_id')
                    ->select('v.product_id', DB::raw('SUM(pl.quantity) as q'))
                    ->get() as $r) {
                    $out[(int) $r->product_id] = ($out[(int) $r->product_id] ?? 0) + (int) $r->q;
                }
            }
        }
        return $out;
    }

    protected function lastDistributorPurchase(int $business_id, int $locationId, array $sealedCats): ?string
    {
        if (empty($sealedCats)) return null;
        $rows = DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.business_id', $business_id)
            ->where('t.location_id', $locationId)
            ->where('t.type', 'purchase')
            ->where('t.transaction_date', '>=', Carbon::now()->subDays(30)->toDateTimeString())
            ->whereExists(function ($q) use ($sealedCats) {
                $q->select(DB::raw(1))->from('purchase_lines as pl')
                    ->join('variations as v', 'v.id', '=', 'pl.variation_id')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereColumn('pl.transaction_id', 't.id')
                    ->whereIn('p.category_id', $sealedCats);
            })
            ->orderByDesc('t.transaction_date')
            ->select('t.transaction_date', 'c.name', 'c.supplier_business_name')
            ->limit(40)->get();
        foreach ($rows as $r) {
            if (preg_match('/\b(ams|all media|alliance|red ?eye|secretly|matador|monostereo)\b/i', $r->name . ' ' . $r->supplier_business_name)) {
                return $r->transaction_date;
            }
        }
        return null;
    }

    /**
     * Used copies that sold fast (within N days of purchase), 2+ in 10 days,
     * or for $15+ in the last 45 days. Matched at album level to a sealed
     * product we carry, or to a distributor feed row when we've never
     * stocked it sealed. Existing rows just get tagged.
     */
    protected function usedToSealed(int $business_id, int $locationId, array $usedCats, array $s, array $meta, array &$rows, string $format): array
    {
        if (empty($usedCats)) return [];
        $start = Carbon::now()->subDays(45)->toDateTimeString();
        $lines = DB::table('transaction_sell_lines as sl')
            ->join('transactions as sale', 'sale.id', '=', 'sl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'sl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('transaction_sell_lines_purchase_lines as tslp', 'tslp.sell_line_id', '=', 'sl.id')
            ->leftJoin('purchase_lines as pl', 'pl.id', '=', 'tslp.purchase_line_id')
            ->leftJoin('transactions as pur', 'pur.id', '=', 'pl.transaction_id')
            ->where('sale.business_id', $business_id)
            ->where('sale.type', 'sell')->where('sale.status', 'final')
            ->where('sale.location_id', $locationId)
            ->whereIn('p.category_id', $usedCats)
            ->where('sale.transaction_date', '>=', $start)
            ->select('p.name', 'p.product_custom_field1 as artist', 'sale.transaction_date as sold_at',
                'sl.unit_price_inc_tax as price', 'pur.transaction_date as bought_at')
            ->get();

        $byKey = [];
        $tenAgo = Carbon::now()->subDays(10)->toDateTimeString();
        foreach ($lines as $l) {
            $key = $this->albumKey($l->artist, $l->name);
            if ($key === '') continue;
            $g = $byKey[$key] ?? ['artist' => $l->artist, 'name' => $l->name, 'n' => 0, 'n10' => 0, 'fast' => 0, 'max_price' => 0, 'days' => []];
            $g['n']++;
            if ($l->sold_at >= $tenAgo) $g['n10']++;
            $g['max_price'] = max($g['max_price'], (float) $l->price);
            if ($l->bought_at) {
                $d = max(0, Carbon::parse($l->bought_at)->diffInDays(Carbon::parse($l->sold_at)));
                $g['days'][] = $d;
                if ($d <= $s['used_fast_days']) $g['fast']++;
            }
            $byKey[$key] = $g;
        }

        $sealedByKey = [];
        foreach ($rows as $i => $r) {
            $sealedByKey[$this->albumKey($r['artist'], $r['title'])] = $i;
        }

        $out = [];
        foreach ($byKey as $key => $g) {
            if (!($g['fast'] > 0 || $g['n10'] >= 2 || $g['max_price'] >= $s['used_min_price'])) continue;
            $why = 'Used sold ' . $g['n'] . 'x in 45 days'
                . ($g['days'] ? ', ' . (int) round(array_sum($g['days']) / count($g['days'])) . ' days to sell' : '')
                . ($g['max_price'] >= $s['used_min_price'] ? ', up to $' . number_format($g['max_price'], 2) : '');
            if (isset($sealedByKey[$key])) {
                $i = $sealedByKey[$key];
                if (!in_array('used', $rows[$i]['why'], true)) {
                    $rows[$i]['why'][] = 'used';
                    $rows[$i]['used_note'] = $why;
                    if ($rows[$i]['order_qty'] < 1 && !$rows[$i]['cant_order'] && ($rows[$i]['count'] ?? 0) == 0 && $rows[$i]['erp_stock'] <= 0) {
                        $rows[$i]['order_qty'] = 1;
                    }
                }
                continue;
            }
            $out[] = [
                'product_id' => null,
                'genre' => 'No genre',
                'artist' => $g['artist'],
                'title' => $g['name'],
                'upc' => null,
                'sku' => null,
                'grade' => '',
                'why' => ['used'],
                'sold_since' => 0, 'sold_10d' => 0, 'sold_ytd' => 0, 'months_sold' => 0,
                'avg_days_to_sell' => null, 'last_sold' => null, 'days_since_sale' => null,
                'erp_stock' => 0, 'on_order' => 0,
                'suggested' => max(1, min(3, (int) $g['n10'])),
                'cant_order' => 'not at a distributor',
                'erp_cost' => null,
                'used_note' => $why . ' (never stocked sealed)',
            ];
        }
        return $out;
    }

    /** Album-level key: artist + title, no format/edition/punctuation noise. */
    public function albumKey(?string $artist, ?string $name): string
    {
        $name = (string) $name;
        $artist = trim((string) $artist);
        foreach ([' / ', ' - ', ' – '] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos !== false) {
                if ($artist === '') $artist = mb_substr($name, 0, $pos);
                $name = mb_substr($name, $pos + mb_strlen($sep));
                break;
            }
        }
        $norm = function ($x) {
            $x = mb_strtolower($x);
            $x = preg_replace('/[\(\[].*?[\)\]]/u', ' ', $x);
            $x = preg_replace('/\b(lp|2lp|3lp|cd|2cd|vinyl|colou?red|deluxe|edition|remaster(ed)?|the|and|explicit|x)\b/u', ' ', $x);
            $x = preg_replace('/[^a-z0-9]+/u', ' ', $x);
            return trim(preg_replace('/\s+/', ' ', $x));
        };
        $toks = array_filter(explode(' ', $norm($artist)));
        sort($toks);
        $a = implode(' ', $toks);
        if (in_array($a, ['various', 'various artists', 'artists various', 'soundtrack', 'unknown'], true)) $a = 'various';
        $t = $norm($name);
        return $t === '' ? '' : $a . '|' . $t;
    }
}
