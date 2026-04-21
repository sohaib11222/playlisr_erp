<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the OpenPhone REST API for sending SMS. Used to notify
 * customers when a wanted record/CD comes in, plus anything else that wants
 * to text instead of email (e.g. daily register-close summary alerts).
 *
 * Config lives in config/services.php under 'openphone':
 *   - api_key        : OpenPhone API key from openphone.com/settings/api
 *   - from_number    : E.164 phone number owned on OpenPhone (e.g. +13235551234)
 *   - enabled        : false turns the whole integration off (default true)
 *
 * Failure behavior: always returns an array with 'success' bool + 'msg'.
 * Never throws; callers can log + degrade without try/catch.
 */
class OpenPhoneService
{
    const API_BASE = 'https://api.openphone.com/v1';

    public function isConfigured(): bool
    {
        return !empty(config('services.openphone.api_key'))
            && !empty(config('services.openphone.from_number'))
            && (bool) config('services.openphone.enabled', true);
    }

    /**
     * Send an SMS.
     *
     * @param  string  $toPhone   Any reasonable US-style phone. Gets normalized
     *                            to E.164 by strip-non-digits + prepend +1 if
     *                            it looks like a 10-digit US number.
     * @param  string  $message   Body text. Keep under ~1000 chars.
     * @return array              [ 'success' => bool, 'msg' => string, 'id' => ?string ]
     */
    public function send(string $toPhone, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'msg' => 'OpenPhone is not configured (add api_key + from_number to config/services.php).'];
        }

        $to = $this->normalize($toPhone);
        if (!$to) {
            return ['success' => false, 'msg' => 'Invalid phone number: ' . $toPhone];
        }

        try {
            $ch = curl_init(self::API_BASE . '/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . config('services.openphone.api_key'),
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'from' => config('services.openphone.from_number'),
                    'to' => [$to],
                    'content' => $message,
                ]),
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('cURL error: ' . $error);
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new \Exception('HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 400));
            }

            $data = json_decode($response, true);
            return [
                'success' => true,
                'msg' => 'sent',
                'id' => $data['data']['id'] ?? ($data['id'] ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('OpenPhone send failed: ' . $e->getMessage());
            return ['success' => false, 'msg' => 'OpenPhone error: ' . $e->getMessage()];
        }
    }

    /**
     * Normalize a phone to E.164. Accepts "(323) 555-1234", "323-555-1234",
     * "+13235551234", "13235551234", etc. Returns null for anything that
     * can't be coerced into a valid-looking US/CA number.
     */
    public function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$digits) return null;

        // Already E.164-ish? If it starts with a non-zero country code and
        // has 10–14 digits after, take it.
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) >= 11 && strlen($digits) <= 14) {
            return '+' . $digits;
        }
        return null;
    }
}
