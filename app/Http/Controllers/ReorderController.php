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
        $format = $request->input('format') === 'cd' ? 'cd' : 'vinyl';
        $since = $request->input('since');
        $since = $since && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : null;
        return [$business_id, $locations, $locationId, $format, $since];
    }

    public function index(Request $request)
    {
        [$business_id, $locations, $locationId, $format, $since] = $this->params($request);
        $data = $locationId ? $this->svc->build($business_id, $locationId, $format, $since) : ['rows' => []];
        return view('report.reorder', array_merge($data, [
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

    public function saveCount(Request $request)
    {
        [$business_id, , $locationId] = $this->params($request);
        $pid = (int) $request->input('product_id');
        $raw = trim((string) $request->input('qty'));
        if (!$pid) return response()->json(['ok' => false], 422);
        $this->svc->saveCount($business_id, $locationId, $pid, $raw === '' ? null : (int) $raw, auth()->user()->first_name ?? '');
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
            'core' => 'Core: check bins',
            'overdue' => 'Overdue',
            'once' => 'Bought once, sold fast',
            'used' => 'Sells used: buy sealed',
            'other' => 'Sold this year',
        ];
    }
}
