<?php

namespace App\Http\Controllers;

use App\Business;
use App\QuickBooksConnection;
use App\Services\QuickBooksService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuickBooksController extends Controller
{
    /**
     * HMAC-signed OAuth state so the callback does not rely on session (Intuit redirect can arrive without a logged-in session).
     */
    protected function getAppKeyForSigning()
    {
        $key = config('app.key');
        if (strpos($key, 'base64:') === 0) {
            return base64_decode(substr($key, 7));
        }

        return $key;
    }

    protected function buildQuickBooksOAuthState($businessId)
    {
        $payload = json_encode([
            'bid' => (int) $businessId,
            'exp' => time() + 600,
            'nonce' => Str::random(32),
        ]);
        $payloadB64 = base64_encode($payload);
        $sig = hash_hmac('sha256', $payloadB64, $this->getAppKeyForSigning());

        return $payloadB64 . '.' . $sig;
    }

    /**
     * @return array|null
     */
    protected function parseQuickBooksOAuthState($state)
    {
        if (empty($state) || strpos($state, '.') === false) {
            return null;
        }
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }
        list($payloadB64, $sig) = $parts;
        $expected = hash_hmac('sha256', $payloadB64, $this->getAppKeyForSigning());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(base64_decode($payloadB64), true);
        if (!is_array($payload) || empty($payload['bid']) || empty($payload['exp'])) {
            return null;
        }
        if (time() > (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    protected function quickBooksCallbackRedirect(Request $request, array $status)
    {
        if (auth()->check()) {
            return redirect()->route('business.getBusinessSettings')->with('status', $status);
        }

        return redirect()->route('login')->with('status', $status);
    }

    public function connect(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);

        if (!$qbService->isConfigured()) {
            return redirect()->route('business.getBusinessSettings')->with('status', [
                'success' => 0,
                'msg' => 'Please configure QuickBooks Client ID and Client Secret in Integrations settings first.',
            ]);
        }

        $state = $this->buildQuickBooksOAuthState($business_id);

        return redirect()->away($qbService->getAuthorizationUrl($state));
    }

    public function callback(Request $request)
    {
        $parsed = $this->parseQuickBooksOAuthState($request->input('state'));
        if (empty($parsed)) {
            return $this->quickBooksCallbackRedirect($request, [
                'success' => 0,
                'msg' => 'QuickBooks authorization failed due to invalid or expired state. Please try again.',
            ]);
        }

        $business_id = (int) $parsed['bid'];
        $code = $request->input('code');
        $realmId = $request->input('realmId');

        if (empty($code) || empty($realmId)) {
            return $this->quickBooksCallbackRedirect($request, [
                'success' => 0,
                'msg' => 'QuickBooks callback is missing required parameters.',
            ]);
        }

        $qbService = new QuickBooksService($business_id);
        $tokenResult = $qbService->exchangeAuthorizationCode($code);

        if (empty($tokenResult['success'])) {
            return $this->quickBooksCallbackRedirect($request, [
                'success' => 0,
                'msg' => !empty($tokenResult['msg']) ? $tokenResult['msg'] : 'QuickBooks connection failed.',
            ]);
        }

        $tokenData = $tokenResult['data'];
        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);
        $refreshExpiresIn = (int) ($tokenData['x_refresh_token_expires_in'] ?? 0);

        QuickBooksConnection::updateOrCreate(
            ['business_id' => $business_id],
            [
                'realm_id' => $realmId,
                'access_token' => !empty($accessToken) ? encrypt($accessToken) : null,
                'refresh_token' => !empty($refreshToken) ? encrypt($refreshToken) : null,
                'token_expires_at' => $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
                'refresh_expires_at' => $refreshExpiresIn > 0 ? Carbon::now()->addSeconds($refreshExpiresIn) : null,
                'environment' => $qbService->getEnvironment(),
                'is_active' => 1,
            ]
        );

        $business = Business::find($business_id);
        if (!empty($business)) {
            $api_settings = is_array($business->api_settings) ? $business->api_settings : [];
            if (empty($api_settings['quickbooks']) || !is_array($api_settings['quickbooks'])) {
                $api_settings['quickbooks'] = [];
            }
            $api_settings['quickbooks']['realm_id'] = $realmId;
            $api_settings['quickbooks']['connected_at'] = Carbon::now()->toDateTimeString();
            $business->api_settings = $api_settings;
            $business->save();
        }

        $qbServiceFresh = new QuickBooksService($business_id);
        $provision = $qbServiceFresh->ensureDefaultSalesItem();
        $statusMsg = 'QuickBooks connected successfully.';
        if (!empty($provision['success'])) {
            $statusMsg .= ' ' . ($provision['msg'] ?? 'Default sales item is ready.');
        } else {
            $statusMsg .= ' ' . ($provision['msg'] ?? 'Could not auto-create the default sales item; set Default Sales Item ID manually or click Test Connection to retry.');
        }

        return $this->quickBooksCallbackRedirect($request, [
            'success' => 1,
            'msg' => $statusMsg,
        ]);
    }

    public function disconnect(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');
            $connection = QuickBooksConnection::where('business_id', $business_id)->first();

            if (!empty($connection)) {
                $connection->is_active = 0;
                $connection->access_token = null;
                $connection->refresh_token = null;
                $connection->token_expires_at = null;
                $connection->refresh_expires_at = null;
                $connection->save();
            }

            return response()->json([
                'success' => true,
                'msg' => 'QuickBooks disconnected successfully.',
            ]);
        } catch (\Exception $e) {
            \Log::error('QuickBooks disconnect failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'msg' => 'Unable to disconnect QuickBooks: ' . $e->getMessage(),
            ]);
        }
    }

    public function testConnection(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $result = $qbService->testConnection();

        return response()->json($result);
    }

    public function syncSale(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'transaction_id' => 'required|integer|min:1',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $result = $qbService->syncSaleTransaction((int) $request->input('transaction_id'));

        return response()->json($result);
    }

    public function dashboard(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $connection = QuickBooksConnection::where('business_id', $business_id)->first();
        $logs = $qbService->getRecentSyncLogs(100);

        return view('quickbooks.dashboard', compact('connection', 'logs'));
    }

    /**
     * Live, read-only "Transaction List by Date" pulled straight from QBO on
     * each load — the same report the accountant views in QuickBooks, now
     * visible inside the ERP. Defaults to month-to-date; ?from_date=&to_date=
     * override.
     */
    public function transactionList(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $from = $request->input('from_date') ?: Carbon::now()->startOfMonth()->format('Y-m-d');
        $to   = $request->input('to_date')   ?: Carbon::now()->format('Y-m-d');

        $qbService = new QuickBooksService($business_id);
        $report = $qbService->getTransactionListForDisplay($from, $to);

        if (empty($report['success'])) {
            return view('quickbooks.transactions', [
                'report' => $report,
                'from_date' => $from,
                'to_date' => $to,
                'columns' => [],
                'filters' => ['type' => '', 'account' => '', 'split' => ''],
                'filter_options' => ['type' => [], 'account' => [], 'split' => []],
                'total' => null,
            ]);
        }

        $columns = $report['columns'];

        // Resolve the actual QB column titles for the filterable/hidden columns.
        $findCol = function (array $candidates) use ($columns) {
            foreach ($columns as $c) {
                foreach ($candidates as $cand) {
                    if (strcasecmp(trim($c), $cand) === 0) {
                        return $c;
                    }
                }
            }
            return null;
        };
        $typeCol    = $findCol(['Transaction Type', 'Transaction type', 'Type']);
        $accountCol = $findCol(['Account name', 'Account']);
        $splitCol   = $findCol(['Split', 'Category']);
        $amountCol  = $findCol(['Amount', 'Total']);

        // Dropdown options from the full (unfiltered) result set.
        $distinct = function ($col) use ($report) {
            if (!$col) {
                return [];
            }
            $vals = [];
            foreach ($report['rows'] as $r) {
                $v = trim($r[$col] ?? '');
                if ($v !== '') {
                    $vals[$v] = true;
                }
            }
            $vals = array_keys($vals);
            sort($vals, SORT_NATURAL | SORT_FLAG_CASE);
            return $vals;
        };
        $filterOptions = [
            'type'    => $distinct($typeCol),
            'account' => $distinct($accountCol),
            'split'   => $distinct($splitCol),
        ];

        $filters = [
            'type'    => trim((string) $request->input('f_type', '')),
            'account' => trim((string) $request->input('f_account', '')),
            'split'   => trim((string) $request->input('f_split', '')),
        ];

        $rows = array_values(array_filter($report['rows'], function ($r) use ($filters, $typeCol, $accountCol, $splitCol) {
            if ($filters['type'] !== '' && $typeCol && ($r[$typeCol] ?? '') !== $filters['type']) {
                return false;
            }
            if ($filters['account'] !== '' && $accountCol && ($r[$accountCol] ?? '') !== $filters['account']) {
                return false;
            }
            if ($filters['split'] !== '' && $splitCol && ($r[$splitCol] ?? '') !== $filters['split']) {
                return false;
            }
            return true;
        }));

        // Hide Num and Memo columns from display + export.
        $hiddenTitles = ['num', 'no.', 'memo', 'memo/description', 'description'];
        $visibleColumns = array_values(array_filter($columns, function ($c) use ($hiddenTitles) {
            return !in_array(strtolower(trim($c)), $hiddenTitles, true);
        }));

        // Recompute the total over the filtered rows.
        $total = null;
        if ($amountCol) {
            $total = 0.0;
            foreach ($rows as $r) {
                $amt = $this->parseCsvAmount($r[$amountCol] ?? '');
                if ($amt !== null) {
                    $total += $amt;
                }
            }
            $total = round($total, 2);
        }

        if (strtolower((string) $request->input('export')) === 'csv') {
            return $this->streamTransactionCsv($visibleColumns, $rows, $from, $to);
        }

        return view('quickbooks.transactions', [
            'report' => ['success' => true, 'rows' => $rows],
            'from_date' => $from,
            'to_date' => $to,
            'columns' => $visibleColumns,
            'filters' => $filters,
            'filter_options' => $filterOptions,
            'total' => $total,
        ]);
    }

    /** Parse a QB report amount cell ("(1,234.56)", "-12.00") to a float. */
    protected function parseCsvAmount($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.+)\)$/', $raw, $m)) {
            $neg = true;
            $raw = $m[1];
        }
        $raw = str_replace([',', '$', ' '], '', $raw);
        if (!is_numeric($raw)) {
            return null;
        }
        $val = (float) $raw;
        return $neg ? -$val : $val;
    }

    protected function streamTransactionCsv(array $columns, array $rows, $from, $to)
    {
        $filename = 'qb-transactions-' . $from . '-to-' . $to . '.csv';

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($rows as $r) {
                $line = [];
                foreach ($columns as $c) {
                    $line[] = $r[$c] ?? '';
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function backfill(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'from_date' => 'required|date',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $qbService = new QuickBooksService($business_id);
        $result = $qbService->backfillSalesFromDate($request->input('from_date'));

        return redirect()->action('QuickBooksController@dashboard')->with('status', [
            'success' => !empty($result['success']) ? 1 : 0,
            'msg' => !empty($result['msg']) ? $result['msg'] : 'Backfill finished.',
        ]);
    }

    // Pull expenses from QB → ERP. Default window: last 14 days (tolerates
    // late posts in QB) up to today. Pass ?from_date=&to_date= to override.
    public function syncExpenses(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $from = $request->input('from_date') ?: Carbon::now()->subDays(14)->format('Y-m-d');
        $to   = $request->input('to_date')   ?: Carbon::now()->format('Y-m-d');

        $qbService = new QuickBooksService($business_id);
        $result = $qbService->syncExpensesFromQb($from, $to);

        return redirect()->action('QuickBooksController@dashboard')->with('status', [
            'success' => !empty($result['success']) ? 1 : 0,
            'msg' => $result['msg'] ?? 'Expense sync finished.',
        ]);
    }
}

