<?php

namespace App\Services;

/**
 * Tells the website backend to post a shipping shift update to #shipping
 * (Sarah, 2026-10-12): Nick's clock-in (from the Clover time clock) and his
 * End Shift. The website builds the text (what's waiting, what shipped).
 * Never throws: a failure here must not affect End Shift or anything else.
 */
class ShippingShiftNotifier
{
    public static function isShippingStaff($user): bool
    {
        $first = strtolower(trim((string) ($user->first_name ?? '')));
        if ($first === '') {
            $first = strtolower(trim(explode(' ', (string) ($user->full_name ?? ''))[0] ?? ''));
        }
        return $first === 'nick';
    }

    /**
     * Ask the website to post the shipping summary (everything since the
     * previous post) to #shipping + Sarah's DM. The website posts once per
     * LA day, so calling this again is harmless.
     * @return array|null decoded response
     */
    public static function postSummary(string $trigger): ?array
    {
        try {
            $key = self::erpApiKey();
            if ($key === '') {
                return null;
            }
            $ch = curl_init(self::baseUrl() . '/erp/shipping-summary/post');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['trigger' => $trigger]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-erp-key: ' . $key],
                CURLOPT_TIMEOUT => 40,
            ]);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) {
                \Log::warning('Shipping summary post failed: HTTP ' . $code . ' ' . substr((string) $raw, 0, 200));
                return null;
            }
            return json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            \Log::warning('Shipping summary post failed: ' . $e->getMessage());
            return null;
        }
    }

    // Same key / base URL resolution as WebsiteOrdersController.
    public static function erpApiKey(): string
    {
        $key = trim((string) config('constants.erp_api_key'));
        if ($key === '') {
            $key = trim((string) env('ERP_API_KEY', ''));
        }
        if ($key === '') {
            try {
                $path = base_path('.env');
                if (is_readable($path)) {
                    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                        if (strpos(ltrim($line), 'ERP_API_KEY=') === 0) {
                            $key = trim(trim(substr(ltrim($line), strlen('ERP_API_KEY='))), "\"'");
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        if ($key === '') {
            try {
                $path = storage_path('app/events-bridge.json');
                if (is_file($path)) {
                    $j = json_decode((string) file_get_contents($path), true);
                    $key = is_array($j) ? trim((string) ($j['erpApiKey'] ?? '')) : '';
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return $key;
    }

    public static function baseUrl(): string
    {
        $base = trim((string) config('constants.nivessa_api'));
        if ($base === '') {
            $base = trim((string) env('NIVESSA_API', ''));
        }
        return rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
    }
}
