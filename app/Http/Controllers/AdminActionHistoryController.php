<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Lists snapshots taken before destructive admin backfills run, with an Undo
// button per snapshot that restores the BEFORE state row-by-row.
//
// Born after the 2026-04-27 purchase-price-mismatch wipe — every admin /run
// action that mutates rows in bulk should now write a JSON snapshot to
// storage/admin-snapshots/ first so we can roll back on demand.
class AdminActionHistoryController extends Controller
{
    public function index()
    {
        $files = collect(Storage::disk('local')->files('admin-snapshots'))
            ->filter(function ($f) { return str_ends_with($f, '.json'); })
            ->sort()
            ->reverse()
            ->take(200)
            ->values();

        $snapshots = [];
        foreach ($files as $f) {
            $raw = Storage::disk('local')->get($f);
            $data = json_decode($raw, true);
            if (!$data) continue;

            $key = pathinfo($f, PATHINFO_FILENAME);
            $snapshots[] = (object) [
                'key' => $key,
                'timestamp' => $data['timestamp'] ?? null,
                'action' => $data['action'] ?? '?',
                'direction' => $data['direction'] ?? null,
                'rows_count' => isset($data['rows']) ? count($data['rows']) : 0,
            ];
        }

        return view('admin.admin_action_history', ['snapshots' => $snapshots]);
    }

    public function undo(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $key = preg_replace('/[^A-Za-z0-9_\-]/', '', $request->input('key', ''));
        if ($key === '') {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Missing snapshot key.']);
        }

        $path = "admin-snapshots/{$key}.json";
        if (!Storage::disk('local')->exists($path)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot not found.']);
        }

        $data = json_decode(Storage::disk('local')->get($path), true);
        if (!$data || empty($data['rows'])) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot empty / unreadable.']);
        }

        $action = $data['action'] ?? '';

        // Variation-cost actions: snapshot rows hold variation id + the two
        // cost columns to restore. Both purchase-price-mismatch and
        // cost-price-rules use the same row schema.
        // future-product-dates: products id + the two timestamp columns.
        $supportedActions = ['purchase-price-mismatch', 'cost-price-rules', 'future-product-dates'];
        if (!in_array($action, $supportedActions, true)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => "Don't know how to undo action: " . $action]);
        }

        $restored = 0;
        foreach (array_chunk($data['rows'], 500) as $chunk) {
            foreach ($chunk as $row) {
                if ($action === 'future-product-dates') {
                    DB::table('products')
                        ->where('id', $row['id'])
                        ->update([
                            'created_at' => $row['created_at'] ?: null,
                            'updated_at' => $row['updated_at'] ?: null,
                        ]);
                } else {
                    DB::table('variations')
                        ->where('id', $row['id'])
                        ->update([
                            'default_purchase_price' => $row['default_purchase_price'],
                            'dpp_inc_tax'            => $row['dpp_inc_tax'],
                            'updated_at'             => now(),
                        ]);
                }
                $restored++;
            }
        }

        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => "Restored $restored rows from snapshot $key."]);
    }
}
