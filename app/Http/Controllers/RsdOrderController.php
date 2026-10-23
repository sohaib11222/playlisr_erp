<?php

namespace App\Http\Controllers;

use App\Services\InventoryCheckService;
use Illuminate\Http\Request;

/**
 * RSD order page (Sarah 10/12): one page per store, built from the customer
 * request sheet (Instagram + DMs + Quo + website), ordered generously, with
 * every distributor's price side by side. Page state (pasted request counts,
 * qty edits, what was ordered where) lives in storage/app/rsd-order-{biz}.json;
 * it never touches ERP stock or purchases.
 */
class RsdOrderController extends Controller
{
    const STORES = ['hollywood' => 'Hollywood', 'pico' => 'Pico'];

    protected function statePath(int $biz): string
    {
        return storage_path('app/rsd-order-' . $biz . '.json');
    }

    protected function loadState(int $biz): array
    {
        $p = $this->statePath($biz);
        $s = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
        return is_array($s) ? $s : [];
    }

    protected function saveState(int $biz, array $s): void
    {
        $p = $this->statePath($biz);
        $tmp = $p . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $p);
    }

    public static function key(string $artist, string $title): string
    {
        $n = function ($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower($s)); };
        return substr(md5($n($artist) . '|' . $n($title)), 0, 12);
    }

    /**
     * Sarah 10/16 (final): double the customer requests plus 2, per store.
     * A store nobody asked at goes by our sales of that artist instead.
     */
    public static function suggested(int $requested, int $sold = 0): int
    {
        if ($requested === 1) return 3; // Sarah 10/16: one request = that copy + 2
        return $requested > 0 ? $requested * 2 + 2 : self::salesBump($sold);
    }

    public static function salesBump(int $sold): int
    {
        return $sold >= 3 ? min(6, (int) ceil($sold / 5)) : 0;
    }

    /** "JEPSEN,CARLY RAE", "Carly Rae Jepsen" and "The Doors"/"Doors" all give the same key. */
    public static function artistKey(string $a): string
    {
        $a = strtolower(trim($a));
        if (preg_match('/^([^,]+),\s*([^,]+)$/', $a, $m) && strpos($a, ' & ') === false) $a = $m[2] . ' ' . $m[1];
        $a = str_replace('&', ' and ', $a);
        $t = array_filter(preg_split('/[^a-z0-9]+/', $a), function ($w) { return $w !== '' && $w !== 'the'; });
        sort($t);
        return implode(' ', $t);
    }

    /** Title words for loose matching: no asides, no filler words, '77 and 1977 both "77". */
    protected static function titleTokens(string $t): array
    {
        $t = strtolower(preg_replace('/\(.*$/', '', $t));
        $t = preg_replace("/'(\d\d)\b/", ' $1', $t);
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $t) as $w) {
            if ($w === '' || in_array($w, ['the', 'a', 'an', 'and', 'of', 'at', 'in', 'vol', 'volume', 'live', 'lp', 'or'], true)) continue;
            if (preg_match('/^(19|20)(\d\d)$/', $w, $m)) $w = $m[2];
            $out[$w] = true;
        }
        return array_keys($out);
    }

    protected static function titleCore(string $t): string
    {
        $t = preg_replace('/\(.*$/', '', $t);
        return preg_replace('/[^a-z0-9]+/', '', strtolower($t));
    }

    /**
     * Copies of each RSD artist sold per store in the last 12 months (all
     * formats, new and used) plus how many of those were RSD releases.
     * Matches the artist column and the legacy "Title / Artist" names.
     */
    protected function artistSales(int $biz, array $artists): array
    {
        $want = [];
        foreach ($artists as $a) {
            $k = self::artistKey($a);
            if ($k === '' || in_array($k, ['artists various', 'various'], true)) continue;
            $want[$k] = true;
        }
        if (empty($want)) return [];
        return \Cache::remember('rsd-order-artist-sales-' . $biz . '-' . md5(implode('|', array_keys($want))), 6 * 3600, function () use ($biz, $want) {
            $locs = \App\BusinessLocation::forDropdown($biz, false)->toArray();
            $storeOf = [];
            foreach ($locs as $id => $name) {
                if (stripos($name, 'pico') !== false) $storeOf[$id] = 'pico';
                elseif (stripos($name, 'hollywood') !== false) $storeOf[$id] = 'hollywood';
            }
            $terms = [];
            foreach (array_keys($want) as $k) {
                $w = explode(' ', $k);
                usort($w, function ($x, $y) { return strlen($y) <=> strlen($x); });
                $terms[$w[0]] = true;
            }
            $rows = \DB::table('transaction_sell_lines as l')
                ->join('transactions as t', 't.id', '=', 'l.transaction_id')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('t.business_id', $biz)->where('t.type', 'sell')->where('t.status', 'final')
                ->whereIn('t.location_id', array_keys($storeOf) ?: [0])
                ->where('t.transaction_date', '>=', now()->subYear())
                ->where(function ($q) use ($terms) {
                    foreach (array_keys($terms) as $w) {
                        $q->orWhere('p.artist', 'like', '%' . $w . '%')->orWhere('p.name', 'like', '%' . $w . '%');
                    }
                })
                ->groupBy('p.id', 'p.name', 'p.artist', 't.location_id')
                ->selectRaw('p.name, p.artist, t.location_id, SUM(l.quantity - COALESCE(l.quantity_returned, 0)) as q')
                ->get();
            $out = [];
            foreach ($rows as $r) {
                $st = $storeOf[$r->location_id] ?? null;
                if (!$st || (float) $r->q <= 0) continue;
                $cands = [(string) $r->artist];
                foreach ([' / ', ' - '] as $sep) {
                    $parts = explode($sep, (string) $r->name);
                    if (count($parts) > 1) { $cands[] = $parts[0]; $cands[] = end($parts); }
                }
                foreach (array_unique(array_map([self::class, 'artistKey'], $cands)) as $k) {
                    if (!isset($want[$k])) continue;
                    $out[$k][$st] = ($out[$k][$st] ?? 0) + (int) round($r->q);
                    if (preg_match('/\bRSD\b|record store day/i', (string) $r->name)) $out[$k]['rsd_' . $st] = ($out[$k]['rsd_' . $st] ?? 0) + (int) round($r->q);
                    break;
                }
            }
            return $out;
        });
    }

    protected function store(Request $request): string
    {
        $s = strtolower((string) $request->input('store'));
        return isset(self::STORES[$s]) ? $s : 'hollywood';
    }

    protected function requests(array $state): array
    {
        if (!empty($state['requests']['rows'])) {
            return [$state['requests']['rows'], $state['requests']['as_of'] ?? null];
        }
        return [config('rsd_order.requests', []), config('rsd_order.requests_as_of')];
    }

    public function index(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $store = $this->store($request);
        $state = $this->loadState($biz);
        [$reqRows, $asOf] = $this->requests($state);
        $ica = app(InventoryCheckService::class);
        if ($request->filled('feed_search')) {
            $feeds = [];
            foreach ($ica->knownSuppliers() as $k => $m) {
                $f = $ica->loadSupplierFeed($biz, $k);
                $feeds[$k] = ['rows' => count((array) ($f['rows'] ?? [])), 'imported_at' => $f['imported_at'] ?? ($f['fetched_at'] ?? null)];
            }
            return response()->json(['feeds' => $feeds, 'hits' => $ica->searchSupplierFeeds($biz, (string) $request->input('feed_search'))]);
        }
        $suppliers = [];
        foreach ($ica->knownSuppliers() as $k => $meta) $suppliers[$k] = $meta['label'] ?? $k;

        $list = (array) config('rsd_order.list', []);
        try {
            $sales = $this->artistSales($biz, array_merge(array_column($reqRows, 1), array_column($list, 0)));
        } catch (\Throwable $e) {
            \Log::warning('rsd-order artist sales: ' . $e->getMessage());
            $sales = [];
        }
        $fmtOf = function ($t) { return preg_match('/\bCD\b/i', $t) ? 'cd' : 'vinyl'; };
        $seen = [];
        $todo = [];
        foreach ($reqRows as $r) {
            [$title, $artist, $hw, $pico, $none] = array_pad(array_values($r), 5, 0);
            // Sarah 10/12: a request with no store goes to Hollywood.
            $hw = (int) $hw + (int) $none;
            if ($hw + (int) $pico <= 0) continue;
            $seen[self::artistKey($artist) . '|' . self::titleCore($title) . '|' . $fmtOf($title)] = true;
            $todo[] = [$title, $artist, $hw, (int) $pico, false];
        }
        // Titles nobody asked for yet: show them when our sales say they'll move.
        foreach ($list as [$a, $t, $f]) {
            if (isset($seen[self::artistKey($a) . '|' . self::titleCore($t) . '|' . $fmtOf($f)])) continue;
            $s = $sales[self::artistKey($a)] ?? [];
            if (self::salesBump((int) ($s['hollywood'] ?? 0)) + self::salesBump((int) ($s['pico'] ?? 0)) <= 0) continue;
            $todo[] = [mb_strtoupper($t . ($f !== '' ? ' (' . $f . ')' : '') . ' (RSD)'), mb_strtoupper($a), 0, 0, true];
        }

        $rows = [];
        $rsdListings = null;
        $tokIndex = [];
        $rsdTagged = [];
        foreach ($todo as [$title, $artist, $hw, $pico, $fromSales]) {
            $sold = $sales[self::artistKey($artist)] ?? [];
            $key = self::key($artist, $title);
            $isRsd = stripos($title, 'NOT RSD') === false;
            $format = preg_match('/\(CD\)|\bCD\b/i', $title) ? 'CD' : 'LP';
            $upcs = array_filter([(string) ($state['upc'][$key] ?? '')]);
            $saved = (array) ($state['prices'][$key] ?? []);
            foreach ($saved as $sv) if (!empty($sv['upc'])) $upcs[] = $sv['upc'];
            $p = $ica->rsdPrices($biz, $artist, $title, $format, array_values(array_unique($upcs)), $isRsd);
            // Distributors name some RSD titles differently ("SREMMLIFE 2",
            // "TEENAGER OF THE YEAR: LIVE", "WE ARE THE WORLD®"): fall back to
            // any RSD-tagged listing whose title starts the same way, same format.
            if ($isRsd && empty($p['prices'])) {
                if ($rsdListings === null) {
                    $rsdListings = [];
                    foreach ($ica->allFeedListings($biz) as $e) {
                        $e['_rsd'] = preg_match('/\(RSD\)|\bRSD\b|record store day|\(R$/i', (string) ($e['title'] ?? '')) === 1;
                        $e['_tok'] = self::titleTokens((string) ($e['title'] ?? ''));
                        if (!$e['_tok']) continue;
                        $n = count($rsdListings);
                        $rsdListings[] = $e;
                        if ($e['_rsd']) $rsdTagged[] = $n;
                        foreach ($e['_tok'] as $w) $tokIndex[$w][] = $n;
                    }
                }
                $mine = self::titleTokens($title);
                $wantCd = $format === 'CD';
                $aNorm = $ica->normalizeText((string) $artist);
                $more = [];
                // Candidates share this title's rarest word.
                $rare = null;
                foreach ($mine as $w) {
                    if (empty($tokIndex[$w])) continue;
                    if ($rare === null || count($tokIndex[$w]) < count($tokIndex[$rare])) $rare = $w;
                }
                $cands = $rare === null ? [] : $tokIndex[$rare];
                // Plus every RSD-tagged listing whose squashed title starts the same
                // ("SREMMLIFE2" vs "SREMMLIFE 2").
                $core = self::titleCore(preg_replace('/:.*$/', '', $title));
                if (strlen($core) >= 5) {
                    foreach ($rsdTagged as $ci) {
                        $hc = self::titleCore(preg_replace('/:.*$/', '', (string) $rsdListings[$ci]['title']));
                        if (strlen($hc) >= 5 && (strpos($hc, $core) === 0 || strpos($core, $hc) === 0)) $cands[] = $ci;
                    }
                }
                $cands = array_values(array_unique($cands));
                foreach ([true] as $tagged) { // RSD-tagged listings only: untagged ones matched regular pressings
                    foreach ($cands as $ci) {
                        $e = $rsdListings[$ci];
                        if ($e['_rsd'] !== $tagged || !$mine) continue;
                        $isCd = preg_match('/\bCD\b/i', (string) $e['title'] . ' ' . (string) ($e['format'] ?? '')) === 1;
                        if ($isCd !== $wantCd) continue;
                        $small = count($mine) <= count($e['_tok']) ? $mine : $e['_tok'];
                        $hit = count(array_intersect($mine, $e['_tok']));
                        $hc = self::titleCore(preg_replace('/:.*$/', '', (string) $e['title']));
                        $prefix = strlen($core) >= 5 && strlen($hc) >= 5 && (strpos($hc, $core) === 0 || strpos($core, $hc) === 0);
                        if (!$prefix && $hit < max(1, (int) ceil(0.8 * count($small)))) continue;
                        $an = trim((string) ($e['artist_norm'] ?? ''));
                        $artistOk = $an !== '' && ($ica->artistsCompatible($aNorm, $an)
                            || count(array_intersect(array_filter(explode(' ', $aNorm), function ($w) { return strlen($w) >= 4; }), explode(' ', $an))) > 0);
                        // Short titles and untagged listings need the artist to line up.
                        if ((count($small) <= 2 || !$tagged) && !$artistOk) continue;
                        if ($an !== '' && !$artistOk) continue;
                        if (!empty($e['upc'])) $more[] = (string) $e['upc'];
                    }
                    if ($more) break;
                }
                if ($more) $p = $ica->rsdPrices($biz, $artist, $title, $format, array_values(array_unique(array_merge($upcs, $more))), $isRsd);
            }
            // Prices pulled live from a distributor site for this page win
            // until the nightly feed has a newer one.
            foreach ($saved as $k => $sv) {
                $cur = $p['prices'][$k] ?? null;
                if ($cur && strtotime((string) ($cur['checked_at'] ?? '')) > strtotime((string) ($sv['checked_at'] ?? ''))) continue;
                if ((float) ($sv['cost'] ?? 0) <= 0 && empty($sv['gone'])) continue;
                $p['prices'][$k] = ['supplier_key' => $k, 'supplier_label' => $suppliers[$k] ?? $k, 'cost' => (float) $sv['cost'],
                    'upc' => $sv['upc'] ?? null, 'in_stock' => !empty($sv['gone']) ? false : null, 'url' => $sv['url'] ?? null,
                    'checked_at' => $sv['checked_at'] ?? null, 'title' => $sv['title'] ?? null];
                if (!empty($sv['gone']) && (float) $sv['cost'] <= 0) unset($p['prices'][$k]);
                if (!empty($sv['upc']) && !in_array($sv['upc'], $p['upcs'], true)) $p['upcs'][] = $sv['upc'];
            }
            $best = null;
            foreach ($p['prices'] as $k => $e) {
                if ($e['in_stock'] === false && $isRsd === false) continue;
                if (!$best || $e['cost'] < $best['cost']) $best = $e;
            }
            $carts = [];
            foreach (['hollywood' => (int) $hw, 'pico' => (int) $pico] as $st => $req) {
                $q = $state['qty'][$st][$key] ?? null;
                $sug = self::suggested($req, (int) ($sold[$st] ?? 0));
                $carts[$st] = ['requested' => $req, 'suggested' => $sug, 'qty' => $q === null ? $sug : (int) $q,
                    'edited' => $q !== null, 'ordered' => $state['ordered'][$st][$key] ?? null,
                    'sold' => (int) ($sold[$st] ?? 0), 'sold_rsd' => (int) ($sold['rsd_' . $st] ?? 0)];
            }
            $rows[] = [
                'key' => $key,
                'title' => trim(preg_replace('/\s*\((RSD|NOT RSD - REGULAR RELEASE)\)\s*$/i', '', $title)),
                'artist' => $artist,
                'is_rsd' => $isRsd,
                'from_sales' => $fromSales,
                'hw' => (int) $hw,
                'pico' => (int) $pico,
                'carts' => $carts,
                'upcs' => $p['upcs'],
                'prices' => $p['prices'],
                'best' => $best,
            ];
        }
        usort($rows, function ($a, $b) {
            $sa = $a['carts']['hollywood']['suggested'] + $a['carts']['pico']['suggested'];
            $sb = $b['carts']['hollywood']['suggested'] + $b['carts']['pico']['suggested'];
            return [$b['hw'] + $b['pico'], $sb, $a['artist']] <=> [$a['hw'] + $a['pico'], $sa, $b['artist']];
        });

        // One cart per store, split by distributor (each title across its two cheapest, see below).
        $carts = [];
        foreach (array_keys(self::STORES) as $st) {
            $carts[$st] = ['byDist' => [], 'unpriced' => [], 'qty' => 0, 'cost' => 0.0];
            foreach ($rows as $r) {
                $q = $r['carts'][$st]['qty'];
                if ($q <= 0) continue;
                $carts[$st]['qty'] += $q;
                if ($r['best']) {
                    // Sarah 10/16: RSD titles are allocated, so split each title
                    // between the two cheapest distributors that carry it
                    // (cheapest gets the extra copy on odd counts).
                    $opts = array_values(array_filter($r['prices'], function ($e) { return $e['in_stock'] !== false && (float) $e['cost'] > 0; }));
                    usort($opts, function ($x, $y) { return $x['cost'] <=> $y['cost']; });
                    if (empty($opts)) $opts = [$r['best']];
                    $split = ($q >= 2 && count($opts) >= 2) ? [[$opts[0], (int) ceil($q / 2)], [$opts[1], (int) floor($q / 2)]] : [[$opts[0], $q]];
                    foreach ($split as [$e, $n]) {
                        $carts[$st]['byDist'][$e['supplier_key']][] = $r + ['qty' => $n];
                        $carts[$st]['cost'] += $n * $e['cost'];
                    }
                } else {
                    $line = $r + ['qty' => $q];
                    $carts[$st]['unpriced'][] = $line;
                }
            }
        }

        $locationId = null;
        foreach (\App\BusinessLocation::forDropdown($biz, false)->toArray() as $id => $name) {
            if (stripos($name, 'hollywood') !== false) $locationId = $id;
        }

        return view('report.rsd_order', [
            'locationId' => $locationId,
            'store' => $store,
            'stores' => self::STORES,
            'rows' => $rows,
            'suppliers' => $suppliers,
            'carts' => $carts,
            'asOf' => $asOf,
            'event' => config('rsd_order.event'),
            'eventDate' => config('rsd_order.event_date'),
            'sheetUrl' => config('rsd_order.sheet_url'),
        ]);
    }

    /** Staff edit request counts right on the page (Sarah 10/12: "we can all update this"). */
    public function saveRequest(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $state = $this->loadState($biz);
        [$rows] = $this->requests($state);
        $col = ['hollywood' => 2, 'pico' => 3, 'none' => 4][(string) $request->input('field')] ?? null;
        $key = (string) $request->input('key');
        $val = max(0, min(500, (int) $request->input('value')));
        $found = false;
        foreach ($rows as $i => $r) {
            $r = array_values($r);
            if (self::key($r[1], $r[0]) !== $key) continue;
            if ($col === null) return response()->json(['success' => false, 'msg' => 'Unknown column']);
            $r[$col] = $val;
            if ($col === 2) $r[4] = 0; // the Hollywood box already includes no-store requests
            $rows[$i] = $r;
            $found = true;
        }
        if (!$found && $col !== null && $val > 0) {
            // A "from our sales" row (full RSD list) getting its first request.
            foreach ((array) config('rsd_order.list', []) as [$la, $lt, $lf]) {
                $t = mb_strtoupper($lt . ($lf !== '' ? ' (' . $lf . ')' : '') . ' (RSD)');
                if (self::key(mb_strtoupper($la), $t) !== $key) continue;
                $row = [$t, mb_strtoupper($la), 0, 0, 0];
                $row[$col] = $val;
                $rows[] = $row;
                $found = true;
                break;
            }
        }
        if (!$found) return response()->json(['success' => false, 'msg' => 'Title not found']);
        $state['requests'] = ['rows' => array_values($rows), 'as_of' => now()->toDateString()];
        $this->saveState($biz, $state);
        return response()->json(['success' => true]);
    }

    public function addRequest(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $artist = mb_strtoupper(trim((string) $request->input('artist')));
        $title = mb_strtoupper(trim((string) $request->input('title')));
        $where = (string) $request->input('where');
        $store = $this->store($request);
        if ($artist === '' || $title === '') {
            return redirect()->action('RsdOrderController@index', ['store' => $store])
                ->with('status', ['success' => 0, 'msg' => 'Type the artist and the title.']);
        }
        if ($request->input('regular')) $title .= ' (NOT RSD - REGULAR RELEASE)';
        $state = $this->loadState($biz);
        [$rows] = $this->requests($state);
        $col = ['hollywood' => 2, 'pico' => 3][$where] ?? 2;
        $key = self::key($artist, $title);
        $hit = false;
        foreach ($rows as $i => $r) {
            $r = array_values($r);
            if (self::key($r[1], $r[0]) === $key) { $r[$col] = (int) $r[$col] + 1; $rows[$i] = $r; $hit = true; }
        }
        if (!$hit) {
            $row = [$title, $artist, 0, 0, 0];
            $row[$col] = 1;
            $rows[] = $row;
        }
        $state['requests'] = ['rows' => array_values($rows), 'as_of' => now()->toDateString()];
        $this->saveState($biz, $state);
        return redirect()->action('RsdOrderController@index', ['store' => $store])
            ->with('status', ['success' => 1, 'msg' => 'Request added.']);
    }

    /** Live distributor prices for RSD titles, posted from the browser: [{key, supplier, upc, cost, gone, title}]. */
    public function savePrices(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $state = $this->loadState($biz);
        $n = 0;
        foreach ((array) $request->input('items', []) as $it) {
            $key = preg_replace('/[^a-f0-9]/', '', (string) ($it['key'] ?? ''));
            $sup = preg_replace('/[^a-z]/', '', (string) ($it['supplier'] ?? ''));
            if ($key === '' || $sup === '') continue;
            $upc = preg_replace('/\D+/', '', (string) ($it['upc'] ?? ''));
            $state['prices'][$key][$sup] = ['upc' => $upc, 'cost' => round((float) ($it['cost'] ?? 0), 2), 'gone' => !empty($it['gone']),
                'title' => mb_substr((string) ($it['title'] ?? ''), 0, 120), 'url' => $sup === 'ams' && $upc ? 'https://www.allmediasupply.com/Product/x/x/' . $upc : null,
                'checked_at' => now()->toIso8601String()];
            if ($upc !== '' && empty($state['upc'][$key])) $state['upc'][$key] = $upc;
            $n++;
        }
        $this->saveState($biz, $state);
        return response()->json(['success' => true, 'saved' => $n]);
    }

    public function saveQty(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $store = $this->store($request);
        $key = preg_replace('/[^a-f0-9]/', '', (string) $request->input('key'));
        if ($key === '') return response()->json(['success' => false, 'msg' => 'Missing title']);
        $state = $this->loadState($biz);
        if ($request->filled('qty')) {
            $state['qty'][$store][$key] = max(0, min(200, (int) $request->input('qty')));
        } else {
            unset($state['qty'][$store][$key]);
        }
        if ($request->has('upc')) {
            $u = preg_replace('/\D+/', '', (string) $request->input('upc'));
            if ($u === '') unset($state['upc'][$key]); else $state['upc'][$key] = $u;
        }
        $this->saveState($biz, $state);
        return response()->json(['success' => true]);
    }

    /**
     * Public list of the RSD titles actually ordered, per store, for
     * nivessa.com/rsd-black-friday/ordered (Sarah 10/23/2026). Titles and
     * stores only: no suppliers, costs or quantities.
     */
    public function publicOrdered(Request $request)
    {
        $names = [];
        foreach ((array) config('rsd_order.list', []) as $r) {
            $r = array_values((array) $r);
            if (!empty($r[0]) && !empty($r[1])) $names[self::key($r[0], $r[1])] = [$r[0], $r[1]];
        }
        foreach ((array) config('rsd_order.requests', []) as $r) {
            $r = array_values((array) $r);
            if (!empty($r[0]) && !empty($r[1])) $names[self::key($r[1], $r[0])] = [$r[1], $r[0]];
        }
        $byKey = [];
        $unmatched = [];
        $orderedCount = [];
        foreach (glob(storage_path('app/rsd-order-*.json')) ?: [] as $file) {
            $state = json_decode((string) @file_get_contents($file), true);
            if (!is_array($state)) continue;
            foreach ((array) ($state['requests']['rows'] ?? []) as $r) {
                $r = array_values((array) $r);
                if (!empty($r[0]) && !empty($r[1])) $names[self::key($r[1], $r[0])] = [$r[1], $r[0]];
            }
            foreach ((array) ($state['ordered'] ?? []) as $store => $rows) {
                $orderedCount[$store] = count((array) $rows);
                foreach ((array) $rows as $key => $o) {
                    if ((int) ($o['qty'] ?? 0) <= 0) continue;
                    if (!isset($names[$key])) { $unmatched[$store] = ($unmatched[$store] ?? 0) + 1; continue; }
                    [$artist, $title] = $names[$key];
                    $title = trim(preg_replace('/\s*\(NOT RSD - REGULAR RELEASE\)\s*/i', '', $title));
                    $byKey[$key] = $byKey[$key] ?? ['artist' => $artist, 'title' => $title, 'stores' => []];
                    $byKey[$key]['stores'][stripos($store, 'pico') !== false ? 'pico' : 'hollywood'] = true;
                }
            }
        }
        $items = array_map(function ($i) { $i['stores'] = array_keys($i['stores']); return $i; }, array_values($byKey));
        usort($items, function ($a, $b) { return strcasecmp($a['artist'] . $a['title'], $b['artist'] . $b['title']); });
        return response()->json([
            'event' => config('rsd_order.event'),
            'date' => config('rsd_order.event_date'),
            'updated' => now()->toDateTimeString(),
            'items' => $items,
            'check' => ['ordered_rows' => $orderedCount, 'unmatched' => $unmatched],
        ])->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=300');
    }

    public function markOrdered(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $store = $this->store($request);
        $state = $this->loadState($biz);
        $supplier = (string) $request->input('supplier');
        foreach ((array) $request->input('keys', []) as $key) {
            $key = preg_replace('/[^a-f0-9]/', '', (string) $key);
            if ($key === '') continue;
            if ($request->input('undo')) {
                unset($state['ordered'][$store][$key]);
            } else {
                $state['ordered'][$store][$key] = ['supplier' => $supplier, 'qty' => (int) ($request->input('qty.' . $key) ?? 0),
                    'at' => now()->toDateString(), 'by' => auth()->user()->first_name ?? ''];
            }
        }
        $this->saveState($biz, $state);
        return response()->json(['success' => true]);
    }

    /**
     * Paste the request sheet's visible table (Title, Artist, HW, PICO,
     * NO STORE[, Total]) to refresh the counts. Header/TOTAL lines skipped.
     */
    public function pasteRequests(Request $request)
    {
        $biz = (int) $request->session()->get('user.business_id');
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $request->input('paste')) as $line) {
            $c = array_map('trim', explode("\t", $line));
            if (count($c) > 5 && $c[0] === '' && preg_match('/[a-z]/i', $c[1] ?? '')) array_shift($c); // copied from column A
            if (count($c) < 4 || $c[0] === '' || preg_match('/^(title|total)$/i', $c[0])) continue;
            $n = function ($v) { return (int) preg_replace('/\D+/', '', (string) $v); };
            $row = [$c[0], $c[1], $n($c[2] ?? 0), $n($c[3] ?? 0), $n($c[4] ?? 0)];
            if ($row[2] + $row[3] + $row[4] > 0) $rows[] = $row;
        }
        $store = $this->store($request);
        if (empty($rows)) {
            return redirect()->action('RsdOrderController@index', ['store' => $store])
                ->with('status', ['success' => 0, 'msg' => 'Nothing to read. Copy the Title through NO STORE columns from the sheet.']);
        }
        $state = $this->loadState($biz);
        $state['requests'] = ['rows' => $rows, 'as_of' => now()->toDateString()];
        $this->saveState($biz, $state);
        return redirect()->action('RsdOrderController@index', ['store' => $store])
            ->with('status', ['success' => 1, 'msg' => count($rows) . ' requested titles loaded.']);
    }

    /**
     * LA regulars for the RSD eblast (Sarah 10/12): customers with an email
     * who bought in one of our stores 2+ times in the last 12 months. In-store
     * buyers are local by definition; anyone whose saved zip is outside LA
     * County (900-918, 935) is dropped. ?format=csv downloads the Brevo import.
     */
    public function laRegulars(Request $request)
    {
        if (!auth()->user()->can('customer.view')) abort(403);
        $biz = (int) $request->session()->get('user.business_id');
        $min = max(1, (int) $request->input('min', 2));
        $since = now()->subMonths((int) $request->input('months', 12))->toDateString();
        $locs = \App\BusinessLocation::where('business_id', $biz)->pluck('name', 'id')->toArray();
        if ($request->input('mode') === 'top') return $this->topSpenders($request, $biz);
        if ($request->input('mode') === 'clover') {
            // In-store customers who gave an email at the Clover register, newest first.
            $staff = array_flip(array_filter(array_map(function ($e) { return strtolower(trim((string) $e)); },
                \DB::table('users')->where('business_id', $biz)->pluck('email')->toArray())));
            $emails = [];
            $rows = \DB::table('contacts')->where('business_id', $biz)->where('is_default', 0)->whereNotNull('clover_customer_id')
                ->whereNotNull('email')->where('email', 'like', '%@%.%')
                ->orderByRaw('COALESCE(last_purchase_date, clover_synced_at, created_at) DESC')
                ->select('email', 'zip_code', 'loyalty_points', 'visit_count')->get();
            foreach ($rows as $r) {
                $e = strtolower(trim($r->email));
                if (!filter_var($e, FILTER_VALIDATE_EMAIL) || isset($staff[$e]) || isset($emails[$e])) continue;
                if (preg_match('/nivessa|hedvat|^test@|example\./', $e) || !preg_match('/\.(com|net|org|edu|us|io|me|co)$/', $e)) continue;
                $zip = substr(preg_replace('/\D+/', '', (string) $r->zip_code), 0, 5);
                if ($zip !== '' && !preg_match('/^(90[0-9]|91[0-8]|935)/', $zip)) continue;
                $emails[$e] = true;
            }
            return response(count($emails) . "\n" . implode("\n", array_keys($emails)), 200, ['Content-Type' => 'text/plain']);
        }
        if ($request->input('mode') === 'stats') {
            $base = function () use ($biz) { return \DB::table('contacts')->where('business_id', $biz)->whereNotNull('email')->where('email', 'like', '%@%.%'); };
            $cols = \Schema::getColumnListing('contacts');
            $out = ['with_email' => $base()->count(), 'by_type' => $base()->groupBy('type')->select('type', \DB::raw('count(*) as n'))->pluck('n', 'type'),
                'with_clover_id' => $base()->whereNotNull('clover_customer_id')->count(),
                'lifetime_gt0' => $base()->where('lifetime_purchases', '>', 0)->count(),
                'loyalty_gt0' => $base()->where('loyalty_points', '>', 0)->count(),
                'any_sell' => $base()->whereExists(function ($q) { $q->select(\DB::raw(1))->from('transactions as t')->whereRaw('t.contact_id = contacts.id')->where('t.type', 'sell'); })->count(),
                'created_last_year' => $base()->where('created_at', '>=', now()->subYear())->count(),
                'la_zip' => $base()->whereRaw("zip_code REGEXP '^(90[0-9]|91[0-8]|935)'")->count(),
                'columns' => array_values(array_filter($cols, function ($c) { return preg_match('/source|added|origin|clover|loyal|tier|visit|purchase|city|zip|marketing|subscri|opt/i', $c); }))];
            return response()->json($out);
        }
        $storeLocs = array_keys(array_filter($locs, function ($n) { return stripos($n, 'hollywood') !== false || stripos($n, 'pico') !== false; }));
        $picoLocs = array_map('intval', array_keys(array_filter($locs, function ($n) { return stripos($n, 'pico') !== false; })));
        $rows = \DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.business_id', $biz)->where('t.type', 'sell')->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since)
            ->whereIn('t.location_id', $storeLocs)
            ->where('c.is_default', 0)
            ->whereNotNull('c.email')->where('c.email', 'like', '%@%.%')
            ->where('c.email', 'not like', '%@nivessa.com')
            ->where('c.name', 'not like', '%website%')->where('c.name', 'not like', '%walk%in%')
            ->groupBy('c.id', 'c.email', 'c.first_name', 'c.last_name', 'c.name', 'c.zip_code')
            ->havingRaw('COUNT(DISTINCT t.id) >= ?', [$min])
            ->select('c.id', 'c.email', 'c.first_name', 'c.last_name', 'c.name', 'c.zip_code',
                \DB::raw('COUNT(DISTINCT t.id) as purchases'), \DB::raw('MAX(t.transaction_date) as last_purchase'),
                \DB::raw('SUM(t.final_total) as spent'),
                \DB::raw('SUM(t.location_id IN (' . implode(',', $picoLocs ?: [0]) . ')) as pico_visits'))
            ->orderByDesc('purchases')->get();
        $out = [];
        $seen = [];
        // Clover (where cashiers ring in-store sales) customers: the rewards
        // sync keeps lifetime_purchases + last_purchase_date on the contact.
        $minSpent = (float) $request->input('min_spent', 75);
        $clover = \DB::table('contacts as c')
            ->where('c.business_id', $biz)->whereNotNull('c.clover_customer_id')
            ->where('c.is_default', 0)
            ->whereNotNull('c.email')->where('c.email', 'like', '%@%.%')->where('c.email', 'not like', '%@nivessa.com')
            ->where('c.last_purchase_date', '>=', $since)
            ->where('c.lifetime_purchases', '>=', $minSpent)
            ->select('c.id', 'c.email', 'c.first_name', 'c.last_name', 'c.name', 'c.zip_code',
                \DB::raw('0 as purchases'), 'c.last_purchase_date as last_purchase', 'c.lifetime_purchases as spent', \DB::raw('0 as pico_visits'))
            ->orderByDesc('c.lifetime_purchases')->get();
        $rows = $rows->concat($clover);
        // Website buyers who picked up in store or ship to LA County.
        $webCount = null;
        try {
            $web = app(\App\Http\Controllers\WebsiteOrdersController::class)
                ->bridgeGet('/erp/la-regulars?months=' . (int) $request->input('months', 12) . '&min=' . $min);
            $webRows = (array) ($web['data'] ?? []);
            $webCount = !empty($web['success']) ? count($webRows) : ('error: ' . ($web['message'] ?? 'unknown'));
            foreach ($webRows as $w) {
                $parts = preg_split('/\s+/', trim((string) ($w['name'] ?? '')), 2);
                $rows->push((object) ['id' => 0, 'email' => $w['email'] ?? '', 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '',
                    'name' => $w['name'] ?? '', 'zip_code' => '', 'purchases' => (int) ($w['orders'] ?? 0),
                    'last_purchase' => $w['last_order'] ?? '', 'spent' => 0, 'pico_visits' => (int) ($w['pico_orders'] ?? 0)]);
            }
        } catch (\Throwable $e) {
            $webCount = 'error: ' . $e->getMessage();
        }
        foreach ($rows as $r) {
            $email = strtolower(trim($r->email));
            if (isset($seen[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $zip = substr(preg_replace('/\D+/', '', (string) $r->zip_code), 0, 5);
            if ($zip !== '' && !preg_match('/^(90[0-9]|91[0-8]|935)/', $zip)) continue;
            $seen[$email] = true;
            $first = trim((string) $r->first_name);
            if ($first === '') $first = explode(' ', trim((string) $r->name))[0] ?? '';
            $out[] = ['EMAIL' => $email, 'FIRSTNAME' => ucfirst(strtolower($first)), 'LASTNAME' => trim((string) $r->last_name),
                'PURCHASES' => (int) $r->purchases, 'LAST_PURCHASE' => substr((string) $r->last_purchase, 0, 10),
                'SPENT' => round((float) $r->spent, 2), 'STORE' => ((int) $r->pico_visits * 2 > (int) $r->purchases) ? 'Pico' : 'Hollywood'];
        }
        if ($request->input('format') === 'csv') {
            return response()->streamDownload(function () use ($out) {
                $f = fopen('php://output', 'w');
                fputcsv($f, ['EMAIL', 'FIRSTNAME', 'LASTNAME', 'PURCHASES', 'LAST_PURCHASE', 'STORE']);
                foreach ($out as $o) fputcsv($f, [$o['EMAIL'], $o['FIRSTNAME'], $o['LASTNAME'], $o['PURCHASES'], $o['LAST_PURCHASE'], $o['STORE']]);
                fclose($f);
            }, 'la-regulars-' . now()->format('Y-m-d') . '.csv');
        }
        $by = ['clover_spent_75_150' => 0, 'clover_spent_150_300' => 0, 'clover_spent_300_plus' => 0];
        foreach ($out as $o) { if ($o['PURCHASES'] === 0) { $k = $o['SPENT'] >= 300 ? 'clover_spent_300_plus' : ($o['SPENT'] >= 150 ? 'clover_spent_150_300' : 'clover_spent_75_150'); $by[$k]++; continue; } $b = $o['PURCHASES'] >= 10 ? '10+' : ($o['PURCHASES'] >= 5 ? '5-9' : ($o['PURCHASES'] >= 3 ? '3-4' : '2')); $by[$b] = ($by[$b] ?? 0) + 1; }
        return response()->json(['since' => $since, 'min_purchases' => $min, 'count' => count($out), 'website_buyers' => $webCount, 'by_purchases' => $by,
            'by_store' => array_count_values(array_column($out, 'STORE')), 'sample' => array_map(function ($o) { return ['purchases' => $o['PURCHASES'], 'last' => $o['LAST_PURCHASE'], 'store' => $o['STORE']]; }, array_slice($out, 0, 5))]);
    }

    /**
     * ?mode=top: every ERP customer email ranked by what they've spent with
     * us (ERP sales in the last N months + Clover lifetime purchases), LA
     * County zip or none, non-US domains and staff removed. ?limit= caps it,
     * ?exclude=a@b.com,c@d.com skips emails already on the Brevo list.
     */
    protected function topSpenders(Request $request, int $biz)
    {
        $since = now()->subMonths((int) $request->input('months', 36))->toDateString();
        $limit = max(1, min(2000, (int) $request->input('limit', 300)));
        $sales = \DB::table('transactions')->where('business_id', $biz)->where('type', 'sell')->where('status', 'final')
            ->where('transaction_date', '>=', $since)->whereNotNull('contact_id')
            ->groupBy('contact_id')
            ->select('contact_id', \DB::raw('COUNT(*) as n'), \DB::raw('SUM(final_total) as spent'), \DB::raw('MAX(transaction_date) as last_at'));
        $staff = array_flip(array_filter(array_map(function ($e) { return strtolower(trim((string) $e)); },
            \DB::table('users')->where('business_id', $biz)->pluck('email')->toArray())));
        $exclude = array_flip(array_filter(array_map('trim', explode(',', strtolower((string) $request->input('exclude', ''))))));
        $rows = \DB::table('contacts as c')
            ->leftJoinSub($sales, 's', 's.contact_id', '=', 'c.id')
            ->where('c.business_id', $biz)->where('c.is_default', 0)
            ->whereNotNull('c.email')->where('c.email', 'like', '%@%.%')
            ->where(function ($q) { $q->where('s.n', '>', 0)->orWhere('c.lifetime_purchases', '>', 0)->orWhere('c.loyalty_points', '>', 0)->orWhere('c.visit_count', '>', 0); })
            ->select('c.email', 'c.zip_code', 'c.name', \DB::raw('COALESCE(s.n,0) as n'), \DB::raw('COALESCE(s.spent,0) as spent'),
                'c.lifetime_purchases', 'c.loyalty_points', 'c.visit_count', 's.last_at', 'c.last_purchase_date')
            ->get();
        $out = [];
        $drop = ['rows' => count($rows), 'invalid_staff_dupe' => 0, 'test' => 0, 'tld' => 0, 'name' => 0, 'zip' => 0];
        foreach ($rows as $r) {
            $email = strtolower(trim($r->email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($staff[$email]) || isset($exclude[$email]) || isset($out[$email])) { $drop['invalid_staff_dupe']++; continue; }
            if (preg_match('/nivessa|hedvat|^test@|example\./', $email)) { $drop['test']++; if (count($drop['test_samples'] ?? []) < 8) $drop['test_samples'][] = preg_replace('/^[^@]{0,3}[^@]*@/', '***@', $email); continue; }
            if (!preg_match('/\.(com|net|org|edu|us|io|me|co)$/', $email)) { $drop['tld']++; continue; }
            if (stripos((string) $r->name, 'website') !== false || stripos((string) $r->name, 'walk') !== false) { $drop['name']++; continue; }
            $zip = substr(preg_replace('/\D+/', '', (string) $r->zip_code), 0, 5);
            if ($zip !== '' && !preg_match('/^(90[0-9]|91[0-8]|935)/', $zip)) { $drop['zip']++; continue; }
            // Clover rewards points track in-store spend (~1 pt per $1); visits break ties.
            $score = max((float) $r->spent + (float) $r->lifetime_purchases, (float) $r->loyalty_points) + 5 * (int) $r->visit_count;
            $out[$email] = ['email' => $email, 'spent' => round($score, 2), 'orders' => (int) $r->n + (int) $r->visit_count,
                'last' => substr((string) max((string) $r->last_at, (string) $r->last_purchase_date), 0, 10)];
        }
        $out = array_values($out);
        usort($out, function ($a, $b) { return $b['spent'] <=> $a['spent']; });
        $total = count($out);
        $out = array_slice($out, 0, $limit);
        if ($request->input('format') === 'emails') {
            return response(implode("\n", array_column($out, 'email')), 200, ['Content-Type' => 'text/plain']);
        }
        return response()->json(['drop' => $drop, 'since' => $since, 'eligible' => $total, 'returned' => count($out),
            'spent_cutoff' => $out ? end($out)['spent'] : null, 'top_spent' => $out ? $out[0]['spent'] : null]);
    }
}
