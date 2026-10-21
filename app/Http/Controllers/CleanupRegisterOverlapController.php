<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// User-authorized cleanup: for the 4 prior-year months whose parked sheet was
// imported, the small pre-existing (live POS) register sales overlap the sheet
// (register was brand-new then; the spreadsheet was the complete log). This
// sets each month's baseline to the spreadsheet total by removing those register
// sells. Full rows (transactions + sell_lines + payments) are snapshotted first,
// so it is ONE-CLICK RESTORABLE at /admin/admin-action-history (action
// 'remove-register-overlap'). Scoped to 4 old store/months — no live POS impact.
class CleanupRegisterOverlapController extends Controller
{
    const CELLS = [
        ['hollywood', '2024-11', 'Hollywood - Nov 2024'],
        ['hollywood', '2025-01', 'Hollywood - Jan 2025'],
        ['pico',      '2025-04', 'Pico - Apr 2025'],
        ['pico',      '2025-05', 'Pico - May 2025'],
    ];

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $cells = [];
        foreach (self::CELLS as [$needle, $month, $label]) {
            $ids = $this->registerIds($businessId, $needle, $month);
            $sum = empty($ids) ? 0 : (float) DB::table('transactions')->whereIn('id', $ids)->sum('final_total');
            $cells[] = ['label' => $label, 'count' => count($ids), 'sum' => $sum];
        }
        return view('admin.cleanup_register_overlap', ['cells' => $cells]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        $businessId = $request->session()->get('user.business_id');

        $allIds = [];
        foreach (self::CELLS as [$needle, $month, $label]) {
            $allIds = array_merge($allIds, $this->registerIds($businessId, $needle, $month));
        }
        $allIds = array_values(array_unique($allIds));
        if (empty($allIds)) {
            return redirect('/admin/cleanup-register-overlap')
                ->with('status', ['success' => 1, 'msg' => 'Nothing to clean — no register overlap found (already cleaned?).']);
        }

        // Snapshot FULL rows so the removal is fully restorable.
        $txns  = DB::table('transactions')->whereIn('id', $allIds)->get()->map(fn($r) => (array) $r)->all();
        $lines = DB::table('transaction_sell_lines')->whereIn('transaction_id', $allIds)->get()->map(fn($r) => (array) $r)->all();
        $pays  = DB::table('transaction_payments')->whereIn('transaction_id', $allIds)->get()->map(fn($r) => (array) $r)->all();

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "remove-register-overlap-{$timestamp}";
        Storage::disk('local')->put("admin-snapshots/{$snapshotKey}.json", json_encode([
            'timestamp' => $timestamp, 'action' => 'remove-register-overlap',
            'user_id' => auth()->id(), 'business_id' => $businessId,
            'transactions' => $txns, 'sell_lines' => $lines, 'payments' => $pays,
            'rows' => array_map(fn($id) => ['id' => $id], $allIds),
        ], JSON_PRETTY_PRINT));

        $removedVal = (float) DB::table('transactions')->whereIn('id', $allIds)->sum('final_total');
        DB::table('transaction_payments')->whereIn('transaction_id', $allIds)->delete();
        DB::table('transaction_sell_lines')->whereIn('transaction_id', $allIds)->delete();
        $n = DB::table('transactions')->whereIn('id', $allIds)->delete();

        return redirect('/admin/cleanup-register-overlap')->with('status', [
            'success' => 1,
            'msg' => "Removed {$n} overlapping register sale(s) (\$" . number_format($removedVal) . ") from the 4 baseline months. They now match the spreadsheet. Snapshot: {$snapshotKey} (undo at /admin/admin-action-history).",
        ]);
    }

    protected function registerIds($businessId, $needle, $month): array
    {
        $loc = DB::table('business_locations')->where('business_id', $businessId)
            ->where('name', 'like', '%' . $needle . '%')->orderBy('id')->first(['id']);
        if (!$loc) { return []; }
        return DB::table('transactions')
            ->where('business_id', $businessId)->where('location_id', $loc->id)
            ->where('type', 'sell')->where('status', 'final')->whereNull('import_source')
            ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
            ->whereRaw("DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$month])
            ->pluck('id')->map(fn($v) => (int) $v)->all();
    }
}
