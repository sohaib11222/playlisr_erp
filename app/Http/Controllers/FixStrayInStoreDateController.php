<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-shot helper: find any In Store-tagged transactions whose date is still
// > 2025-12-31 (i.e. leftover from the bulk fixes) and let Sarah edit the
// date directly. Pulls the matching row's artist/title/amount/ext_id from
// transaction_sell_lines + transactions so we can see what it is before
// picking the right date. Snapshots BEFORE state for undo.
class FixStrayInStoreDateController extends Controller
{
    const IMPORT_SOURCE = 'nivessa_backend_sales_in_store_new_used_sales';
    const CUTOFF        = '2025-12-31';

    public function index()
    {
        return view('admin.fix_stray_in_store_date', [
            'rows' => $this->findStrays(),
            'mode' => null,
            'snapshot_key' => null,
            'updated' => 0,
        ]);
    }

    public function run(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $now = now();

        // Map of tx_id => 'YYYY-MM-DD' from the form
        $dates = $request->input('date', []);
        if (!is_array($dates)) $dates = [];

        $valid = [];
        foreach ($dates as $txId => $newDate) {
            $newDate = trim((string) $newDate);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) continue;
            $valid[(int) $txId] = $newDate;
        }

        if (empty($valid)) {
            return redirect('/admin/fix-stray-in-store-date')
                ->with('status', 'No valid dates submitted (expecting YYYY-MM-DD per row).');
        }

        // Snapshot BEFORE
        $beforeRows = DB::table('transactions')
            ->whereIn('id', array_keys($valid))
            ->where('business_id', $businessId)
            ->where('import_source', self::IMPORT_SOURCE)
            ->get(['id', 'import_source', 'transaction_date']);

        $snapshotRows = $beforeRows->map(function ($r) {
            return [
                'id' => $r->id,
                'import_source' => $r->import_source,
                'transaction_date' => (string) $r->transaction_date,
            ];
        })->all();

        $snapshotKey = 'fix-in-store-sold-dates-' . $now->format('Y-m-d_His');
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp' => $now->toDateTimeString(),
                'action' => 'fix-in-store-sold-dates',
                'business_id' => $businessId,
                'rows' => $snapshotRows,
            ], JSON_PRETTY_PRINT)
        );

        $updated = 0;
        foreach ($valid as $txId => $newDate) {
            $count = DB::table('transactions')
                ->where('id', $txId)
                ->where('business_id', $businessId)
                ->where('import_source', self::IMPORT_SOURCE)
                ->update([
                    'transaction_date' => $newDate . ' 12:00:00',
                    'updated_at' => $now,
                ]);
            $updated += $count;
        }

        return view('admin.fix_stray_in_store_date', [
            'rows' => $this->findStrays(),
            'mode' => 'commit',
            'snapshot_key' => $snapshotKey,
            'updated' => $updated,
        ]);
    }

    private function findStrays()
    {
        $businessId = request()->session()->get('user.business_id');

        return DB::table('transactions as t')
            ->leftJoin('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $businessId)
            ->where('t.import_source', self::IMPORT_SOURCE)
            ->where('t.transaction_date', '>', self::CUTOFF . ' 23:59:59')
            ->select(
                't.id', 't.import_external_id', 't.transaction_date', 't.final_total',
                'tsl.legacy_artist', 'tsl.legacy_title', 'tsl.legacy_format', 'tsl.legacy_genre'
            )
            ->orderBy('t.id')
            ->get();
    }
}
