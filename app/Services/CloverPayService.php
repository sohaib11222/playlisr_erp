<?php

namespace App\Services;

use App\Business;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pay on Clover (Sarah 2026-11-13): the POS sends the sale total straight to
 * the store's Clover Flex through Clover's REST Pay Display API, so cashiers
 * stop keying every sale twice.
 *
 * Kept apart from CloverService on purpose: that one runs the payment sync and
 * recon with the stores' private tokens. This one only uses the "Nivessa ERP"
 * private app (semi-integrated, REST client) and its per-store OAuth tokens.
 *
 * Settings live in business.api_settings['clover']['rest_pay']:
 *   app_id, app_secret, raid, environment ('production'|'sandbox')
 *   locations[<erp location id>] = merchant_id, access_token, refresh_token,
 *       access_token_expiration, refresh_token_expiration, device_serial,
 *       enabled (bool), connected_at
 *
 * Every attempt is logged to storage/app/clover_pay_attempts.json BEFORE the
 * call to Clover, so a charge that went through while the POS lost the reply
 * can always be found again by its external payment id.
 */
class CloverPayService
{
    const ATTEMPTS_PATH = 'clover_pay_attempts.json';
    const ATTEMPTS_KEEP = 3000;

    protected $businessId;
    protected $business;

    public function __construct($businessId)
    {
        $this->businessId = (int) $businessId;
        $this->business = Business::find($this->businessId);
    }

    /** Whole rest_pay block. */
    public function config()
    {
        $api = is_array($this->business->api_settings ?? null) ? $this->business->api_settings : [];
        $rp = $api['clover']['rest_pay'] ?? [];
        return is_array($rp) ? $rp : [];
    }

    public function locationConfig($locationId)
    {
        $rp = $this->config();
        $loc = $rp['locations'][(string) $locationId] ?? ($rp['locations'][(int) $locationId] ?? []);
        return is_array($loc) ? $loc : [];
    }

    /** Merge-and-save into api_settings['clover']['rest_pay']. */
    public function saveConfig(array $changes, $locationId = null)
    {
        $business = Business::find($this->businessId);
        $api = is_array($business->api_settings) ? $business->api_settings : [];
        if (empty($api['clover']) || !is_array($api['clover'])) {
            $api['clover'] = [];
        }
        $rp = $api['clover']['rest_pay'] ?? [];
        if (!is_array($rp)) {
            $rp = [];
        }
        if ($locationId === null) {
            $rp = array_merge($rp, $changes);
        } else {
            $key = (string) (int) $locationId;
            $loc = $rp['locations'][$key] ?? [];
            $rp['locations'][$key] = array_merge(is_array($loc) ? $loc : [], $changes);
        }
        $api['clover']['rest_pay'] = $rp;
        $business->api_settings = $api;
        $business->save();
        $this->business = $business;
    }

    public function isProduction()
    {
        return ($this->config()['environment'] ?? 'production') !== 'sandbox';
    }

    public function oauthHost()
    {
        return $this->isProduction() ? 'https://www.clover.com' : 'https://sandbox.dev.clover.com';
    }

    public function apiHost()
    {
        return $this->isProduction() ? 'https://api.clover.com' : 'https://apisandbox.dev.clover.com';
    }

    /** Ready to take a payment at this store? (switch on + every piece present) */
    public function isReady($locationId)
    {
        $rp = $this->config();
        $loc = $this->locationConfig($locationId);
        return !empty($loc['enabled'])
            && !empty($rp['raid'])
            && !empty($loc['access_token'])
            && !empty($loc['device_serial']);
    }

    public function authorizeUrl($state, $redirectUri)
    {
        $rp = $this->config();
        return $this->oauthHost() . '/oauth/v2/authorize?' . http_build_query([
            'client_id' => $rp['app_id'] ?? '',
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /** OAuth code -> tokens. */
    public function exchangeCode($code)
    {
        $rp = $this->config();
        return $this->postForm($this->apiHost() . '/oauth/v2/token', [
            'client_id' => $rp['app_id'] ?? '',
            'client_secret' => $rp['app_secret'] ?? '',
            'code' => $code,
        ]);
    }

    /** Access token for a store, refreshed when it is about to expire. */
    public function accessToken($locationId)
    {
        $loc = $this->locationConfig($locationId);
        $token = $loc['access_token'] ?? '';
        $exp = (int) ($loc['access_token_expiration'] ?? 0);
        if ($token && $exp && $exp - 300 < time() && !empty($loc['refresh_token'])) {
            $rp = $this->config();
            $res = $this->postJson($this->apiHost() . '/oauth/v2/refresh', [
                'client_id' => $rp['app_id'] ?? '',
                'refresh_token' => $loc['refresh_token'],
            ]);
            if (!empty($res['success']) && !empty($res['data']['access_token'])) {
                $d = $res['data'];
                $this->saveConfig([
                    'access_token' => $d['access_token'],
                    'refresh_token' => $d['refresh_token'] ?? $loc['refresh_token'],
                    'access_token_expiration' => $d['access_token_expiration'] ?? '',
                    'refresh_token_expiration' => $d['refresh_token_expiration'] ?? ($loc['refresh_token_expiration'] ?? ''),
                ], $locationId);
                $token = $d['access_token'];
            } else {
                Log::warning('CloverPay token refresh failed for location ' . $locationId . ': ' . ($res['msg'] ?? ''));
            }
        }
        return $token;
    }

    /** Devices on the store's merchant (for picking the Flex serial). */
    public function devices($locationId)
    {
        $loc = $this->locationConfig($locationId);
        if (empty($loc['merchant_id'])) {
            return ['success' => false, 'msg' => 'Merchant ID missing for this store. Connect it again.'];
        }
        $res = $this->request('GET', $this->apiHost() . '/v3/merchants/' . rawurlencode($loc['merchant_id']) . '/devices', null, [
            'Authorization: Bearer ' . $this->accessToken($locationId),
        ], 20);
        if (empty($res['success'])) {
            return $res;
        }
        $out = [];
        foreach (($res['data']['elements'] ?? []) as $d) {
            $out[] = [
                'serial' => $d['serial'] ?? '',
                'name' => $d['name'] ?? '',
                'model' => $d['productName'] ?? ($d['model'] ?? ''),
            ];
        }
        return ['success' => true, 'devices' => $out];
    }

    /** Call /connect on the store's Flex. */
    protected function connect($locationId, $method, $path, $body = null, $idempotencyKey = null, $timeout = 30)
    {
        $rp = $this->config();
        $loc = $this->locationConfig($locationId);
        $headers = [
            'Authorization: Bearer ' . $this->accessToken($locationId),
            'X-Clover-Device-Id: ' . ($loc['device_serial'] ?? ''),
            'X-POS-Id: ' . ($rp['raid'] ?? ''),
        ];
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $this->request($method, $this->apiHost() . '/connect' . $path, $body, $headers, $timeout);
    }

    public function ping($locationId)
    {
        return $this->connect($locationId, 'POST', '/v1/device/ping', new \stdClass(), null, 15);
    }

    public function welcome($locationId)
    {
        return $this->connect($locationId, 'POST', '/v1/device/welcome', new \stdClass(), null, 10);
    }

    public function cancelOnDevice($locationId)
    {
        return $this->connect($locationId, 'POST', '/v1/device/cancel', new \stdClass(), null, 10);
    }

    /**
     * Take a card payment on the Flex. Blocks until the customer finishes,
     * cancels, or Clover times out. $amountCents is the ERP's final total.
     */
    public function pay($locationId, $amountCents, $externalId, $idempotencyKey, $taxCents = null)
    {
        $body = [
            'amount' => (int) $amountCents,
            'externalPaymentId' => $externalId,
            'final' => true,
        ];
        if ($taxCents !== null) {
            $body['taxAmount'] = (int) $taxCents;
        }
        return $this->connect($locationId, 'POST', '/v1/payments', $body, $idempotencyKey, 120);
    }

    /** Look a payment up by our external id (recovery after a lost reply). */
    public function findByExternalId($locationId, $externalId)
    {
        return $this->connect($locationId, 'GET', '/v1/payments/external/' . rawurlencode($externalId), null, null, 20);
    }

    // ---- attempt log -------------------------------------------------------

    public static function logAttempt(array $row)
    {
        try {
            $path = Storage::path(self::ATTEMPTS_PATH);
            $fh = fopen($path, 'c+');
            if (!$fh) {
                return;
            }
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $items = json_decode($raw ?: '[]', true);
            if (!is_array($items)) {
                $items = [];
            }
            $found = false;
            foreach ($items as &$it) {
                if (($it['external_id'] ?? '') === ($row['external_id'] ?? '-')) {
                    $it = array_merge($it, $row);
                    $found = true;
                    break;
                }
            }
            unset($it);
            if (!$found) {
                array_unshift($items, $row);
            }
            $items = array_slice($items, 0, self::ATTEMPTS_KEEP);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(array_values($items), JSON_PRETTY_PRINT));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        } catch (\Throwable $e) {
            Log::error('CloverPay attempt log failed: ' . $e->getMessage());
        }
    }

    public static function attempts($limit = 100)
    {
        if (!Storage::exists(self::ATTEMPTS_PATH)) {
            return [];
        }
        $items = json_decode(Storage::get(self::ATTEMPTS_PATH), true);
        return is_array($items) ? array_slice($items, 0, $limit) : [];
    }

    // ---- http ----------------------------------------------------------------

    protected function postForm($url, array $fields)
    {
        return $this->request('POST', $url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded'], 20, false);
    }

    protected function postJson($url, array $body)
    {
        return $this->request('POST', $url, $body, [], 20);
    }

    protected function request($method, $url, $body, array $headers, $timeout = 30, $json = true)
    {
        try {
            $ch = curl_init($url);
            $h = array_merge(['Accept: application/json'], $headers);
            if ($json && $body !== null) {
                $h[] = 'Content-Type: application/json';
                $body = json_encode($body);
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $h,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            if ($body !== null && $method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) {
                return ['success' => false, 'http' => 0, 'timeout' => stripos($err, 'timed out') !== false, 'msg' => 'Could not reach Clover: ' . $err];
            }
            $data = json_decode($resp, true);
            if ($code >= 200 && $code < 300) {
                return ['success' => true, 'http' => $code, 'data' => is_array($data) ? $data : []];
            }
            $msg = is_array($data) ? ($data['message'] ?? ($data['error']['message'] ?? ($data['error'] ?? ''))) : '';
            return ['success' => false, 'http' => $code, 'data' => is_array($data) ? $data : [], 'msg' => is_string($msg) && $msg !== '' ? $msg : ('Clover returned HTTP ' . $code)];
        } catch (\Throwable $e) {
            return ['success' => false, 'http' => 0, 'msg' => $e->getMessage()];
        }
    }
}
