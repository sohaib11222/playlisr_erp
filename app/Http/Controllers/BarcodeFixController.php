<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Fix made-up SKUs (Sarah 2026-10-08, root cause #2 in the inventory
 * clean-up): sealed listings whose SKU is not the real barcode can't be
 * scanned, matched to distributor prices, or matched to their duplicate.
 *
 * For each one this page shows what it could really be:
 *   - the same album already in the ERP under its real barcode -> Merge
 *     (ProductMergeController::mergeSelected, keeps stock + sales, undoable),
 *   - distributor listings of the same album + format -> Use this barcode,
 *   - or type/scan the barcode by hand.
 * Barcode saves are snapshotted ('set-real-barcode', undo at Admin Action
 * History) and pushed to nivessa.com. Owner-only.
 */
class BarcodeFixController extends Controller
{
    const PAGE = 25;

    protected function isOwner()
    {
        $u = auth()->user();
        return $u && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    protected function skippedPath(int $biz): string
    {
        return storage_path('app/barcode-fix-skipped-' . $biz . '.json');
    }

    protected function skipped(int $biz): array
    {
        $j = json_decode((string) @file_get_contents($this->skippedPath($biz)), true);
        return is_array($j) ? $j : [];
    }

    /**
     * Album keys safe for listing-to-listing matching: both sides need a real
     * artist, and a "title" that is just the artist name doesn't count (found
     * 10/14: "Safe In The Hands Of Love - Yves Tumor" matched "Yves Tumor -
     * Asymptotical World" through the shared key "yves tumor").
     */
    protected function pairKeys($ica, $artist, $name): array
    {
        return array_values(array_filter($ica->albumKeys($artist, $name), function ($k) {
            return $k[0] !== '' && $k[1] !== '' && $k[0] !== $k[1];
        }));
    }

    /** Same artist words, nothing more or less ("Evilive II" is not "Evilive"). */
    protected function sameArtist(string $a, string $b): bool
    {
        $t = function ($s) { $x = array_values(array_unique(array_filter(explode(' ', $s)))); sort($x); return $x; };
        return $t($a) === $t($b);
    }

    public function index()
    {
        if (!$this->isOwner()) abort(403);
        return view('products.fix_barcodes');
    }

    /** Active sealed listings with a made-up SKU, in-stock first, with candidates. */
    public function data(Request $request)
    {
        if (!$this->isOwner()) abort(403);
        @set_time_limit(120);
        @ini_set('memory_limit', '768M');
        $biz = (int) $request->session()->get('user.business_id');
        $offset = max(0, (int) $request->query('offset', 0));
        $skipped = $this->skipped($biz);
        $locNames = \DB::table('business_locations')->where('business_id', $biz)->pluck('name', 'id')->all();

        $base = \DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)
            ->whereRaw("LOWER(c.name) LIKE '%sealed%'")
            ->whereRaw("REPLACE(REPLACE(COALESCE(p.sku,''),' ',''),'-','') NOT REGEXP '^[0-9]{8,14}$'");
        if ($skipped) $base->whereNotIn('p.id', array_map('intval', array_keys($skipped)));
        $total = (clone $base)->count();
        $inStock = (clone $base)->whereRaw('(select coalesce(sum(qty_available),0) from variation_location_details v where v.product_id = p.id) > 0')->count();

        $rows = $base
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'c.name as cat',
                \DB::raw('(select coalesce(sum(qty_available),0) from variation_location_details v where v.product_id = p.id) as stock'),
                \DB::raw("(select group_concat(concat(v.location_id, ':', v.qty_available) separator '|') from variation_location_details v where v.product_id = p.id) as locs"),
                \DB::raw('(select max(sell_price_inc_tax) from variations va where va.product_id = p.id and va.deleted_at is null) as price'))
            ->orderByDesc('stock')->orderByDesc('p.id');
        // Simple mode (default, Sarah 10/14 "not helpful"): only listings that
        // have a real-barcode twin, so every card is one yes/no question.
        $onlyTwins = $request->query('mode') !== 'all';
        $rowsQuery = $rows;

        $ica = app(\App\Services\InventoryCheckService::class);
        $split = function ($locs) use ($locNames) {
            $hw = 0; $pico = 0;
            foreach (array_filter(explode('|', (string) $locs)) as $part) {
                [$lid, $q] = array_pad(explode(':', $part, 2), 2, 0);
                $n = mb_strtolower((string) ($locNames[(int) $lid] ?? ''));
                if (strpos($n, 'hollywood') !== false) $hw += max(0, (float) $q);
                elseif (strpos($n, 'pico') !== false) $pico += max(0, (float) $q);
            }
            return [$hw, $pico];
        };

        // Real-barcode twins in the ERP: look up active barcode listings that
        // share a title word with this page's rows, then confirm with the same
        // album matcher the distributor prices use.
        $build = function ($r) use ($split, $ica, $biz) {
            [$hw, $pico] = $split($r->locs);
            $fam = $ica->formatFamily($r->cat);
            $keys = $this->pairKeys($ica, $r->artist, $r->name);
            $twins = [];
            $word = '';
            foreach ($keys as [$a, $t]) {
                foreach (explode(' ', $t) as $w) { if (mb_strlen($w) > mb_strlen($word)) $word = $w; }
            }
            if (mb_strlen($word) >= 3) {
                $cands = \DB::table('products as p')
                    ->join('categories as c', 'c.id', '=', 'p.category_id')
                    ->where('p.business_id', $biz)->where('p.is_inactive', 0)->where('p.id', '!=', $r->id)
                    ->whereRaw("REPLACE(REPLACE(COALESCE(p.sku,''),' ',''),'-','') REGEXP '^[0-9]{8,14}$'")
                    ->whereRaw('LOWER(p.name) LIKE ?', ['%' . $word . '%'])
                    ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'c.name as cat',
                        \DB::raw("(select group_concat(concat(v.location_id, ':', v.qty_available) separator '|') from variation_location_details v where v.product_id = p.id) as locs"),
                        \DB::raw('(select max(sell_price_inc_tax) from variations va where va.product_id = p.id and va.deleted_at is null) as price'))
                    ->limit(200)->get();
                $mine = [];
                foreach ($keys as [$a, $t]) $mine[$t][] = $a;
                foreach ($cands as $c) {
                    if ($ica->formatFamily($c->cat) !== $fam) continue;
                    $hit = false;
                    foreach ($this->pairKeys($ica, $c->artist, $c->name) as [$ca, $ct]) {
                        foreach ($mine[$ct] ?? [] as $a) { if ($this->sameArtist($a, $ca)) { $hit = true; break 2; } }
                    }
                    if (!$hit) continue;
                    [$chw, $cpico] = $split($c->locs);
                    $twins[] = ['id' => (int) $c->id, 'name' => $c->name, 'sku' => $c->sku, 'cat' => $c->cat, 'hw' => $chw, 'pico' => $cpico, 'price' => round((float) $c->price, 2)];
                }
            }
            $twinUpcs = [];
            foreach ($twins as $t) $twinUpcs[$ica->upcKey($t['sku'])] = true;
            $dist = array_values(array_filter($ica->albumListings($biz, $r->artist, $r->name, $r->cat), function ($d) use ($twinUpcs, $ica) {
                return !isset($twinUpcs[$ica->upcKey($d['upc'])]);
            }));
            return [
                'id' => (int) $r->id, 'name' => $r->name, 'artist' => $r->artist, 'sku' => $r->sku, 'cat' => $r->cat,
                'hw' => $hw, 'pico' => $pico, 'price' => round((float) $r->price, 2),
                'twins' => array_slice($twins, 0, 6), 'distributors' => array_slice($dist, 0, 8),
            ];
        };

        $out = [];
        $next = $offset;
        if (!$onlyTwins) {
            foreach ((clone $rowsQuery)->offset($offset)->limit(self::PAGE)->get() as $r) $out[] = $build($r);
            $next = $offset + self::PAGE;
        } else {
            // Walk forward until 10 cards with a twin (or ~25s of looking).
            $started = microtime(true);
            while (count($out) < 10 && (microtime(true) - $started) < 25) {
                $batch = (clone $rowsQuery)->offset($next)->limit(40)->get();
                if ($batch->isEmpty()) break;
                foreach ($batch as $r) {
                    $next++;
                    $row = $build($r);
                    if ($row['twins']) { $out[] = $row; if (count($out) >= 10) break; }
                }
            }
        }
        return response()->json(['total' => $total, 'in_stock' => $inStock, 'offset' => $offset, 'next_offset' => $next, 'per_page' => self::PAGE, 'rows' => $out]);
    }

    /**
     * Whole-catalog scan (Sarah 10/14 "i need to do this for whole erp"):
     * every active sealed made-up-SKU listing that has exactly ONE real-barcode
     * twin (same album, same format). One in-memory index instead of a query
     * per row. Read-only; ?csv=1 downloads it.
     */
    public function scan(Request $request)
    {
        if (!$this->isOwner()) abort(403);
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');
        $biz = (int) $request->session()->get('user.business_id');
        $ica = app(\App\Services\InventoryCheckService::class);
        $locNames = \DB::table('business_locations')->where('business_id', $biz)->pluck('name', 'id')->all();
        $stock = [];
        foreach (\DB::table('variation_location_details as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)->select('v.product_id', 'v.location_id', 'v.qty_available')->cursor() as $v) {
            $n = mb_strtolower((string) ($locNames[(int) $v->location_id] ?? ''));
            $k = strpos($n, 'pico') !== false ? 'pico' : (strpos($n, 'hollywood') !== false ? 'hw' : null);
            if ($k) $stock[(int) $v->product_id][$k] = ($stock[(int) $v->product_id][$k] ?? 0) + max(0, (float) $v->qty_available);
        }
        $sold = \DB::table('transaction_sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('t.business_id', $biz)->where('t.type', 'sell')->where('t.status', 'final')
            ->groupBy('l.product_id')->select('l.product_id', \DB::raw('SUM(l.quantity) as v'))->pluck('v', 'product_id')->all();
        $price = \DB::table('variations as va')->join('products as p', 'p.id', '=', 'va.product_id')->where('p.business_id', $biz)
            ->whereNull('va.deleted_at')->groupBy('va.product_id')->select('va.product_id', \DB::raw('MAX(va.sell_price_inc_tax) as v'))->pluck('v', 'product_id')->all();

        $users = \DB::table('users')->where('business_id', $biz)->select('id', \DB::raw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n"))->pluck('n', 'id')->all();
        $who = function ($p) use ($users) { return trim(($users[(int) $p->created_by] ?? '?') . ', ' . ($p->created_at ? date('n/j/Y', strtotime($p->created_at)) : '')); };
        // AMS cost for the kept (real-barcode) listing, so an overpriced
        // record stands out (Sarah 10/14).
        $ams = function ($k) use ($ica, $biz) {
            foreach ($ica->allSupplierPrices($biz, $k->artist, $k->name, $k->cat, $k->sku) as $sp) {
                if (($sp['supplier_key'] ?? '') === 'ams') return round((float) $sp['cost'], 2);
            }
            return null;
        };
        $isBarcode = function ($sku) { return (bool) preg_match('/^[0-9]{8,14}$/', preg_replace('/[\s-]+/', '', (string) $sku)); };
        $index = []; $made = [];
        foreach (\DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)->whereRaw("LOWER(c.name) LIKE '%sealed%'")
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'c.name as cat', 'p.created_by', 'p.created_at')->cursor() as $p) {
            $fam = $ica->formatFamily($p->cat);
            if ($fam === null) continue;
            if ($isBarcode($p->sku)) {
                foreach ($this->pairKeys($ica, $p->artist, $p->name) as [$a, $t]) $index[$fam . '|' . $t][] = [$a, $p];
            } else {
                $made[] = [$fam, $p];
            }
        }
        $skipped = $this->skipped($biz);
        $pairs = []; $amb = 0; $none = 0;
        foreach ($made as [$fam, $p]) {
            if (isset($skipped[(int) $p->id])) continue;
            $hits = []; $hitArtist = [];
            foreach ($this->pairKeys($ica, $p->artist, $p->name) as [$a, $t]) {
                foreach ($index[$fam . '|' . $t] ?? [] as [$ta, $tp]) {
                    if ($this->sameArtist($a, $ta)) { $hits[(int) $tp->id] = $tp; $hitArtist[(int) $tp->id] = trim($a . ' ' . $ta); }
                }
            }
            if (count($hits) === 0) { $none++; continue; }
            if (count($hits) > 1) { $amb++; continue; }
            $k = reset($hits);
            // Same words on both names (ignoring artist words and format
            // noise)? "Renaissance (Box Set)" vs "Renaissance" or "Evilive II"
            // vs "Evilive" are different releases even though the core title matches.
            $words = function ($n) {
                $n = mb_strtolower((string) $n);
                $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
                $noise = ['the','and','a','an','lp','2lp','3lp','cd','vinyl','x','180g','140g','150g','200g','gatefold','black','standard','edition','by'];
                return array_values(array_diff(array_unique(array_filter(explode(' ', $n))), $noise));
            };
            $artistWords = array_merge($words($p->artist), $words($k->artist), $words($hitArtist[(int) $k->id] ?? ''));
            $wa = array_diff($words($p->name), $artistWords); $wb = array_diff($words($k->name), $artistWords);
            sort($wa); sort($wb);
            $exact = array_values(array_unique($wa)) === array_values(array_unique($wb));
            // Sarah 10/14: when the made-up listing has the old cost x1.25 kind
            // of price (cents like $46.25) and the barcode listing was entered
            // later at a whole-dollar price, the newer price is the real one.
            $fp = round((float) ($price[$p->id] ?? 0), 2); $kp = round((float) ($price[$k->id] ?? 0), 2);
            $samePrice = abs($fp - $kp) < 0.01;
            $newerWhole = !$samePrice && $kp > 0 && fmod($kp, 1) == 0 && in_array((int) round(fmod($fp, 1) * 100), [25, 50, 75], true)
                && strtotime((string) $k->created_at) > strtotime((string) $p->created_at);
            $pairs[] = [
                'safe' => (bool) $exact && ($samePrice || $newerWhole),
                'price_rule' => $newerWhole ? 'newer whole-dollar price' : null,
                'exact' => (bool) $exact,
                'id' => (int) $p->id, 'name' => $p->name, 'sku' => $p->sku, 'cat' => $p->cat,
                'hw' => $stock[$p->id]['hw'] ?? 0, 'pico' => $stock[$p->id]['pico'] ?? 0, 'sold' => (float) ($sold[$p->id] ?? 0), 'price' => round((float) ($price[$p->id] ?? 0), 2),
                'keep_id' => (int) $k->id, 'keep_name' => $k->name, 'keep_sku' => $k->sku, 'keep_cat' => $k->cat, 'keep_who' => $who($k), 'who' => $who($p),
                'keep_ams' => $ams($k),
                'keep_hw' => $stock[$k->id]['hw'] ?? 0, 'keep_pico' => $stock[$k->id]['pico'] ?? 0, 'keep_sold' => (float) ($sold[$k->id] ?? 0), 'keep_price' => round((float) ($price[$k->id] ?? 0), 2),
            ];
        }
        if ($request->query('csv')) {
            $fh = fopen('php://temp', 'w+');
            fputcsv($fh, ['Made-up listing', 'Its SKU', 'Format', 'HW', 'Pico', 'Sold', 'Price', 'Real-barcode listing', 'Barcode', 'HW', 'Pico', 'Sold', 'Price', 'AMS cost', 'Made-up link', 'Real link']);
            foreach ($pairs as $x) fputcsv($fh, [$x['name'], $x['sku'], $x['cat'], $x['hw'], $x['pico'], $x['sold'], $x['price'], $x['keep_name'], $x['keep_sku'], $x['keep_hw'], $x['keep_pico'], $x['keep_sold'], $x['keep_price'], $x['keep_ams'], url('/products/' . $x['id'] . '/edit'), url('/products/' . $x['keep_id'] . '/edit')]);
            rewind($fh); $csv = stream_get_contents($fh); fclose($fh);
            return response($csv, 200)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="entered-twice.csv"');
        }
        return response()->json(['made_up' => count($made), 'one_match' => count($pairs), 'several_matches' => $amb, 'no_match' => $none,
            'same_price' => count(array_filter($pairs, function ($x) { return abs($x['price'] - $x['keep_price']) < 0.01; })),
            'safe' => count(array_filter($pairs, function ($x) { return $x['safe']; })),
            'sample' => $request->query('all') ? $pairs : array_slice($pairs, 0, 40)]);
    }

    public function preview()
    {
        if (!$this->isOwner()) abort(403);
        return view('products.fix_barcodes_preview');
    }

    /** Save a real barcode as the SKU (product + its single variation). */
    public function save(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $biz = (int) $request->session()->get('user.business_id');
        $upc = preg_replace('/\D+/', '', (string) $request->input('barcode'));
        if (strlen($upc) < 8 || strlen($upc) > 14) {
            return response()->json(['success' => false, 'msg' => 'That is not a barcode (needs 8 to 14 digits).']);
        }
        $p = \DB::table('products')->where('business_id', $biz)->where('id', (int) $id)->first();
        if (!$p) return response()->json(['success' => false, 'msg' => 'Product not found.']);

        // Same barcode already on another listing = it's a duplicate; merge instead.
        $ica = app(\App\Services\InventoryCheckService::class);
        $key = $ica->upcKey($upc);
        $other = \DB::table('products')->where('business_id', $biz)->where('id', '!=', $p->id)->where('is_inactive', 0)
            ->whereRaw("TRIM(LEADING '0' FROM REPLACE(REPLACE(sku,' ',''),'-','')) = ?", [$key])
            ->select('id', 'name')->first();
        if ($other) {
            return response()->json(['success' => false, 'twin' => ['id' => (int) $other->id, 'name' => $other->name],
                'msg' => 'Another listing already has this barcode: "' . $other->name . '". Merge into it instead.']);
        }

        $vars = \DB::table('variations')->where('product_id', $p->id)->whereNull('deleted_at')->select('id', 'sub_sku')->get();
        $ts = now()->format('Y-m-d_His') . '-' . $p->id;
        \DB::beginTransaction();
        try {
            \Storage::disk('local')->put("admin-snapshots/set-real-barcode-{$ts}.json", json_encode([
                'timestamp' => $ts, 'action' => 'set-real-barcode', 'user_id' => auth()->id(), 'business_id' => $biz,
                'source_name' => $p->name, 'target_name' => $p->sku . ' -> ' . $upc,
                'rows' => [['id' => (int) $p->id, 'old_sku' => $p->sku, 'new_sku' => $upc,
                    'variations' => $vars->map(function ($v) { return ['id' => (int) $v->id, 'old_sub_sku' => $v->sub_sku]; })->all()]],
            ], JSON_PRETTY_PRINT));
            \DB::table('products')->where('id', $p->id)->update(['sku' => $upc, 'updated_at' => now()]);
            // Single-variation listings scan by sub_sku, so it gets the barcode too.
            if (count($vars) === 1) \DB::table('variations')->where('id', $vars[0]->id)->update(['sub_sku' => $upc]);
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::error('set-real-barcode failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Save failed, nothing was changed.']);
        }
        try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([(int) $p->id]); } catch (\Throwable $e) {}
        return response()->json(['success' => true, 'msg' => 'Saved barcode ' . $upc . '.']);
    }

    /** Merge this listing into its real-barcode twin (stock + sales move over). */
    public function merge(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $keep = (int) $request->input('keep_id');
        $sub = Request::create('/products/merge-selected', 'POST', ['product_ids' => [(int) $id, $keep], 'keep_id' => $keep]);
        $sub->setLaravelSession($request->session());
        $sub->setUserResolver($request->getUserResolver());
        $biz0 = (int) $request->session()->get('user.business_id');
        // Before merging: each listing's stock per store + when it was entered.
        $srcVld = \DB::table('variation_location_details')->where('product_id', (int) $id)->pluck('qty_available', 'location_id')->all();
        $created = \DB::table('products')->whereIn('id', [(int) $id, $keep])->pluck('created_at', 'id')->all();
        $res = app(ProductMergeController::class)->mergeSelected($sub);
        $data = method_exists($res, 'getData') ? $res->getData(true) : [];
        if (!empty($data['success'])) {
            $note = $this->settleMergedStock($biz0, (int) $id, $keep, $srcVld, $created);
            if ($note) { $data['msg'] = ($data['msg'] ?? 'Merged.') . ' ' . $note; $res = response()->json($data); }
        }
        $price = $request->input('price');
        if ($price === null || $price === '' || empty($data['success'])) return $res;

        // Sarah picked which price is right (Check by hand tab): set it on the
        // kept listing. Undo: Admin Action History ('set-merge-price').
        $price = round((float) $price, 2);
        $biz = (int) $request->session()->get('user.business_id');
        $vars = \DB::table('variations as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $biz)->where('v.product_id', $keep)->whereNull('v.deleted_at')
            ->select('v.id', 'v.sell_price_inc_tax', 'v.default_sell_price')->get();
        $rows = [];
        foreach ($vars as $v) {
            $oldInc = (float) $v->sell_price_inc_tax; $oldExc = (float) $v->default_sell_price;
            if (abs($oldInc - $price) < 0.005) continue;
            $newExc = ($oldInc > 0 && $oldExc > 0) ? round($oldExc * $price / $oldInc, 4) : $price;
            \DB::table('variations')->where('id', $v->id)->update(['sell_price_inc_tax' => $price, 'default_sell_price' => $newExc, 'updated_at' => now()]);
            $rows[] = ['id' => (int) $v->id, 'old_inc' => $oldInc, 'old_exc' => $oldExc, 'new_inc' => $price, 'new_exc' => $newExc];
        }
        if ($rows) {
            $ts = now()->format('Y-m-d_His') . '-' . $keep;
            \Storage::disk('local')->put("admin-snapshots/set-merge-price-{$ts}.json", json_encode([
                'timestamp' => $ts, 'action' => 'set-merge-price', 'user_id' => auth()->id(), 'business_id' => $biz,
                'source_name' => (string) \DB::table('products')->where('id', $keep)->value('name'), 'target_name' => '$' . number_format($price, 2),
                'product_id' => $keep, 'rows' => $rows,
            ], JSON_PRETTY_PRINT));
            \DB::table('products')->where('id', $keep)->update(['updated_at' => now()]);
            try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([$keep]); } catch (\Throwable $e) {}
        }
        $data['msg'] = ($data['msg'] ?? 'Merged.') . ' Price set to $' . number_format($price, 2) . '.';
        return response()->json($data);
    }

    /**
     * Which stock count to trust after a merge (Sarah 10/14, Soundgarden King
     * Animal): a shelf count wins (Weekly Reorder bin counts, minus what sold
     * at that store since); otherwise the listing entered LATER wins at each
     * store it had a row for. Snapshot 'set-merge-stock' for undo.
     */
    protected function settleMergedStock(int $biz, int $srcId, int $keepId, array $srcVld, array $created): ?string
    {
        try {
            $counts = app(\App\Services\ReorderService::class)->loadState($biz)['counts'] ?? [];
            $rows = \DB::table('variation_location_details')->where('product_id', $keepId)->select('id', 'location_id', 'qty_available')->get();
            $srcNewer = strtotime((string) ($created[$srcId] ?? '')) > strtotime((string) ($created[$keepId] ?? ''));
            // A store the later listing was set up for but had no stock row at
            // counts as 0 there (King Animal: later listing showed 0/0).
            foreach (\DB::table('product_locations')->where('product_id', $srcId)->pluck('location_id') as $l) {
                if (!array_key_exists((int) $l, $srcVld)) $srcVld[(int) $l] = 0.0;
            }
            $changes = []; $why = [];
            foreach ($rows as $r) {
                $loc = (int) $r->location_id;
                $want = null; $reason = null;
                $c = null;
                foreach ([$srcId, $keepId] as $pid) {
                    $x = $counts[$loc . ':' . $pid] ?? null;
                    if ($x && (!$c || $x['at'] > $c['at'])) $c = $x;
                }
                if ($c) {
                    $soldSince = (float) \DB::table('transaction_sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
                        ->where('t.type', 'sell')->where('t.status', 'final')->where('t.location_id', $loc)->where('l.product_id', $keepId)
                        ->where('t.transaction_date', '>=', $c['at'])->sum('l.quantity');
                    $want = max(0, (float) $c['qty'] - $soldSince); $reason = 'shelf count';
                } elseif ($srcNewer && array_key_exists($loc, $srcVld)) {
                    $want = (float) $srcVld[$loc]; $reason = 'newer listing';
                }
                if ($want !== null && abs($want - (float) $r->qty_available) > 0.001) {
                    \DB::table('variation_location_details')->where('id', $r->id)->update(['qty_available' => $want, 'updated_at' => now()]);
                    $changes[] = ['id' => (int) $r->id, 'location_id' => $loc, 'old' => (float) $r->qty_available, 'new' => $want, 'why' => $reason];
                    $why[$reason] = true;
                }
            }
            if (!$changes) return null;
            $ts = now()->format('Y-m-d_His') . '-' . $keepId;
            \Storage::disk('local')->put("admin-snapshots/set-merge-stock-{$ts}.json", json_encode([
                'timestamp' => $ts, 'action' => 'set-merge-stock', 'user_id' => auth()->id(), 'business_id' => $biz,
                'source_name' => (string) \DB::table('products')->where('id', $keepId)->value('name'), 'target_name' => 'stock from ' . implode(' / ', array_keys($why)),
                'product_id' => $keepId, 'rows' => $changes,
            ], JSON_PRETTY_PRINT));
            \DB::table('product_stock_cache')->where('product_id', $keepId)->delete();
            try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([$keepId]); } catch (\Throwable $e) {}
            return 'Stock set from the ' . implode(' / ', array_keys($why)) . '.';
        } catch (\Throwable $e) {
            \Log::warning('settleMergedStock failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Merges already done today under the old stock rule: re-settle each
     * kept listing's stock with settleMergedStock (shelf count, else the
     * later-entered listing). GET lists what would change; POST applies.
     */
    public function restock(Request $request)
    {
        if (!$this->isOwner()) abort(403);
        $biz = (int) $request->session()->get('user.business_id');
        $day = preg_replace('/[^0-9-]/', '', (string) $request->input('day', now()->format('Y-m-d')));
        $out = [];
        $disk = \Storage::disk('local');
        foreach ($disk->files('admin-snapshots') as $f) {
            if (strpos(basename($f), 'merge-products-bulk-' . $day) !== 0) continue;
            $d = json_decode((string) $disk->get($f), true);
            if (!is_array($d) || (int) ($d['business_id'] ?? 0) !== $biz) continue;
            foreach (($d['merges'] ?? []) as $m) {
                $src = (int) ($m['source_id'] ?? 0); $keep = (int) ($m['target_id'] ?? 0);
                if (!$src || !$keep) continue;
                $srcVld = [];
                foreach (($m['source_vld'] ?? []) as $r) $srcVld[(int) $r['location_id']] = (float) $r['qty_available'];
                $created = \DB::table('products')->whereIn('id', [$src, $keep])->pluck('created_at', 'id')->all();
                $out[] = ['src' => $src, 'keep' => $keep, 'srcVld' => $srcVld, 'created' => $created, 'name' => $m['target_name'] ?? ''];
            }
        }
        if ($request->isMethod('post')) {
            $done = 0; $notes = [];
            foreach ($out as $x) {
                $n = $this->settleMergedStock($biz, $x['src'], $x['keep'], $x['srcVld'], $x['created']);
                if ($n) { $done++; $notes[] = $x['name']; }
            }
            return response()->json(['success' => true, 'checked' => count($out), 'changed' => $done, 'names' => array_slice($notes, 0, 50)]);
        }
        $would = [];
        foreach ($out as $x) {
            $srcNewer = strtotime((string) ($x['created'][$x['src']] ?? '')) > strtotime((string) ($x['created'][$x['keep']] ?? ''));
            foreach (\DB::table('product_locations')->where('product_id', $x['src'])->pluck('location_id') as $l) {
                if (!array_key_exists((int) $l, $x['srcVld'])) $x['srcVld'][(int) $l] = 0.0;
            }
            foreach (\DB::table('variation_location_details')->where('product_id', $x['keep'])->get() as $r) {
                if ($srcNewer && array_key_exists((int) $r->location_id, $x['srcVld']) && abs($x['srcVld'][(int) $r->location_id] - (float) $r->qty_available) > 0.001) {
                    $sp = \DB::table('products')->where('id', $x['src'])->select('name', 'sku', 'created_at', 'created_by')->first();
                    $kp = \DB::table('products')->where('id', $x['keep'])->select('name', 'sku', 'created_at', 'created_by')->first();
                    $u = function ($id) { return (string) \DB::table('users')->where('id', $id)->value('first_name'); };
                    $would[] = ['store' => (string) \DB::table('business_locations')->where('id', $r->location_id)->value('name'),
                        'now' => (float) $r->qty_available, 'would_be' => $x['srcVld'][(int) $r->location_id],
                        'kept' => $kp->name . ' (' . $kp->sku . ', entered by ' . $u($kp->created_by) . ' ' . substr((string) $kp->created_at, 0, 10) . ')',
                        'merged_away' => $sp->name . ' (' . $sp->sku . ', entered by ' . $u($sp->created_by) . ' ' . substr((string) $sp->created_at, 0, 10) . ')'];
                }
            }
        }
        return response()->json(['checked' => count($out), 'would_change' => count($would), 'list' => $would]);
    }

    /** Set one listing's stock at one store (Sarah's call on a merge), undoable as 'set-merge-stock'. */
    public function setStock(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $biz = (int) $request->session()->get('user.business_id');
        $loc = (int) $request->input('location_id');
        $qty = max(0, (float) $request->input('qty'));
        $p = \DB::table('products')->where('business_id', $biz)->where('id', (int) $id)->first();
        $r = \DB::table('variation_location_details')->where('product_id', (int) $id)->where('location_id', $loc)->first();
        if (!$p || !$r) return response()->json(['success' => false, 'msg' => 'No stock row for that product at that store.']);
        \DB::table('variation_location_details')->where('id', $r->id)->update(['qty_available' => $qty, 'updated_at' => now()]);
        $ts = now()->format('Y-m-d_His') . '-' . $p->id;
        \Storage::disk('local')->put("admin-snapshots/set-merge-stock-{$ts}.json", json_encode([
            'timestamp' => $ts, 'action' => 'set-merge-stock', 'user_id' => auth()->id(), 'business_id' => $biz,
            'source_name' => $p->name, 'target_name' => 'stock set by owner', 'product_id' => (int) $p->id,
            'rows' => [['id' => (int) $r->id, 'location_id' => $loc, 'old' => (float) $r->qty_available, 'new' => $qty, 'why' => 'owner']],
        ], JSON_PRETTY_PRINT));
        \DB::table('product_stock_cache')->where('product_id', $p->id)->delete();
        try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([(int) $p->id]); } catch (\Throwable $e) {}
        return response()->json(['success' => true, 'msg' => $p->name . ': ' . (float) $r->qty_available . ' -> ' . $qty]);
    }

    /** Hide a listing from this page (can't tell / not worth it). */
    public function skip(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $biz = (int) $request->session()->get('user.business_id');
        $s = $this->skipped($biz);
        $s[(int) $id] = now()->toDateString();
        @file_put_contents($this->skippedPath($biz), json_encode($s));
        return response()->json(['success' => true]);
    }
}
