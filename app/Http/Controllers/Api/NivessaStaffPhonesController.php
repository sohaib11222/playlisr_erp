<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StaffPhonesController;
use Illuminate\Http\JsonResponse;

/**
 * Staff phone numbers for the website's Quo integration (jonhedvat/server):
 * staff texting the store lines shouldn't get customer auto-texts or show up
 * in the "still waiting on a reply" Slack reminders.
 *
 * Source: every Sling user's phone plus the numbers on /tasks/staff-phones.
 * Sarah is left out on purpose - she wants the auto-texts (2026-09-25).
 *
 * Guarded by the shared nivessa_web bearer token. Returns 10-digit numbers.
 */
class NivessaStaffPhonesController extends Controller
{
    // Sarah's Sling user id (org admin) and ERP login.
    private const EXCLUDE_SLING_USER_IDS = ['19993148'];
    private const EXCLUDE_EMAILS = ['sarah@nivessa.com'];

    private static function ten($raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        return strlen($d) === 10 ? $d : null;
    }

    public function index(): JsonResponse
    {
        $out = [];
        $excluded = [];

        try {
            $businessId = (int) (config('services.nivessa_web.business_id') ?: 1);
            foreach (StaffPhonesController::staff($businessId) as $u) {
                $n = self::ten($u->contact_number);
                if (!$n) continue;
                if (in_array(strtolower((string) $u->email), self::EXCLUDE_EMAILS, true)) {
                    $excluded[$n] = true;
                    continue;
                }
                $out[$n] = true;
            }
        } catch (\Throwable $e) {
        }

        try {
            foreach ((new \App\Services\SlingClient())->userPhones() as $slingId => $phone) {
                $n = self::ten($phone);
                if (!$n) continue;
                if (in_array((string) $slingId, self::EXCLUDE_SLING_USER_IDS, true)) {
                    $excluded[$n] = true;
                    continue;
                }
                $out[$n] = true;
            }
        } catch (\Throwable $e) {
        }

        foreach (array_keys($excluded) as $n) unset($out[$n]);

        return response()->json(['success' => true, 'phones' => array_keys($out)]);
    }
}
