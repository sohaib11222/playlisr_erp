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
     * Calendar shifts in a date range. Per Sling's published bash examples
     * (getsling/getsling-api-docs), the canonical endpoint is per-user:
     *   GET https://api.getsling.com/calendar/{orgId}/users/{userId}?dates={start}/{end}
     * with no /v1/ prefix. We iterate the user roster and aggregate.
     *
     * Returns a flat array of shift records, each tagged with `user.id` so
     * downstream code can pivot on it (matches the field hoursByErpUser
     * already reads).
     */
    public function shifts(string $startDate, string $endDate)
    {
        if (!$this->isConfigured()) return [];

        $users = $this->users();
        if (empty($users)) return [];

        $shifts = [];
        $dates = $startDate . '/' . $endDate;
        $calendarBase = 'https://api.getsling.com/calendar/' . $this->orgId . '/users/';
        foreach ($users as $u) {
            $uid = $u['id'] ?? null;
            if (!$uid) continue;
            $url = $calendarBase . $uid . '?dates=' . rawurlencode($dates);
            $body = $this->get($url);
            $entries = [];
            if (is_array($body)) {
                if (isset($body[0])) $entries = $body;
                elseif (isset($body['shifts']) && is_array($body['shifts'])) $entries = $body['shifts'];
                elseif (isset($body['data']) && is_array($body['data'])) $entries = $body['data'];
                elseif (isset($body['events']) && is_array($body['events'])) $entries = $body['events'];
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) continue;
                // Make sure user.id is set so consumers can group by user.
                if (empty($entry['user']['id']) && empty($entry['userId'])) {
                    $entry['user'] = ['id' => $uid];
                }
                $shifts[] = $entry;
            }
        }
        return $shifts;
    }

    /**
     * Sum hours per ERP user_id for the date range, matching Sling users
     * to ERP users by lowercased email. Returns [user_id => hours].
     *
     * Skips time-off entries (Sling's calendar mixes them with shifts) so
     * a PTO day doesn't inflate worked hours, and applies the manual
     * Sling-email → ERP user overrides set from the shift debug page so
     * staff whose Sling email differs from their ERP profile email still
     * count correctly.
     *
     * Each shift is capped at 12h to defend against bogus data.
     */
    public function hoursByErpUser(string $startDate, string $endDate, array $erpUserIdByEmail): array
    {
        if (!$this->isConfigured()) return [];

        // Layer manual overrides on top of the email map the caller built
        // from `users.email` / `users.username`.
        $overridesRaw = (string) (System::getProperty('sling_user_overrides') ?? '');
        if ($overridesRaw !== '') {
            $overrides = json_decode($overridesRaw, true);
            if (is_array($overrides)) {
                foreach ($overrides as $oEmail => $oUid) {
                    $erpUserIdByEmail[strtolower(trim((string) $oEmail))] = (int) $oUid;
                }
            }
        }

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
            if (self::isTimeOff($shift)) continue;

            $sid = $shift['user']['id'] ?? ($shift['userId'] ?? null);
            $start = $shift['dtstart'] ?? ($shift['startDate'] ?? null);
            $end = $shift['dtend'] ?? ($shift['endDate'] ?? null);
            if (!$sid || !$start || !$end) continue;

            $email = $slingEmailById[(string) $sid] ?? null;
            if (!$email) continue;
            $erpUid = $erpUserIdByEmail[$email] ?? null;
            if (!$erpUid) continue;

            $sec = max(0, strtotime($end) - strtotime($start));
            $sec = min($sec, 12 * 3600);
            $hours[$erpUid] = ($hours[$erpUid] ?? 0) + ($sec / 3600.0);
        }
        return $hours;
    }

    /**
     * Heuristic: is this Sling calendar entry a time-off / PTO record
     * rather than a worked shift? Used to keep PTO out of "hours worked"
     * totals on the productivity report. Mirrors the SyncSlingShifts
     * detector so persisted rows and live report numbers agree.
     */
    public static function isTimeOff(array $shift): bool
    {
        $type = null;
        foreach (['type', 'eventType', 'kind', 'category'] as $k) {
            if (!empty($shift[$k]) && is_string($shift[$k])) {
                $type = strtolower(trim($shift[$k]));
                break;
            }
        }
        if ($type !== null) {
            if (preg_match('/^(timeoff|time[\-_ ]?off|pto|vacation|sick|leave|absence)$/', $type)) {
                return true;
            }
            if (preg_match('/^(shift|event|availab|free)/', $type)) {
                // Explicitly something else; don't fall through to the
                // duration heuristic which would over-tag long shifts.
                return false;
            }
        }
        if (!empty($shift['allDay']) || !empty($shift['all_day'])) {
            return true;
        }
        $start = $shift['dtstart'] ?? ($shift['startDate'] ?? null);
        $end = $shift['dtend'] ?? ($shift['endDate'] ?? null);
        if ($start && $end) {
            $sec = max(0, strtotime($end) - strtotime($start));
            $hours = $sec / 3600.0;
            $startsMidnight = preg_match('/[T ]00:00(:00)?$/', (string) $start);
            $endsLateDay = preg_match('/[T ]23:59(:\d{2})?$/', (string) $end);
            if (($startsMidnight && $endsLateDay) || $hours >= 20) {
                return true;
            }
        }
        return false;
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
