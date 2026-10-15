<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Website customer return requests, ported from the website's /admin/returns
 * and /admin/returns/create (Sarah, 2026-10-15). Approving a store credit
 * return makes the website issue and email a store credit gift card, exactly
 * as the website admin did.
 */
class ReturnsController extends Controller
{
    const TABS = ['' => 'All', 'PENDING_VALIDATION' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'];

    const REFUND_METHODS = [
        'to_original' => 'Refund to original payment',
        'store_credit' => 'Store credit / gift card',
        'cash' => 'Cash',
    ];

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->guard();
        // Pending is the default tab; ?status= (empty) is All.
        $status = $request->has('status') ? (string) $request->query('status') : 'PENDING_VALIDATION';
        if (!array_key_exists($status, self::TABS)) {
            $status = 'PENDING_VALIDATION';
        }
        $resp = WebsiteBridge::admin('GET', '/returns/admin' . ($status !== '' ? '?status=' . $status : ''), null, 30);

        return view('website_admin.inbox.returns', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load returns.') : null,
            'returns' => $resp['data'] ?? [],
            'status' => $status,
            'tabs' => self::TABS,
        ]);
    }

    public function resolve(Request $request, $id)
    {
        $this->guard();
        $action = (string) $request->input('action');
        if (!in_array($action, ['approve', 'reject'], true)) {
            return back()->with('error', 'Unknown action.');
        }
        $body = ['action' => $action];
        if ($action === 'reject') {
            $reason = trim((string) $request->input('rejectionReason', ''));
            if ($reason === '') {
                return back()->with('error', 'A rejection reason is required.');
            }
            $body['rejectionReason'] = $reason;
        }
        $resp = WebsiteBridge::admin('PATCH', '/returns/admin/' . rawurlencode($id), $body, 30);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? ($action === 'approve' ? 'Failed to approve.' : 'Failed to reject.'));
        }
        return back()->with('status', $resp['message'] ?? ($action === 'approve' ? 'Return approved.' : 'Return rejected.'));
    }

    /** Step through: search customer (?q=), pick customer (?customer=), pick order (&order=). */
    public function create(Request $request)
    {
        $this->guard();
        $q = trim((string) $request->query('q', ''));
        $customerId = trim((string) $request->query('customer', ''));
        $orderId = trim((string) $request->query('order', ''));

        $results = [];
        $searchError = null;
        if ($customerId === '' && mb_strlen($q) >= 2) {
            $r = WebsiteBridge::admin('GET', '/returns/admin/customer-lookup?q=' . rawurlencode($q));
            if (!empty($r['success'])) {
                $results = $r['data'] ?? [];
            } else {
                $searchError = $r['message'] ?? 'Search failed.';
            }
        }

        $customer = null;
        $orders = [];
        $ordersError = null;
        if ($customerId !== '') {
            $r = WebsiteBridge::admin('GET', '/returns/admin/customer/' . rawurlencode($customerId) . '/orders', null, 30);
            if (!empty($r['success'])) {
                $customer = $r['customer'] ?? ['_id' => $customerId, 'name' => '', 'email' => ''];
                $orders = $r['data'] ?? [];
            } else {
                $ordersError = $r['message'] ?? 'Could not load orders.';
            }
        }

        $selectedOrder = null;
        foreach ($orders as $o) {
            if (($o['_id'] ?? '') === $orderId) {
                $selectedOrder = $o;
                break;
            }
        }

        return view('website_admin.inbox.returns_create', [
            'q' => $q,
            'results' => $results,
            'searchError' => $searchError,
            'customer' => $customer,
            'orders' => $orders,
            'ordersError' => $ordersError,
            'selectedOrder' => $selectedOrder,
            'refundMethods' => self::REFUND_METHODS,
        ]);
    }

    public function store(Request $request)
    {
        $this->guard();
        $customerId = (string) $request->input('customer');
        $orderId = (string) $request->input('order');
        $reason = trim((string) $request->input('reason', ''));
        if ($customerId === '' || $orderId === '' || $reason === '') {
            return back()->withInput()->with('error', 'Pick a customer and an order, and enter a reason.');
        }

        // Items come from the order itself, re-read here rather than trusted from the form.
        $r = WebsiteBridge::admin('GET', '/returns/admin/customer/' . rawurlencode($customerId) . '/orders', null, 30);
        $order = null;
        foreach (($r['data'] ?? []) as $o) {
            if (($o['_id'] ?? '') === $orderId) {
                $order = $o;
                break;
            }
        }
        if (!$order) {
            return back()->withInput()->with('error', $r['message'] ?? 'That order could not be found for this customer.');
        }

        $items = [];
        foreach (($order['items'] ?? []) as $oi) {
            $pid = $oi['product_id'] ?? null;
            $items[] = array_filter([
                'product_id' => is_array($pid) ? ($pid['_id'] ?? null) : $pid,
                'product_name' => $oi['product_name'] ?? (is_array($pid) ? ($pid['name'] ?? null) : null),
                'quantity' => $oi['quantity'] ?? 1,
                'price' => $oi['price'] ?? 0,
            ], function ($v) { return $v !== null; });
        }

        // An order paid with store credit can only be refunded as store credit.
        $method = (string) $request->input('refund_method', 'to_original');
        if (!array_key_exists($method, self::REFUND_METHODS)) {
            $method = 'to_original';
        }
        if (($order['used_store_credit'] ?? 0) > 0) {
            $method = 'store_credit';
        }

        $resp = WebsiteBridge::admin('POST', '/returns/admin/create', [
            'channel' => 'store',
            'user_id' => $customerId,
            'order_id' => $orderId,
            'reason' => $reason,
            'items' => $items,
            'refund_method' => $method,
        ], 30);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'Failed to create return.');
        }
        return redirect()->route('wa.inbox.returns', ['status' => ''])->with('status', $resp['message'] ?? 'Return request created.');
    }
}
