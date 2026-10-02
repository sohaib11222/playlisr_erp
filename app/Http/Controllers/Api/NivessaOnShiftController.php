<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlingClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is on the floor at a store right now, from live Sling, for the
 * website's Quo "still waiting on a reply" Slack reminder (jonhedvat/server):
 * the reminder @mentions these people so the callback list lands on whoever
 * is actually working instead of the whole channel (Sarah 2026-10-02).
 *
 * GET /api/v1/nivessa-web/on-shift?store=hollywood|pico
 * -> { success, store, at, staff: [{ name, email, phone, position, until }] }
 *
 * Floor positions (Cashier / Event Lead / Floor Sales) first, same rule as
 * the party bonus tool; if nobody is on a floor shift, anyone with any shift
 * at that store right now (manager check-in, checklist). Shares the Sling
 * caches used by the Commissions page so this adds no extra Sling calls.
 * Guarded by the shared nivessa_web bearer token.
 */
class NivessaOnShiftController extends Controller
{
    private const FLOOR_POSITIONS = ['cashier', 'event lead', 'floor sales'];

    public function index(Request $request): JsonResponse
    {
        $store = strtolower(trim((string) $request->query('store', '')));
        if (!in_array($store, ['hollywood', 'pico'], true)) {
            return response()->json(['success' => false, 'message' => 'store must be hollywood or pico'], 400);
        }

        $now = \Carbon\Carbon::now('America/Los_Angeles');
        $out = ['success' => true, 'store' => $store, 'at' => $now->toIso8601String(), 'staff' => []];

        try {
            $client = app(SlingClient::class);
            if (!$client->isConfigured()) {
                $out['note'] = 'sling not configured';
                return response()->json($out);
            }

            $groups = \Cache::remember('sling_groups_v1', 3600, function () use ($client) { return $client->groups(); });
            $locationNameById = [];
            $positionNameById = [];
            foreach ((array) $groups as $g) {
                $gid = (string) ($g['id'] ?? '');
                $gname = trim((string) ($g['name'] ?? ''));
                if ($gid === '' || $gname === '') { continue; }
                $gtype = strtolower(trim((string) ($g['type'] ?? '')));
                if ($gtype === 'location') { $locationNameById[$gid] = $gname; }
                elseif ($gtype === 'position') { $positionNameById[$gid] = $gname; }
            }

            $slingUsers = \Cache::remember('sling_users_v1', 3600, function () use ($client) { return $client->users(); });
            $userById = [];
            foreach ((array) $slingUsers as $u) {
                $sid = (string) ($u['id'] ?? '');
                if ($sid !== '') { $userById[$sid] = $u; }
            }
            $phones = [];
            try { $phones = $client->userPhones(); } catch (\Throwable $e) {}

            $date = $now->toDateString();
            $cacheKey = 'sling_shifts_live_v2_' . $date;
            $shifts = \Cache::get($cacheKey);
            if (!is_array($shifts) || empty($shifts)) {
                $shifts = $client->orgShifts($date, $date);
                if ($shifts === null) { $shifts = $client->shifts($date, $date); }
                if (!empty($shifts)) { \Cache::put($cacheKey, $shifts, 10); }
            }

            $floor = [];
            $anyone = [];
            foreach ((array) $shifts as $s) {
                if (!is_array($s) || SlingClient::isTimeOff($s)) { continue; }
                $locId = (string) ($s['location']['id'] ?? '');
                $loc = strtolower((string) ($s['location']['name'] ?? ($locId !== '' ? ($locationNameById[$locId] ?? '') : '')));
                if ($loc === '' || strpos($loc, $store) === false) { continue; }
                $start = $s['dtstart'] ?? ($s['startDate'] ?? null);
                $end = $s['dtend'] ?? ($s['endDate'] ?? null);
                if (!$start) { continue; }
                $ss = \Carbon\Carbon::parse($start);
                $se = $end ? \Carbon\Carbon::parse($end) : $ss->copy()->endOfDay();
                if (!($ss->lte($now) && $se->gt($now))) { continue; }
                $sid = (string) ($s['user']['id'] ?? ($s['userId'] ?? ''));
                if ($sid === '') { continue; }
                $u = $userById[$sid] ?? [];
                $name = trim(trim((string) ($u['name'] ?? '')) . ' ' . trim((string) ($u['lastname'] ?? '')));
                if ($name === '') { $name = trim((string) ($s['user']['name'] ?? '')) ?: ('Sling #' . $sid); }
                $posId = (string) ($s['position']['id'] ?? '');
                $pos = (string) ($s['position']['name'] ?? ($posId !== '' ? ($positionNameById[$posId] ?? '') : ''));
                $row = [
                    'name' => $name,
                    'email' => strtolower(trim((string) ($u['email'] ?? ''))),
                    'phone' => $phones[$sid] ?? '',
                    'position' => $pos,
                    'until' => $se->toIso8601String(),
                ];
                $isFloor = false;
                foreach (self::FLOOR_POSITIONS as $fp) { if (strpos(strtolower($pos), $fp) !== false) { $isFloor = true; break; } }
                if ($isFloor) { $floor[$sid] = $row; } else { $anyone[$sid] = $row; }
            }
            $out['staff'] = array_values(!empty($floor) ? $floor : $anyone);
            $out['floor'] = !empty($floor);
        } catch (\Throwable $e) {
            $out['note'] = 'sling lookup failed: ' . $e->getMessage();
        }

        return response()->json($out);
    }
}
