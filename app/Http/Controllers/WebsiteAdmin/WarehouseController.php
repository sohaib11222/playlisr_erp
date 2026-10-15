<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Warehouse pages moved from the website's /admin/warehouse (Sarah,
 * 2026-10-15): order list, packer view, order detail (picks, location,
 * notes, Discogs status, USPS labels), employee analytics, metrics and
 * zones. Data is the website's UnifiedOrder pick list, read and written
 * through WebsiteBridge so both sides stay in sync. Picks go through the
 * ERP bridge so the picker's ERP name is recorded.
 */
class WarehouseController extends Controller
{
    const DEFAULT_ZONES = [
        'Hollywood' => [
            'Hollywood Upstairs',
            'Hollywood Main Floor',
            'Hollywood Basement',
            'Hollywood Basement Small Room',
            'Hollywood Warehouse street level',
        ],
        'Pico' => ['Pico Main Floor', 'Pico Storage'],
        'Warehouse' => ['Warehouse'],
        'Other' => ['Other'],
    ];

    const DISCOGS_STATUSES = [
        'New Order', 'Buyer Contacted', 'Invoice Sent', 'Payment Pending', 'Payment Received',
        'In Progress', 'Shipped', 'Merged', 'Order Changed', 'Refund Sent', 'Cancelled',
        'Cancelled (Non-Paying Buyer)', 'Cancelled (Item Unavailable)',
        "Cancelled (Per Buyer's Request)", 'Cancelled (Refund Sent)',
    ];

    // What the website's PUT /discogs-status accepts.
    const DISCOGS_SETTABLE = [
        'New Order', 'Buyer Contacted', 'Invoice Sent', 'Payment Pending', 'Payment Received',
        'In Progress', 'Shipped', 'Cancelled', 'Cancelled (Non-Paying Buyer)',
        'Cancelled (Item Unavailable)', "Cancelled (Per Buyer's Request)",
    ];

    const USPS_SERVICES = [
        'MEDIA_MAIL' => 'USPS Media Mail',
        'GROUND_ADVANTAGE' => 'USPS Ground Advantage',
        'PRIORITY' => 'USPS Priority Mail',
    ];

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $id);
    }

    /* ---------- Zones (shared by every warehouse page) ---------- */

    // The website kept custom zones in each browser's localStorage. Here they
    // are saved once for everyone.
    protected static function zonesPath(): string
    {
        return storage_path('app/warehouse-zones.json');
    }

    public static function zones(): array
    {
        try {
            if (is_file(self::zonesPath())) {
                $j = json_decode((string) file_get_contents(self::zonesPath()), true);
                if (is_array($j)) {
                    $out = [];
                    foreach (array_keys(self::DEFAULT_ZONES) as $store) {
                        $out[$store] = array_values(array_filter((array) ($j[$store] ?? []), 'is_string'));
                    }
                    return $out;
                }
            }
        } catch (\Throwable $e) {
        }
        return self::DEFAULT_ZONES;
    }

    public static function allZones(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::zones()))));
    }

    protected static function saveZones(array $zones): bool
    {
        return @file_put_contents(self::zonesPath(), json_encode($zones, JSON_PRETTY_PRINT)) !== false;
    }

    public static function inferStore(string $zone): string
    {
        if (strpos($zone, 'Pico') === 0) return 'Pico';
        if (strpos($zone, 'Warehouse') === 0) return 'Warehouse';
        if (strpos($zone, 'Hollywood') === 0) return 'Hollywood';
        return 'Other';
    }

    /**
     * Same rule as the website's getEffectiveItemLocation: the item's own
     * location first, then the POS store, then the product's mapped location.
     */
    public static function effectiveLocation(array $item): ?array
    {
        $loc = $item['location'] ?? null;
        if (is_array($loc) && (!empty($loc['zone']) || !empty($loc['store']) || !empty($loc['sub_location']) || !empty($loc['shelf_details']) || !empty($loc['discogs_location_code']))) {
            return $loc + ['fromProduct' => false];
        }
        $product = is_array($item['product_id'] ?? null) ? $item['product_id'] : [];
        $pLoc = is_array($product['location'] ?? null) ? $product['location'] : [];
        $pos = strtolower(trim((string) ($product['posLocation'] ?? $product['storeLocation'] ?? '')));
        $posStore = $pos === 'hollywood' ? 'Hollywood' : ($pos === 'pico' ? 'Pico' : (strpos($pos, 'warehouse') !== false ? 'Warehouse' : null));
        if ($posStore) {
            return ['store' => $posStore, 'zone' => null, 'sub_location' => null, 'shelf_details' => null,
                'discogs_location_code' => $pLoc['discogs_code'] ?? null, 'fromProduct' => true];
        }
        if (!empty($pLoc['zone']) || !empty($pLoc['store']) || !empty($pLoc['sub_location']) || !empty($pLoc['shelf_details']) || !empty($pLoc['discogs_code'])) {
            return ['zone' => $pLoc['zone'] ?? null, 'store' => $pLoc['store'] ?? null, 'sub_location' => $pLoc['sub_location'] ?? null,
                'shelf_details' => $pLoc['shelf_details'] ?? null, 'discogs_location_code' => $pLoc['discogs_code'] ?? null, 'fromProduct' => true];
        }
        return null;
    }

    /* ---------- Pill colors (same meaning as the website's badges) ---------- */

    public static function statusColor(?string $s): string
    {
        $s = (string) $s;
        if (in_array($s, ['Paid', 'Shipped', 'shipped', 'delivered'], true)) return 'green';
        if (in_array($s, ['Pending', 'pending', 'New Order'], true)) return 'yellow';
        if (in_array($s, ['processing', 'Buyer Contacted', 'Invoice Sent'], true)) return 'blue';
        if ($s === 'Payment Pending') return 'orange';
        if (in_array($s, ['Payment Received', 'In Progress'], true)) return 'indigo';
        if (in_array($s, ['Merged', 'Order Changed'], true)) return 'purple';
        if ($s === 'Refund Sent' || stripos($s, 'cancel') === 0) return 'red';
        return '';
    }

    public static function pickingColor(?string $s): string
    {
        $s = (string) $s;
        if (in_array($s, ['Completed', 'All Picked', 'Picked'], true)) return 'green';
        if (in_array($s, ['In Progress', 'Partially Picked'], true)) return 'blue';
        if (in_array($s, ['Issues', "Some Can't Find", "Can't Find"], true)) return 'red';
        return '';
    }

    /* ---------- Order list ---------- */

    public function index(Request $request)
    {
        $this->guard();
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'source' => (string) $request->query('source', ''),
            'discogs_status' => (string) $request->query('discogs_status', ''),
            'picking_status' => (string) $request->query('picking_status', ''),
            'zone' => (string) $request->query('zone', ''),
            'sort_by' => (string) $request->query('sort_by', 'newest'),
            'all' => $request->query('all', '1') === '1' ? '1' : '0',
        ];
        $page = max(1, (int) $request->query('page', 1));
        $params = array_filter([
            'source' => $f['source'],
            'discogs_status' => $f['discogs_status'],
            'picking_status' => $f['picking_status'],
            'sort_by' => $f['sort_by'],
            'search' => $f['q'],
        ], fn($v) => $v !== '');
        $params['include_shipped'] = $f['all'] === '1' ? 'true' : 'false';
        $params['page'] = $page;
        $params['limit'] = 50;

        $resp = WebsiteBridge::admin('GET', '/warehouse/orders?' . http_build_query($params), null, 30);
        $quick = WebsiteBridge::admin('GET', '/warehouse/analytics/quick-metrics');
        $error = ($resp['success'] ?? false) ? null : ($resp['message'] ?? 'Could not load orders.');
        $orders = $resp['orders'] ?? [];

        // Zone filter and column sort apply to the page shown, as on the website.
        if ($f['zone'] !== '') {
            $orders = array_values(array_filter($orders, function ($o) use ($f) {
                foreach ($o['items'] ?? [] as $it) {
                    if ((self::effectiveLocation($it)['zone'] ?? null) === $f['zone']) return true;
                }
                return false;
            }));
        }
        $sort = (string) $request->query('sort', '');
        $dir = $request->query('dir') === 'desc' ? -1 : 1;
        if ($sort !== '') {
            $key = function ($o) use ($sort) {
                switch ($sort) {
                    case 'order_id': return (string) ($o['external_order_id'] ?? $o['_id'] ?? '');
                    case 'customer': return strtolower((string) ($o['customer_name'] ?? $o['buyer']['name'] ?? ''));
                    case 'items': return count($o['items'] ?? []);
                    case 'total': return (float) ($o['total_amount'] ?? $o['total']['value'] ?? 0);
                    case 'status': return strtolower((string) ($o['status'] ?? $o['order_status'] ?? ''));
                    case 'picking': return strtolower((string) ($o['overall_picking_status'] ?? ''));
                    case 'percentage': return (int) ($o['picking_percentage'] ?? 0);
                    case 'date': return strtotime($o['order_date'] ?? '') ?: 0;
                }
                return 0;
            };
            usort($orders, fn($a, $b) => ($key($a) <=> $key($b)) * $dir);
        }

        return view('website_admin.warehouse.index', [
            'f' => $f,
            'orders' => $orders,
            'error' => $error,
            'page' => $page,
            'totalPages' => (int) ($resp['totalPages'] ?? 1),
            'totalCount' => (int) ($resp['totalCount'] ?? count($orders)),
            'quick' => ($quick['success'] ?? false) ? ($quick['metrics'] ?? null) : null,
            'sort' => $sort,
            'dir' => $dir === -1 ? 'desc' : 'asc',
            'zones' => self::allZones(),
            'discogsStatuses' => self::DISCOGS_STATUSES,
        ]);
    }

    public function sync(Request $request)
    {
        $this->guard();
        @set_time_limit(320);
        $resp = WebsiteBridge::admin('POST', '/warehouse/sync/discogs', [], 300);
        if (!($resp['success'] ?? false)) {
            return redirect()->back()->with('error', 'Discogs sync failed: ' . ($resp['message'] ?? 'unknown error'));
        }
        $n = $resp['synced'] ?? $resp['count'] ?? null;
        return redirect()->back()->with('status', $n !== null ? "Synced {$n} orders from Discogs." : ($resp['message'] ?? 'Discogs orders synced.'));
    }

    /* ---------- Packer view ---------- */

    public function packer(Request $request)
    {
        $this->guard();
        $f = [
            'store' => (string) $request->query('store', ''),
            'zone' => (string) $request->query('zone', ''),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', ''),
        ];
        $page = max(1, (int) $request->query('page', 1));
        $params = array_filter(['store' => $f['store'], 'zone' => $f['zone'], 'status' => $f['status']], fn($v) => $v !== '');
        if ($page > 1) $params['page'] = $page;
        $resp = WebsiteBridge::admin('GET', '/warehouse/packer-view' . ($params ? '?' . http_build_query($params) : ''), null, 30);
        $error = ($resp['success'] ?? false) ? null : ($resp['message'] ?? 'Could not load the pick list.');
        $grouped = $resp['groupedByZone'] ?? [];
        if ($f['sort'] !== '') {
            $key = function ($i) use ($f) {
                switch ($f['sort']) {
                    case 'order': return strtolower((string) ($i['order_number'] ?? $i['external_order_id'] ?? ''));
                    case 'customer': return strtolower((string) ($i['customer_name'] ?? ''));
                    case 'item': return strtolower((string) ($i['name'] ?? ''));
                    case 'location': return strtolower((string) ($i['discogs_location'] ?? $i['location']['sub_location'] ?? ''));
                    case 'status': return strtolower((string) ($i['picking_status'] ?? ''));
                    case 'qty': return (int) ($i['quantity'] ?? 0);
                }
                return 0;
            };
            foreach ($grouped as $z => $items) {
                usort($items, fn($a, $b) => $key($a) <=> $key($b));
                $grouped[$z] = $items;
            }
        }
        $summary = $resp['summary'] ?? null;
        $shown = array_sum(array_map('count', $grouped));
        return view('website_admin.warehouse.packer', [
            'f' => $f,
            'grouped' => $grouped,
            'zoneStats' => $resp['zoneStats'] ?? [],
            'summary' => $summary,
            'error' => $error,
            'page' => $page,
            'hasMore' => $summary && ($page * 100) < (int) ($summary['total_items'] ?? 0) && $shown >= 100,
            'zones' => self::allZones(),
        ]);
    }

    /** One line ($itemId) or every line ("all") Picked / Can't Find / Not Picked. JSON for the page buttons. */
    public function pick(Request $request, string $orderId, string $itemId)
    {
        $this->guard();
        $status = (string) $request->input('status');
        $ok = self::validId($orderId) && ($itemId === 'all' || self::validId($itemId))
            && in_array($status, ['Picked', 'Not Picked', "Can't Find"], true);
        $body = ['status' => $status, 'changedBy' => WebsiteBridge::actor()];
        if ($request->filled('notes')) {
            $body['notes'] = (string) $request->input('notes');
        }
        $resp = !$ok ? ['success' => false, 'message' => 'That pick could not be saved.']
            : WebsiteBridge::erp('POST', $itemId === 'all' ? "/picks/{$orderId}/all" : "/picks/{$orderId}/items/{$itemId}", $body);
        $saved = ($resp['success'] ?? false) === true;
        $message = $saved ? 'Saved.' : ($resp['message'] ?? 'Could not save the pick.');
        if ($request->expectsJson()) {
            return response()->json(['success' => $saved, 'message' => $message, 'by' => WebsiteBridge::actor(), 'updated' => $resp['updated'] ?? null], $saved ? 200 : 422);
        }
        return redirect()->back()->with($saved ? 'status' : 'error', $saved ? 'Pick saved.' : $message);
    }

    /* ---------- Order detail ---------- */

    public function show(string $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('GET', '/warehouse/orders/' . rawurlencode($id));
        $order = ($resp['success'] ?? false) ? ($resp['order'] ?? null) : null;
        return view('website_admin.warehouse.show', [
            'order' => $order,
            'error' => $order ? null : ($resp['message'] ?? 'Order not found.'),
            'zones' => self::allZones(),
            'settable' => self::DISCOGS_SETTABLE,
            'services' => self::USPS_SERVICES,
        ]);
    }

    public function updateLocation(Request $request, string $id, string $itemId)
    {
        $this->guard();
        $zone = trim((string) $request->input('zone'));
        if ($zone === '' || !self::validId($id) || !self::validId($itemId)) {
            $resp = ['success' => false, 'message' => 'Pick a zone first.'];
        } else {
            $resp = WebsiteBridge::admin('PUT', "/warehouse/orders/{$id}/items/{$itemId}/location", [
                'zone' => $zone,
                'store' => self::inferStore($zone),
            ]);
        }
        $ok = ($resp['success'] ?? false) === true;
        if ($request->expectsJson()) {
            return response()->json(['success' => $ok, 'message' => $ok ? 'Location saved.' : ($resp['message'] ?? 'Could not save the location.')], $ok ? 200 : 422);
        }
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? 'Location saved.' : ($resp['message'] ?? 'Could not save the location.'));
    }

    public function saveNotes(Request $request, string $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('PUT', "/warehouse/orders/{$id}/notes", [
            'warehouse_notes' => (string) $request->input('warehouse_notes', ''),
        ]);
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? 'Notes saved.' : ($resp['message'] ?? 'Could not save the notes.'));
    }

    public function discogsStatus(Request $request, string $id)
    {
        $this->guard();
        $status = (string) $request->input('status');
        if (!in_array($status, self::DISCOGS_SETTABLE, true)) {
            return redirect()->back()->with('error', 'Pick a status first.');
        }
        $body = ['status' => $status];
        $tracking = trim((string) $request->input('tracking_number'));
        if ($tracking !== '') {
            $body['tracking_number'] = $tracking;
        }
        $resp = WebsiteBridge::admin('PUT', "/warehouse/orders/{$id}/discogs-status", $body, 30);
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? "Discogs status is now {$status}." : ($resp['message'] ?? 'Discogs did not accept the change.'));
    }

    public function uspsRates(Request $request, string $id)
    {
        $this->guard();
        $service = (string) $request->query('service', 'GROUND_ADVANTAGE');
        if (!isset(self::USPS_SERVICES[$service])) $service = 'GROUND_ADVANTAGE';
        $resp = WebsiteBridge::admin('GET', '/shipping/usps/rates?' . http_build_query(['orderId' => $id, 'serviceType' => $service]), null, 30);
        if (!($resp['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Could not get USPS rates.'], 422);
        }
        $rates = is_array($resp['rates'] ?? null) ? array_values($resp['rates']) : [];
        // Best match for the chosen service, same as the website.
        $pick = null;
        foreach ($rates as $r) {
            if (strtoupper((string) ($r['mailClass'] ?? '')) === $service) { $pick = $r; break; }
        }
        if (!$pick) {
            $needle = strtolower(str_replace('USPS ', '', self::USPS_SERVICES[$service]));
            foreach ($rates as $r) {
                if (strpos(strtolower((string) ($r['productName'] ?? '')), $needle) !== false) { $pick = $r; break; }
            }
        }
        $pick = $pick ?: ($rates[0] ?? null);
        return response()->json(['success' => true, 'rate' => $pick ? [
            'name' => $pick['productName'] ?? $pick['mailClass'] ?? '',
            'price' => isset($pick['price']) ? (float) $pick['price'] : null,
            'days' => $pick['estimatedDeliveryDays'] ?? null,
        ] : null]);
    }

    public function buyLabel(Request $request, string $id)
    {
        $this->guard();
        $service = (string) $request->input('service', 'GROUND_ADVANTAGE');
        if (!isset(self::USPS_SERVICES[$service])) {
            return response()->json(['success' => false, 'message' => 'Unknown USPS service.'], 422);
        }
        @set_time_limit(90);
        $resp = WebsiteBridge::admin('POST', '/shipping/usps/create-label', [
            'orderId' => $id,
            'serviceType' => $service,
            'packageDetails' => ['weightOz' => 16, 'length' => 12.5, 'width' => 12.5, 'height' => 1],
        ], 75);
        if (!($resp['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Could not create the label.'], 422);
        }
        $label = $resp['label'] ?? [];
        return response()->json(['success' => true, 'label' => [
            'trackingNumber' => $label['trackingNumber'] ?? '',
            'labelImage' => $label['labelImage'] ?? null,
            'serviceType' => $label['serviceType'] ?? $service,
            'createdAt' => $label['createdAt'] ?? null,
        ], 'paypalSynced' => (bool) ($resp['paypalSync']['synced'] ?? false)]);
    }

    public function voidLabel(Request $request, string $id)
    {
        $this->guard();
        $tracking = trim((string) $request->input('tracking_number'));
        if ($tracking === '') {
            return redirect()->back()->with('error', 'This order has no tracking number to void.');
        }
        $resp = WebsiteBridge::admin('POST', '/shipping/usps/void-label', ['orderId' => $id, 'trackingNumber' => $tracking], 45);
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? 'Label voided. The tracking number was cleared.' : ($resp['message'] ?? 'Could not void the label.'));
    }

    /** The website's old /admin/warehouse/orders/[id]: a read-only view of a website Order. */
    public function websiteOrder(string $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('GET', '/order/single/' . rawurlencode($id));
        $order = ($resp['success'] ?? false) ? ($resp['data'] ?? null) : null;
        return view('website_admin.warehouse.website_order', [
            'order' => is_array($order) ? $order : null,
            'error' => $order ? null : ($resp['message'] ?? 'Order not found.'),
            'id' => $id,
        ]);
    }

    /* ---------- Analytics and metrics ---------- */

    public function analytics(Request $request)
    {
        $this->guard();
        $tz = 'America/Los_Angeles';
        $date = (string) $request->query('date', '');
        $start = (string) $request->query('start', '');
        $end = (string) $request->query('end', '');
        $isDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $useRange = $isDate($start) && $isDate($end);
        if (!$useRange && !$isDate($date)) {
            $date = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');
        }
        $params = $useRange ? ['startDate' => $start, 'endDate' => $end] : ['date' => $date];
        $resp = WebsiteBridge::admin('GET', '/warehouse/analytics/employee-summary?' . http_build_query($params), null, 30);
        $rows = ($resp['success'] ?? false) ? ($resp['data'] ?? []) : [];
        $totals = $resp['totals'] ?? ['total_items_picked' => 0, 'total_unique_items' => 0, 'total_shipped_orders' => 0, 'total_employees' => 0];

        if ($request->query('export') === 'csv') {
            $name = 'employee-summary-' . ($useRange ? "{$start}-to-{$end}" : $date) . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Employee Name', 'Email', 'Items Picked', 'Unique Items', 'Orders Worked', 'Shipped Orders']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['employee_name'] ?? '', $r['employee_email'] ?? '', $r['items_picked'] ?? 0,
                        $r['unique_items_picked'] ?? 0, $r['orders_worked_on'] ?? 0, $r['shipped_orders_count'] ?? 0]);
                }
                fclose($out);
            }, $name, ['Content-Type' => 'text/csv']);
        }

        return view('website_admin.warehouse.analytics', [
            'rows' => $rows,
            'totals' => $totals,
            'error' => ($resp['success'] ?? false) ? null : ($resp['message'] ?? 'Could not load the summary.'),
            'date' => $date,
            'start' => $start,
            'end' => $end,
            'useRange' => $useRange,
            'label' => $useRange
                ? date('M j, Y', strtotime($start)) . ' to ' . date('M j, Y', strtotime($end))
                : date('F j, Y', strtotime($date)),
        ]);
    }

    public function metrics()
    {
        $this->guard();
        $resp = WebsiteBridge::admin('GET', '/warehouse/analytics/metrics', null, 45);
        return view('website_admin.warehouse.metrics', [
            'm' => ($resp['success'] ?? false) ? ($resp['metrics'] ?? null) : null,
            'error' => ($resp['success'] ?? false) ? null : ($resp['message'] ?? 'Could not load the metrics.'),
        ]);
    }

    /* ---------- Zones ---------- */

    public function zonesPage()
    {
        $this->guard();
        return view('website_admin.warehouse.zones', ['zones' => self::zones()]);
    }

    public function zonesSave(Request $request)
    {
        $this->guard();
        $zones = self::zones();
        $action = (string) $request->input('action');
        $store = (string) $request->input('store');
        $name = trim(preg_replace('/\s+/', ' ', (string) $request->input('name')));
        $old = (string) $request->input('old');

        if ($action === 'reset') {
            $zones = self::DEFAULT_ZONES;
            $msg = 'Zones are back to the defaults.';
        } else {
            if (!isset($zones[$store])) {
                return redirect()->back()->with('error', 'Pick a store first.');
            }
            if ($action === 'add') {
                if ($name === '') return redirect()->back()->with('error', 'Type a zone name first.');
                if (in_array($name, $zones[$store], true)) return redirect()->back()->with('error', 'That zone already exists for this store.');
                $zones[$store][] = $name;
                $msg = "Added {$name} to {$store}.";
            } elseif ($action === 'rename') {
                if ($old === 'Other') return redirect()->back()->with('error', 'The Other zone cannot be renamed.');
                if ($name === '') return redirect()->back()->with('error', 'A zone name cannot be empty.');
                $i = array_search($old, $zones[$store], true);
                if ($i === false) return redirect()->back()->with('error', 'That zone was not found.');
                $zones[$store][$i] = $name;
                $msg = "Renamed {$old} to {$name}.";
            } elseif ($action === 'delete') {
                if ($old === 'Other') return redirect()->back()->with('error', 'The Other zone cannot be deleted.');
                $zones[$store] = array_values(array_filter($zones[$store], fn($z) => $z !== $old));
                $msg = "Deleted {$old}.";
            } else {
                return redirect()->back();
            }
        }
        if (!self::saveZones($zones)) {
            return redirect()->back()->with('error', 'Could not save the zones on the ERP server.');
        }
        return redirect()->back()->with('status', $msg);
    }
}
