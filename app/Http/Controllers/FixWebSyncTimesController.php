<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-off fixer for website/Discogs sales that the website pushed into the ERP
// LIVE (via the connector createSale) BEFORE the 2026-07-06 timezone fix. Those
// rows stored transaction_date in UTC, so the recon feed showed them ~7h ahead
// (a 10:44am sale read as 5:44pm).
//
// For these connector rows transaction_date and created_at are the SAME instant
// (both stamped at insert) - created_at is the correct America/Los_Angeles
// wall-time, transaction_date is the UTC copy. So the fix is simply
// transaction_date = created_at. That's exact and DST-proof, and a no-op for
// already-correct rows (post-fix rows have the two equal).
//
// Scope guard: import_source IS NULL (live connector rows only - never the
// daily-command placeholder rows, whose transaction_date is a real order date
// that legitimately differs from created_at), note starts "Website order" or
// "Discogs order", and the two timestamps differ by >= 2h (the UTC signature).
//
// Snapshot + undo via /admin/admin-action-history (action 'fix-web-sync-times',
// shares the transaction_date restore path with 'fix-imported-dates').
class FixWebSyncTimesController extends Controller
{
    const MIN_DIFF_MINUTES = 120;

    public function index()
    {
        return $this->render(null, null, null);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');
        $now = now();
        $snapshotKey = null;
        $updated = 0;

        if ($commit) {
            $rows = $this->badRowQuery($businessId)->get(['id', 'transaction_date']);
            if ($rows->isNotEmpty()) {
                $snapshotKey = 'fix-web-sync-times-' . $now->format('Y-m-d_His');
                Storage::disk('local')->put(
                    "admin-snapshots/{$snapshotKey}.json",
                    json_encode([
                        'timestamp' => $now->toDateTimeString(),
                        'action' => 'fix-web-sync-times',
                        'business_id' => $businessId,
                        'rows' => $rows->map(function ($r) {
                            return ['id' => $r->id, 'transaction_date' => (string) $r->transaction_date];
                        })->all(),
                    ], JSON_PRETTY_PRINT)
                );

                $updated = $this->badRowQuery($businessId)->update([
                    'transaction_date' => DB::raw('created_at'),
                    'updated_at' => $now,
                ]);
            }
        }

        return $this->render($commit ? 'commit' : 'preview', $snapshotKey, $updated);
    }

    private function render($mode, $snapshotKey, $updated)
    {
        $businessId = request()->session()->get('user.business_id');
        $count = (clone $this->badRowQuery($businessId))->count();
        $samples = $this->badRowQuery($businessId)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'invoice_no', 'transaction_date', 'created_at', 'additional_notes', 'final_total']);

        return view('admin.fix_web_sync_times', [
            'count' => $count,
            'samples' => $samples,
            'mode' => $mode,
            'updated' => $updated,
            'snapshot_key' => $snapshotKey,
        ]);
    }

    // Live connector web/Discogs sales whose stored time is off from their
    // insert time by >= 2h (the UTC shift). import_source IS NULL keeps the
    // daily-command placeholder rows (real order dates) out of scope.
    private function badRowQuery($businessId)
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->whereNull('import_source')
            ->where(function ($q) {
                $q->where('additional_notes', 'like', 'Website order%')
                  ->orWhere('additional_notes', 'like', 'Discogs order%');
            })
            ->whereRaw('ABS(TIMESTAMPDIFF(MINUTE, transaction_date, created_at)) >= ' . (int) self::MIN_DIFF_MINUTES);
    }
}
