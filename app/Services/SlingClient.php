<?php

namespace App\Services;

use App\System;

/**
 * Thin Sling (getsling.com) API client. Reads the auth token + org id
 * from the system table — the token is captured by the SlingController
 * admin form when Sarah pastes it in.
 *
 * Sling free has no programmatic API key; the token here is the same
 * Authorization header her browser session uses, copied via the
 * /admin/sling/login bookmarklet flow.
 */
class SlingClient
{
    /** @var string */
    private $token;
    /** @var string */
    private $orgId;
    /** @var string */
    private $base = 'https://api.getsling.com/v1';

    public function __construct()
    {
        $this->token = (string) (System::getProperty('sling_auth_token') ?? '');
        $this->orgId = (string) (System::getProperty('sling_org_id') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->orgId !== '';
    }

    public function orgId(): string
    {
        return $this->orgId;
    }

    /**
     * Fetch every active user in the org. Used to build a sling_user_id ->
     * email map so we can join Sling shifts onto ERP users.
     */
    public function users()
    {
        if (!$this->isConfigured()) return [];
        $body = $this->get($this->base . '/' . $this->orgId . '/users');
        if (!is_array($body)) return [];
        // Sling returns either a flat list or {users: [...]} depending on endpoint.
        if (isset($body[0])) return $body;
        if (isset($body['users']) && is_array($body['users'])) return $body['users'];
        return [];
    }

    /**
     * Calendar shifts in a date range. Sling exposes /v1/{orgId}/calendar/
     * {YYYY-MM-DD}/{YYYY-MM-DD} as the canonical "what happened in this
     * window" endpoint, returning every published shift.
     */
    public function shifts(string $startDate, string $endDate)
    {
        if (!$this->isConfigured()) return [];
        $url = $this->base . '/' . $this->orgId . '/calendar/' . $startDate . '/' . $endDate;
        $body = $this->get($url);
        if (!is_array($body)) return [];
        // Endpoint is sometimes a bare list, sometimes wrapped.
        if (isset($body[0])) return $body;
        if (isset($body['shifts']) && is_array($body['shifts'])) return $body['shifts'];
        if (isset($body['data']) && is_array($body['data'])) return $body['data'];
        return [];
    }

    /**
     * Sum hours per ERP user_id for the date range, matching Sling users
     * to ERP users by lowercased email. Returns [user_id => hours].
     * Each shift is capped at 12h to defend against bogus data.
     */
    public function hoursByErpUser(string $startDate, string $endDate, array $erpUserIdByEmail): array
    {
        if (!$this->isConfigured()) return [];

        $slingEmailById = [];
        foreach ($this->users() as $u) {
            $id = $u['id'] ?? null;
            $email = $u['email'] ?? null;
            if ($id && $email) {
                $slingEmailById[(string) $id] = strtolower(trim($email));
            }
        }

        $shifts = $this->shifts($startDate, $endDate);
        $hours = [];
        foreach ($shifts as $shift) {
            $sid = $shift['user']['id'] ?? ($shift['userId'] ?? null);
            $start = $shift['dtstart'] ?? ($shift['startDate'] ?? null);
            $end = $shift['dtend'] ?? ($shift['endDate'] ?? null);
            if (!$sid || !$start || !$end) continue;

            $email = $slingEmailById[(string) $sid] ?? null;
            if (!$email) continue;
            $erpUid = $erpUserIdByEmail[$email] ?? null;
            if (!$erpUid) continue;

            $sec = max(0, strtotime($end) - strtotime($start));
            // Same per-shift cap principle as the cash_registers fallback,
            // but a bit looser since Sling shifts are scheduled, not "open".
            $sec = min($sec, 12 * 3600);
            $hours[$erpUid] = ($hours[$erpUid] ?? 0) + ($sec / 3600.0);
        }
        return $hours;
    }

    /**
     * Quick health check — calls /users and returns [ok, message, count]
     * for the admin Test Connection button.
     */
    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Token or org id missing.', 'count' => 0];
        }
        $url = $this->base . '/' . $this->orgId . '/users';
        $det = $this->getDetailed($url);
        if ($det['http_code'] >= 200 && $det['http_code'] < 300) {
            $body = is_string($det['body']) ? json_decode($det['body'], true) : null;
            $count = is_array($body) ? (isset($body[0]) ? count($body) : (isset($body['users']) ? count($body['users']) : 0)) : 0;
            return ['ok' => true, 'message' => 'OK — fetched ' . $count . ' users.', 'count' => $count];
        }
        $msg = 'HTTP ' . $det['http_code'];
        if ($det['curl_error']) $msg .= ' (' . $det['curl_error'] . ')';
        if ($det['body']) $msg .= ' — ' . substr($det['body'], 0, 200);
        return ['ok' => false, 'message' => $msg, 'count' => 0];
    }

    private function get(string $url)
    {
        $det = $this->getDetailed($url);
        if ($det['http_code'] < 200 || $det['http_code'] >= 300) return null;
        return is_string($det['body']) ? json_decode($det['body'], true) : null;
    }

    private function getDetailed(string $url): array
    {
        // Sling's bash login example returns the FULL Authorization header
        // value (with scheme prefix). But if Sarah pasted a bare JWT from
        // localStorage, the prefix is missing and Sling rejects with
        // "Unsupported authorization token type". Try the saved token as-is
        // first, then transparently retry with a "Bearer " prefix on 401.
        $det = $this->doGet($url, $this->token);
        if ($det['http_code'] === 401 && stripos($this->token, 'bearer ') !== 0) {
            $retry = $this->doGet($url, 'Bearer ' . $this->token);
            if ($retry['http_code'] >= 200 && $retry['http_code'] < 300) {
                // Persist the working format so the report doesn't pay the
                // retry cost on every request.
                $this->token = 'Bearer ' . $this->token;
                System::addProperty('sling_auth_token', $this->token);
                return $retry;
            }
            // Surface whichever response is more informative.
            return $retry['body'] !== '' ? $retry : $det;
        }
        return $det;
    }

    private function doGet(string $url, string $authHeader): array
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $authHeader,
                'Accept: application/json',
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string) curl_error($ch);
            curl_close($ch);
            return ['http_code' => $http, 'body' => is_string($body) ? $body : '', 'curl_error' => $err];
        } catch (\Throwable $e) {
            return ['http_code' => 0, 'body' => '', 'curl_error' => $e->getMessage()];
        }
    }
}
