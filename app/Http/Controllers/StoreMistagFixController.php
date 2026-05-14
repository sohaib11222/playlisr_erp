<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // Sarah 2026-05-13: orphan-first matching. Start from Clover
        // charges with no same-store ERP match, then use Sling to find
        // who was scheduled at the Clover store during the swipe. Look
        // for any ERP sale by those cashiers on that LA day with the
        // same amount (any location, any time). If exactly one match
        // and it's at the wrong store → that's the mistag.
        //
        // This bypasses the 5-min time window that the previous
        // sale-first matcher used — pre-May-11 cashiers often
        // batch-entered ERP sales at end of shift, so time-deltas
        // were huge and the tight match window missed them.

        // Pull ERP sells in the window (with a 1-day buffer for late
        // batch entry that lands past midnight). Indexed by
        // (created_by, day, cents) so the orphan loop can probe in O(1).
        $erpWindowStart = (clone (new \DateTime($start)))->modify('-1 day')->format('Y-m-d');
        $erpWindowEnd   = (clone (new \DateTime($end)))->modify('+1 day')->format('Y-m-d');
        $sales = DB::table('transactions as t')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereDate('t.transaction_date', '>=', $erpWindowStart)
            ->whereDate('t.transaction_date', '<=', $erpWindowEnd)
            ->select(
                't.id', 't.invoice_no', 't.transaction_date', 't.final_total', 't.location_id',
                't.created_by',
                'bl.name as current_location_name',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as cashier")
            )
            ->orderBy('t.transaction_date')
            ->get();

        // Index ERP sales by (cashier_id, day, cents) → list of sale rows.
        // 'day' is the LA date string from transaction_date — assumes app
        // TZ is LA (matches the rest of the controller's assumptions).
        $erpByCashierDayCents = [];
        // Also index ERP sales by clover_id-style lookup so we can detect
        // already-correctly-tagged sales (cashier rang at right store with
        // matching amount/day → skip).
        $erpByLocDayCents = [];
        foreach ($sales as $s) {
            $day = substr((string) $s->transaction_date, 0, 10);
            $cents = (int) round((float) $s->final_total * 100);
            $cashier = (int) ($s->created_by ?? 0);
            $loc = (int) ($s->location_id ?? 0);
            if ($cashier > 0) {
                $erpByCashierDayCents[$cashier][$day][$cents][] = $s;
            }
            $erpByLocDayCents[$loc][$day][$cents] = ($erpByLocDayCents[$loc][$day][$cents] ?? 0) + 1;
        }

        if ($sales->isEmpty()) return [];

        // Load Sling shifts spanning the window so we can ask "who was
        // scheduled at this store at this time?". Indexed per
        // normalized location name → list of {start_ts, end_ts,
        // erp_user_id} so the orphan loop can probe by store + time.
        $shiftsByLocNorm = [];
        $hasSling = Schema::hasTable('sling_shifts');
        if ($hasSling) {
            $shiftRows = DB::table('sling_shifts')
                ->where('event_type', 'shift')
                ->whereNotNull('erp_user_id')
                ->whereNotNull('location_name')
                ->whereDate('dtstart', '>=', $erpWindowStart)
                ->whereDate('dtstart', '<=', $erpWindowEnd)
                ->select('erp_user_id', 'location_name', 'dtstart', 'dtend')
                ->get();
            foreach ($shiftRows as $r) {
                $key = strtolower(trim((string) $r->location_name));
                $shiftsByLocNorm[$key][] = [
                    'start_ts' => @strtotime((string) $r->dtstart) ?: 0,
                    'end_ts'   => @strtotime((string) ($r->dtend ?? $r->dtstart)) ?: 0,
                    'erp_user_id' => (int) $r->erp_user_id,
                ];
            }
        }

        // location_id → normalized name (for matching Sling shifts).
        $locNameNormById = [];
        foreach (\App\BusinessLocation::where('business_id', $businessId)->get(['id', 'name']) as $bl) {
            $locNameNormById[(int) $bl->id] = strtolower(trim((string) $bl->name));
        }

        // Pull Clover orphans — charges that have no same-store ERP
        // sale within ±$0.20 / ±10 min. These are the ones we want to
        // reassign by looking up the right ERP sale via Sling.
        $cpStart = (clone (new \DateTime($start)))->modify('-1 day')->format('Y-m-d');
        $cpEnd   = (clone (new \DateTime($end)))->modify('+1 day')->format('Y-m-d');
        $cpRows = DB::table('clover_payments')
            ->where('business_id', $businessId)
            ->where('result', 'SUCCESS')
            ->whereDate('paid_at', '>=', $cpStart)
            ->whereDate('paid_at', '<=', $cpEnd)
            ->whereNotNull('location_id')
            ->where('location_id', '!=', 0)
            ->where('amount', '>', 0)
            ->select('id', 'location_id', 'amount', 'paid_at', 'employee_name', 'clover_payment_id')
            ->orderBy('paid_at')
            ->get();

        if ($cpRows->isEmpty()) return [];

        // For each Clover row, decide if it's orphan at its store.
        // "Orphan" = no ERP sale at the same store + same day + same
        // cents (±$0.20 tolerance for bag fees). Closer time isn't
        // required since cashiers batch-entered ERP at end of shift.
        $candidates = [];
        $claimedErpTxIds = []; // avoid double-attributing the same ERP sale to two orphans
        foreach ($cpRows as $cp) {
            $cpLoc = (int) $cp->location_id;
            $cpCents = (int) round((float) $cp->amount * 100);
            $cpDay = substr((string) $cp->paid_at, 0, 10);
            $cpTs = @strtotime((string) $cp->paid_at) ?: 0;

            // Is this Clover charge already cleanly matched at its own
            // store? Same store + same day + same cents = treat as
            // matched (the existing same-store matcher claims it).
            $sameStoreMatchCount = 0;
            for ($d = -20; $d <= 20; $d++) {
                $sameStoreMatchCount += $erpByLocDayCents[$cpLoc][$cpDay][$cpCents + $d] ?? 0;
            }
            if ($sameStoreMatchCount > 0) continue;

            // Look up cashiers scheduled at the Clover's store during
            // this swipe. If no Sling data, skip — we don't want to
            // guess at retags without authoritative shift data.
            $targetLocNorm = $locNameNormById[$cpLoc] ?? null;
            $scheduledCashiers = [];
            foreach (($shiftsByLocNorm[$targetLocNorm] ?? []) as $sh) {
                if ($sh['start_ts'] <= $cpTs && $cpTs <= $sh['end_ts']) {
                    $scheduledCashiers[$sh['erp_user_id']] = true;
                }
            }
            if (empty($scheduledCashiers)) continue;

            // For each scheduled cashier, look for ERP sales they rang
            // on the same LA day with matching amount (±$0.20). Prefer
            // ones currently tagged at the WRONG store (those are the
            // mistags we want to fix).
            $best = null;
            foreach (array_keys($scheduledCashiers) as $cashierId) {
                $dayBucket = $erpByCashierDayCents[$cashierId][$cpDay] ?? null;
                if ($dayBucket === null) continue;
                for ($d = -20; $d <= 20; $d++) {
                    foreach (($dayBucket[$cpCents + $d] ?? []) as $s) {
                        if ((int) $s->location_id === $cpLoc) continue; // already correct
                        if (isset($claimedErpTxIds[$s->id])) continue; // already paired
                        $score = abs($d) * 1000;
                        if ($best === null || $score < $best['score']) {
                            $best = [
                                'sale' => $s,
                                'score' => $score,
                                'amount_delta_cents' => abs($d),
                            ];
                        }
                    }
                }
            }

            if ($best !== null) {
                $s = $best['sale'];
                $claimedErpTxIds[$s->id] = true;
                $candidates[] = [
                    'tx_id' => $s->id,
                    'invoice_no' => $s->invoice_no,
                    'transaction_date' => $s->transaction_date,
                    'final_total' => (float) $s->final_total,
                    'current_location_id' => (int) $s->location_id,
                    'current_location_name' => $s->current_location_name,
                    'cashier' => $s->cashier,
                    'suggested_location_id' => $cpLoc,
                    'clover_payment_id' => $cp->clover_payment_id,
                    'clover_paid_at' => $cp->paid_at,
                    'clover_amount' => (float) $cp->amount,
                    'clover_employee' => $cp->employee_name,
                    'amount_delta_cents' => $best['amount_delta_cents'],
                    'time_delta_sec' => abs(@strtotime((string) $s->transaction_date) - $cpTs),
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
