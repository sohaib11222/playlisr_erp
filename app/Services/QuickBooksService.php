<?php

namespace App\Services;

use App\Business;
use App\Contact;
use App\QuickBooksAccountMapping;
use App\QuickBooksConnection;
use App\QuickBooksEntityMap;
use App\QuickBooksSyncLog;
use App\Transaction;
use App\Utils\BusinessUtil;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class QuickBooksService
{
    protected $businessUtil;
    protected $businessId;
    protected $settings;

    public function __construct($businessId = null)
    {
        $this->businessUtil = new BusinessUtil();
        $this->businessId = $businessId ?: request()->session()->get('user.business_id');
        $this->settings = $this->businessUtil->getApiSettings($this->businessId);
    }

    public function isConfigured()
    {
        $qb = $this->settings['quickbooks'] ?? [];

        return !empty($qb['client_id']) && !empty($qb['client_secret']);
    }

    public function getEnvironment()
    {
        $qb = $this->settings['quickbooks'] ?? [];

        return !empty($qb['environment']) ? $qb['environment'] : 'production';
    }

    protected function getQuickBooksSetting($key, $default = '')
    {
        $qb = $this->settings['quickbooks'] ?? [];
        $value = isset($qb[$key]) ? $qb[$key] : $default;

        return is_string($value) ? trim($value) : $value;
    }

    protected function getQuickBooksScope()
    {
        $rawScope = $this->getQuickBooksSetting('scope', 'com.intuit.quickbooks.accounting');
        if (empty($rawScope)) {
            return 'com.intuit.quickbooks.accounting';
        }

        // Accept comma or whitespace separated values and normalize to OAuth space-delimited scopes.
        $normalized = preg_replace('/\s+/', ' ', str_replace(',', ' ', $rawScope));

        return trim($normalized);
    }

    public function getRedirectUri()
    {
        $redirectUri = $this->getQuickBooksSetting('redirect_uri', '');
        if (!empty($redirectUri)) {
            return $redirectUri;
        }

        return action('QuickBooksController@callback');
    }

    public function getAuthorizationUrl($state)
    {
        $clientId = $this->getQuickBooksSetting('client_id', '');
        $scope = $this->getQuickBooksScope();

        if ($this->getEnvironment() === 'sandbox') {
            $base = 'https://appcenter.intuit.com/connect/oauth2';
        } else {
            $base = 'https://appcenter.intuit.com/connect/oauth2';
        }

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => $this->getRedirectUri(),
            'state' => $state,
        ]);

        return $base . '?' . $params;
    }

    public function exchangeAuthorizationCode($code)
    {
        $clientId = $this->getQuickBooksSetting('client_id', '');
        $clientSecret = $this->getQuickBooksSetting('client_secret', '');

        if (empty($clientId) || empty($clientSecret)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks credentials are not configured.',
            ];
        }

        try {
            $tokenUrl = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

            $postFields = http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->getRedirectUri(),
            ]);

            $ch = curl_init($tokenUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (!empty($error)) {
                return [
                    'success' => false,
                    'msg' => 'Token request failed: ' . $error,
                ];
            }

            $payload = json_decode($response, true);
            if ($httpCode < 200 || $httpCode >= 300) {
                return [
                    'success' => false,
                    'msg' => !empty($payload['error_description']) ? $payload['error_description'] : 'QuickBooks token exchange failed.',
                    'response' => $payload,
                ];
            }

            return [
                'success' => true,
                'data' => $payload,
            ];
        } catch (\Exception $e) {
            Log::error('QuickBooks token exchange error: ' . $e->getMessage());

            return [
                'success' => false,
                'msg' => 'QuickBooks token exchange error: ' . $e->getMessage(),
            ];
        }
    }

    public function testConnection()
    {
        $connection = $this->getActiveConnection();
        if (empty($connection) || empty($connection->realm_id)) {
            return $this->notConnectedResponse();
        }

        $result = $this->apiRequest('GET', '/companyinfo/' . $connection->realm_id);

        if (empty($result['success'])) {
            return $result;
        }

        $company = $result['data']['CompanyInfo'] ?? [];

        $out = [
            'success' => true,
            'msg' => 'QuickBooks connection is healthy.',
            'company' => [
                'id' => $company['Id'] ?? null,
                'name' => $company['CompanyName'] ?? null,
                'legal_name' => $company['LegalName'] ?? null,
                'country' => $company['Country'] ?? null,
            ],
        ];

        $qb = $this->settings['quickbooks'] ?? [];
        if (empty($qb['default_sales_item_id'])) {
            $provision = $this->ensureDefaultSalesItem();
            $out['default_sales_item_provision'] = $provision;
        }

        return $out;
    }

    /**
     * Find or create a NonInventory item in QuickBooks and persist default_sales_item_id in api_settings.
     * Called after OAuth connect so the client does not need to create an item manually.
     */
    public function ensureDefaultSalesItem()
    {
        $qb = $this->settings['quickbooks'] ?? [];
        if (!empty($qb['default_sales_item_id'])) {
            return [
                'success' => true,
                'item_id' => $qb['default_sales_item_id'],
                'msg' => 'Default sales item is already configured.',
            ];
        }

        $itemName = trim((string) ($qb['default_sales_item_name'] ?? 'Playlist ERP Sales Sync'));
        if ($itemName === '') {
            $itemName = 'Playlist ERP Sales Sync';
        }

        $existingId = $this->findQuickBooksItemIdByName($itemName);
        if (!empty($existingId)) {
            $this->persistQuickBooksApiSettings(['default_sales_item_id' => (string) $existingId]);

            return [
                'success' => true,
                'item_id' => (string) $existingId,
                'msg' => 'Linked to existing QuickBooks item: ' . $itemName,
            ];
        }

        $incomeAccountId = $this->getFirstIncomeAccountId();
        if (empty($incomeAccountId)) {
            return [
                'success' => false,
                'msg' => 'Could not find an active Income account in QuickBooks. Create one in Chart of Accounts, then try Test Connection again.',
            ];
        }

        $create = $this->apiRequest('POST', '/item', [
            'Name' => mb_substr($itemName, 0, 100),
            'Type' => 'NonInventory',
            'IncomeAccountRef' => ['value' => (string) $incomeAccountId],
        ]);

        if (empty($create['success'])) {
            return [
                'success' => false,
                'msg' => 'Could not create default sales item in QuickBooks: ' . ($create['msg'] ?? 'Unknown error'),
            ];
        }

        $item = $create['data']['Item'] ?? [];
        $newId = $item['Id'] ?? null;
        if (empty($newId)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks did not return an item ID after create.',
            ];
        }

        $this->persistQuickBooksApiSettings(['default_sales_item_id' => (string) $newId]);

        return [
            'success' => true,
            'item_id' => (string) $newId,
            'msg' => 'Created QuickBooks item "' . $itemName . '" for ERP sales sync.',
        ];
    }

    protected function persistQuickBooksApiSettings(array $merge)
    {
        $business = Business::find($this->businessId);
        if (empty($business)) {
            return false;
        }

        $api = $business->api_settings ?? [];
        if (!is_array($api)) {
            $api = [];
        }
        if (empty($api['quickbooks']) || !is_array($api['quickbooks'])) {
            $api['quickbooks'] = [];
        }
        $api['quickbooks'] = array_merge($api['quickbooks'], $merge);
        $business->api_settings = $api;
        $business->save();
        $this->settings = $this->businessUtil->getApiSettings($this->businessId);

        return true;
    }

    protected function findQuickBooksItemIdByName($name)
    {
        $safe = str_replace("'", "''", $name);
        $sql = "SELECT Id, Name FROM Item WHERE Name = '" . $safe . "' MAXRESULTS 1";
        $qr = $this->apiRequest('GET', '/query', null, ['query' => $sql]);
        if (empty($qr['success'])) {
            return null;
        }

        $items = $qr['data']['QueryResponse']['Item'] ?? null;
        if ($items === null) {
            return null;
        }

        if (isset($items['Id'])) {
            return $items['Id'];
        }
        if (is_array($items) && isset($items[0]['Id'])) {
            return $items[0]['Id'];
        }

        return null;
    }

    protected function getFirstIncomeAccountId()
    {
        $sql = "SELECT Id, Name FROM Account WHERE AccountType = 'Income' AND Active = true MAXRESULTS 10";
        $qr = $this->apiRequest('GET', '/query', null, ['query' => $sql]);
        if (empty($qr['success'])) {
            return null;
        }

        $accounts = $qr['data']['QueryResponse']['Account'] ?? null;
        if ($accounts === null) {
            return null;
        }

        if (isset($accounts['Id'])) {
            return $accounts['Id'];
        }
        if (is_array($accounts) && isset($accounts[0]['Id'])) {
            return $accounts[0]['Id'];
        }

        return null;
    }

    public function getRecentSyncLogs($limit = 50)
    {
        return QuickBooksSyncLog::where('business_id', $this->businessId)
            ->orderBy('id', 'desc')
            ->limit(max(1, (int) $limit))
            ->get();
    }

    /**
     * List bank accounts with their current balances. Used by the
     * Sales-by-Channel-adjacent /reports/cash-flow page so Sarah can see
     * "what's in each account right now" without leaving the ERP.
     *
     * Returns ['success' => true, 'accounts' => [['name', 'balance', 'type']]].
     */
    public function getBankAccounts()
    {
        $query = "SELECT Id, Name, FullyQualifiedName, AccountType, AccountSubType, CurrentBalance, Active "
               . "FROM Account WHERE AccountType IN ('Bank','Credit Card') AND Active = true MAXRESULTS 100";
        $result = $this->apiRequest('GET', '/query', null, ['query' => $query]);
        if (empty($result['success'])) {
            return $result;
        }
        $rows = $result['data']['QueryResponse']['Account'] ?? [];
        if (isset($rows['Id'])) { $rows = [$rows]; } // QB returns single object when only 1 row.
        $accounts = [];
        foreach ($rows as $r) {
            $accounts[] = [
                'name'    => $r['Name'] ?? '',
                'type'    => $r['AccountType'] ?? '',
                'subtype' => $r['AccountSubType'] ?? '',
                'balance' => (float)($r['CurrentBalance'] ?? 0),
            ];
        }
        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * Pull QuickBooks's standard Cash Flow report for a date range.
     * Returns the raw report payload — caller flattens for display.
     *
     * QB's /reports/CashFlow returns a tree of sections (Operating,
     * Investing, Financing) with nested rows + a summary "Net cash
     * increase" line. We hand the structured payload back so the view
     * can walk it however it wants.
     */
    public function getCashFlowReport($startDate, $endDate)
    {
        $params = [
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'accounting_method' => 'Accrual',
        ];
        $result = $this->apiRequest('GET', '/reports/CashFlow', null, $params);
        if (empty($result['success'])) {
            return $result;
        }
        return [
            'success' => true,
            'report'  => $result['data'] ?? [],
        ];
    }

    public function backfillSalesFromDate($fromDate)
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $transactions = Transaction::where('business_id', $this->businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->orderBy('transaction_date', 'asc')
            ->get(['id']);

        $summary = [
            'total' => $transactions->count(),
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($transactions as $txn) {
            $result = $this->syncSaleTransaction((int) $txn->id);
            if (!empty($result['success'])) {
                $summary['success']++;
            } else {
                $summary['failed']++;
                $summary['details'][] = [
                    'transaction_id' => $txn->id,
                    'msg' => $result['msg'] ?? 'Unknown sync error',
                ];
            }
        }

        return [
            'success' => true,
            'msg' => 'Backfill completed.',
            'summary' => $summary,
        ];
    }

    public function syncSaleTransaction($transactionId)
    {
        $transaction = Transaction::with([
            'contact',
            'sell_lines.product',
            'sell_lines.sell_line_purchase_lines.purchase_line',
            'payment_lines',
        ])
            ->where('business_id', $this->businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->find($transactionId);

        if (empty($transaction)) {
            return [
                'success' => false,
                'msg' => 'Final sell transaction not found for QuickBooks sync.',
            ];
        }

        $settings = $this->settings['quickbooks'] ?? [];
        $defaultItemId = $settings['default_sales_item_id'] ?? '';
        if (empty($defaultItemId)) {
            return [
                'success' => false,
                'msg' => 'Please set QuickBooks Default Sales Item ID in Integrations before syncing sales.',
            ];
        }

        $customerResult = $this->getOrCreateQuickBooksCustomer($transaction->contact);
        if (empty($customerResult['success'])) {
            $this->createSyncLog($transaction, 'outbound', 'invoice_create', 'failed', null, $customerResult['msg']);

            return $customerResult;
        }

        $lineItems = [];
        foreach ($transaction->sell_lines as $line) {
            $qty = (float) ($line->quantity ?? 0) - (float) ($line->quantity_returned ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitPrice = (float) ($line->unit_price_inc_tax ?? $line->unit_price ?? 0);
            $amount = round($qty * $unitPrice, 2);
            $description = !empty($line->product) ? $line->product->name : 'ERP Sale Line';

            $lineItems[] = [
                'Amount' => $amount,
                'Description' => mb_substr($description, 0, 4000),
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => (string) $defaultItemId],
                    'Qty' => $qty,
                    'UnitPrice' => $unitPrice,
                ],
            ];
        }

        if (empty($lineItems)) {
            $lineItems[] = [
                'Amount' => (float) $transaction->final_total,
                'Description' => 'ERP Invoice #' . ($transaction->invoice_no ?: $transaction->id),
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'ItemRef' => ['value' => (string) $defaultItemId],
                    'Qty' => 1,
                    'UnitPrice' => (float) $transaction->final_total,
                ],
            ];
        }

        $docNumber = !empty($transaction->invoice_no) ? $transaction->invoice_no : ('ERP-' . $transaction->id);
        $commonPayload = [
            'DocNumber' => !empty($transaction->invoice_no) ? $transaction->invoice_no : ('ERP-' . $transaction->id),
            'TxnDate' => Carbon::parse($transaction->transaction_date)->toDateString(),
            'CustomerRef' => ['value' => $customerResult['qbo_customer_id']],
            'PrivateNote' => 'Synced from ERP transaction ID ' . $transaction->id,
            'Line' => $lineItems,
        ];

        $totalPaid = (float) $transaction->payment_lines->sum('amount');
        $finalTotal = (float) ($transaction->final_total ?? 0);
        $isFullyPaid = strtolower((string) $transaction->payment_status) === 'paid' || $totalPaid >= ($finalTotal - 0.01);

        $documentType = $isFullyPaid ? 'sales_receipt' : 'invoice';
        $warnings = [];

        if ($documentType === 'sales_receipt') {
            $receiptSync = $this->syncSalesReceiptDocument($transaction, $commonPayload, $docNumber);
            if (empty($receiptSync['success'])) {
                return $receiptSync;
            }
        } else {
            $invoiceSync = $this->syncInvoiceDocument($transaction, $commonPayload, $docNumber);
            if (empty($invoiceSync['success'])) {
                return $invoiceSync;
            }

            if ($totalPaid > 0) {
                $paymentSync = $this->syncInvoicePayments($transaction, $invoiceSync['qbo_invoice_id'] ?? null, $customerResult['qbo_customer_id']);
                if (empty($paymentSync['success'])) {
                    return $paymentSync;
                }
            }
        }

        $cogsSync = $this->syncSaleCogsJournal($transaction);
        if (empty($cogsSync['success'])) {
            $warnings[] = $cogsSync['msg'];
        }

        return [
            'success' => true,
            'msg' => 'Sale synced to QuickBooks successfully.',
            'document_type' => $documentType,
            'warnings' => $warnings,
        ];
    }

    protected function syncSalesReceiptDocument(Transaction $transaction, array $payload, $docNumber)
    {
        $existingMap = $this->getEntityMap('sell_sales_receipt', $transaction->id);
        if (!empty($existingMap)) {
            return [
                'success' => true,
                'qbo_sales_receipt_id' => $existingMap->qbo_id,
                'msg' => 'Sales receipt already synced.',
            ];
        }

        $idempotencyKey = $this->makeIdempotencyKey('sales_receipt', $transaction->id);
        $result = $this->apiRequest('POST', '/salesreceipt', $payload);
        if (empty($result['success'])) {
            $this->createSyncLog(
                $transaction,
                'outbound',
                'sales_receipt_create',
                'failed',
                $payload,
                $result['msg'],
                $result['response'] ?? null,
                $result['intuit_tid'] ?? null,
                $idempotencyKey,
                (int) ($result['attempts'] ?? 1)
            );

            return [
                'success' => false,
                'msg' => 'QuickBooks sales receipt sync failed: ' . $result['msg'],
            ];
        }

        $receipt = $result['data']['SalesReceipt'] ?? [];
        if (!empty($receipt['Id'])) {
            $this->upsertEntityMap('sell_sales_receipt', $transaction->id, $receipt['Id'], $receipt['SyncToken'] ?? null);
        }

        $this->createSyncLog(
            $transaction,
            'outbound',
            'sales_receipt_create',
            'success',
            $payload,
            null,
            $result['data'] ?? null,
            $result['intuit_tid'] ?? null,
            $idempotencyKey,
            (int) ($result['attempts'] ?? 1)
        );

        return [
            'success' => true,
            'qbo_sales_receipt_id' => $receipt['Id'] ?? null,
            'qbo_doc_number' => $receipt['DocNumber'] ?? $docNumber,
        ];
    }

    protected function syncInvoiceDocument(Transaction $transaction, array $payload, $docNumber)
    {
        $existingMap = $this->getEntityMap('sell_invoice', $transaction->id);
        if (!empty($existingMap)) {
            return [
                'success' => true,
                'qbo_invoice_id' => $existingMap->qbo_id,
                'msg' => 'Invoice already synced.',
            ];
        }

        $idempotencyKey = $this->makeIdempotencyKey('invoice', $transaction->id);
        $invoiceResult = $this->apiRequest('POST', '/invoice', $payload);

        if (empty($invoiceResult['success'])) {
            $this->createSyncLog(
                $transaction,
                'outbound',
                'invoice_create',
                'failed',
                $payload,
                $invoiceResult['msg'],
                $invoiceResult['response'] ?? null,
                $invoiceResult['intuit_tid'] ?? null,
                $idempotencyKey,
                (int) ($invoiceResult['attempts'] ?? 1)
            );

            return [
                'success' => false,
                'msg' => 'QuickBooks invoice sync failed: ' . $invoiceResult['msg'],
            ];
        }

        $invoice = $invoiceResult['data']['Invoice'] ?? [];
        if (!empty($invoice['Id'])) {
            $this->upsertEntityMap('sell_invoice', $transaction->id, $invoice['Id'], $invoice['SyncToken'] ?? null);
        }

        $this->createSyncLog(
            $transaction,
            'outbound',
            'invoice_create',
            'success',
            $payload,
            null,
            $invoiceResult['data'] ?? null,
            $invoiceResult['intuit_tid'] ?? null,
            $idempotencyKey,
            (int) ($invoiceResult['attempts'] ?? 1)
        );

        return [
            'success' => true,
            'qbo_invoice_id' => $invoice['Id'] ?? null,
            'qbo_doc_number' => $invoice['DocNumber'] ?? $docNumber,
        ];
    }

    protected function syncInvoicePayments(Transaction $transaction, $qboInvoiceId, $qboCustomerId)
    {
        if (empty($qboInvoiceId)) {
            return [
                'success' => false,
                'msg' => 'Cannot sync payments: QBO invoice ID is missing.',
            ];
        }

        $payments = $transaction->payment_lines
            ->filter(function ($payment) {
                return (float) ($payment->amount ?? 0) > 0;
            })
            ->values();

        foreach ($payments as $payment) {
            $paymentMap = $this->getEntityMap('sell_payment', $payment->id);
            if (!empty($paymentMap)) {
                continue;
            }

            $payload = [
                'TxnDate' => Carbon::parse($payment->paid_on ?? $transaction->transaction_date)->toDateString(),
                'TotalAmt' => round((float) $payment->amount, 2),
                'CustomerRef' => ['value' => $qboCustomerId],
                'Line' => [
                    [
                        'Amount' => round((float) $payment->amount, 2),
                        'LinkedTxn' => [
                            ['TxnId' => (string) $qboInvoiceId, 'TxnType' => 'Invoice'],
                        ],
                    ],
                ],
                'PrivateNote' => 'Synced from ERP payment ID ' . $payment->id . ' for ERP sale #' . $transaction->id,
            ];

            if (empty($payload['CustomerRef']['value'])) {
                return [
                    'success' => false,
                    'msg' => 'Cannot sync payment: customer mapping is missing.',
                ];
            }

            $depositAccountId = $this->resolveDepositAccountId($payment->method ?? null);
            if (!empty($depositAccountId)) {
                $payload['DepositToAccountRef'] = ['value' => (string) $depositAccountId];
            }

            $idempotencyKey = $this->makeIdempotencyKey('payment', $payment->id);
            $result = $this->apiRequest('POST', '/payment', $payload);
            if (empty($result['success'])) {
                $this->createSyncLog(
                    $transaction,
                    'outbound',
                    'payment_create',
                    'failed',
                    $payload,
                    $result['msg'],
                    $result['response'] ?? null,
                    $result['intuit_tid'] ?? null,
                    $idempotencyKey,
                    (int) ($result['attempts'] ?? 1)
                );

                return [
                    'success' => false,
                    'msg' => 'QuickBooks payment sync failed: ' . $result['msg'],
                ];
            }

            $qboPayment = $result['data']['Payment'] ?? [];
            if (!empty($qboPayment['Id'])) {
                $this->upsertEntityMap('sell_payment', $payment->id, $qboPayment['Id'], $qboPayment['SyncToken'] ?? null);
            }

            $this->createSyncLog(
                $transaction,
                'outbound',
                'payment_create',
                'success',
                $payload,
                null,
                $result['data'] ?? null,
                $result['intuit_tid'] ?? null,
                $idempotencyKey,
                (int) ($result['attempts'] ?? 1)
            );
        }

        return ['success' => true];
    }

    protected function syncSaleCogsJournal(Transaction $transaction)
    {
        $existingMap = $this->getEntityMap('sell_cogs_journal', $transaction->id);
        if (!empty($existingMap)) {
            return ['success' => true, 'msg' => 'COGS journal already synced.'];
        }

        $cogsAmount = $this->calculateSaleCogsAmount($transaction);
        if ($cogsAmount <= 0) {
            return ['success' => true, 'msg' => 'COGS journal skipped: no COGS amount found for sale.'];
        }

        $settings = $this->settings['quickbooks'] ?? [];
        $cogsAccountId = $settings['cogs_account_id'] ?? '';
        $inventoryAssetAccountId = $settings['inventory_asset_account_id'] ?? '';

        if (empty($cogsAccountId) || empty($inventoryAssetAccountId)) {
            return ['success' => false, 'msg' => 'COGS sync skipped: configure COGS and Inventory Asset account IDs in QuickBooks settings.'];
        }

        $payload = [
            'TxnDate' => Carbon::parse($transaction->transaction_date)->toDateString(),
            'PrivateNote' => 'ERP COGS journal for sale #' . $transaction->id,
            'Line' => [
                [
                    'Description' => 'COGS for ERP sale #' . $transaction->id,
                    'Amount' => $cogsAmount,
                    'DetailType' => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Debit',
                        'AccountRef' => ['value' => (string) $cogsAccountId],
                    ],
                ],
                [
                    'Description' => 'Inventory reduction for ERP sale #' . $transaction->id,
                    'Amount' => $cogsAmount,
                    'DetailType' => 'JournalEntryLineDetail',
                    'JournalEntryLineDetail' => [
                        'PostingType' => 'Credit',
                        'AccountRef' => ['value' => (string) $inventoryAssetAccountId],
                    ],
                ],
            ],
        ];

        $idempotencyKey = $this->makeIdempotencyKey('cogs', $transaction->id);
        $result = $this->apiRequest('POST', '/journalentry', $payload);
        if (empty($result['success'])) {
            $this->createSyncLog(
                $transaction,
                'outbound',
                'cogs_journal_create',
                'failed',
                $payload,
                $result['msg'],
                $result['response'] ?? null,
                $result['intuit_tid'] ?? null,
                $idempotencyKey,
                (int) ($result['attempts'] ?? 1)
            );

            return [
                'success' => false,
                'msg' => 'QuickBooks COGS journal sync failed: ' . $result['msg'],
            ];
        }

        $journal = $result['data']['JournalEntry'] ?? [];
        if (!empty($journal['Id'])) {
            $this->upsertEntityMap('sell_cogs_journal', $transaction->id, $journal['Id'], $journal['SyncToken'] ?? null);
        }

        $this->createSyncLog(
            $transaction,
            'outbound',
            'cogs_journal_create',
            'success',
            $payload,
            null,
            $result['data'] ?? null,
            $result['intuit_tid'] ?? null,
            $idempotencyKey,
            (int) ($result['attempts'] ?? 1)
        );

        return ['success' => true];
    }

    protected function getOrCreateQuickBooksCustomer($contact)
    {
        if (empty($contact) || !($contact instanceof Contact)) {
            return [
                'success' => false,
                'msg' => 'Customer is missing on this sale.',
            ];
        }

        $existingMap = QuickBooksEntityMap::where('business_id', $this->businessId)
            ->where('entity_type', 'contact_customer')
            ->where('erp_id', $contact->id)
            ->first();

        if (!empty($existingMap)) {
            return [
                'success' => true,
                'qbo_customer_id' => $existingMap->qbo_id,
            ];
        }

        $displayName = trim($contact->name ?: ($contact->supplier_business_name ?: ('Customer #' . $contact->id)));
        $payload = [
            'DisplayName' => mb_substr($displayName, 0, 100),
            'GivenName' => mb_substr($contact->first_name ?: $displayName, 0, 25),
            'FamilyName' => mb_substr($contact->last_name ?: '', 0, 25),
        ];

        if (!empty($contact->email)) {
            $payload['PrimaryEmailAddr'] = ['Address' => $contact->email];
        }
        if (!empty($contact->mobile)) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => mb_substr($contact->mobile, 0, 21)];
        }

        $createResult = $this->apiRequest('POST', '/customer', $payload);

        if (empty($createResult['success'])) {
            return [
                'success' => false,
                'msg' => 'Customer sync failed: ' . $createResult['msg'],
            ];
        }

        $customer = $createResult['data']['Customer'] ?? [];
        $qboCustomerId = $customer['Id'] ?? null;
        if (empty($qboCustomerId)) {
            return [
                'success' => false,
                'msg' => 'Customer sync failed: missing QBO customer ID.',
            ];
        }

        QuickBooksEntityMap::updateOrCreate(
            [
                'business_id' => $this->businessId,
                'entity_type' => 'contact_customer',
                'erp_id' => $contact->id,
            ],
            [
                'qbo_id' => $qboCustomerId,
                'qbo_sync_token' => $customer['SyncToken'] ?? null,
                'last_synced_at' => Carbon::now(),
            ]
        );

        return [
            'success' => true,
            'qbo_customer_id' => $qboCustomerId,
        ];
    }

    protected function apiRequest($method, $endpoint, $payload = null, $queryParams = [])
    {
        $intuitTid = null;
        $connection = $this->getActiveConnection();
        if (empty($connection)) {
            return $this->notConnectedResponse();
        }

        $tokenResult = $this->getValidAccessToken($connection);
        if (empty($tokenResult['success'])) {
            return $tokenResult;
        }

        $accessToken = $tokenResult['access_token'];
        $realmId = $connection->realm_id;
        if (empty($realmId)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks realm ID is missing.',
            ];
        }

        $baseApi = $this->getEnvironment() === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';

        $url = $baseApi . '/v3/company/' . $realmId . $endpoint;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $attempts = 0;
        $maxAttempts = 5;
        $retryableHttp = [429, 500, 502, 503, 504];
        $backoffSeconds = [0, 1, 3, 10, 30];
        $response = null;
        $httpCode = 0;
        $curlError = null;
        do {
            $attempts++;
            $ch = curl_init($url);
            $headerFn = function ($curl, $headerLine) use (&$intuitTid) {
                $line = trim($headerLine);
                if (stripos($line, 'Intuit-Tid:') === 0) {
                    $intuitTid = trim(substr($line, strlen('Intuit-Tid:')));
                } elseif (stripos($line, 'intuit_tid:') === 0) {
                    $intuitTid = trim(substr($line, strlen('intuit_tid:')));
                }

                return strlen($headerLine);
            };

            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HEADERFUNCTION => $headerFn,
            ];
            if (!empty($payload)) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
            }
            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                if ($attempts < $maxAttempts) {
                    sleep($backoffSeconds[min($attempts, count($backoffSeconds) - 1)]);
                    continue;
                }
                break;
            }

            if (in_array($httpCode, $retryableHttp, true) && $attempts < $maxAttempts) {
                sleep($backoffSeconds[min($attempts, count($backoffSeconds) - 1)]);
                continue;
            }
            break;
        } while ($attempts < $maxAttempts);

        if (!empty($curlError)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks API network error: ' . $curlError,
                'intuit_tid' => $intuitTid,
                'attempts' => $attempts,
            ];
        }

        $parsed = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $tidSuffix = !empty($intuitTid) ? ' (intuit_tid=' . $intuitTid . ')' : '';
            return [
                'success' => false,
                'msg' => $this->extractQuickBooksError($parsed, $httpCode) . $tidSuffix,
                'response' => $parsed,
                'intuit_tid' => $intuitTid,
                'attempts' => $attempts,
            ];
        }

        return [
            'success' => true,
            'data' => $parsed,
            'intuit_tid' => $intuitTid,
            'attempts' => $attempts,
        ];
    }

    protected function getActiveConnection()
    {
        return QuickBooksConnection::where('business_id', $this->businessId)
            ->where('is_active', 1)
            ->first();
    }

    protected function notConnectedResponse()
    {
        $settingsUrl = action('BusinessController@getBusinessSettings');

        return [
            'success' => false,
            'msg' => 'QuickBooks is not connected for this business.',
            'details' => [
                'business_id' => $this->businessId,
                'action_required' => 'Go to Business Settings > Integrations and reconnect QuickBooks.',
                'settings_url' => $settingsUrl,
                'checks' => [
                    'Ensure callback URL in Intuit app exactly matches your ERP callback URL.',
                    'Confirm this user is logged into the same ERP business that connected QuickBooks.',
                    'After reconnect, click Test Connection and retry sync.',
                ],
            ],
        ];
    }

    protected function getValidAccessToken($connection)
    {
        try {
            if (!empty($connection->access_token) && !empty($connection->token_expires_at)) {
                if (Carbon::now()->lt(Carbon::parse($connection->token_expires_at)->subMinutes(2))) {
                    return [
                        'success' => true,
                        'access_token' => decrypt($connection->access_token),
                    ];
                }
            }

            if (empty($connection->refresh_token)) {
                return [
                    'success' => false,
                    'msg' => 'QuickBooks refresh token is missing. Please reconnect.',
                ];
            }

            $refreshToken = decrypt($connection->refresh_token);
            $refreshResult = $this->refreshAccessToken($refreshToken);
            if (empty($refreshResult['success'])) {
                return $refreshResult;
            }

            $data = $refreshResult['data'];
            $connection->access_token = !empty($data['access_token']) ? encrypt($data['access_token']) : $connection->access_token;
            $connection->refresh_token = !empty($data['refresh_token']) ? encrypt($data['refresh_token']) : $connection->refresh_token;
            $connection->token_expires_at = !empty($data['expires_in']) ? Carbon::now()->addSeconds((int) $data['expires_in']) : $connection->token_expires_at;
            $connection->refresh_expires_at = !empty($data['x_refresh_token_expires_in']) ? Carbon::now()->addSeconds((int) $data['x_refresh_token_expires_in']) : $connection->refresh_expires_at;
            $connection->save();

            return [
                'success' => true,
                'access_token' => $data['access_token'],
            ];
        } catch (\Exception $e) {
            Log::error('QuickBooks token validation failed: ' . $e->getMessage());

            return [
                'success' => false,
                'msg' => 'QuickBooks token validation failed: ' . $e->getMessage(),
            ];
        }
    }

    protected function refreshAccessToken($refreshToken)
    {
        $clientId = $this->getQuickBooksSetting('client_id', '');
        $clientSecret = $this->getQuickBooksSetting('client_secret', '');

        if (empty($clientId) || empty($clientSecret)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks credentials are missing for token refresh.',
            ];
        }

        $tokenUrl = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        $postFields = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!empty($error)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks refresh failed: ' . $error,
            ];
        }

        $payload = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'msg' => !empty($payload['error_description']) ? $payload['error_description'] : 'QuickBooks refresh token request failed.',
                'response' => $payload,
            ];
        }

        return [
            'success' => true,
            'data' => $payload,
        ];
    }

    protected function createSyncLog($transaction, $direction, $operation, $status, $requestPayload = null, $errorMessage = null, $responsePayload = null, $intuitTid = null, $idempotencyKey = null, $attempts = 1)
    {
        try {
            $errorMessageWithTid = $errorMessage;
            if (!empty($intuitTid) && empty($errorMessageWithTid)) {
                $errorMessageWithTid = 'QuickBooks request failed (intuit_tid=' . $intuitTid . ').';
            } elseif (!empty($intuitTid) && !empty($errorMessageWithTid) && stripos($errorMessageWithTid, 'intuit_tid=') === false) {
                $errorMessageWithTid .= ' (intuit_tid=' . $intuitTid . ')';
            }

            QuickBooksSyncLog::create([
                'business_id' => $this->businessId,
                'erp_entity_type' => 'sell',
                'erp_entity_id' => $transaction->id,
                'direction' => $direction,
                'operation' => $operation,
                'status' => $status,
                'request_payload' => !empty($requestPayload) ? json_encode($requestPayload) : null,
                'response_payload' => !empty($responsePayload) ? json_encode($responsePayload) : null,
                'error_message' => $errorMessageWithTid,
                'idempotency_key' => $idempotencyKey,
                'attempts' => max(1, (int) $attempts),
                'processed_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            Log::error('QuickBooks sync log write failed: ' . $e->getMessage());
        }
    }

    protected function extractQuickBooksError($payload, $httpCode)
    {
        if (!empty($payload['Fault']['Error'][0]['Message'])) {
            $msg = $payload['Fault']['Error'][0]['Message'];
            if (!empty($payload['Fault']['Error'][0]['Detail'])) {
                $msg .= ' - ' . $payload['Fault']['Error'][0]['Detail'];
            }

            return 'QBO API error (' . $httpCode . '): ' . $msg;
        }

        return 'QBO API error (' . $httpCode . ').';
    }

    protected function getEntityMap($entityType, $erpId)
    {
        return QuickBooksEntityMap::where('business_id', $this->businessId)
            ->where('entity_type', $entityType)
            ->where('erp_id', $erpId)
            ->first();
    }

    protected function upsertEntityMap($entityType, $erpId, $qboId, $syncToken = null)
    {
        QuickBooksEntityMap::updateOrCreate(
            [
                'business_id' => $this->businessId,
                'entity_type' => $entityType,
                'erp_id' => $erpId,
            ],
            [
                'qbo_id' => (string) $qboId,
                'qbo_sync_token' => $syncToken,
                'last_synced_at' => Carbon::now(),
            ]
        );
    }

    protected function makeIdempotencyKey($operation, $erpId, $version = 1)
    {
        return implode(':', [$operation, $this->businessId, $erpId, $version]);
    }

    protected function resolveDepositAccountId($paymentMethod)
    {
        if (empty($paymentMethod)) {
            return null;
        }

        $mapping = QuickBooksAccountMapping::where('business_id', $this->businessId)
            ->where('payment_method', $paymentMethod)
            ->first();
        if (!empty($mapping) && !empty($mapping->qbo_account_id)) {
            return $mapping->qbo_account_id;
        }

        $settings = $this->settings['quickbooks'] ?? [];
        return $settings['default_deposit_account_id'] ?? null;
    }

    protected function calculateSaleCogsAmount(Transaction $transaction)
    {
        $total = 0.0;
        foreach ($transaction->sell_lines as $line) {
            foreach ($line->sell_line_purchase_lines as $mapLine) {
                $mappedQty = (float) ($mapLine->quantity ?? 0) - (float) ($mapLine->qty_returned ?? 0);
                if ($mappedQty <= 0) {
                    continue;
                }

                $purchasePrice = (float) ($mapLine->purchase_line->purchase_price_inc_tax ?? $mapLine->purchase_line->purchase_price ?? 0);
                $total += $mappedQty * $purchasePrice;
            }
        }

        return round($total, 2);
    }
}

