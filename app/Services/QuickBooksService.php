<?php

namespace App\Services;

use App\Contact;
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

    public function getRedirectUri()
    {
        $qb = $this->settings['quickbooks'] ?? [];

        if (!empty($qb['redirect_uri'])) {
            return $qb['redirect_uri'];
        }

        return action('QuickBooksController@callback');
    }

    public function getAuthorizationUrl($state)
    {
        $qb = $this->settings['quickbooks'] ?? [];

        $clientId = $qb['client_id'] ?? '';
        $scope = $qb['scope'] ?? 'com.intuit.quickbooks.accounting';

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
        $qb = $this->settings['quickbooks'] ?? [];
        $clientId = $qb['client_id'] ?? '';
        $clientSecret = $qb['client_secret'] ?? '';

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
            return [
                'success' => false,
                'msg' => 'QuickBooks is not connected.',
            ];
        }

        $result = $this->apiRequest('GET', '/companyinfo/' . $connection->realm_id);

        if (empty($result['success'])) {
            return $result;
        }

        $company = $result['data']['CompanyInfo'] ?? [];

        return [
            'success' => true,
            'msg' => 'QuickBooks connection is healthy.',
            'company' => [
                'id' => $company['Id'] ?? null,
                'name' => $company['CompanyName'] ?? null,
                'legal_name' => $company['LegalName'] ?? null,
                'country' => $company['Country'] ?? null,
            ],
        ];
    }

    public function syncSaleTransaction($transactionId)
    {
        $transaction = Transaction::with(['contact', 'sell_lines.product'])
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

        $payload = [
            'DocNumber' => !empty($transaction->invoice_no) ? $transaction->invoice_no : ('ERP-' . $transaction->id),
            'TxnDate' => Carbon::parse($transaction->transaction_date)->toDateString(),
            'CustomerRef' => ['value' => $customerResult['qbo_customer_id']],
            'PrivateNote' => 'Synced from ERP transaction ID ' . $transaction->id,
            'Line' => $lineItems,
        ];

        $invoiceResult = $this->apiRequest('POST', '/invoice', $payload);

        if (empty($invoiceResult['success'])) {
            $this->createSyncLog($transaction, 'outbound', 'invoice_create', 'failed', $payload, $invoiceResult['msg']);

            return [
                'success' => false,
                'msg' => 'QuickBooks invoice sync failed: ' . $invoiceResult['msg'],
            ];
        }

        $invoice = $invoiceResult['data']['Invoice'] ?? [];
        if (!empty($invoice['Id'])) {
            QuickBooksEntityMap::updateOrCreate(
                [
                    'business_id' => $this->businessId,
                    'entity_type' => 'sell_invoice',
                    'erp_id' => $transaction->id,
                ],
                [
                    'qbo_id' => $invoice['Id'],
                    'qbo_sync_token' => $invoice['SyncToken'] ?? null,
                    'last_synced_at' => Carbon::now(),
                ]
            );
        }

        $this->createSyncLog($transaction, 'outbound', 'invoice_create', 'success', $payload, null);

        return [
            'success' => true,
            'msg' => 'Sale synced to QuickBooks successfully.',
            'qbo_invoice_id' => $invoice['Id'] ?? null,
            'qbo_doc_number' => $invoice['DocNumber'] ?? null,
        ];
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
        $connection = $this->getActiveConnection();
        if (empty($connection)) {
            return [
                'success' => false,
                'msg' => 'QuickBooks is not connected.',
            ];
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

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
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
            return [
                'success' => false,
                'msg' => 'QuickBooks API network error: ' . $curlError,
            ];
        }

        $parsed = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'msg' => $this->extractQuickBooksError($parsed, $httpCode),
                'response' => $parsed,
            ];
        }

        return [
            'success' => true,
            'data' => $parsed,
        ];
    }

    protected function getActiveConnection()
    {
        return QuickBooksConnection::where('business_id', $this->businessId)
            ->where('is_active', 1)
            ->first();
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
        $qb = $this->settings['quickbooks'] ?? [];
        $clientId = $qb['client_id'] ?? '';
        $clientSecret = $qb['client_secret'] ?? '';

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

    protected function createSyncLog($transaction, $direction, $operation, $status, $requestPayload = null, $errorMessage = null)
    {
        try {
            QuickBooksSyncLog::create([
                'business_id' => $this->businessId,
                'erp_entity_type' => 'sell',
                'erp_entity_id' => $transaction->id,
                'direction' => $direction,
                'operation' => $operation,
                'status' => $status,
                'request_payload' => !empty($requestPayload) ? json_encode($requestPayload) : null,
                'error_message' => $errorMessage,
                'attempts' => 1,
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
}

