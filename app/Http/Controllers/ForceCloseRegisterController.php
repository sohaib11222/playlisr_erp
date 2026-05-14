<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// Force-close cash registers that were left open. Built 2026-05-13 after
// the register-left-open panel surfaced months of orphaned shifts from
// ex-staff (Zella, Ilan, Owen, Joey K...) plus today's same-cashier
// double-open (Manolo at hollywood).
//
// Each close writes a snapshot to storage/admin-snapshots/ so the row
// can be restored to status='open' via /admin/admin-action-history if
// the close turns out to be wrong. Closing_amount defaults to the
// register's initial_amount (we don't know what the cashier actually
// counted) and the closing_note records that it was force-closed by
// admin so the reconciliation trail stays honest.
class ForceCloseRegisterController extends Controller
{
    /** Default age threshold for "stale" bulk close. */
    const STALE_HOURS = 20;

    public function index()
    {
        $business_id = (int) request()->session()->get('user.business_id');
        $hasSafeDrop = Schema::hasColumn('cash_registers', 'safe_drop_amount');

        // Include both currently-open registers AND recently-closed ones
        // (last 48h) so admins can also DELETE duplicate registers that
        // were already closed but are still polluting daily totals
        // (e.g. Manolo's same-day double-open on 2026-05-13).
        $recentCutoff = \Carbon::now()->subHours(48)->toDateTimeString();
        $regs = DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'u.id', '=', 'cr.user_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'cr.location_id')
            ->where('cr.business_id', $business_id)
            ->where(function ($q) use ($recentCutoff) {
                $q->where('cr.status', 'open')
                  ->orWhere(function ($q2) use ($recentCutoff) {
                      $q2->where('cr.status', 'close')
                         ->where('cr.created_at', '>=', $recentCutoff);
                  });
            })
            ->orderBy('cr.created_at', 'desc')
            ->get([
                'cr.id', 'cr.user_id', 'cr.location_id', 'cr.status',
                'cr.created_at', 'cr.closed_at',
                'u.surname', 'u.first_name', 'u.last_name',
                'u.status as user_status', 'u.allow_login',
                'bl.name as location_name',
            ]);

        $registerIds = $regs->pluck('id')->all();
        $initials = empty($registerIds)
            ? collect()
            : DB::table('cash_register_transactions')
                ->whereIn('cash_register_id', $registerIds)
                ->where('transaction_type', 'initial')
                ->get(['cash_register_id', 'amount'])
                ->keyBy('cash_register_id');

        // Flag same-cashier-same-day duplicates: for each (user_id, store, date)
        // group with >1 register, mark the NEWER ones as duplicates so admins
        // can delete them cleanly.
        $dupKeyToCount = [];
        foreach ($regs as $r) {
            $day = substr((string) $r->created_at, 0, 10);
            $key = $r->user_id . '|' . $r->location_id . '|' . $day;
            $dupKeyToCount[$key] = ($dupKeyToCount[$key] ?? 0) + 1;
        }

        $now = \Carbon::now('America/Los_Angeles');
        $rows = [];
        // Track the OLDEST register per (user, store, day) — only NEWER ones
        // get the "duplicate" flag.
        $oldestPerKey = [];
        foreach ($regs->reverse() as $r) {
            $day = substr((string) $r->created_at, 0, 10);
            $key = $r->user_id . '|' . $r->location_id . '|' . $day;
            if (!isset($oldestPerKey[$key])) {
                $oldestPerKey[$key] = $r->id;
            }
        }

        foreach ($regs as $r) {
            $name = trim(($r->surname ?? '') . ' ' . ($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
            $name = preg_replace('/\s+/', ' ', $name) ?: ('User #' . $r->user_id);

            $opened = \Carbon::parse($r->created_at);
            $ageH = $opened->diffInMinutes($now) / 60.0;

            $isCurrentStaff = ($r->user_status === 'active') && ((int) $r->allow_login === 1);
            $initial = $initials->get($r->id);
            $initialAmount = $initial ? (float) $initial->amount : 0.0;

            $day = substr((string) $r->created_at, 0, 10);
            $key = $r->user_id . '|' . $r->location_id . '|' . $day;
            $isDuplicate = ($dupKeyToCount[$key] ?? 0) > 1
                && ($oldestPerKey[$key] ?? null) !== $r->id;

            $rows[] = (object) [
                'id'              => $r->id,
                'status'          => $r->status,
                'name'            => $name,
                'location_name'   => $r->location_name ?: 'Unknown location',
                'opened_at'       => $opened->setTimezone('America/Los_Angeles')->format('M j, Y g:i A'),
                'closed_at'       => $r->closed_at,
                'age_hours'       => round($ageH, 1),
                'is_stale'        => $r->status === 'open' && $ageH > self::STALE_HOURS,
                'is_current_staff'=> $isCurrentStaff,
                'initial_amount'  => $initialAmount,
                'is_duplicate'    => $isDuplicate,
            ];
        }

        return view('admin.force_close_registers', [
            'rows'        => $rows,
            'stale_hours' => self::STALE_HOURS,
            'stale_count' => count(array_filter($rows, function ($r) { return $r->is_stale; })),
        ]);
    }

    /** Close ONE register by id. Snapshot + close. */
    public function closeOne(Request $request)
    {
        $id = (int) $request->input('register_id');
        $business_id = (int) $request->session()->get('user.business_id');

        $reg = DB::table('cash_registers')
            ->where('id', $id)
            ->where('business_id', $business_id)
            ->where('status', 'open')
            ->first();
        if (!$reg) {
            return redirect('/admin/force-close-registers')
                ->with('status', ['success' => 0, 'msg' => "Register #{$id} not found or already closed."]);
        }

        $snapshotKey = $this->snapshot([$reg]);
        $this->closeRow($reg, $snapshotKey);

        return redirect('/admin/force-close-registers')
            ->with('status', ['success' => 1, 'msg' => "Closed register #{$id}. Undo at /admin/admin-action-history."]);
    }

    /** Delete ONE register by id. Snapshot the row + all its
     *  cash_register_transactions first so it's undoable. Used to remove
     *  duplicate same-day same-cashier registers that were polluting
     *  totals (e.g. Manolo's reg 625 on 2026-05-13 — second open on top
     *  of his own reg 622 from earlier the same day). */
    public function deleteOne(Request $request)
    {
        $id = (int) $request->input('register_id');
        $business_id = (int) $request->session()->get('user.business_id');

        $reg = DB::table('cash_registers')
            ->where('id', $id)
            ->where('business_id', $business_id)
            ->first();
        if (!$reg) {
            return redirect('/admin/force-close-registers')
                ->with('status', ['success' => 0, 'msg' => "Register #{$id} not found."]);
        }

        $txns = DB::table('cash_register_transactions')
            ->where('cash_register_id', $id)
            ->get();

        $key = 'delete_register_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $payload = [
            'action'    => 'delete-register',
            'timestamp' => now()->toIso8601String(),
            'causer_id' => auth()->check() ? auth()->id() : null,
            'register'  => (array) $reg,
            'transactions' => $txns->map(function ($t) { return (array) $t; })->all(),
        ];
        Storage::disk('local')->put("admin-snapshots/{$key}.json", json_encode($payload));

        DB::table('cash_register_transactions')->where('cash_register_id', $id)->delete();
        DB::table('cash_registers')->where('id', $id)->delete();

        return redirect('/admin/force-close-registers')
            ->with('status', [
                'success' => 1,
                'msg' => "Deleted register #{$id} (" . count($txns) . " transaction row(s) also removed). Undo at /admin/admin-action-history (snapshot {$key}).",
            ]);
    }

    /** Close every register older than STALE_HOURS. */
    public function closeStale(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $cutoff = \Carbon::now()->subHours(self::STALE_HOURS)->toDateTimeString();

        $regs = DB::table('cash_registers')
            ->where('business_id', $business_id)
            ->where('status', 'open')
            ->where('created_at', '<', $cutoff)
            ->get();
        if ($regs->isEmpty()) {
            return redirect('/admin/force-close-registers')
                ->with('status', ['success' => 1, 'msg' => 'No stale registers to close.']);
        }

        $snapshotKey = $this->snapshot($regs);
        foreach ($regs as $reg) {
            $this->closeRow($reg, $snapshotKey);
        }

        return redirect('/admin/force-close-registers')
            ->with('status', ['success' => 1, 'msg' => 'Closed ' . count($regs) . ' stale register(s). Undo at /admin/admin-action-history.']);
    }

    /** Write a snapshot of the BEFORE state so the close is undoable. */
    protected function snapshot($regs): string
    {
        $rows = [];
        foreach ($regs as $r) {
            $rows[] = (array) $r;
        }
        $key = 'force_close_register_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $payload = [
            'action'    => 'force-close-register',
            'timestamp' => now()->toIso8601String(),
            'causer_id' => auth()->check() ? auth()->id() : null,
            'rows'      => $rows,
        ];
        Storage::disk('local')->put("admin-snapshots/{$key}.json", json_encode($payload));
        return $key;
    }

    /** Apply the close to a single row. Closing values are deliberately
     *  conservative: closing_amount = initial_amount (we don't know what
     *  the cashier counted) + a closing_note that flags this as admin-
     *  force-closed so the reconciliation trail is honest. */
    protected function closeRow($reg, string $snapshotKey): void
    {
        $whoami = auth()->check() ? (auth()->user()->username ?: ('user#' . auth()->id())) : 'system';
        $note = sprintf(
            "Force-closed by admin %s on %s. Original cashier didn't record a closing count; closing_amount defaulted to initial_amount. Snapshot: %s.",
            $whoami,
            now()->setTimezone('America/Los_Angeles')->format('M j, Y g:i A'),
            $snapshotKey
        );

        $initial = DB::table('cash_register_transactions')
            ->where('cash_register_id', $reg->id)
            ->where('transaction_type', 'initial')
            ->value('amount');
        $initialAmount = $initial !== null ? (float) $initial : 0.0;

        DB::table('cash_registers')
            ->where('id', $reg->id)
            ->update([
                'status'         => 'close',
                'closed_at'      => now()->format('Y-m-d H:i:s'),
                'closing_amount' => $initialAmount,
                'closing_note'   => $note,
                'updated_at'     => now(),
            ]);
    }
}
