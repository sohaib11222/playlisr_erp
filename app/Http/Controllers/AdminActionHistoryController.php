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
        // fix-imported-dates: transactions id + transaction_date to restore.
        // fix-in-store-sold-dates: same row schema as fix-imported-dates.
        // bfc-receive: rows hold product_id, variation_id, purchase_line_id,
        // location_id, quantity. Undo decrements VLD, deletes the purchase
        // line, marks the auto-created product inactive, and flips the
        // linked transaction back to draft. Skips any line that's already
        // had stock sold against it.
        $supportedActions = ['purchase-price-mismatch', 'cost-price-rules', 'future-product-dates', 'fix-imported-dates', 'fix-in-store-sold-dates', 'bfc-receive', 'qb-expense-import'];
        if (!in_array($action, $supportedActions, true)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => "Don't know how to undo action: " . $action]);
        }

        if ($action === 'bfc-receive') {
            return $this->undoBfcReceive($data, $key);
        }

        // qb-expense-import: snapshot rows hold inserted transaction IDs.
        // Undo deletes them outright (no payment/line items to worry about).
        if ($action === 'qb-expense-import') {
            $ids = array_filter(array_map(function ($r) { return $r['id'] ?? null; }, $data['rows']));
            $deleted = DB::table('transactions')
                ->whereIn('id', $ids)
                ->whereIn('type', ['expense', 'expense_refund'])
                ->delete();
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Deleted $deleted imported expense row(s) from snapshot $key."]);
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
                } elseif ($action === 'fix-imported-dates' || $action === 'fix-in-store-sold-dates') {
                    DB::table('transactions')
                        ->where('id', $row['id'])
                        ->update([
                            'transaction_date' => $row['transaction_date'],
                            'updated_at'       => now(),
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

    // Undo a "Buy from customer" receive. Per-line: skip if already sold,
    // otherwise drop stock back to 0, delete the purchase_line, and mark
    // the auto-created product inactive so it stops showing up in product
    // listings (we don't hard-delete in case audit trails reference it).
    // After all lines: flip the transaction back to draft.
    protected function undoBfcReceive(array $data, $key)
    {
        $reverted = 0;
        $skippedSold = 0;
        foreach ($data['rows'] as $row) {
            $purchaseLineId = $row['purchase_line_id'] ?? null;
            if (!$purchaseLineId) {
                continue;
            }
            $pl = DB::table('purchase_lines')->where('id', $purchaseLineId)->first();
            if (!$pl) {
                continue; // already gone
            }
            // Soft-warn skip — staff already sold (some of) this stock.
            // Don't touch it; reverting would leave a sale record pointing
            // at a deleted purchase line.
            if (((float) $pl->quantity_sold) > 0) {
                $skippedSold++;
                continue;
            }

            // Only decrement VLD if accept actually bumped stock. New snapshots
            // include 'stock_bumped' = false (purchase is created as draft, so
            // qty_available stays 0 until staff finalize). Old snapshots from
            // before this flag was added defaulted to bumping, so absence of
            // the flag means "yes, decrement".
            $stockBumped = array_key_exists('stock_bumped', $row) ? (bool) $row['stock_bumped'] : true;
            $qty = (float) ($row['quantity'] ?? 0);
            if ($stockBumped && $qty > 0 && !empty($row['variation_id']) && !empty($row['location_id'])) {
                DB::table('variation_location_details')
                    ->where('variation_id', $row['variation_id'])
                    ->where('location_id', $row['location_id'])
                    ->decrement('qty_available', $qty);
            }

            DB::table('purchase_lines')->where('id', $purchaseLineId)->delete();

            if (!empty($row['product_id'])) {
                DB::table('products')
                    ->where('id', $row['product_id'])
                    ->update(['is_inactive' => 1, 'not_for_selling' => 1, 'updated_at' => now()]);
            }
            // Clear the BFC line's refs so re-accept (if it ever happens) is clean
            DB::table('buy_customer_offer_lines')
                ->where('id', $row['offer_line_id'] ?? 0)
                ->update(['purchase_line_id' => null]);

            $reverted++;
        }

        // Flip transaction back to draft so future inventory math doesn't
        // double-count it. Don't delete it — it's the audit trail of the BFC.
        if (!empty($data['transaction_id'])) {
            DB::table('transactions')
                ->where('id', $data['transaction_id'])
                ->update(['status' => 'draft', 'payment_status' => 'due', 'updated_at' => now()]);
        }

        $msg = "Reverted $reverted BFC line(s)";
        if ($skippedSold > 0) {
            $msg .= "; skipped $skippedSold line(s) that already had stock sold (cannot safely revert).";
        } else {
            $msg .= " from snapshot $key.";
        }
        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
