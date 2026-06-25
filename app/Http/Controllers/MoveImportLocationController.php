<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Fixes the historical importer's store mis-filing behind the Like-for-Like
// report's prior-year n/a cells.
//
// ImportNivessaHistoricalSales defaults every store-agnostic "in store sales"
// sheet to Hollywood — its code calls Hollywood "the original in-store location
// pre-Pico". That's backwards: Hollywood didn't open until June 2024, so any
// in-store sale dated before then is really Pico. Those rows landed on
// Hollywood, which is why Pico's older months read $0 (n/a in LFL) and Hollywood
// shows revenue from before it existed.
//
// This tool previews the misfiled rows (finalized sells on Hollywood, from the
// in-store import batches, dated before the cutoff), grouped by batch x month
// with checkboxes, and moves the selected ones to Pico. Each apply snapshots the
// BEFORE location_id to storage/admin-snapshots/ so it's one-click undoable at
// /admin/admin-action-history (action 'reassign-import-location'). Nothing is
// deleted; only finalized historical sells are touched, never live POS flow.
class MoveImportLocationController extends Controller
{
    // Hollywood opened June 2024 — in-store sales before this can't be Hollywood.
    const DEFAULT_CUTOFF = '2024-06-01';

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');

        $fromId = $this->locationId($businessId, 'hollywood');
        $toId   = $this->locationId($businessId, 'pico');
        $cutoff = $this->cutoff($request);

        $groups = collect();
        if ($fromId && $toId) {
            $groups = $this->inStoreBatchQuery($businessId, $fromId)
                ->select(
                    DB::raw("COALESCE(import_source,'') as import_source"),
                    DB::raw("DATE_FORMAT(transaction_date, '%Y-%m') as ym"),
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('COALESCE(SUM(final_total),0) as revenue'),
                    DB::raw("MIN(CASE WHEN transaction_date < '{$cutoff} 00:00:00' THEN 1 ELSE 0 END) as pre_cutoff")
                )
                ->groupBy('import_source', 'ym')
                ->orderBy('import_source')
                ->orderBy('ym')
                ->get();
        }

        return view('admin.move_import_location', [
            'fromId' => $fromId,
            'toId'   => $toId,
            'cutoff' => $cutoff,
            'groups' => $groups,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = $request->session()->get('user.business_id');
        $fromId = $this->locationId($businessId, 'hollywood');
        $toId   = $this->locationId($businessId, 'pico');
        $cutoff = $this->cutoff($request);

        if (!$fromId || !$toId) {
            return back()->with('status', ['success' => 0, 'msg' => 'Could not resolve both the Hollywood and Pico locations.']);
        }

        // Selected groups arrive as "import_source|ym" tokens. Re-resolve every
        // matching row from the DB (never trust the posted set) so a stale form
        // can't move rows that aren't really misfiled.
        $tokens = (array) $request->input('groups', []);
        $pairs = [];
        foreach ($tokens as $tok) {
            $parts = explode('|', (string) $tok, 2);
            if (count($parts) === 2 && $parts[1] !== '') { $pairs[] = $parts; }
        }
        if (empty($pairs)) {
            return back()->with('status', ['success' => 0, 'msg' => 'Nothing selected to move.']);
        }

        $rows = $this->inStoreBatchQuery($businessId, $fromId)
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as [$src, $ym]) {
                    $q->orWhere(function ($qq) use ($src, $ym) {
                        $qq->where(DB::raw("COALESCE(import_source,'')"), $src)
                           ->whereRaw("DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$ym]);
                    });
                }
            })
            ->get(['id', 'location_id']);

        if ($rows->isEmpty()) {
            return back()->with('status', ['success' => 0, 'msg' => 'Selected rows no longer match (already moved?).']);
        }

        // Snapshot BEFORE location_id so the move is undoable.
        $snapRows = $rows->map(function ($r) {
            return ['id' => $r->id, 'location_id' => $r->location_id];
        })->all();

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "reassign-import-location-{$timestamp}-{$fromId}-to-{$toId}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'        => $timestamp,
                'action'           => 'reassign-import-location',
                'user_id'          => auth()->id(),
                'business_id'      => $businessId,
                'from_location_id' => $fromId,
                'to_location_id'   => $toId,
                'cutoff'           => $cutoff,
                'rows'             => $snapRows,
            ], JSON_PRETTY_PRINT)
        );

        $moved = 0;
        foreach (array_chunk($rows->pluck('id')->all(), 1000) as $chunk) {
            $moved += DB::table('transactions')
                ->whereIn('id', $chunk)
                ->where('location_id', $fromId)
                ->update(['location_id' => $toId, 'updated_at' => now()]);
        }

        return redirect('/admin/move-import-location')
            ->with('status', [
                'success' => 1,
                'msg' => "Moved {$moved} misfiled in-store sale(s) from Hollywood to Pico. Snapshot: {$snapshotKey} (undo at /admin/admin-action-history).",
            ]);
    }

    // Finalized in-store-import sells currently sitting on $fromId. The
    // in-store batches are the store-agnostic sheets the importer defaulted to
    // Hollywood — matched by an 'in store' / 'instore' import_source, which
    // never matches the store-specific pico_sales_* / hw_* / hollywood_* sheets.
    protected function inStoreBatchQuery($businessId, $fromId)
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('location_id', $fromId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->where('import_source', 'like', 'nivessa_backend_sales_%')
            ->where(function ($q) {
                $q->where('import_source', 'like', '%in_store%')
                  ->orWhere('import_source', 'like', '%instore%');
            });
    }

    protected function locationId($businessId, $needle)
    {
        $loc = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->where('name', 'like', '%' . $needle . '%')
            ->orderBy('id')
            ->first(['id']);
        return $loc ? (int) $loc->id : 0;
    }

    protected function cutoff(Request $request)
    {
        $raw = trim((string) $request->input('cutoff', ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : self::DEFAULT_CUTOFF;
    }
}
