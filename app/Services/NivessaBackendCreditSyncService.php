<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NivessaBackendCreditSyncService
{
    /**
     * Push a signed balance delta to nivesia-backend by customer email.
     */
    public function syncDeltaByEmail(string $email, float $delta, string $reason = '', array $metadata = []): bool
    {
        $email = strtolower(trim($email));
        $delta = round($delta, 2);

        if ($email === '' || $delta == 0.0) {
            return false;
        }

        $baseUrl = rtrim((string) config('services.nivessa_web.backend_sync_url'), '/');
        $token = (string) config('services.nivessa_web.api_token');
        if ($baseUrl === '' || $token === '') {
            return false;
        }

        try {
            $payload = json_encode([
                'email' => $email,
                'delta' => $delta,
                'reason' => $reason !== '' ? $reason : 'erp_sync',
                'source' => 'erp',
                'metadata' => $metadata,
            ]);
            if ($payload === false) {
                return false;
            }

            $ch = curl_init($baseUrl . '/api/v1/store-credit-sync/adjust-by-email');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status < 200 || $status >= 300) {
                Log::warning('Nivessa backend store-credit sync failed', [
                    'email' => $email,
                    'delta' => $delta,
                    'reason' => $reason,
                    'status' => $status,
                    'curl_error' => $curlErr,
                    'body' => $body,
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('Nivessa backend store-credit sync exception', [
                'email' => $email,
                'delta' => $delta,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Fetch backend store-credit balances keyed by normalized email.
     *
     * @param array<int, string> $emails
     * @return array<string, array{exists: bool, balance: float}>
     */
    public function fetchBalancesByEmail(array $emails): array
    {
        $baseUrl = rtrim((string) config('services.nivessa_web.backend_sync_url'), '/');
        $token = (string) config('services.nivessa_web.api_token');
        if ($baseUrl === '' || $token === '') {
            return [];
        }

        $normalized = [];
        foreach ($emails as $email) {
            $e = strtolower(trim((string) $email));
            if ($e !== '') {
                $normalized[$e] = true;
            }
        }
        $normalized = array_keys($normalized);
        if (empty($normalized)) {
            return [];
        }

        $payload = json_encode(['emails' => array_values($normalized)]);
        if ($payload === false) {
            return [];
        }

        try {
            $ch = curl_init($baseUrl . '/api/v1/store-credit-sync/balances-by-email');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 12,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body === false || $status < 200 || $status >= 300) {
                return [];
            }

            $json = json_decode((string) $body, true);
            if (!is_array($json) || empty($json['success']) || !isset($json['balances']) || !is_array($json['balances'])) {
                return [];
            }

            $out = [];
            foreach ($json['balances'] as $email => $row) {
                $key = strtolower(trim((string) $email));
                if ($key === '') {
                    continue;
                }
                $out[$key] = [
                    'exists' => !empty($row['exists']),
                    'balance' => round((float) ($row['balance'] ?? 0), 2),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('Nivessa backend balance fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
