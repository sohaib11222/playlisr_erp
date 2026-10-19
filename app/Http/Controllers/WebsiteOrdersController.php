<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Website order fulfillment console, moved into the ERP (Sarah, 2026-08-27
 * onward) so it can eventually replace nivessa.com's own /admin/orders.
 * Started as cancellation only (the website admin's old generic "Change
 * Status" dropdown had no required reason); grew into the full Needs
 * Action/To Ship/Pickup/Completed/Archived console mirroring
 * src/app/admin/orders/page.jsx on the website. Talks to the website's ERP
 * bridge (POST/GET /api/v1/erp/orders*) the same way EventsController talks
 * to the RSVP/preorder bridge — a small self-contained curl helper here
 * rather than sharing EventsController's, since that class is live day-of
 * event tooling and not worth the risk of touching for this.
 */
class WebsiteOrdersController extends Controller
{
    // Keep in sync with CANCEL_REASONS in server/controllers/erpOrderCancel.controller.js
    const REASONS = [
        'sold_in_store'   => 'Sold in store before online inventory updated',
        'sold_on_discogs' => 'Sold on Discogs before online inventory updated',
        'condition_issue' => "Condition issue we didn't catch",
        'other'           => 'Other (type a reason)',
    ];

    // Keep in sync with the status <select> on the website's order-status
    // modal (src/app/admin/orders/page.jsx).
    const STATUSES = [
        'processing'       => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'picked_up'        => 'Picked Up',
        'shipped'          => 'Shipped',
        'email_sent'       => 'Email Sent',
        'delivered'        => 'Delivered',
        'cancelled'        => 'Cancelled',
        'flag'             => 'Flag',
    ];

    // Tab definitions — same rules as the TABS constant in
    // src/app/admin/orders/page.jsx. Archived is handled separately (it
    // matches by the `archived` flag, not by status). preorder_ship/
    // preorder_pickup mirror the website's carve-out: an order with any
    // not-yet-arrived item is pulled out of Needs Action/To Ship/Pickup
    // into its own bucket, since there's nothing to action until the
    // stock actually arrives (see matchesTab()).
    const TABS = [
        'needs_action'    => ['label' => 'Needs Action',      'statuses' => ['processing', 'flag', 'pending', ''], 'fulfillment' => null],
        'to_ship'         => ['label' => 'To Ship',           'statuses' => ['processing', 'pending', 'flag', ''],                     'fulfillment' => 'shipping'],
        'pickup'          => ['label' => 'Pickup',            'statuses' => ['processing', 'pending', 'flag', ''], 'fulfillment' => 'pickup'],
        // Pulled and waiting for the customer (Sarah, 2026-10-16)
        'ready_pickup'    => ['label' => 'Ready for Pickup',  'statuses' => ['ready_for_pickup'], 'fulfillment' => 'pickup'],
        // Every item picked, just needs packing and a label (Sarah, 2026-10-16)
        'ready_ship'      => ['label' => 'Ready to Ship',     'statuses' => ['processing', 'pending', 'flag', ''],                     'fulfillment' => 'shipping'],
        'preorder_ship'   => ['label' => 'Preorder · To Ship', 'statuses' => ['processing', 'ready_for_pickup', 'flag', 'pending', ''], 'fulfillment' => 'shipping'],
        'preorder_pickup' => ['label' => 'Preorder · Pickup',  'statuses' => ['processing', 'ready_for_pickup', 'flag', 'pending', ''], 'fulfillment' => 'pickup'],
        'completed'       => ['label' => 'Completed',         'statuses' => ['shipped', 'picked_up', 'delivered', 'email_sent', 'cancelled'], 'fulfillment' => null],
    ];

    const PICKUP_SLA_WARN_HOURS = 24;
    const PICKUP_SLA_OVERDUE_HOURS = 48;

    public function index(Request $request)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $activeTab = $request->query('tab', 'needs_action');
        if (!array_key_exists($activeTab, self::TABS) && $activeTab !== 'archived') {
            $activeTab = 'needs_action';
        }
        $statusFilter = (string) $request->query('status', '');
        // An order link (?order=) shows the order whatever its payment status (refunded too)
        $paymentStatusFilter = (string) $request->query('payment_status', preg_match('/^[a-f0-9]{24}$/i', (string) $request->query('order', '')) ? 'all' : 'completed');
        $search = trim((string) $request->query('q', ''));
        $dateFrom = (string) $request->query('from', '');
        $dateTo = (string) $request->query('to', '');
        // Pickup location filter, same as the website admin: hollywood, pico,
        // or unknown (pickup orders with no location saved).
        $locationFilter = (string) $request->query('location', '');
        // Filters used by the #shipping summary links (Sarah, 2026-10-13):
        // overdue=1 (5+ days old), only=website|discogs, shipped_since /
        // cancelled_since (ISO time of the previous summary post).
        $overdueOnly = $request->query('overdue') === '1';
        $onlySource = (string) $request->query('only', '');
        $shippedSince = strtotime((string) $request->query('shipped_since', '')) ?: 0;
        $cancelledSince = strtotime((string) $request->query('cancelled_since', '')) ?: 0;
        $overdueCutoff = time() - 5 * 86400;

        // payment_status is passed to the bridge itself (not just filtered
        // client-side after) so the 300-order window is filled with orders
        // that actually match, instead of getting crowded out by pending/
        // failed-payment orders and pushing real matches out of the window.
        $resp = $this->websiteApi('GET', '/erp/orders/console?limit=300&payment_status=' . urlencode($paymentStatusFilter));
        $bridgeError = $resp === null || ($resp['success'] ?? true) === false;
        $allOrders = $bridgeError ? [] : ($resp['data'] ?? []);
        $bridgeErrorMessage = $bridgeError ? ($resp['message'] ?? 'Could not reach the website.') : null;

        // Link to one order (?order=<id>), e.g. pasted in Slack (Sarah, 2026-10-16):
        // open the tab it lives in and show just that order.
        $linkOrder = (string) $request->query('order', '');
        if (preg_match('/^[a-f0-9]{24}$/i', $linkOrder)) {
            foreach ($allOrders as $lo) {
                if (($lo['_id'] ?? '') !== $linkOrder) continue;
                // Keep the normal Completed view (and counts) unless this order needs All
                if (($lo['payment_status'] ?? '') === 'completed' && !$request->has('payment_status')) $paymentStatusFilter = 'completed';
                foreach (array_merge(array_keys(self::TABS), ['archived']) as $t) {
                    if (self::matchesTab($lo, $t)) { $activeTab = $t; break; }
                }
                break;
            }
            $search = $linkOrder;
        }

        $tabCounts = ['needs_action' => 0, 'to_ship' => 0, 'pickup' => 0, 'ready_pickup' => 0, 'ready_ship' => 0, 'preorder_ship' => 0, 'preorder_pickup' => 0, 'completed' => 0, 'archived' => 0, 'pickup_overdue' => 0];
        foreach ($allOrders as $o) {
            // Mirror the website: its badge counts only ever see orders
            // already fetched for the active payment_status filter
            // (useGetAllOrdersQuery({payment_status})). This bridge pulls
            // every payment status in one shot, so the same filter has to
            // be applied here before counting, or the badges run way
            // ahead of what nivessa.com/admin/orders shows (pending/
            // failed/refunded orders were inflating every count).
            if ($paymentStatusFilter !== 'all' && ($o['payment_status'] ?? '') !== $paymentStatusFilter) {
                continue;
            }
            if (!empty($o['archived'])) {
                $tabCounts['archived']++;
                continue;
            }
            $status = $o['order_status'] ?? '';
            $fm = $o['fulfillment_method'] ?? null;
            if (self::isGiftCardOrder($o)) {
                $tabCounts['completed']++;
                continue;
            }
            if (in_array($status, self::TABS['completed']['statuses'], true)) $tabCounts['completed']++;

            // Preorder orders (any item not in stock yet) get their own
            // buckets — nothing for staff to action until the street date,
            // so they're excluded from Needs Action/To Ship/Pickup below.
            if (self::orderHasPreorderItem($o)) {
                if ($fm === 'shipping' && in_array($status, self::TABS['preorder_ship']['statuses'], true)) {
                    $tabCounts['preorder_ship']++;
                }
                if ($fm === 'pickup' && in_array($status, self::TABS['preorder_pickup']['statuses'], true)) {
                    $tabCounts['preorder_pickup']++;
                }
                continue;
            }

            if (in_array($status, self::TABS['needs_action']['statuses'], true) && !($fm === 'shipping' && self::allPicked($o))) $tabCounts['needs_action']++;
            if ($fm === 'shipping' && in_array($status, self::TABS['to_ship']['statuses'], true)) {
                $tabCounts[self::allPicked($o) ? 'ready_ship' : 'to_ship']++;
            }
            if ($fm === 'pickup' && $status === 'ready_for_pickup') $tabCounts['ready_pickup']++;
            if ($fm === 'pickup' && in_array($status, self::TABS['pickup']['statuses'], true)) {
                $tabCounts['pickup']++;
                if (self::hoursSince($o['createdAt'] ?? null) >= self::PICKUP_SLA_OVERDUE_HOURS) {
                    $tabCounts['pickup_overdue']++;
                }
            }
        }

        // Pickup orders with no known location. Not archive-filtered on
        // purpose (matches the website): they need their location fixed.
        $unknownLocationCount = 0;
        $preorderSummary = [];
        foreach ($allOrders as $o) {
            if ($paymentStatusFilter !== 'all' && ($o['payment_status'] ?? '') !== $paymentStatusFilter) continue;
            if (($o['fulfillment_method'] ?? null) === 'pickup' && !self::knownPickupLocation($o)) $unknownLocationCount++;
            if (!empty($o['archived'])) continue;
            // Total preorders per album (ship + pickup combined), for the
            // preorder tabs.
            foreach ($o['items'] ?? [] as $item) {
                $p = $item['product_id'] ?? null;
                if (!is_array($p) || empty($p['isPreorder'])) continue;
                $key = $p['_id'] ?? ($p['name'] ?? ($item['product_name'] ?? null));
                if (!$key) continue;
                if (!isset($preorderSummary[$key])) {
                    $preorderSummary[$key] = [
                        'name' => $p['name'] ?? ($item['product_name'] ?? 'Untitled'),
                        'media' => self::mediaTypeLabel($p['productType'] ?? null),
                        'format' => $p['format'] ?? null,
                        'sku' => $p['sku'] ?? null,
                        'price' => $p['price'] ?? ($item['price'] ?? null),
                        'street_date' => $p['preorderShipDate'] ?? null,
                        'erp_id' => $p['posProductId'] ?? null,
                        'quantity' => 0,
                    ];
                }
                $preorderSummary[$key]['quantity'] += (int) ($item['quantity'] ?? 0);
            }
        }
        $preorderSummary = array_values($preorderSummary);
        usort($preorderSummary, function ($a, $b) {
            $ta = $a['street_date'] ? strtotime($a['street_date']) : PHP_INT_MAX;
            $tb = $b['street_date'] ? strtotime($b['street_date']) : PHP_INT_MAX;
            return $ta === $tb ? strcasecmp($a['name'], $b['name']) : $ta <=> $tb;
        });

        // Store filter needs each item's pull spot (same as the Location column)
        $filterLocations = self::erpLocations($allOrders);
        // Items waiting to be pulled at each spot (Store filter + Pick route)
        $pullCounts = ['hollywood' => 0, 'pico' => 0, 'warehouse' => 0];
        foreach (self::pendingPulls($allOrders, $filterLocations) as $pr) {
            if (isset($pullCounts[$pr['store']])) $pullCounts[$pr['store']]++;
        }
        $orders = array_values(array_filter($allOrders, function ($o) use ($activeTab, $statusFilter, $paymentStatusFilter, $search, $dateFrom, $dateTo, $overdueOnly, $onlySource, $shippedSince, $cancelledSince, $overdueCutoff, $locationFilter, $filterLocations) {
            if ($onlySource === 'discogs') return false;
            if ($overdueOnly && (strtotime($o['createdAt'] ?? '') ?: PHP_INT_MAX) > $overdueCutoff) return false;
            if ($shippedSince && (strtotime($o['shipped_at'] ?? '') ?: 0) < $shippedSince) return false;
            if ($cancelledSince && (strtotime($o['cancelled_at'] ?? '') ?: 0) < $cancelledSince) return false;
            if (!self::matchesTab($o, $activeTab)) return false;
            if ($paymentStatusFilter !== 'all' && ($o['payment_status'] ?? '') !== $paymentStatusFilter) return false;
            if ($statusFilter !== '' && ($o['order_status'] ?? '') !== $statusFilter) return false;
            if (in_array($locationFilter, ['hollywood', 'pico', 'warehouse'], true)) {
                // Any item to pull at that spot (Hollywood store, Pico, or the
                // warehouse), shipping or pickup. Sarah, 2026-10-16.
                $hit = false;
                $offQueue = self::offQueueNames($o);
                foreach (($o['items'] ?? []) as $it) {
                    if ($hit) break;
                    if (!empty($it['is_gift_card']) || !empty($it['cancelled'])) continue;
                    if (isset($offQueue[strtolower(trim((string) ($it['product_id']['name'] ?? ($it['product_name'] ?? ''))))])) continue;
                    $hit = self::placeIsStore(self::pullParts($it, $filterLocations[(int) ($it['product_id']['posProductId'] ?? 0)] ?? null)['place'], $locationFilter);
                }
                if (!$hit) return false;
            }
            if ($search !== '') {
                $needle = strtolower($search);
                $hay = strtolower(($o['_id'] ?? '') . ' ' . ($o['user_id']['name'] ?? '') . ' ' . ($o['user_id']['email'] ?? '') . ' ' . ($o['display_name'] ?? '') . ' ' . ($o['orderNumber'] ?? ''));
                // Shipping name (the Buyer column often shows it), product name and artist (Sarah, 2026-10-16)
                $hay .= ' ' . strtolower((string) ($o['shippingAddress'][0]['name'] ?? ''));
                foreach (($o['items'] ?? []) as $it) {
                    $hay .= ' ' . strtolower(($it['product_id']['name'] ?? ($it['product_name'] ?? '')) . ' ' . ($it['product_id']['artist'] ?? ($it['product_artist'] ?? '')));
                }
                if (strpos($hay, $needle) === false) return false;
            }
            $createdAt = strtotime($o['createdAt'] ?? '') ?: 0;
            if ($dateFrom !== '' && $createdAt < strtotime($dateFrom)) return false;
            if ($dateTo !== '' && $createdAt > strtotime($dateTo . ' 23:59:59')) return false;
            return true;
        }));

        if ($activeTab === 'pickup') {
            usort($orders, fn($a, $b) => strtotime($a['createdAt'] ?? '') <=> strtotime($b['createdAt'] ?? ''));
        }

        // Discogs orders waiting to ship sit on the To Ship tab too, so Nick
        // marks everything shipped in one place (Sarah, 2026-10-12).
        $discogsToShip = [];
        $discogsError = null;
        $discogsWaiting = null;
        $discogsWaitingError = null;
        if ($activeTab === 'to_ship') {
            $d = $this->websiteApi('GET', '/erp/discogs/to-ship', null, 20);
            if (($d['success'] ?? false) === true) {
                $discogsToShip = $onlySource === 'website' ? [] : ($d['data'] ?? []);
                if ($overdueOnly) {
                    $discogsToShip = array_values(array_filter($discogsToShip, function ($o) use ($overdueCutoff) {
                        return (strtotime($o['order_date'] ?? '') ?: PHP_INT_MAX) <= $overdueCutoff;
                    }));
                }
                // Slack links filter to one order with ?q=<discogs id>.
                if ($search !== '') {
                    $needle = strtolower($search);
                    $discogsToShip = array_values(array_filter($discogsToShip, function ($o) use ($needle) {
                        return strpos(strtolower(($o['id'] ?? '') . ' ' . ($o['buyer'] ?? '')), $needle) !== false;
                    }));
                }
            } else {
                $discogsError = 'Could not load Discogs orders: ' . ($d['message'] ?? 'unknown error');
            }
            // Paid Discogs orders we told the buyer we can't find, still
            // waiting on their answer (Sarah, 2026-10-14): not to ship, but
            // listed so they get a refund or substitute.
            $w = $this->websiteApi('GET', '/erp/discogs/waiting-on-buyer', null, 30);
            if (($w['success'] ?? false) === true) {
                $discogsWaiting = $w['data'] ?? [];
                usort($discogsWaiting, fn($a, $b) => ($b['days_waiting'] ?? 0) <=> ($a['days_waiting'] ?? 0));
            } else {
                $discogsWaitingError = 'Could not load Discogs orders waiting on the buyer: ' . ($w['message'] ?? 'unknown error');
            }
        }

        // Column sorting from the header links (?sort=listed&dir=asc), kept in
        // the URL. All rows are on one page, so sort here (Sarah, 2026-10-13).
        $sortKey = (string) $request->query('sort', '');
        $sortDir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $erpLocations = self::erpLocations($orders);
        self::$sortGenres = $erpLocations;
        if ($sortKey !== '') {
            $orders = self::sortRows($orders, $sortKey, $sortDir, false);
            $discogsToShip = self::sortRows($discogsToShip, $sortKey, $sortDir, true);
        }

        return view('website-orders.index', [
            'erpLocations' => $erpLocations,
            'sortKey' => $sortKey,
            'sortDir' => $sortDir,
            'discogsToShip' => $discogsToShip,
            'discogsError' => $discogsError,
            'discogsWaiting' => $discogsWaiting,
            'discogsWaitingError' => $discogsWaitingError,
            'orders' => $orders,
            'bridgeError' => $bridgeError,
            'bridgeErrorMessage' => $bridgeErrorMessage,
            'reasons' => self::REASONS,
            'statuses' => self::STATUSES,
            'tabs' => self::TABS,
            'tabCounts' => $tabCounts,
            'activeTab' => $activeTab,
            'statusFilter' => $statusFilter,
            'paymentStatusFilter' => $paymentStatusFilter,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'locationFilter' => $locationFilter,
            'pullCounts' => $pullCounts,
            'unknownLocationCount' => $unknownLocationCount,
            'preorderSummary' => $preorderSummary,
            'deskNotes' => self::loadDeskNotes(),
            'summaryFilter' => $overdueOnly || $onlySource !== '' || $shippedSince || $cancelledSince,
            'pickupSlaOverdueHours' => self::PICKUP_SLA_OVERDUE_HOURS,
        ]);
    }

    /**
     * Paid, not-yet-picked-up pickup orders, shaped for the Customer Pickups
     * page (Sarah, 2026-08-29 — regular website preorders/orders held for
     * in-store pickup weren't showing up anywhere staff actually look;
     * they only lived here on /website-orders). Same bridge data as
     * index(), just filtered/reshaped instead of duplicated.
     */
    public function pickupOrdersRows(): array
    {
        // Only ever wants completed-payment orders (see filter below) — pass
        // that to the bridge itself so the 300-order window isn't crowded
        // out by pending/failed-payment orders (same fix as index()).
        $resp = $this->websiteApi('GET', '/erp/orders/console?limit=300&payment_status=completed');
        if ($resp === null || ($resp['success'] ?? true) === false) {
            return [];
        }
        $allOrders = $resp['data'] ?? [];

        $rows = array_values(array_filter($allOrders, function ($o) {
            if (!empty($o['archived'])) return false;
            if (($o['fulfillment_method'] ?? null) !== 'pickup') return false;
            if (($o['payment_status'] ?? '') !== 'completed') return false;
            $status = $o['order_status'] ?? '';
            // Picked-up orders stay on the list for 30 days (shown green)
            // so staff still have the customer's name and items after
            // handing it over (Sarah, 2026-10-07).
            if ($status === 'picked_up') {
                $ts = strtotime($o['updatedAt'] ?? $o['createdAt'] ?? '');
                return $ts && $ts > strtotime('-30 days');
            }
            return in_array($status, ['processing', 'ready_for_pickup'], true);
        }));

        // Oldest first, picked-up orders at the bottom.
        usort($rows, fn($a, $b) => [($a['order_status'] ?? '') === 'picked_up', strtotime($a['createdAt'] ?? '')]
            <=> [($b['order_status'] ?? '') === 'picked_up', strtotime($b['createdAt'] ?? '')]);

        $erpLocations = self::erpLocations($rows);

        return array_map(function ($o) use ($erpLocations) {
            $shipDates = [];
            $items = array_map(function ($item) use (&$shipDates, $erpLocations) {
                $name = $item['product_name'] ?? (($item['is_gift_card'] ?? false) ? 'Gift Card' : 'Item');
                $qty = (int) ($item['quantity'] ?? 1);
                $product = $item['product_id'] ?? [];
                $isPreorder = !empty($product['isPreorder']);
                if ($isPreorder && !empty($product['preorderShipDate'])) {
                    $shipDates[] = $product['preorderShipDate'];
                }
                // Always show the quantity and the format (the CD and the
                // standard LP of a release often share one product name, so
                // staff couldn't tell what to pull). Sarah, 2026-10-06.
                $format = trim((string) ($product['subCategory'] ?? ''));
                $format = ['vinyl records' => 'Vinyl', 'cds' => 'CD', 'cassettes' => 'Cassette'][strtolower($format)] ?? $format;
                if ($format !== '' && stripos($name, $format) === false) {
                    $name .= " ({$format})";
                }
                return [
                    'label'      => "{$qty} x {$name}",
                    'qty'        => $qty,
                    'isPreorder' => $isPreorder,
                    'spot'       => ($item['is_gift_card'] ?? false) ? '' : self::pullSpot($item, $erpLocations[(int) ($product['posProductId'] ?? 0)] ?? null),
                ];
            }, $o['items'] ?? []);

            // Order-level preorder flag: true if ANY item is still a
            // preorder — that's what determines whether Nick can pull it
            // off the shelf right now or has to wait for the street date.
            $isPreorder = !empty(array_filter($items, fn($i) => $i['isPreorder']));
            sort($shipDates);
            $shipDate = $shipDates[0] ?? null;

            return [
                'id'          => $o['_id'] ?? '',
                'customer'    => $o['display_name'] ?? (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? 'Guest')),
                'email'       => $o['user_id']['email'] ?? '',
                'phone'       => $o['contactNumber'] ?? '',
                'items'       => array_map(fn($i) => $i['label'], $items),
                'spots'       => array_map(fn($i) => $i['spot'], $items),
                'unitCount'   => array_sum(array_map(fn($i) => $i['qty'], $items)),
                'total'       => (float) ($o['total_amount'] ?? 0),
                'location'    => $o['pickup_location'] ?? '',
                'placed'      => $o['createdAt'] ?? null,
                'status'      => $o['order_status'] ?? 'processing',
                'isPreorder'  => $isPreorder,
                'shipDate'    => $shipDate,
                // Paid column on /customer-pickups: every row here is a
                // completed payment, but cashiers want to see it (and how
                // it was paid) at a glance on event night.
                'paid'        => (($o['payment_status'] ?? '') === 'completed'),
                'paymentMethod' => (string) ($o['paymentMethod'] ?? ''),
                'storeCredit' => (float) ($o['used_store_credit'] ?? 0),
                'remindedAt'  => $o['pickup_reminded_at'] ?? null,
                'remindedVia' => $o['pickup_reminded_via'] ?? null,
            ];
        }, $rows);
    }

    /**
     * Mark a Discogs order shipped (status on Discogs + tracking number sent
     * to the buyer). The website only records it once Discogs accepts it.
     */
    public function shipDiscogs(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $back = redirect()->route('website-orders.index', ['tab' => 'to_ship']);
        if (!preg_match('/^[0-9]+-[0-9]+$/', $id)) {
            return $back->with('error', 'That is not a Discogs order id.');
        }
        $tracking = trim((string) $request->input('tracking_number'));
        if ($tracking === '') {
            return $back->with('error', 'Enter a tracking number for Discogs order ' . $id . '.');
        }
        $resp = $this->websiteApi('POST', "/erp/discogs/orders/{$id}/ship", [
            'trackingNumber' => $tracking,
            'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
        ], 30);
        if (!empty($resp['timedOut'])) {
            return $back->with('error', 'Discogs took too long to answer for order ' . $id . '. Reload in a minute to check; pressing it again will not mark it twice.');
        }
        if (($resp['success'] ?? false) !== true) {
            return $back->with('error', 'Discogs order ' . $id . ' was not marked shipped: ' . ($resp['message'] ?? 'unknown error'));
        }
        return $back->with('status', 'Discogs order ' . $id . ' marked shipped. The buyer got the tracking number on Discogs.');
    }

    public function updateStatus(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $status = (string) $request->input('status');
        $tracking = trim((string) $request->input('tracking_number'));
        // "Waiting on Stock" is ERP-only: flag it here and never call the
        // website, so the customer isn't notified. Any other status clears it.
        $business_id = (int) $request->session()->get('user.business_id');
        if ($status === 'waiting_stock') {
            \App\Services\WaitingStockOrders::set($business_id, $id, true);
            return redirect()->back()->with('status', 'Marked waiting on stock. Customer was not notified.');
        }
        if (!array_key_exists($status, self::STATUSES)) {
            return redirect()->back()->with('error', 'Pick a valid order status.');
        }
        if ($status === 'shipped' && $tracking === '') {
            return redirect()->back()->with('error', 'Enter a tracking number for shipped orders.');
        }
        if ($status === 'cancelled') {
            return redirect()->back()->with('error', 'Use the Cancel order button so the customer gets the reason and their refund.');
        }

        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/status", [
            'status' => $status,
            'trackingNumber' => $tracking !== '' ? $tracking : null,
            // input('notify_customer', true) would always fall back to the
            // true default, since an unchecked checkbox sends no field at
            // all — has() is what actually tells checked from unchecked.
            'notifyCustomer' => $request->has('notify_customer'),
            'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
        ]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->back()->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to update the order.');
        }
        \App\Services\WaitingStockOrders::set($business_id, $id, false);

        return redirect()->back()->with('status', 'Order status updated.');
    }

    /**
     * Mark a shipping order's lines picked / can't find / not picked, on the
     * same warehouse pick list the website's /admin/warehouse page uses, so
     * Nick's earlier picks there show here too (Sarah, 2026-10-15). $itemId
     * "all" marks every line. Answers JSON when asked (the page's buttons),
     * else redirects back.
     */
    public function pick(Request $request, string $orderId, string $itemId)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $status = (string) $request->input('status');
        $ok = preg_match('/^[a-f0-9]{24}$/i', $orderId) && ($itemId === 'all' || preg_match('/^[a-f0-9]{24}$/i', $itemId))
            && in_array($status, ['Picked', 'Not Picked', "Can't Find", 'Pending Arrival', 'Dropshipped'], true);
        $resp = !$ok ? ['success' => false, 'message' => 'That pick could not be saved.']
            : $this->websiteApi('POST', $itemId === 'all' ? "/erp/picks/{$orderId}/all" : "/erp/picks/{$orderId}/items/{$itemId}", [
                'status' => $status,
                'notes' => $request->has('notes') ? (string) $request->input('notes') : null,
                'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
            ]);
        $saved = $resp !== null && ($resp['success'] ?? false) === true;
        $message = $saved ? 'Saved.' : (($resp['message'] ?? null) ?: 'Could not reach the website to save the pick.');
        if ($request->expectsJson()) {
            return response()->json(['success' => $saved, 'message' => $message], $saved ? 200 : 422);
        }
        return redirect()->back()->with($saved ? 'status' : 'error', $saved ? 'Pick saved.' : $message);
    }

    /** Re-send the ready-for-pickup email + text to a customer who hasn't come in (Sarah, 2026-10-08). */
    public function remind(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/remind", []);
        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->back()->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to send the reminder.');
        }
        return redirect()->back()->with('status', $resp['message'] ?? 'Reminder sent.');
    }

    public function archive(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $archived = filter_var($request->input('archived'), FILTER_VALIDATE_BOOLEAN);

        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/archive", [
            'archived' => $archived,
            'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
        ]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->back()->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to update the order.');
        }

        return redirect()->back()->with('status', $archived ? 'Order archived.' : 'Order restored.');
    }

    /** Bin map vs. store: open order lines to fix + misfiled records (Sarah, 2026-10-16). */
    public function binCheck(Request $request)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $o = $this->websiteApi('GET', '/erp/bin-store/orders', null, 60);
        $p = $this->websiteApi('GET', '/erp/bin-store/products', null, 90);
        return view('website-orders.bin-check', [
            'orderFixes' => ($o['success'] ?? false) ? ($o['data'] ?? []) : [],
            'orderError' => ($o['success'] ?? false) ? null : (($o['message'] ?? null) ?: 'Could not reach the website.'),
            'products' => ($p['success'] ?? false) ? ($p['data'] ?? []) : [],
            'productError' => ($p['success'] ?? false) ? null : (($p['message'] ?? null) ?: 'Could not reach the website.'),
            'canUndo' => \App\Http\Controllers\BinMoveController::canUndo(),
            'origins' => (function () { try { return \App\Http\Controllers\BinMoveController::lastMoveOrigins(); } catch (\Throwable $e) { \Log::warning('bin origins: ' . $e->getMessage()); return []; } })(),
        ]);
    }

    public function binCheckApply(Request $request)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $resp = $this->websiteApi('POST', '/erp/bin-store/orders/apply', [], 120);
        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->route('website-orders.binCheck')->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to fix the orders.');
        }
        $n = (int) ($resp['changed'] ?? 0);
        return redirect()->route('website-orders.binCheck')->with('status', "Fixed {$n} order line" . ($n === 1 ? '' : 's') . '. The old store is saved on each line.');
    }

    /** Move one shipping line to the store that really has it (Sarah, 2026-10-14). */
    public function itemStore(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $store = $request->input('store') === 'pico' ? 'pico' : 'hollywood';
        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/item-store", [
            'productId' => (string) $request->input('product_id'),
            'store' => $store,
            'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
        ]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->back()->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to update the order.');
        }

        return redirect()->back()->with('status', 'Now pulled from ' . ($store === 'pico' ? 'Pico' : 'Hollywood') . '.');
    }

    /**
     * "Listed 3/14/2025 (7 months)" for an item, so staff know how long it's
     * been on the shelf and how hard to look (Sarah, 2026-10-13).
     */
    /**
     * Where to pull one order line (Sarah, 2026-10-16: "needs to be VERY
     * clear"): place, area, bin. A bin-map code is the real spot; Hollywood
     * zones are areas of the Hollywood warehouse, so pico115 in "Hollywood
     * Main Floor" is Warehouse / HW Main Floor / Bin pico115. Store stock
     * sits in the genre bins: Hollywood Store / Vinyl - Used / R&B, Soul & Funk.
     *
     * @return array{place:string, area:string, genre:string, bin:string}
     */
    public static function pullParts(array $item, ?array $erp): array
    {
        $product = $item['product_id'] ?? [];
        $loc = $item['location'] ?? ($product['location'] ?? null);
        $zone = is_array($loc) ? trim((string) ($loc['zone'] ?? '')) : '';
        $code = trim((string) ($item['discogs_code'] ?? ($product['discogsLocation'] ?? '')));
        // A stale Discogs bin can point at the other store (Boston LP 10/16: website
        // said bin pico66 = Hollywood, ERP has it only at Pico, Used Rock). When the
        // ERP stocks it only at the other store, the ERP wins.
        if ($code !== '' && $zone !== '' && $erp) {
            $codeStore = stripos($zone, 'pico') === 0 ? 'pico' : 'hollywood';
            $erpStores = [];
            foreach (array_keys($erp['stores'] ?: $erp['stocked'] ?: []) as $n) {
                if (stripos($n, 'pico') !== false) $erpStores['pico'] = true;
                elseif (stripos($n, 'hollywood') !== false || stripos($n, 'warehouse') !== false) $erpStores['hollywood'] = true;
            }
            if ($erpStores && !isset($erpStores[$codeStore])) $code = '';
        }
        if ($code !== '') {
            $place = $zone === '' ? '' : (stripos($zone, 'pico') === 0 ? 'Pico Store' : 'Warehouse');
            return ['place' => $place, 'area' => preg_replace('/^hollywood\b/i', 'HW', $zone), 'genre' => '', 'bin' => $code];
        }
        if (!$erp) return ['place' => '', 'area' => $zone, 'genre' => '', 'bin' => ''];
        $places = array_map(function ($n) {
            // One name for the warehouse everywhere (bin items say "Warehouse")
            if (stripos($n, 'discogs') !== false) return 'Discogs listing (spot not saved)';
            return stripos($n, 'warehouse') !== false ? 'Warehouse' : str_ireplace(' In Store', ' Store', $n);
        }, array_keys($erp['stores'] ?: $erp['stocked'] ?: []));
        $inStore = (bool) array_filter($places, fn($n) => stripos($n, ' Store') !== false);
        // Store stock: the genre bin on two lines, "Vinyl - Used" then
        // "R&B, Soul & Funk" (ERP category / sub-category).
        // Same for warehouse stock with no bin (sealed stock sits by genre too).
        $genre = ($inStore || ($erp['bin'] ?? '') === '') ? explode(' / ', (string) ($erp['genre'] ?? ''), 2) : [];
        return [
            'place' => implode(' or ', $places),
            'area'  => trim($genre[0] ?? ''),
            'genre' => trim($genre[1] ?? ''),
            'bin'   => (string) ($erp['bin'] ?? ''),
        ];
    }

    /** Pull spot per item name (lowercase), for the pick checklist. */
    public static function spotsByName(array $order, array $erpLocations): array
    {
        $out = [];
        foreach (($order['items'] ?? []) as $it) {
            if (!empty($it['is_gift_card'])) continue;
            $name = strtolower(trim((string) ($it['product_name'] ?? ($it['product_id']['name'] ?? ''))));
            if ($name === '') continue;
            $out[$name] = self::pullSpot($it, $erpLocations[(int) ($it['product_id']['posProductId'] ?? 0)] ?? null);
        }
        return $out;
    }

    /** Where a pullParts() place pulls from: "hollywood" (the store), "pico", "warehouse", or "". */
    public static function pullStore(string $place): string
    {
        if (stripos($place, 'pico') !== false) return 'pico';
        if (stripos($place, 'warehouse') !== false) return 'warehouse';
        if (stripos($place, 'hollywood') !== false) return 'hollywood';
        return '';
    }

    /** Item names (lowercase) that are off the pull queue: picked, or marked Can't Find. */
    public static function offQueueNames(array $o): array
    {
        $out = [];
        foreach (($o['pick_progress']['lines'] ?? []) as $pl) {
            if (in_array($pl['status'] ?? '', ['Picked', 'Packed', 'Shipped', "Can't Find", 'Dropshipped'], true)) {
                $out[strtolower(trim((string) ($pl['name'] ?? '')))] = true;
            }
        }
        return $out;
    }

    /** Genre heading for a store pick-route row: the genre bin, else the full ERP genre. */
    public static function routeGenre(array $r): string
    {
        $g = trim((string) ($r['parts']['genre'] ?? ''));
        if ($g === '' && ($r['genre_full'] ?? '') !== '') {
            $bits = explode(' / ', $r['genre_full'], 2);
            $g = trim($bits[1] ?? $bits[0]);
        }
        return $g !== '' ? ucwords($g) : 'No genre saved';
    }

    /** Whether a pullParts() place is at that pull spot (hollywood / pico / warehouse). */
    public static function placeIsStore(string $place, string $store): bool
    {
        return self::pullStore($place) === $store;
    }

    /**
     * Every item still to pull: open orders (the Needs Action rules, so no
     * preorders, nothing already ready for pickup), not cancelled, not yet
     * marked picked. Each row has the order, item, name and pull spot.
     */
    public static function pendingPulls(array $orders, array $erpLocations): array
    {
        $out = [];
        foreach ($orders as $o) {
            if (!self::matchesTab($o, 'needs_action')) continue;
            $done = [];
            $lines = []; // pick-list line by item name, for the Picked / Can't find buttons
            foreach (($o['pick_progress']['lines'] ?? []) as $pl) {
                $k = strtolower(trim((string) ($pl['name'] ?? '')));
                $lines[$k] = $pl;
                // Picked, or marked Can't Find: off the queue (Sarah, 2026-10-16)
                if (in_array($pl['status'] ?? '', ['Picked', 'Packed', 'Shipped', "Can't Find", 'Dropshipped'], true)) $done[$k] = true;
            }
            foreach (($o['items'] ?? []) as $it) {
                if (!empty($it['is_gift_card']) || !empty($it['cancelled']) || (empty($it['product_id']) && !empty($it['gift_card_amount']))) continue;
                $name = (string) ($it['product_id']['name'] ?? ($it['product_name'] ?? 'Item'));
                if (isset($done[strtolower(trim($name))])) continue;
                $parts = self::pullParts($it, $erpLocations[(int) ($it['product_id']['posProductId'] ?? 0)] ?? null);
                $pl = $lines[strtolower(trim($name))] ?? null;
                $out[] = [
                    'order' => $o, 'item' => $it, 'name' => $name, 'parts' => $parts, 'store' => self::pullStore($parts['place']),
                    'pick_order' => (string) ($o['pick_progress']['order_id'] ?? ''),
                    'pick_item' => (string) ($pl['item_id'] ?? ''),
                    'pick_status' => (string) ($pl['status'] ?? 'Not Picked'),
                    // Full ERP genre ("Vinyl - Used / Rock") and format for the pick route
                    'genre_full' => (string) (($erpLocations[(int) ($it['product_id']['posProductId'] ?? 0)]['genre'] ?? '') ?: ($it['product_id']['genre'] ?? '')),
                    'format' => trim(implode(' ', array_filter([self::mediaTypeLabel($it['product_id']['productType'] ?? null), (string) ($it['product_id']['format'] ?? '')]))),
                ];
            }
        }
        return $out;
    }

    /**
     * Pick route (Sarah, 2026-10-16): everything still to pull at one spot,
     * in walking order so the picker does one pass: area, then bin (or
     * format, then genre, for store stock), then artist.
     */
    public function pickRoute(Request $request)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $store = in_array($request->query('store'), ['hollywood', 'pico', 'warehouse'], true) ? $request->query('store') : 'warehouse';
        $resp = $this->websiteApi('GET', '/erp/orders/console?limit=300&payment_status=completed');
        $error = $resp === null || ($resp['success'] ?? true) === false;
        $orders = $error ? [] : ($resp['data'] ?? []);
        $all = self::pendingPulls($orders, self::erpLocations($orders));
        $counts = ['hollywood' => 0, 'pico' => 0, 'warehouse' => 0];
        foreach ($all as $r) if (isset($counts[$r['store']])) $counts[$r['store']]++;
        $rows = array_values(array_filter($all, fn($r) => $r['store'] === $store));
        // Store routes walk by genre (bins are laid out by genre), the warehouse
        // by area then bin; inside each, newest orders first (Sarah, 2026-10-16).
        usort($rows, function ($a, $b) use ($store) {
            // Within a genre/bin, newest orders first (Sarah, 2026-10-16)
            $newest = fn($r) => sprintf('%012d', 9999999999 - (strtotime($r['order']['createdAt'] ?? '') ?: 0));
            $key = fn($r) => $store === 'warehouse'
                ? [strtolower($r['parts']['area']), $r['parts']['bin'], $newest($r), strtolower((string) ($r['item']['product_id']['artist'] ?? ''))]
                : [strtolower(self::routeGenre($r)), $r['parts']['bin'], $newest($r), strtolower((string) ($r['item']['product_id']['artist'] ?? ''))];
            $ka = $key($a);
            $kb = $key($b);
            for ($i = 0; $i < 4; $i++) {
                $c = strnatcasecmp($ka[$i], $kb[$i]);
                if ($c !== 0) return $c;
            }
            return 0;
        });
        return view('website-orders.pick-route', [
            'store' => $store,
            'rows' => $rows,
            'counts' => $counts,
            'error' => $error ? (($resp['message'] ?? null) ?: 'Could not reach the website.') : null,
        ]);
    }

    /** pullParts() as one line: "Warehouse / HW Main Floor / Bin pico66". */
    public static function pullSpot(array $item, ?array $erp): string
    {
        $p = self::pullParts($item, $erp);
        return implode(' / ', array_filter([$p['place'], $p['area'], $p['genre'], $p['bin'] !== '' ? 'Bin ' . $p['bin'] : ''], fn($x) => $x !== ''));
    }

    /** Value a row is sorted by for a column (website orders or Discogs rows). */
    /**
     * Where each ordered item physically is, from the ERP's own stock (the
     * website's item location is usually blank): stores with qty on hand
     * (Hollywood / Pico / Warehouse) plus the bin if we have one, keyed by
     * ERP product id (Sarah, 2026-10-14).
     */
    protected static function erpLocations(array $orders): array
    {
        $ids = [];
        foreach ($orders as $o) {
            foreach (($o['items'] ?? []) as $it) {
                $pid = (int) ($it['product_id']['posProductId'] ?? 0);
                if ($pid > 0) $ids[$pid] = true;
            }
        }
        if (!$ids) return [];

        try {
            $rows = \DB::table('products as p')
                ->leftJoin('variation_location_details as vld', 'vld.product_id', '=', 'p.id')
                ->leftJoin('business_locations as bl', 'bl.id', '=', 'vld.location_id')
                ->leftJoin('categories as gc', 'gc.id', '=', 'p.category_id')
                ->leftJoin('categories as gs', 'gs.id', '=', 'p.sub_category_id')
                ->whereIn('p.id', array_keys($ids))
                ->select('p.id', 'p.bin_position', 'p.listing_location', 'gc.name as genre', 'gs.name as sub_genre', 'bl.name as loc_name', 'vld.qty_available')
                ->get();
        } catch (\Throwable $e) {
            \Log::warning('website-orders erpLocations failed: ' . $e->getMessage());
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $pid = (int) $r->id;
            if (!isset($map[$pid])) {
                $bin = trim((string) ($r->bin_position ?? ''));
                if ($bin === '') $bin = trim((string) ($r->listing_location ?? ''));
                // Store bins are laid out by genre (the ERP category), so this is
                // what the picker walks to (Sarah, 2026-10-15).
                $genre = implode(' / ', array_filter([trim((string) ($r->genre ?? '')), trim((string) ($r->sub_genre ?? ''))]));
                $map[$pid] = ['stores' => [], 'stocked' => [], 'bin' => $bin, 'genre' => $genre];
            }
            if (empty($r->loc_name)) continue;
            $qty = (float) ($r->qty_available ?? 0);
            $name = (string) $r->loc_name;
            // "Discogs Warehouse" is where Discogs imports are filed in the ERP, not a real room
            if (stripos($name, 'discogs') !== false) $label = 'Discogs listing';
            elseif (stripos($name, 'warehouse') !== false) $label = 'Hollywood Warehouse';
            elseif (stripos($name, 'pico') !== false) $label = 'Pico In Store';
            elseif (stripos($name, 'hollywood') !== false) $label = 'Hollywood In Store';
            else $label = $name;
            // Where it has been stocked, even at 0 now: a website sale already
            // took the qty down, but that's still where the record sits.
            $map[$pid]['stocked'][$label] = true;
            if ($qty > 0) $map[$pid]['stores'][$label] = ($map[$pid]['stores'][$label] ?? 0) + $qty;
        }
        return $map;
    }

    /** ERP locations/genres by product id, so the Genre column can sort by what it shows. */
    protected static $sortGenres = [];

    public static function sortValue(array $o, string $key, bool $discogs)
    {
        $items = $o['items'] ?? [];
        switch ($key) {
            case 'buyer':
                return strtolower($discogs ? ($o['buyer'] ?? '') : ($o['display_name'] ?? (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? ''))));
            case 'product':
                $first = $items[0] ?? [];
                return strtolower($discogs ? ($first['name'] ?? '') : ($first['product_id']['name'] ?? ($first['product_name'] ?? '')));
            case 'listed':
                $dates = array_filter(array_map(function ($it) use ($discogs) {
                    $d = $discogs ? ($it['listed_at'] ?? null) : ($it['product_id']['posCreatedAt'] ?? ($it['product_id']['createdAt'] ?? null));
                    return $d ? strtotime($d) : null;
                }, $items));
                return $dates ? min($dates) : PHP_INT_MAX;
            case 'status':
                return strtolower($discogs ? ($o['status'] ?? '') : ($o['order_status'] ?? ''));
            case 'genre':
                // First item's genre (same ERP-then-website fallback the column shows); blanks last.
                if ($discogs) return '';
                $first = $items[0] ?? [];
                $pid = (int) ($first['product_id']['posProductId'] ?? 0);
                $g = ($pid && (self::$sortGenres[$pid]['genre'] ?? '') !== '') ? self::$sortGenres[$pid]['genre'] : ($first['product_id']['genre'] ?? '');
                return $g !== '' ? strtolower($g) : "\u{FFFF}";
            case 'fulfillment':
                return strtolower($o['fulfillment_method'] ?? '');
            case 'total':
                return (float) ($discogs ? ($o['total'] ?? 0) : ($o['total_amount'] ?? 0));
            case 'placed':
                return strtotime($discogs ? ($o['order_date'] ?? '') : ($o['createdAt'] ?? '')) ?: 0;
        }
        return 0;
    }

    public static function sortRows(array $rows, string $key, string $dir, bool $discogs): array
    {
        usort($rows, function ($a, $b) use ($key, $dir, $discogs) {
            $c = self::sortValue($a, $key, $discogs) <=> self::sortValue($b, $key, $discogs);
            return $dir === 'desc' ? -$c : $c;
        });
        return $rows;
    }

    /** "10/10/2024 · 2 years" for the Listed column. */
    public static function listedShort($date): ?string
    {
        $label = self::listedLabel($date);
        if (!$label) return null;
        return preg_replace('/^Listed (\S+) \((.+)\)$/', '$1 · $2', $label);
    }

    public static function listedLabel($date): ?string
    {
        $t = $date ? strtotime((string) $date) : false;
        if (!$t) return null;
        $days = (int) floor((time() - $t) / 86400);
        if ($days < 0) $days = 0;
        if ($days < 60) {
            $age = $days . ' day' . ($days === 1 ? '' : 's');
        } elseif ($days < 730) {
            $m = (int) floor($days / 30.44);
            $age = $m . ' month' . ($m === 1 ? '' : 's');
        } else {
            $y = (int) floor($days / 365.25);
            $age = $y . ' year' . ($y === 1 ? '' : 's');
        }
        return 'Listed ' . date('n/j/Y', $t) . ' (' . $age . ')';
    }

    protected static function isGiftCardOrder(array $order): bool
    {
        $items = $order['items'] ?? [];
        if (empty($items)) return false;
        foreach ($items as $item) {
            $isGift = ($item['is_gift_card'] ?? false) === true
                || (empty($item['product_id']) && !empty($item['gift_card_amount']));
            if (!$isGift) return false;
        }
        return true;
    }

    // True if any line item is stock that hasn't arrived yet. Used to keep
    // the pickup SLA overdue count from flagging preorder orders — they
    // can't be pulled until the street date no matter how long they've sat.
    protected static function orderHasPreorderItem(array $order): bool
    {
        foreach ($order['items'] ?? [] as $item) {
            if (!empty($item['product_id']['isPreorder'])) return true;
        }
        return false;
    }

    protected static function matchesTab(array $order, string $tab): bool
    {
        $isArchived = !empty($order['archived']);
        if ($tab === 'archived') return $isArchived;
        if ($isArchived) return false;

        if (self::isGiftCardOrder($order)) {
            return $tab === 'completed';
        }

        $def = self::TABS[$tab] ?? null;
        if (!$def) return true;
        $status = $order['order_status'] ?? '';
        $fm = $order['fulfillment_method'] ?? null;
        $statusOk = in_array($status, $def['statuses'], true);
        $fulfillmentOk = $def['fulfillment'] === null || $fm === $def['fulfillment'];

        // Same carve-out as the website: preorder orders only ever show up
        // in preorder_ship/preorder_pickup, never in the regular buckets.
        $isPreorder = self::orderHasPreorderItem($order);
        if ($tab === 'preorder_ship' || $tab === 'preorder_pickup') {
            return $isPreorder && $statusOk && $fulfillmentOk;
        }
        if ($isPreorder && in_array($tab, ['needs_action', 'to_ship', 'pickup', 'ready_pickup', 'ready_ship'], true)) {
            return false;
        }
        // To Ship holds orders still being pulled; fully picked ones are Ready to Ship
        if ($tab === 'to_ship' && self::allPicked($order)) return false;
        // Fully picked shipping orders live in Ready to Ship only
        if ($tab === 'needs_action' && $fm === 'shipping' && self::allPicked($order)) return false;
        if ($tab === 'ready_ship' && !self::allPicked($order)) return false;

        return $statusOk && $fulfillmentOk;
    }

    /** Every pickable item on the order is marked Picked (or further along). */
    public static function allPicked(array $o): bool
    {
        $p = $o['pick_progress'] ?? null;
        return is_array($p) && (int) ($p['total'] ?? 0) > 0 && (int) ($p['picked'] ?? 0) >= (int) $p['total'];
    }

    public static function knownPickupLocation(array $o): bool
    {
        return in_array($o['pickup_location'] ?? '', ['hollywood', 'pico'], true);
    }

    public static function pickupLocationLabel(array $o): string
    {
        $map = ['hollywood' => 'Hollywood', 'pico' => 'Pico'];
        return $map[$o['pickup_location'] ?? ''] ?? 'No Location Set';
    }

    // productType is the real media type ("format" is a vinyl sub-tag that
    // reads the same for an LP and its CD), shortened to LP / CD / Cassette.
    public static function mediaTypeLabel(?string $productType): ?string
    {
        if (!$productType) return null;
        $map = ['Vinyl Records' => 'LP', 'CDs' => 'CD', 'Cassettes' => 'Cassette'];
        return $map[$productType] ?? $productType;
    }

    // Shared notes for the orders desk (the website admin kept these in one
    // browser only). Stored in the system table so everyone sees the same.
    protected static function loadDeskNotes(): array
    {
        $raw = \App\System::getProperty('website_orders_desk_notes');
        $d = $raw ? json_decode($raw, true) : null;
        return is_array($d) ? $d : ['text' => '', 'by' => null, 'at' => null];
    }

    /**
     * Ship or pick up, plus the shipping address (Sarah, 2026-10-16: switch a
     * pickup to free courtesy shipping and fill in the address the customer
     * texts back). Switching to shipping never charges the customer.
     */
    public function saveFulfillment(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            return redirect()->back()->with('error', 'Bad order id.');
        }
        $choice = (string) $request->input('method');
        $method = $choice === 'shipping' ? 'shipping' : 'pickup';
        $u = auth()->user();
        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/fulfillment", [
            'method' => $method,
            'pickupLocation' => in_array($choice, ['hollywood', 'pico'], true) ? $choice : null,
            'address' => [
                'name' => (string) $request->input('name'),
                'fullAddress' => (string) $request->input('fullAddress'),
                'apartment' => (string) $request->input('apartment'),
                'city' => (string) $request->input('city'),
                'state' => (string) $request->input('state'),
                'zipCode' => (string) $request->input('zipCode'),
                'country' => (string) $request->input('country'),
            ],
            'changedBy' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? null),
        ]);
        $ok = $resp !== null && ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok
            ? ($method === 'shipping' ? 'Saved. This order ships (no shipping charge).' : 'Saved. This order is a pickup.')
            : (($resp['message'] ?? null) ?: 'Could not reach the website to save.'));
    }

    /** Note on one website order (Sarah, 2026-10-16). JSON: { text }. */
    public function saveOrderNote(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            return response()->json(['success' => false, 'message' => 'Bad order id.'], 422);
        }
        $u = auth()->user();
        $by = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? null);
        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/staff-note", [
            'text' => mb_substr((string) $request->input('text', ''), 0, 2000),
            'changedBy' => $by,
        ]);
        $ok = $resp !== null && ($resp['success'] ?? false) === true;
        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Saved.' : (($resp['message'] ?? null) ?: 'Could not reach the website to save the note.'),
            'by' => $ok ? ($by . ' ' . now()->setTimezone('America/Los_Angeles')->format('m/d/y g:ia')) : null,
        ], $ok ? 200 : 422);
    }

    public function saveNotes(Request $request)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $text = mb_substr((string) $request->input('notes', ''), 0, 10000);
        $u = auth()->user();
        \App\System::addProperty('website_orders_desk_notes', json_encode([
            'text' => $text,
            'by' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? null),
            'at' => now()->toIso8601String(),
        ]));
        return redirect()->back()->with('status', 'Notes saved.');
    }

    public static function hoursSinceStatic(?string $dateLike): int
    {
        return self::hoursSince($dateLike);
    }

    protected static function hoursSince(?string $dateLike): int
    {
        if (!$dateLike) return 0;
        $t = strtotime($dateLike);
        if ($t === false) return 0;
        return max(0, (int) floor((time() - $t) / 3600));
    }

    public function cancel(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $reason = (string) $request->input('reason');
        $note = trim((string) $request->input('note'));
        if (!array_key_exists($reason, self::REASONS)) {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', 'Pick a valid cancellation reason.');
        }
        if ($reason === 'other' && $note === '') {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', "A note is required when reason is 'Other'.");
        }
        // Internal reason for Jon (never sent to the customer), required (Sarah, 2026-10-16)
        $internal = trim((string) $request->input('internal_note'));
        if (mb_strlen($internal) < 10) {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', 'Write the reason for Jon (what happened and why we refunded) before cancelling. Nothing was cancelled.');
        }

        // Item-level cancel: the dialog sends the ticked lines. An empty
        // pick when there were items to pick is a mistake, not "everything".
        $itemIds = array_values(array_filter((array) $request->input('item_ids', []), 'is_string'));
        if ($request->input('item_select') === '1' && empty($itemIds)) {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', 'Tick at least one item to cancel.');
        }

        // The cancel runs the refund (up to 3 Stripe calls), the customer
        // email and the warehouse sync before answering, so 10s wasn't
        // enough: staff saw "could not reach" after the refund had gone
        // through. Retrying is safe (the website won't refund twice).
        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/cancel", [
            'itemIds' => $itemIds,
            'reason' => $reason,
            'note' => $note !== '' ? $note : null,
            'cancelledBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
            'notifyCustomer' => true,
        ], 45);

        if ($resp === null) {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', 'Could not reach the website to cancel the order.');
        }
        if (!empty($resp['timedOut'])) {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', 'The website took too long to answer, so the cancel may have gone through. Reload the page and check the order. If it still shows, press Cancel order again; it will not refund twice.');
        }
        if (($resp['success'] ?? false) !== true) {
            return redirect()->route('website-orders.index', ['order' => $id])->with('error', $resp['message'] ?? 'Cancellation failed.');
        }

        // Save the internal reason on the order (shown on the order for Jon)
        $this->websiteApi('POST', "/erp/orders/{$id}/refund-note", [
            'text' => mb_substr($internal, 0, 4000),
            'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
            'items' => array_values(array_filter(array_map('trim', explode('||', (string) $request->input('item_names', ''))))),
        ]);

        // Say what happened to the money, not just "cancelled".
        $refundLine = (string) ($resp['refundSummary'] ?? '');
        $refundReason = (string) ($resp['refund']['reason'] ?? '');
        $message = ($resp['message'] ?? 'Order cancelled.') . ($refundLine !== '' ? ' ' . $refundLine : '');
        if (empty($resp['alreadyCancelled'])) {
            $message .= ' The customer has been emailed.';
        }
        $needsAttention = $refundReason !== '' && !in_array($refundReason, ['ok', 'not_charged', 'free_order', 'already_refunded'], true);

        return redirect()->route('website-orders.index', ['order' => $id])->with($needsAttention ? 'error' : 'status', $message);
    }

    /**
     * Read-only preview of the daily #shipping summary plus the check for
     * refunds made straight in Stripe (nothing is posted or saved).
     * ?pi=<Stripe payment intent> checks one payment, ?days= the window.
     */
    public function shippingSummaryPreview(Request $request)
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $query = http_build_query(array_filter([
            'pi' => preg_replace('/[^A-Za-z0-9_]/', '', (string) $request->query('pi', '')),
            'days' => (int) $request->query('days', 0) ?: null,
            'redate' => $request->query('redate') === '1' ? '1' : null,
        ]));
        $resp = $this->websiteApi('GET', '/erp/shipping-summary/preview' . ($query !== '' ? "?{$query}" : ''), null, 45);
        if (($resp['success'] ?? false) !== true) {
            return response('Preview failed: ' . ($resp['message'] ?? 'unknown error'), 502)->header('Content-Type', 'text/plain; charset=utf-8');
        }
        $out = "WHAT WOULD BE POSTED TO #shipping (preview, nothing sent)\n\n" . ($resp['summary'] ?? '') . "\n\n";
        $out .= "REFUNDS IN STRIPE (preview, nothing recorded)\n";
        foreach (($resp['refunds'] ?? []) as $r) {
            $out .= sprintf("%s  $%0.2f  %s  order #%s  %s  -> %s\n",
                substr((string) ($r['created'] ?? ''), 0, 10), (float) ($r['amount'] ?? 0), $r['payment_intent'] ?? '',
                $r['order'] ?? '-', $r['customer'] ?? '', $r['result'] ?? '');
        }
        if (empty($resp['refunds'])) {
            $out .= "(none)\n";
        }
        $out .= "\nWHAT WOULD BE POSTED TO #pico (preview, nothing sent)\n\n";
        try {
            $out .= (\App\Services\PicoPullsNotifier::build('preview') ?? '(nothing to pull at Pico, no post)') . "\n";
        } catch (\Throwable $e) {
            $out .= 'Pico preview failed: ' . $e->getMessage() . "\n";
        }
        return response($out)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** Same resolution as EventsController's — config, env, .env on disk, then the UI-set store file. */
    protected function erpApiKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            $key = $this->envFromDisk('ERP_API_KEY');
        }
        if ($key === '') {
            $key = $this->keyFromStore();
        }
        return $key;
    }

    protected function keyFromStore(): string
    {
        try {
            $path = storage_path('app/events-bridge.json');
            if (!is_file($path)) {
                return '';
            }
            $j = json_decode((string) file_get_contents($path), true);
            return is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function envFromDisk(string $name): string
    {
        try {
            $path = base_path('.env');
            if (!is_readable($path)) {
                return '';
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), $name . '=') === 0) {
                    return trim(trim(substr(ltrim($line), strlen($name) + 1)), "\"'");
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '';
    }

    protected function bridgeBaseUrl(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        return rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
    }

    /**
     * POST/GET the website bridge with the shared key. Always returns an
     * array — on failure it's {success:false, message: <specific reason>}
     * rather than a bare null, so a broken bridge call is self-diagnosing
     * from the error banner instead of always reading "Could not reach the
     * website" regardless of what actually went wrong (bad key, network
     * failure, or the endpoint itself erroring with a non-JSON body).
     */
    /** Read-only bridge GET for other ERP pages (RSD eblast list). */
    public function bridgeGet(string $path, int $timeout = 20): ?array
    {
        return $this->websiteApi('GET', $path, null, $timeout);
    }

    /** Bridge POST for other ERP pages (web-order contact backfill). */
    public function bridgePost(string $path, array $body, int $timeout = 20): ?array
    {
        return $this->websiteApi('POST', $path, $body, $timeout);
    }

    protected function websiteApi(string $method, string $path, array $body = null, int $timeout = 10): ?array
    {
        $key = $this->erpApiKey();
        if ($key === '') {
            return ['success' => false, 'message' => 'No ERP bridge key configured (set one on the Events page).'];
        }
        try {
            $ch = curl_init($this->bridgeBaseUrl() . $path);
            $headers = ['Accept: application/json', 'x-erp-key: ' . $key];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);
            if ($raw === false && $curlErrNo === CURLE_OPERATION_TIMEDOUT) {
                return ['success' => false, 'timedOut' => true, 'message' => 'The website took too long to answer.'];
            }
            if ($raw === false) {
                return ['success' => false, 'message' => 'Could not reach the website' . ($curlErr !== '' ? " (curl: {$curlErr})" : '') . '.'];
            }
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                $snippet = substr(trim((string) $raw), 0, 200);
                return [
                    'success' => false,
                    'message' => "Website returned a non-JSON response (HTTP {$code})" . ($snippet !== '' ? ": {$snippet}" : ' (empty body).'),
                ];
            }
            // Surface non-2xx bodies too (e.g. validation errors) rather than
            // collapsing them to a generic failure — the caller checks
            // $resp['success'].
            return $decoded;
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Bridge error: ' . $e->getMessage()];
        }
    }
}
