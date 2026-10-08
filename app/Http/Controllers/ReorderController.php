<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\ReorderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Weekly Reorder page: one store + one format -> this week's AMS/RedEye
 * order, sorted by genre, with bin counts entered right on the page.
 * See ReorderService for the rules. Open to all staff (operational
 * reorder data, same as the Inventory Check Assistant).
 */
class ReorderController extends Controller
{
    protected $svc;

    public function __construct(ReorderService $svc)
    {
        $this->svc = $svc;
    }

    protected function params(Request $request): array
    {
        $business_id = $request->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id, false)->toArray();
        $permitted = auth()->user()->permitted_locations();
        if ($permitted !== 'all') {
            $locations = array_intersect_key($locations, array_flip($permitted));
        }
        $locationId = (int) $request->input('location_id');
        if (!isset($locations[$locationId])) {
            $locationId = (int) (array_search(true, array_map(function ($n) {
                return stripos($n, 'hollywood') !== false;
            }, $locations)) ?: array_key_first($locations));
        }
        $format = in_array($request->input('format'), ['cd', 'cassette', 'other'], true) ? $request->input('format') : 'vinyl';
        $since = $request->input('since');
        $since = $since && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : null;
        return [$business_id, $locations, $locationId, $format, $since];
    }

    public function index(Request $request)
    {
        [$business_id, $locations, $locationId, $format, $since] = $this->params($request);
        if ($format === 'other') {
            $other = $locationId ? $this->svc->otherAreas($business_id, $locationId) : [];
            return view('report.reorder_other', [
                'areas' => $other['areas'] ?? [],
                'popular' => $other['popular'] ?? [],
                'usedGenres' => $other['used_genres'] ?? [],
                'locations' => $locations,
                'locationId' => $locationId,
                'storeName' => $locations[$locationId] ?? '',
            ]);
        }
        $data = $locationId ? $this->svc->build($business_id, $locationId, $format, $since) : ['rows' => []];

        // This store's "new stock" slice of the weekly purchasing budget
        // (same numbers as the Inventory Check banner).
        $budget = null;
        try {
            $pb = app(\App\Services\InventoryCheckService::class)
                ->currentPurchaseBudget($business_id, auth()->user()->permitted_locations());
            foreach ($pb['per_store'] ?? [] as $st) {
                if (stripos($locations[$locationId] ?? '', $st['label']) !== false) {
                    $budget = ['store' => $st['label'], 'new' => $st['new'], 'week_no' => $pb['week_no'] ?? null,
                        'start' => $pb['start'] ?? null, 'end' => $pb['end'] ?? null];
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Reorder budget failed', ['err' => $e->getMessage()]);
        }

        return view('report.reorder', array_merge($data, [
            'budget' => $budget,
            'locations' => $locations,
            'locationId' => $locationId,
            'format' => $format,
            'sinceParam' => $since,
            'storeName' => $locations[$locationId] ?? '',
        ]));
    }

    public function csv(Request $request)
    {
        [$business_id, $locations, $locationId, $format, $since] = $this->params($request);
        $data = $this->svc->build($business_id, $locationId, $format, $since);
        $store = preg_replace('/[^a-z]+/', '-', strtolower($locations[$locationId] ?? 'store'));
        $name = 'reorder-' . trim($store, '-') . '-' . $format . '-' . now()->format('Y-m-d') . '.csv';
        $whyLabels = $this->whyLabels();

        return response()->streamDownload(function () use ($data, $whyLabels) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Genre', 'Artist', 'Title', 'UPC', 'Grade', 'Why', 'Sold since last order', 'Sold 10 days',
                'Sold this year', 'Avg days to sell', 'Last sold', 'ERP stock', 'Bin count', 'On order', 'Suggested stock',
                'Order qty', 'Best price', 'Best supplier', 'AMS price', 'Note']);
            foreach ($data['rows'] as $r) {
                fputcsv($out, [
                    $r['genre'], $r['artist'], $r['title'], $r['supplier_upc'], $r['grade'],
                    implode(', ', array_map(function ($w) use ($whyLabels) { return $whyLabels[$w] ?? $w; }, $r['why'])),
                    $r['sold_since'], $r['sold_10d'], $r['sold_ytd'], $r['avg_days_to_sell'], $r['last_sold'],
                    $r['erp_stock'], $r['count'], $r['on_order'], $r['suggested'], $r['order_qty'],
                    $r['best_cost'], $r['best_supplier'], $r['ams_cost'],
                    trim(($r['cant_order'] ? "Can't order: " . $r['cant_order'] . '. ' : '') . ($r['used_note'] ?? '')),
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * Backtest against a real past order: run the page as of the day before
     * it and compare titles/copies with that purchase's lines.
     * /reports/reorder/backtest?location_id=&format=&as_of=Y-m-d&since=Y-m-d&purchase_ids=1,2
     */
    public function backtest(Request $request)
    {
        [$business_id, , $locationId, $format, $since] = $this->params($request);
        $asOf = (string) $request->input('as_of');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) return response()->json(['error' => 'as_of=Y-m-d required'], 422);
        $data = $this->svc->build($business_id, $locationId, $format, $since, $asOf);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('purchase_ids'))));
        $actual = \Illuminate\Support\Facades\DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'pl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('t.business_id', $business_id)->whereIn('t.id', $ids ?: [0])
            ->groupBy('p.id')
            ->select('p.id', 'p.name', 'c.name as cat', \Illuminate\Support\Facades\DB::raw('SUM(pl.quantity) as qty'))
            ->get();
        $byPid = [];
        $byKey = [];
        foreach ($data['rows'] as $r) {
            foreach ($r['product_ids'] ?? [] as $pid) $byPid[$pid] = $r;
            $byKey[$this->svc->albumKey($r['artist'], $r['title'])] = $r;
        }
        $sug = array_filter($data['rows'], function ($r) { return $r['order_qty'] > 0; });
        $hit = 0; $hitCopies = 0; $missed = []; $matchedRows = [];
        foreach ($actual as $a) {
            $r = $byPid[(int) $a->id] ?? ($byKey[$this->svc->albumKey(null, $a->name)] ?? null);
            if ($r && $r['order_qty'] > 0) {
                $hit++; $hitCopies += min((int) $a->qty, $r['order_qty']);
                $matchedRows[$r['product_id']] = true;
            } else {
                $missed[] = ['name' => $a->name, 'cat' => $a->cat, 'qty' => (float) $a->qty,
                    'page' => $r ? ['why' => $r['why'], 'sold_ytd' => $r['sold_ytd'], 'sold_since' => $r['sold_since']] : 'not on page'];
            }
        }
        $extra = [];
        foreach ($sug as $r) {
            if (empty($matchedRows[$r['product_id']])) $extra[] = ['title' => trim($r['artist'] . ' / ' . $r['title']), 'qty' => $r['order_qty'], 'why' => $r['why']];
        }
        return response()->json([
            'as_of' => $asOf,
            'since' => $data['since']->toDateTimeString(),
            'actual_titles' => count($actual), 'actual_copies' => (float) $actual->sum('qty'),
            'page_titles' => count($sug), 'page_copies' => array_sum(array_column($sug, 'order_qty')),
            'both_titles' => $hit, 'both_copies' => $hitCopies,
            'missed' => array_slice($missed, 0, 80),
            'page_only' => array_slice($extra, 0, 80),
        ]);
    }

    public function saveCount(Request $request)
    {
        [$business_id, , $locationId] = $this->params($request);
        $pid = (int) $request->input('product_id');
        $raw = trim((string) $request->input('qty'));
        if (!$pid) return response()->json(['ok' => false], 422);
        $this->svc->saveCount($business_id, $locationId, $pid, $raw === '' ? null : (int) $raw, auth()->user()->first_name ?? '',
            array_map('intval', array_filter(explode(',', (string) $request->input('product_ids', '')))));
        return response()->json(['ok' => true]);
    }

    public function importCounts(Request $request)
    {
        [$business_id, , $locationId, $format] = $this->params($request);
        $request->validate(['file' => 'required|file']);
        $countedAt = $request->input('counted_on') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->input('counted_on'))
            ? $request->input('counted_on') . ' 23:59:00' : now()->toDateTimeString();

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(function ($h) { return strtolower(trim((string) $h)); }, fgetcsv($fh) ?: []);
        $upcCol = array_search('upc', $header);
        $qtyCol = false;
        foreach (['qty', 'count', 'quantity', 'qty in stock', 'in stock', 'bin count'] as $c) {
            if (($qtyCol = array_search($c, $header)) !== false) break;
        }
        if ($upcCol === false || $qtyCol === false) {
            return back()->with('status', ['success' => 0, 'msg' => 'The file needs a "upc" column and a "qty" column.']);
        }
        $pairs = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (!isset($row[$upcCol]) || trim((string) ($row[$qtyCol] ?? '')) === '') continue;
            $pairs[] = ['upc' => $row[$upcCol], 'qty' => (int) $row[$qtyCol]];
        }
        fclose($fh);
        [$matched, $unmatched] = $this->svc->importCounts($business_id, $locationId, $pairs, $countedAt, auth()->user()->first_name ?? '');

        return redirect()->action('ReorderController@index', ['location_id' => $locationId, 'format' => $format])
            ->with('status', ['success' => 1, 'msg' => "Loaded {$matched} bin counts. " . count($unmatched) . ' barcodes had no match in the ERP.']);
    }

    public function markOrdered(Request $request)
    {
        [$business_id, , $locationId, $format] = $this->params($request);
        $lines = [];
        foreach ((array) $request->input('lines', []) as $l) {
            $qty = (int) ($l['qty'] ?? 0);
            if ($qty < 1) continue;
            $lines[] = [
                'product_id' => (int) ($l['product_id'] ?? 0) ?: null,
                'upc' => (string) ($l['upc'] ?? ''),
                'title' => mb_substr((string) ($l['title'] ?? ''), 0, 200),
                'supplier' => mb_substr((string) ($l['supplier'] ?? ''), 0, 60),
                'qty' => $qty,
            ];
        }
        if (empty($lines)) {
            return response()->json(['ok' => false, 'msg' => 'Nothing to order.'], 422);
        }
        $order = $this->svc->markOrdered($business_id, $locationId, $format, $lines, auth()->user()->first_name ?? '',
            mb_substr((string) $request->input('note', ''), 0, 200));
        return response()->json(['ok' => true, 'id' => $order['id']]);
    }

    public function deleteOrder(Request $request, string $id)
    {
        [$business_id, , $locationId, $format] = $this->params($request);
        $this->svc->deleteOrder($business_id, $id);
        return redirect()->action('ReorderController@index', ['location_id' => $locationId, 'format' => $format])
            ->with('status', ['success' => 1, 'msg' => 'Order removed from the log.']);
    }

    public function saveSettings(Request $request)
    {
        [$business_id, , $locationId, $format] = $this->params($request);
        $num = function ($k, $def, $min, $max) use ($request) {
            $v = (float) $request->input($k, $def);
            return max($min, min($max, $v));
        };
        $d = ReorderService::DEFAULT_SETTINGS;
        $this->svc->saveSettings($business_id, [
            'cover_months_vinyl' => $num('cover_months_vinyl', $d['cover_months_vinyl'], 0.1, 6),
            'cover_months_cd'    => $num('cover_months_cd', $d['cover_months_cd'], 0.1, 6),
            'cover_months_cassette' => $num('cover_months_cassette', $d['cover_months_cassette'], 0.1, 12),
            'abc_factor' => ['A' => $num('abc_A', 1.25, 0, 3), 'B' => $num('abc_B', 1, 0, 3), 'C' => $num('abc_C', 0.75, 0, 3)],
            'xyz_factor' => ['X' => $num('xyz_X', 1, 0, 3), 'Y' => $num('xyz_Y', 0.9, 0, 3), 'Z' => $num('xyz_Z', 0.75, 0, 3)],
            'bought_once_days' => (int) $num('bought_once_days', 90, 1, 365),
            'used_fast_days'   => (int) $num('used_fast_days', 45, 1, 365),
            'used_min_price'   => $num('used_min_price', 15, 0, 1000),
            'max_line_qty'     => (int) $num('max_line_qty', 10, 1, 100),
            'count_fresh_days' => (int) $num('count_fresh_days', 21, 1, 120),
            'blank_count' => in_array($request->input('blank_count'), ['sold', 'erp', 'zero'], true) ? $request->input('blank_count') : 'sold',
        ]);
        return redirect()->action('ReorderController@index', ['location_id' => $locationId, 'format' => $format])
            ->with('status', ['success' => 1, 'msg' => 'Settings saved.']);
    }

    public function whyLabels(): array
    {
        return [
            'sold' => 'Sold since last order',
            'core' => 'Always stock: check the bin',
            'overdue' => "Hasn't sold lately: missing?",
            'once' => 'Bought once, sold fast',
            'used' => 'Sells used: buy new',
            'other' => 'Sold this year',
        ];
    }
}
