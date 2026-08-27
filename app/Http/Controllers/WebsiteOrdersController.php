<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Website order cancellation, moved into the ERP (Sarah, 2026-08-27) instead
 * of the website admin's old generic "Change Status" dropdown, which had no
 * required reason. Talks to the website's ERP bridge
 * (POST/GET /api/v1/erp/orders*) the same way EventsController talks to the
 * RSVP/preorder bridge — a small self-contained curl helper here rather than
 * sharing EventsController's, since that class is live day-of event tooling
 * and not worth the risk of touching for this.
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

    public function index(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $resp = $this->websiteApi('GET', '/erp/orders?limit=100');
        $bridgeError = $resp === null || ($resp['success'] ?? true) === false;
        $orders = $bridgeError ? [] : ($resp['data'] ?? []);
        $bridgeErrorMessage = $bridgeError ? ($resp['message'] ?? 'Could not reach the website.') : null;

        return view('website-orders.index', [
            'orders' => $orders,
            'bridgeError' => $bridgeError,
            'bridgeErrorMessage' => $bridgeErrorMessage,
            'reasons' => self::REASONS,
        ]);
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
