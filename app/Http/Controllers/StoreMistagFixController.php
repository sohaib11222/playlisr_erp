<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-shot fixer for ERP sales rung before the 2026-05-11 duty picker
// landed — back then cashiers could ring without picking a specific
// store, so HW sales sometimes got stored with Pico's location_id
// (or vice versa). The Clover terminal correctly tagged location, so
// any ERP transaction whose final_total + minute exactly matches a
// Clover charge at a DIFFERENT location is almost certainly a mistag.
//
// Lists candidates with a checkbox per row; on Apply, snapshots BEFORE
// state to admin-snapshots/ and updates transactions.location_id to the
// Clover-side location. Undo from /admin/admin-action-history.
class StoreMistagFixController extends Controller
{
    public function index(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        $business_locations = \App\BusinessLocation::where('business_id', $business_id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $start = $request->input('start_date', '2026-04-01');
        $end   = $request->input('end_date',   '2026-05-10');

        $candidates = $this->findCandidates($business_id, $start, $end);

        return view('admin.store_mistag_fix', [
            'candidates' => $candidates,
            'business_locations' => $business_locations,
            'start_date' => $start,
            'end_date'   => $end,
            'mode' => null,
            'snapshot_key' => null,
            'updated' => 0,
        ]);
    }

    public function run(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        $now = now();

        $start = $request->input('start_date', '2026-04-01');
        $end   = $request->input('end_date',   '2026-05-10');

        // Form: tx_id => new_location_id for each checked row.
        $picks = $request->input('retag', []);
        if (!is_array($picks)) $picks = [];

        $valid = [];
        foreach ($picks as $txId => $newLocId) {
            $txId = (int) $txId;
            $newLocId = (int) $newLocId;
            if ($txId > 0 && $newLocId > 0) {
                $valid[$txId] = $newLocId;
            }
        }

        if (empty($valid)) {
            return redirect('/admin/store-mistag-fix?start_date=' . urlencode($start) . '&end_date=' . urlencode($end))
                ->with('status', 'No rows checked. Pick at least one to retag.');
        }

        // Snapshot BEFORE state of every row we're about to update.
        $beforeRows = DB::table('transactions')
            ->whereIn('id', array_keys($valid))
            ->where('business_id', $business_id)
            ->get(['id', 'location_id', 'transaction_date', 'final_total', 'invoice_no']);

        $snapshotRows = $beforeRows->map(function ($r) use ($valid) {
            return [
                'id' => $r->id,
                'old_location_id' => $r->location_id,
                'new_location_id' => $valid[$r->id] ?? null,
                'transaction_date' => (string) $r->transaction_date,
                'final_total' => (float) $r->final_total,
                'invoice_no' => $r->invoice_no,
            ];
        })->all();

        $snapshotKey = 'store-mistag-fix-' . $now->format('Y-m-d_His');
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp' => $now->toDateTimeString(),
                'action' => 'store-mistag-fix',
                'business_id' => $business_id,
                'window' => ['start' => $start, 'end' => $end],
                'rows' => $snapshotRows,
            ], JSON_PRETTY_PRINT)
        );

        $updated = 0;
        foreach ($valid as $txId => $newLocId) {
            $count = DB::table('transactions')
                ->where('id', $txId)
                ->where('business_id', $business_id)
                ->update([
                    'location_id' => $newLocId,
                    'updated_at' => $now,
                ]);
            $updated += $count;
        }

        return view('admin.store_mistag_fix', [
            'candidates' => $this->findCandidates($business_id, $start, $end),
            'business_locations' => \App\BusinessLocation::where('business_id', $business_id)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'start_date' => $start,
            'end_date'   => $end,
            'mode' => 'commit',
            'snapshot_key' => $snapshotKey,
            'updated' => $updated,
        ]);
    }

    /**
     * Find ERP transactions whose location_id != the location_id of an
     * exact-amount, near-minute Clover charge. These are almost certainly
     * the same sale, mistagged on the ERP side.
     *
     * Match criteria:
     *   - amount delta <= $0.20 (covers bag fees / tax-rounding edges)
     *   - timestamp delta <= 5 minutes
     *   - ERP location_id != Clover location_id (the whole point)
     *   - Clover row has a non-null location_id (mistag target needs to be a real store)
     *   - No other ERP row at the Clover-side location within the same window
     *     and amount (avoid double-attribution)
     */
    private function findCandidates(int $businessId, string $start, string $end): array
    {
        // Pull candidate ERP sells in the window. Exclude Whatnot since
        // those don't go through Clover.
        $sales = DB::table('transactions as t')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end)
            ->select(
                't.id', 't.invoice_no', 't.transaction_date', 't.final_total', 't.location_id',
                'bl.name as current_location_name',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as cashier")
            )
            ->orderBy('t.transaction_date')
            ->get();

        if ($sales->isEmpty()) return [];

        // Pull Clover charges in the same window (±1 day buffer for
        // overnight batch carryover).
        $cpStart = (clone (new \DateTime($start)))->modify('-1 day')->format('Y-m-d');
        $cpEnd   = (clone (new \DateTime($end)))->modify('+1 day')->format('Y-m-d');
        $cpRows = DB::table('clover_payments')
            ->where('business_id', $businessId)
            ->where('result', 'SUCCESS')
            ->whereDate('paid_at', '>=', $cpStart)
            ->whereDate('paid_at', '<=', $cpEnd)
            ->whereNotNull('location_id')
            ->where('location_id', '!=', 0)
            ->select('id', 'location_id', 'amount', 'paid_at', 'employee_name', 'clover_payment_id')
            ->orderBy('paid_at')
            ->get();

        if ($cpRows->isEmpty()) return [];

        // Index Clover by location and minute-bucket for fast lookup.
        $cpByCents = [];
        foreach ($cpRows as $cp) {
            $cents = (int) round((float) $cp->amount * 100);
            $cpByCents[$cents][] = $cp;
        }

        // Also index ERP sales by location+cents+minute so we can
        // detect "already correctly attributed at the other store"
        // and skip those (avoid double-attribution).
        $erpByLocCentsMinute = [];
        foreach ($sales as $s) {
            $cents = (int) round((float) $s->final_total * 100);
            $minute = substr((string) $s->transaction_date, 0, 16); // YYYY-MM-DD HH:MM
            $key = $s->location_id . '|' . $cents . '|' . $minute;
            $erpByLocCentsMinute[$key] = true;
        }

        $candidates = [];
        foreach ($sales as $s) {
            $erpCents = (int) round((float) $s->final_total * 100);
            $erpTs = strtotime((string) $s->transaction_date);
            $erpLoc = (int) $s->location_id;

            // Scan ±$0.20 amount neighborhood for Clover candidates.
            $best = null;
            for ($d = -20; $d <= 20; $d++) {
                foreach (($cpByCents[$erpCents + $d] ?? []) as $cp) {
                    $cpLoc = (int) $cp->location_id;
                    if ($cpLoc === $erpLoc) continue; // same store — not a mistag
                    $cpTs = strtotime((string) $cp->paid_at);
                    $timeDelta = abs($cpTs - $erpTs);
                    if ($timeDelta > 300) continue; // 5 min window

                    // Avoid double-attribution: if an ERP row already
                    // exists at the Clover-side location with same
                    // amount + same minute, skip (that one already
                    // accounts for the Clover charge).
                    $cpMinute = substr((string) $cp->paid_at, 0, 16);
                    $dupKey = $cpLoc . '|' . $erpCents . '|' . $cpMinute;
                    if (isset($erpByLocCentsMinute[$dupKey])) continue;

                    $score = abs($d) * 1000 + $timeDelta;
                    if ($best === null || $score < $best['score']) {
                        $best = [
                            'cp' => $cp,
                            'score' => $score,
                            'amount_delta_cents' => abs($d),
                            'time_delta_sec' => $timeDelta,
                        ];
                    }
                }
            }

            if ($best !== null) {
                $candidates[] = [
                    'tx_id' => $s->id,
                    'invoice_no' => $s->invoice_no,
                    'transaction_date' => $s->transaction_date,
                    'final_total' => (float) $s->final_total,
                    'current_location_id' => $erpLoc,
                    'current_location_name' => $s->current_location_name,
                    'cashier' => $s->cashier,
                    'suggested_location_id' => (int) $best['cp']->location_id,
                    'clover_payment_id' => $best['cp']->clover_payment_id,
                    'clover_paid_at' => $best['cp']->paid_at,
                    'clover_employee' => $best['cp']->employee_name,
                    'amount_delta_cents' => $best['amount_delta_cents'],
                    'time_delta_sec' => $best['time_delta_sec'],
                ];
            }
        }

        return $candidates;
    }

    private function guardAdmin(): void
    {
        if (!auth()->user() || !auth()->user()->can('superadmin')) {
            $isAdmin = false;
            try {
                $isAdmin = app(\App\Utils\BusinessUtil::class)->is_admin(auth()->user());
            } catch (\Throwable $e) { /* ignore */ }
            if (!$isAdmin) abort(403, 'Admin only.');
        }
    }
}
