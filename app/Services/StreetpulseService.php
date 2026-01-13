<?php

namespace App\Services;

use App\Business;
use App\Utils\BusinessUtil;
use App\Transaction;
use Illuminate\Support\Facades\Log;

class StreetpulseService
{
    private $businessUtil;
    private $businessId;
    private $settings;

    public function __construct($businessId = null)
    {
        $this->businessUtil = new BusinessUtil();
        $this->businessId = $businessId ?? request()->session()->get('user.business_id');
        $this->settings = $this->businessUtil->getApiSettings($this->businessId);
    }

    /**
     * Check if Streetpulse is configured with required credentials
     *
     * @return bool
     */
    public function isConfigured()
    {
        $streetpulse = $this->settings['streetpulse'] ?? [];
        return !empty($streetpulse['api_key']) && !empty($streetpulse['endpoint']);
    }

    /**
     * Test connection to Streetpulse API
     *
     * @return array
     */
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Streetpulse API credentials not configured. Please configure in Business Settings > Integrations.'
            ];
        }

        try {
            $streetpulse = $this->settings['streetpulse'] ?? [];
            $endpoint = rtrim($streetpulse['endpoint'], '/') . '/test';
            
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $streetpulse['api_key'],
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL Error: ' . $error);
            }

            if ($httpCode === 200 || $httpCode === 201) {
                return [
                    'success' => true,
                    'msg' => 'Connection successful!'
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
            }
        } catch (\Exception $e) {
            Log::error('Streetpulse Connection Test Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Sync sales data to Streetpulse
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function syncSales($startDate = null, $endDate = null)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Streetpulse API credentials not configured.'
            ];
        }

        try {
            $streetpulse = $this->settings['streetpulse'] ?? [];
            $endpoint = rtrim($streetpulse['endpoint'], '/') . '/sales/sync';

            // Get sales transactions for the date range
            $query = Transaction::where('business_id', $this->businessId)
                ->where('type', 'sell')
                ->where('status', 'final');

            if ($startDate) {
                $query->where('transaction_date', '>=', $startDate);
            }
            if ($endDate) {
                $query->where('transaction_date', '<=', $endDate);
            }

            $transactions = $query->with(['sell_lines', 'contact'])->get();

            $salesData = [];
            foreach ($transactions as $transaction) {
                $salesData[] = [
                    'transaction_id' => $transaction->id,
                    'invoice_no' => $transaction->invoice_no,
                    'transaction_date' => $transaction->transaction_date,
                    'customer_name' => $transaction->contact->name ?? 'Walk-in Customer',
                    'customer_id' => $transaction->contact_id,
                    'total' => $transaction->final_total,
                    'items' => $transaction->sell_lines->map(function ($line) {
                        return [
                            'product_id' => $line->product_id,
                            'product_name' => $line->product->name ?? '',
                            'quantity' => $line->quantity,
                            'unit_price' => $line->unit_price,
                            'total' => $line->quantity * $line->unit_price
                        ];
                    })->toArray()
                ];
            }

            // Send data to Streetpulse
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'sales' => $salesData,
                    'username' => $streetpulse['username'] ?? null
                ]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $streetpulse['api_key'],
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL Error: ' . $error);
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $responseData = json_decode($response, true);
                return [
                    'success' => true,
                    'msg' => 'Synced ' . count($salesData) . ' sales successfully.',
                    'data' => $responseData
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
            }
        } catch (\Exception $e) {
            Log::error('Streetpulse Sync Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Sync failed: ' . $e->getMessage()
            ];
        }
    }
}

