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
        $suppliers = [];
        foreach ($ica->knownSuppliers() as $k => $meta) $suppliers[$k] = $meta['label'] ?? $k;

        $rows = [];
        foreach ($reqRows as $r) {
            [$title, $artist, $hw, $pico, $none] = array_pad(array_values($r), 5, 0);
            $mine = $store === 'hollywood' ? (int) $hw : (int) $pico;
            $other = $store === 'hollywood' ? (int) $pico : (int) $hw;
            $none = (int) $none;
            $requested = $mine + $none; // no-store requests count at both stores
            if ((int) $hw + (int) $pico + $none <= 0) continue;
            $key = self::key($artist, $title);
            $isRsd = stripos($title, 'NOT RSD') === false;
            $format = preg_match('/\(CD\)|\bCD\b/i', $title) ? 'CD' : 'LP';
            $upcs = array_filter([(string) ($state['upc'][$key] ?? '')]);
            $p = $ica->rsdPrices($biz, $artist, $title, $format, $upcs, $isRsd);
            $best = null;
            foreach ($p['prices'] as $k => $e) {
                if ($e['in_stock'] === false && $isRsd === false) continue;
                if (!$best || $e['cost'] < $best['cost']) $best = $e;
            }
            $qty = $state['qty'][$store][$key] ?? null;
            $suggested = self::suggested($requested);
            $rows[] = [
                'key' => $key,
                'title' => trim(preg_replace('/\s*\((RSD|NOT RSD - REGULAR RELEASE)\)\s*$/i', '', $title)),
                'artist' => $artist,
                'is_rsd' => $isRsd,
                'hw' => (int) $hw,
                'pico' => (int) $pico,
                'mine' => $mine,
                'none' => $none,
                'other' => $other,
                'requested' => $requested,
                'suggested' => $suggested,
                'qty' => $qty === null ? $suggested : (int) $qty,
                'qty_edited' => $qty !== null,
                'upcs' => $p['upcs'],
                'prices' => $p['prices'],
                'best' => $best,
                'ordered' => $state['ordered'][$store][$key] ?? null,
            ];
        }
        usort($rows, function ($a, $b) {
            return [$b['requested'], $b['hw'] + $b['pico'] + $b['none'], $a['artist']] <=> [$a['requested'], $a['hw'] + $a['pico'] + $a['none'], $b['artist']];
        });

        // Per-distributor lists: each title goes to its cheapest distributor.
        $byDist = [];
        $unpriced = [];
        foreach ($rows as $r) {
            if ($r['qty'] <= 0) continue;
            if ($r['best']) {
                $byDist[$r['best']['supplier_key']][] = $r;
            } else {
                $unpriced[] = $r;
            }
        }

        $locationId = null;
        foreach (\App\BusinessLocation::forDropdown($biz, false)->toArray() as $id => $name) {
            if (stripos($name, $store === 'pico' ? 'pico' : 'hollywood') !== false) $locationId = $id;
        }

        return view('report.rsd_order', [
            'locationId' => $locationId,
            'store' => $store,
            'stores' => self::STORES,
            'rows' => $rows,
            'suppliers' => $suppliers,
            'byDist' => $byDist,
            'unpriced' => $unpriced,
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
        $col = ['hollywood' => 2, 'pico' => 3][$where] ?? 4;
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
}
