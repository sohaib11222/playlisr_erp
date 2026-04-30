<?php

namespace App\Http\Controllers;

use App\DiscogsOrder;
use App\Services\DiscogsService;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin UI for pulling Discogs marketplace orders into the ERP.
 *
 * Stores results in `discogs_orders` (not the POS transactions table)
 * so a bad sync can never disrupt the live register. Sync is idempotent:
 * upsert on (business_id, discogs_order_id) — re-running the same window
 * updates rows in place, never duplicates.
 *
 * Surfaced at /admin/discogs-order-sync.
 */
class DiscogsOrderSyncController extends Controller
{
    /** @var DiscogsService */
    protected $discogs;

    /** @var BusinessUtil */
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $this->guardAdmin();

        $business_id = $request->session()->get('user.business_id');

        // Show the most recently synced 25 orders so Sarah can spot-check
        // the import.
        $recent = DiscogsOrder::where('business_id', $business_id)
            ->orderByDesc('order_date')
            ->limit(25)
            ->get();

        $totals = DiscogsOrder::where('business_id', $business_id)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as revenue,
                MIN(order_date) as first_at, MAX(order_date) as last_at')
            ->first();

        return view('admin.discogs_order_sync', compact('recent', 'totals'));
    }

    public function sync(Request $request)
    {
        $this->guardAdmin();

        $business_id = $request->session()->get('user.business_id');

        $start_date = $request->input('start_date');
        $end_date   = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            return back()->with('status', ['type' => 'error', 'msg' => 'Start and end date required.']);
        }

        $this->discogs = new DiscogsService($business_id);
        if (!$this->discogs->isConfigured()) {
            return back()->with('status', [
                'type' => 'error',
                'msg' => 'Discogs API token not configured. Add it under Business Settings → Integrations.',
            ]);
        }

        // Discogs's `created_after` / `created_before` use ISO-8601.
        $created_after = $start_date . 'T00:00:00Z';
        $created_before = $end_date . 'T23:59:59Z';

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Page through results — Discogs caps per_page at 100.
        $page = 1;
        $max_pages = 50; // safety stop (5000 orders)
        do {
            $resp = $this->discogs->fetchOrders($created_after, $created_before, $page, 100);
            if (!empty($resp['error'])) {
                $errors[] = 'Page ' . $page . ': ' . $resp['error'];
                break;
            }
            $orders = $resp['orders'] ?? [];
            if (empty($orders)) {
                break;
            }

            foreach ($orders as $o) {
                try {
                    $order_id = (string)($o['id'] ?? '');
                    if ($order_id === '') {
                        $skipped++;
                        continue;
                    }
                    $total = isset($o['total']['value']) ? (float)$o['total']['value'] : 0.0;
                    $currency = $o['total']['currency'] ?? 'USD';
                    $status = $o['status'] ?? null;
                    $created = $o['created'] ?? null;
                    $items_count = is_array($o['items'] ?? null) ? count($o['items']) : 0;
                    $buyer = $o['buyer']['username'] ?? null;

                    $existed = DiscogsOrder::where('business_id', $business_id)
                        ->where('discogs_order_id', $order_id)
                        ->first();

                    DiscogsOrder::updateOrCreate(
                        ['business_id' => $business_id, 'discogs_order_id' => $order_id],
                        [
                            'order_date' => $created ? date('Y-m-d H:i:s', strtotime($created)) : now(),
                            'status' => $status,
                            'total' => $total,
                            'currency' => $currency,
                            'items_count' => $items_count,
                            'buyer' => $buyer,
                            'raw_payload' => $o,
                        ]
                    );

                    if ($existed) {
                        $updated++;
                    } else {
                        $imported++;
                    }
                } catch (\Exception $e) {
                    $skipped++;
                    Log::warning('DiscogsOrderSync: row failed', [
                        'order_id' => $o['id'] ?? null,
                        'err' => $e->getMessage(),
                    ]);
                }
            }

            $pagination = $resp['pagination'] ?? [];
            $has_more = !empty($pagination['urls']['next']);
            $page++;
        } while ($has_more && $page <= $max_pages);

        $msg = "Discogs sync complete: imported {$imported}, updated {$updated}, skipped {$skipped}.";
        if (!empty($errors)) {
            $msg .= ' Errors: ' . implode(' | ', array_slice($errors, 0, 3));
        }
        return back()->with('status', [
            'type' => empty($errors) ? 'success' : 'warning',
            'msg' => $msg,
        ]);
    }

    protected function guardAdmin()
    {
        $user = auth()->user();
        if (!$user || !$this->businessUtil->is_admin($user)) {
            abort(403, 'Admins only.');
        }
    }
}
