<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Diagnoses cash-register opening discrepancies. Lists every cash_registers
// row from the last N hours alongside:
//   - the initial cash_register_transactions credit (what got saved as
//     "opening balance" after counted - safe_drop)
//   - the activity_log entry from the duty picker that captured what the
//     cashier typed into the opening_cash field
// so Sarah can see whether the recorded opening matches what was typed at
// duty pick. Built 2026-05-13 after Manolo's $1,028 open didn't match his
// reported ~$600 count.
//
// Read-only — no mutations, no Apply button.
class CashRegisterDebugController extends Controller
{
    public function index()
    {
        $business_id = (int) request()->session()->get('user.business_id');
        $hours = (int) request()->query('hours', 72);
        if ($hours < 1)   $hours = 72;
        if ($hours > 720) $hours = 720;
        $highlight = trim((string) request()->query('user', ''));

        $since = \Carbon::now()->subHours($hours);

        $hasSafeDrop = Schema::hasColumn('cash_registers', 'safe_drop_amount');

        $cols = [
            'cr.id',
            'cr.user_id',
            'cr.location_id',
            'cr.status',
            'cr.created_at',
            'cr.closed_at',
            'cr.closing_amount',
            'u.first_name',
            'u.last_name',
            'u.username',
            'bl.name as location_name',
        ];
        if ($hasSafeDrop) {
            $cols[] = 'cr.safe_drop_amount';
        }

        $registers = DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'u.id', '=', 'cr.user_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'cr.location_id')
            ->where('cr.business_id', $business_id)
            ->where('cr.created_at', '>=', $since)
            ->orderByDesc('cr.created_at')
            ->get($cols);

        $registerIds = $registers->pluck('id')->all();

        // Initial credit row per register: this is the "opening balance" saved
        // after (counted - safe_drop). If it's missing, $initial_amount was 0.
        $initials = empty($registerIds)
            ? collect()
            : DB::table('cash_register_transactions')
                ->whereIn('cash_register_id', $registerIds)
                ->where('transaction_type', 'initial')
                ->get(['cash_register_id', 'amount', 'pay_method', 'created_at'])
                ->keyBy('cash_register_id');

        // Pull duty-picker logs for the same window. Each entry's properties
        // JSON has the opening_cash the cashier typed. We match by causer_id
        // (= user_id) within a small time window BEFORE the register was
        // created — the duty picker is the step right before /cash-register.
        $dutyLogs = DB::table('activity_log')
            ->where('business_id', $business_id)
            ->where('description', 'pos_duty')
            ->where('created_at', '>=', $since->copy()->subHours(2))
            ->orderBy('created_at')
            ->get(['id', 'causer_id', 'properties', 'created_at']);

        $dutyByUser = [];
        foreach ($dutyLogs as $log) {
            $props = json_decode($log->properties ?? '{}', true) ?: [];
            $dutyByUser[(int) $log->causer_id][] = [
                'created_at'   => $log->created_at,
                'duty'         => $props['duty']         ?? null,
                'location_id'  => $props['location_id']  ?? null,
                'opening_cash' => $props['opening_cash'] ?? null,
            ];
        }

        $rows = [];
        foreach ($registers as $r) {
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->username ?? ('User #' . $r->user_id));

            $initial = $initials->get($r->id);
            $initialAmount = $initial ? (float) $initial->amount : 0.0;
            $safeDrop = $hasSafeDrop ? (float) ($r->safe_drop_amount ?? 0) : null;

            // Counted = initial + safe drop (reconstruction of what was typed
            // in the "Cash in hand" field at open).
            $counted = $hasSafeDrop ? $initialAmount + ($safeDrop ?? 0.0) : null;

            // Best-match duty-picker entry: the latest pos_duty entry for
            // this user created BEFORE the register row, within 90 minutes.
            $matchDuty = null;
            $userLogs = $dutyByUser[(int) $r->user_id] ?? [];
            $regCreated = strtotime($r->created_at);
            foreach ($userLogs as $log) {
                $logTs = strtotime($log['created_at']);
                if ($logTs > $regCreated) continue;
                if ($regCreated - $logTs > 90 * 60) continue;
                if ($matchDuty === null || $logTs > strtotime($matchDuty['created_at'])) {
                    $matchDuty = $log;
                }
            }

            $typedOpening = $matchDuty['opening_cash'] ?? null;

            // Flag rows where the typed value diverges materially from what
            // ended up recorded as counted (>$1 difference). These are the
            // suspicious ones.
            $delta = null;
            $suspicious = false;
            if ($typedOpening !== null && $counted !== null) {
                $delta = $counted - (float) $typedOpening;
                if (abs($delta) > 1.0) $suspicious = true;
            }

            $hl = $highlight !== '' && (
                stripos($name, $highlight) !== false
                || stripos($r->username ?? '', $highlight) !== false
            );

            $rows[] = (object) [
                'id'             => $r->id,
                'user_id'        => $r->user_id,
                'name'           => $name,
                'username'       => $r->username,
                'location_name'  => $r->location_name,
                'status'         => $r->status,
                'created_at'     => $r->created_at,
                'closed_at'      => $r->closed_at,
                'closing_amount' => $r->closing_amount,
                'safe_drop'      => $safeDrop,
                'initial_amount' => $initialAmount,
                'counted'        => $counted,
                'typed_opening'  => $typedOpening,
                'duty_log_at'    => $matchDuty['created_at'] ?? null,
                'duty_log_loc'   => $matchDuty['location_id'] ?? null,
                'duty_log_duty'  => $matchDuty['duty'] ?? null,
                'delta'          => $delta,
                'suspicious'     => $suspicious,
                'highlight'      => $hl,
            ];
        }

        return view('admin.cash_register_debug', [
            'rows'         => $rows,
            'hours'        => $hours,
            'highlight'    => $highlight,
            'has_safedrop' => $hasSafeDrop,
        ]);
    }
}
