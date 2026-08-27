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
        'inventory_error' => "Can't locate it in current inventory",
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
    // matches by the `archived` flag, not by status).
    const TABS = [
        'needs_action' => ['label' => 'Needs Action', 'statuses' => ['processing', 'ready_for_pickup', 'flag', 'pending', ''], 'fulfillment' => null],
        'to_ship'      => ['label' => 'To Ship',      'statuses' => ['processing', 'pending', 'flag', ''],                     'fulfillment' => 'shipping'],
        'pickup'       => ['label' => 'Pickup',       'statuses' => ['processing', 'ready_for_pickup', 'pending', 'flag', ''], 'fulfillment' => 'pickup'],
        'completed'    => ['label' => 'Completed',    'statuses' => ['shipped', 'picked_up', 'delivered', 'email_sent', 'cancelled'], 'fulfillment' => null],
    ];

    const PICKUP_SLA_WARN_HOURS = 24;
    const PICKUP_SLA_OVERDUE_HOURS = 48;

    public function index(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $resp = $this->websiteApi('GET', '/erp/orders/console?limit=300');
        $bridgeError = $resp === null || ($resp['success'] ?? true) === false;
        $allOrders = $bridgeError ? [] : ($resp['data'] ?? []);
        $bridgeErrorMessage = $bridgeError ? ($resp['message'] ?? 'Could not reach the website.') : null;

        $activeTab = $request->query('tab', 'needs_action');
        if (!array_key_exists($activeTab, self::TABS) && $activeTab !== 'archived') {
            $activeTab = 'needs_action';
        }
        $statusFilter = (string) $request->query('status', '');
        $paymentStatusFilter = (string) $request->query('payment_status', 'completed');
        $search = trim((string) $request->query('q', ''));
        $dateFrom = (string) $request->query('from', '');
        $dateTo = (string) $request->query('to', '');

        $tabCounts = ['needs_action' => 0, 'to_ship' => 0, 'pickup' => 0, 'completed' => 0, 'archived' => 0, 'pickup_overdue' => 0];
        foreach ($allOrders as $o) {
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
            if (in_array($status, self::TABS['needs_action']['statuses'], true)) $tabCounts['needs_action']++;
            if (in_array($status, self::TABS['completed']['statuses'], true)) $tabCounts['completed']++;
            if ($fm === 'shipping' && in_array($status, self::TABS['to_ship']['statuses'], true)) {
                $tabCounts['to_ship']++;
            }
            if ($fm === 'pickup' && in_array($status, self::TABS['pickup']['statuses'], true)) {
                $tabCounts['pickup']++;
                if (self::hoursSince($o['createdAt'] ?? null) >= self::PICKUP_SLA_OVERDUE_HOURS) {
                    $tabCounts['pickup_overdue']++;
                }
            }
        }

        $orders = array_values(array_filter($allOrders, function ($o) use ($activeTab, $statusFilter, $paymentStatusFilter, $search, $dateFrom, $dateTo) {
            if (!self::matchesTab($o, $activeTab)) return false;
            if ($paymentStatusFilter !== 'all' && ($o['payment_status'] ?? '') !== $paymentStatusFilter) return false;
            if ($statusFilter !== '' && ($o['order_status'] ?? '') !== $statusFilter) return false;
            if ($search !== '') {
                $needle = strtolower($search);
                $hay = strtolower(($o['_id'] ?? '') . ' ' . ($o['user_id']['name'] ?? '') . ' ' . ($o['user_id']['email'] ?? ''));
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

        return view('website-orders.index', [
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
            'pickupSlaOverdueHours' => self::PICKUP_SLA_OVERDUE_HOURS,
        ]);
    }

    public function updateStatus(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $status = (string) $request->input('status');
        $tracking = trim((string) $request->input('tracking_number'));
        if (!array_key_exists($status, self::STATUSES)) {
            return redirect()->back()->with('error', 'Pick a valid order status.');
        }
        if ($status === 'shipped' && $tracking === '') {
            return redirect()->back()->with('error', 'Enter a tracking number for shipped orders.');
        }

        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/status", [
            'status' => $status,
            'trackingNumber' => $tracking !== '' ? $tracking : null,
            'notifyCustomer' => filter_var($request->input('notify_customer', true), FILTER_VALIDATE_BOOLEAN),
            'changedBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
        ]);

        if ($resp === null || ($resp['success'] ?? false) !== true) {
            return redirect()->back()->with('error', ($resp['message'] ?? null) ?: 'Could not reach the website to update the order.');
        }

        return redirect()->back()->with('status', 'Order status updated.');
    }

    public function archive(Request $request, string $id)
    {
        if (!auth()->user()->can('product.create')) {
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
        $statusOk = in_array($status, $def['statuses'], true);
        $fulfillmentOk = $def['fulfillment'] === null || ($order['fulfillment_method'] ?? null) === $def['fulfillment'];
        return $statusOk && $fulfillmentOk;
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
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $reason = (string) $request->input('reason');
        $note = trim((string) $request->input('note'));
        if (!array_key_exists($reason, self::REASONS)) {
            return redirect()->route('website-orders.index')->with('error', 'Pick a valid cancellation reason.');
        }
        if ($reason === 'other' && $note === '') {
            return redirect()->route('website-orders.index')->with('error', "A note is required when reason is 'Other'.");
        }

        $resp = $this->websiteApi('POST', "/erp/orders/{$id}/cancel", [
            'reason' => $reason,
            'note' => $note !== '' ? $note : null,
            'cancelledBy' => trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username,
            'notifyCustomer' => true,
        ]);

        if ($resp === null) {
            return redirect()->route('website-orders.index')->with('error', 'Could not reach the website to cancel the order.');
        }
        if (($resp['success'] ?? false) !== true) {
            return redirect()->route('website-orders.index')->with('error', $resp['message'] ?? 'Cancellation failed.');
        }

        return redirect()->route('website-orders.index')->with('status', 'Order cancelled and the customer has been notified.');
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

    /** POST/GET the website bridge with the shared key. Null on failure/unconfigured. */
    protected function websiteApi(string $method, string $path, array $body = null): ?array
    {
        $key = $this->erpApiKey();
        if ($key === '') {
            return null;
        }
        try {
            $ch = curl_init($this->bridgeBaseUrl() . $path);
            $headers = ['Accept: application/json', 'x-erp-key: ' . $key];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CUSTOMREQUEST  => $method,
            ]);
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            // Surface non-2xx bodies too (e.g. validation errors) rather than
            // collapsing them to null — the caller checks $resp['success'].
            return $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
