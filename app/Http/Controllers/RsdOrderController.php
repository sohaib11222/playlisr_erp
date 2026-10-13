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

    /** Generous: everything asked for, plus half again (at least one extra). */
    public static function suggested(int $requested): int
    {
        return $requested <= 0 ? 0 : $requested + max(1, (int) ceil($requested / 2));
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

        $rows = [];
        foreach ($reqRows as $r) {
            [$title, $artist, $hw, $pico, $none] = array_pad(array_values($r), 5, 0);
            // Sarah 10/12: a request with no store goes to Hollywood.
            $hw = (int) $hw + (int) $none;
            $none = 0;
            $mine = $store === 'hollywood' ? (int) $hw : (int) $pico;
            $other = $store === 'hollywood' ? (int) $pico : (int) $hw;
            $none = (int) $none;
            $requested = $mine;
            if ((int) $hw + (int) $pico + $none <= 0) continue;
            $key = self::key($artist, $title);
            $isRsd = stripos($title, 'NOT RSD') === false;
            $format = preg_match('/\(CD\)|\bCD\b/i', $title) ? 'CD' : 'LP';
            $upcs = array_filter([(string) ($state['upc'][$key] ?? '')]);
            $saved = (array) ($state['prices'][$key] ?? []);
            foreach ($saved as $sv) if (!empty($sv['upc'])) $upcs[] = $sv['upc'];
            $p = $ica->rsdPrices($biz, $artist, $title, $format, array_values(array_unique($upcs)), $isRsd);
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
                $sug = self::suggested($req);
                $carts[$st] = ['requested' => $req, 'suggested' => $sug, 'qty' => $q === null ? $sug : (int) $q,
                    'edited' => $q !== null, 'ordered' => $state['ordered'][$st][$key] ?? null];
            }
            $rows[] = [
                'key' => $key,
                'title' => trim(preg_replace('/\s*\((RSD|NOT RSD - REGULAR RELEASE)\)\s*$/i', '', $title)),
                'artist' => $artist,
                'is_rsd' => $isRsd,
                'hw' => (int) $hw,
                'pico' => (int) $pico,
                'carts' => $carts,
                'upcs' => $p['upcs'],
                'prices' => $p['prices'],
                'best' => $best,
            ];
        }
        usort($rows, function ($a, $b) {
            return [$b['hw'] + $b['pico'], $a['artist']] <=> [$a['hw'] + $a['pico'], $b['artist']];
        });

        // One cart per store, split by distributor: each title goes to its cheapest one.
        $carts = [];
        foreach (array_keys(self::STORES) as $st) {
            $carts[$st] = ['byDist' => [], 'unpriced' => [], 'qty' => 0, 'cost' => 0.0];
            foreach ($rows as $r) {
                $q = $r['carts'][$st]['qty'];
                if ($q <= 0) continue;
                $line = $r + ['qty' => $q];
                $carts[$st]['qty'] += $q;
                if ($r['best']) {
                    $carts[$st]['byDist'][$r['best']['supplier_key']][] = $line;
                    $carts[$st]['cost'] += $q * $r['best']['cost'];
                } else {
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
            ->where(function ($q) { $q->where('s.n', '>', 0)->orWhere('c.lifetime_purchases', '>', 0); })
            ->select('c.email', 'c.zip_code', 'c.name', \DB::raw('COALESCE(s.n,0) as n'), \DB::raw('COALESCE(s.spent,0) as spent'),
                'c.lifetime_purchases', 's.last_at', 'c.last_purchase_date')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $email = strtolower(trim($r->email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($staff[$email]) || isset($exclude[$email]) || isset($out[$email])) continue;
            if (preg_match('/nivessa|hedvat|^test@|example\./', $email)) continue;
            if (!preg_match('/\.(com|net|org|edu|us|io|me|co)$/', $email)) continue;
            if (stripos((string) $r->name, 'website') !== false || stripos((string) $r->name, 'walk') !== false) continue;
            $zip = substr(preg_replace('/\D+/', '', (string) $r->zip_code), 0, 5);
            if ($zip !== '' && !preg_match('/^(90[0-9]|91[0-8]|935)/', $zip)) continue;
            $out[$email] = ['email' => $email, 'spent' => round((float) $r->spent + (float) $r->lifetime_purchases, 2), 'orders' => (int) $r->n,
                'last' => substr((string) max((string) $r->last_at, (string) $r->last_purchase_date), 0, 10)];
        }
        $out = array_values($out);
        usort($out, function ($a, $b) { return $b['spent'] <=> $a['spent']; });
        $total = count($out);
        $out = array_slice($out, 0, $limit);
        if ($request->input('format') === 'emails') {
            return response(implode("\n", array_column($out, 'email')), 200, ['Content-Type' => 'text/plain']);
        }
        return response()->json(['since' => $since, 'eligible' => $total, 'returned' => count($out),
            'spent_cutoff' => $out ? end($out)['spent'] : null, 'top_spent' => $out ? $out[0]['spent'] : null]);
    }
}
