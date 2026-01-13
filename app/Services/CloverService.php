<?php

namespace App\Services;

use App\Business;
use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\Log;

class CloverService
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
     * Check if Clover is configured with required credentials
     *
     * @return bool
     */
    public function isConfigured()
    {
        $clover = $this->settings['clover'] ?? [];
        return !empty($clover['app_id']) && 
               !empty($clover['app_secret']) && 
               !empty($clover['merchant_id']);
    }

    /**
     * Get OAuth access token from Clover
     *
     * @return string|null
     */
    private function getAccessToken()
    {
        $clover = $this->settings['clover'] ?? [];
        
        // If we have a stored access token, use it
        if (!empty($clover['access_token'])) {
            return $clover['access_token'];
        }

        // Otherwise, get a new token
        try {
            $baseUrl = $clover['environment'] === 'production' 
                ? 'https://api.clover.com' 
                : 'https://sandbox.dev.clover.com';

            $ch = curl_init($baseUrl . '/oauth/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'client_id' => $clover['app_id'],
                    'client_secret' => $clover['app_secret'],
                    'code' => '', // This would be obtained from OAuth flow
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return $data['access_token'] ?? null;
            }
        } catch (\Exception $e) {
            Log::error('Clover OAuth Error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Send payment to Clover device
     *
     * @param float $amount
     * @param string $orderId
     * @return array
     */
    public function sendPayment($amount, $orderId)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured. Please configure in Business Settings > Integrations.'
            ];
        }

        try {
            $clover = $this->settings['clover'] ?? [];
            $baseUrl = $clover['environment'] === 'production' 
                ? 'https://api.clover.com' 
                : 'https://sandbox.dev.clover.com';

            $accessToken = $this->getAccessToken();
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'msg' => 'Failed to obtain Clover access token. Please check your credentials.'
                ];
            }

            // Create payment request
            $paymentData = [
                'amount' => (int)($amount * 100), // Clover uses cents
                'externalReferenceId' => $orderId,
                'tipAmount' => 0,
                'taxAmount' => 0
            ];

            $ch = curl_init($baseUrl . '/v3/merchants/' . $clover['merchant_id'] . '/payments');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($paymentData),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL Error: ' . $error);
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $data,
                    'payment_id' => $data['id'] ?? null
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
            }
        } catch (\Exception $e) {
            Log::error('Clover Payment Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Clover payment error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get payment status from Clover
     *
     * @param string $paymentId
     * @return array
     */
    public function getPaymentStatus($paymentId)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'Clover API credentials not configured.'
            ];
        }

        try {
            $clover = $this->settings['clover'] ?? [];
            $baseUrl = $clover['environment'] === 'production' 
                ? 'https://api.clover.com' 
                : 'https://sandbox.dev.clover.com';

            $accessToken = $this->getAccessToken();
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'msg' => 'Failed to obtain Clover access token.'
                ];
            }

            $ch = curl_init($baseUrl . '/v3/merchants/' . $clover['merchant_id'] . '/payments/' . $paymentId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $data
                ];
            } else {
                throw new \Exception('HTTP Error: ' . $httpCode);
            }
        } catch (\Exception $e) {
            Log::error('Clover Payment Status Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Error checking payment status: ' . $e->getMessage()
            ];
        }
    }
}

