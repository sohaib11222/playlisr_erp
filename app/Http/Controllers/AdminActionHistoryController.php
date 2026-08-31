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
        // Take the 200 MOST RECENT files. Sorting by filename would order by the
        // action-name prefix (so "backfill-*" sorts below "product-*"/"recategorize-*"
        // and gets cut off by the cap) — sort by the timestamp embedded in the
        // name instead so newest actions always show regardless of action name.
        $files = collect(Storage::disk('local')->files('admin-snapshots'))
            ->filter(function ($f) { return str_ends_with($f, '.json'); })
            ->sortByDesc(function ($f) {
                preg_match('/(\d{4}-\d{2}-\d{2}_\d{6})/', $f, $m);
                return $m[1] ?? '';
            })
            ->take(200)
            ->values();

        $snapshots = [];
        foreach ($files as $f) {
            $raw = Storage::disk('local')->get($f);
            $data = json_decode($raw, true);
            if (!$data) continue;

            $key = pathinfo($f, PATHINFO_FILENAME);

            // Human-readable detail per action (so e.g. category merges are
            // identifiable at a glance instead of just a row count).
            $detail = $data['direction'] ?? null;
            if (in_array(($data['action'] ?? ''), ['merge-categories', 'merge-products', 'merge-products-bulk', 'product-name-cleanup', 'backfill-artist-from-name'], true)) {
                $detail = ($data['source_name'] ?? '?') . ' → ' . ($data['target_name'] ?? '?');
            }

            $snapshots[] = (object) [
                'key' => $key,
                'timestamp' => $data['timestamp'] ?? null,
                'action' => $data['action'] ?? '?',
                'direction' => $data['direction'] ?? null,
                'detail' => $detail,
                'rows_count' => isset($data['rows']) ? count($data['rows']) : 0,
            ];
        }

        // True newest-first. (Filenames sort by action prefix, not time, which
        // scrambles the chronology — sort by the embedded timestamp instead.)
        usort($snapshots, function ($a, $b) {
            return strcmp((string) ($b->timestamp ?? ''), (string) ($a->timestamp ?? ''));
        });

        return view('admin.admin_action_history', ['snapshots' => $snapshots]);
    }

    /**
     * Show the actual row-by-row changes in one snapshot (product name, before ->
     * after), so an admin can see exactly what a backfill did — e.g. which artists
     * the Discogs fill wrote.
     */
    public function show($key)
    {
        $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
        $path = "admin-snapshots/{$key}.json";
        if ($key === '' || !Storage::disk('local')->exists($path)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot not found.']);
        }
        $data = json_decode(Storage::disk('local')->get($path), true) ?: [];
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];

        // Resolve product names for rows that carry a product id.
        $ids = [];
        foreach ($rows as $r) { if (isset($r['id'])) { $ids[] = (int) $r['id']; } }
        $names = empty($ids) ? [] : DB::table('products')->whereIn('id', $ids)->pluck('name', 'id')->toArray();

        $view = [];
        foreach ($rows as $r) {
            $view[] = [
                'id' => $r['id'] ?? null,
                'name' => isset($r['id']) ? ($names[(int) $r['id']] ?? '(deleted product)') : null,
                'old' => $r['old'] ?? null,
                'new' => $r['new'] ?? null,
            ];
        }

        return view('admin.admin_action_history_detail', [
            'key' => $key,
            'action' => $data['action'] ?? '?',
            'timestamp' => $data['timestamp'] ?? null,
            'detail' => ($data['source_name'] ?? null),
            'rows' => $view,
        ]);
    }

    /**
     * One combined page of EVERY artist backfill row (Product, Before, After,
     * source) across all backfill-artist-from-name snapshots — so the whole
     * Discogs artist fill can be reviewed at once instead of batch-by-batch.
     */
    public function artistFills()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $files = collect(Storage::disk('local')->files('admin-snapshots'))
            ->filter(function ($f) {
                return str_ends_with($f, '.json') && str_contains($f, 'backfill-artist-from-name');
            })
            ->sortByDesc(function ($f) {
                preg_match('/(\d{4}-\d{2}-\d{2}_\d{6})/', $f, $m);
                return $m[1] ?? '';
            })
            ->values();

        $rows = [];
        $cap = 8000; // plenty to review; guards the page from an unbounded catalog
        foreach ($files as $f) {
            if (count($rows) >= $cap) { break; }
            $data = json_decode(Storage::disk('local')->get($f), true);
            if (!$data || empty($data['rows']) || !is_array($data['rows'])) { continue; }
            $source = str_contains(strtolower((string) ($data['source_name'] ?? '')), 'discogs') ? 'Discogs' : 'Name';
            $ts = $data['timestamp'] ?? null;
            foreach ($data['rows'] as $r) {
                if (!isset($r['id'])) { continue; }
                $rows[] = ['id' => (int) $r['id'], 'old' => $r['old'] ?? null, 'new' => $r['new'] ?? null, 'source' => $source, 'ts' => $ts];
                if (count($rows) >= $cap) { break; }
            }
        }

        $ids = array_column($rows, 'id');
        $names = empty($ids) ? [] : DB::table('products')->whereIn('id', $ids)->pluck('name', 'id')->toArray();
        foreach ($rows as &$r) { $r['name'] = $names[$r['id']] ?? '(deleted product)'; }
        unset($r);

        return view('admin.admin_action_artist_fills', [
            'rows' => $rows,
            'capped' => count($rows) >= $cap,
            'cap' => $cap,
        ]);
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
        if (!$data) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot empty / unreadable.']);
        }

        $action = $data['action'] ?? '';

        // merge-categories can legitimately have zero product rows (merging an
        // empty category), so it's dispatched before the empty-rows guard.
        if ($action === 'merge-categories') {
            return $this->undoMergeCategories($data, $key);
        }

        // merge-products: reverse a duplicate-product merge (below the empty-rows
        // guard would be wrong — a merge with zero prior sales is still valid).
        if ($action === 'merge-products') {
            return $this->undoMergeProducts($data, $key);
        }

        // merge-products-bulk: reverse a whole batch of merges as a unit.
        if ($action === 'merge-products-bulk') {
            return $this->undoMergeProductsBulk($data, $key);
        }

        // product-name-cleanup: restore each product's previous name.
        if ($action === 'product-name-cleanup') {
            return $this->undoProductNameCleanup($data, $key);
        }

        // backfill-artist-from-name: restore each product's previous artist value.
        if ($action === 'backfill-artist-from-name') {
            return $this->undoBackfillArtist($data, $key);
        }

        // ams-invoice-import: snapshot holds the purchase transaction id created
        // from an AMS PDF (no 'rows'). These are logged at status=ordered (never
        // received), so there's no stock to reverse — undo just deletes the
        // purchase lines then the transaction, scoped to type=purchase, and
        // clears the invoice's already-imported sidecar so it can be re-imported.
        // Skips if the purchase is already gone, and refuses if it was somehow
        // received since (stock would be involved — delete it from Purchases).
        if ($action === 'ams-invoice-import') {
            $txId = $data['transaction_id'] ?? null;
            if (!$txId) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Snapshot missing transaction id.']);
            }
            $purchase = DB::table('transactions')->where('id', $txId)->where('type', 'purchase')->first();
            if (!$purchase) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => "Purchase #{$txId} already gone — nothing to undo."]);
            }
            if (($purchase->status ?? '') === 'received') {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => "Purchase #{$txId} was received (stock involved) — delete it from the Purchases page instead."]);
            }
            DB::beginTransaction();
            try {
                $lines = DB::table('purchase_lines')->where('transaction_id', $txId)->delete();
                DB::table('transactions')->where('id', $txId)->where('type', 'purchase')->delete();
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Undo failed, nothing changed: ' . $e->getMessage()]);
            }
            $invoice = $data['invoice'] ?? '';
            if ($invoice !== '' && Storage::disk('local')->exists("ams-imports/{$invoice}.json")) {
                Storage::disk('local')->delete("ams-imports/{$invoice}.json");
            }
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Deleted purchase #{$txId} + {$lines} line(s) from AMS import (snapshot {$key})."]);
        }

        if (empty($data['rows'])) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot empty / unreadable.']);
        }

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
        // reassign-user-created-by: rows hold {table, id, created_by} — the
        // original owner before a wrong-login reassignment. Undo restores each
        // row's created_by, but only if it's still pointing at the to-user
        // (so a later manual change isn't clobbered).
        // remove-label-duplicates: rows hold the FULL deleted activity_log rows;
        // undo re-inserts them verbatim (skips any id that already exists).
        // merge-categories: rows hold {id, category_id, sub_category_id} (the
        // product's BEFORE category refs); children hold {id, parent_id} for
        // sub-categories that were reparented; source_id/target_id name the two
        // categories. Undo un-soft-deletes the source, reverts each product's
        // refs (only where they still point at the target), and reparents the
        // children back.
        // reassign-register-user: rows hold {id, user_id} — the cash_registers
        // row's original owner before a wrong-login reassignment. Undo restores
        // user_id, but only if it still points at the to-user (so a later manual
        // change isn't clobbered).
        $supportedActions = ['purchase-price-mismatch', 'cost-price-rules', 'future-product-dates', 'fix-imported-dates', 'fix-in-store-sold-dates', 'fix-web-sync-times', 'bfc-receive', 'qb-expense-import', 'whatnot-statement-import', 'force-close-register', 'delete-register', 'reassign-register-user', 'backfill-cash-buys', 'update-product-cost', 'apply-legacy-store-credit', 'reassign-user-created-by', 'remove-label-duplicates', 'ring-backfill', 'merge-categories', 'merge-products', 'merge-products-bulk', 'product-name-cleanup', 'backfill-artist-from-name', 'events-update', 'events-delete', 'events-import', 'reassign-import-location', 'nivessa-sheet-import', 'remove-register-overlap', 'recategorize-audio-gear', 'zero-retired-stock', 'zero-bootleg-stock'];
        if (!in_array($action, $supportedActions, true)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => "Don't know how to undo action: " . $action]);
        }

        // events-*: rows hold the FULL events JSON store as it was BEFORE the
        // edit/delete/import. Undo writes it back over the sidecar verbatim.
        if (in_array($action, ['events-update', 'events-delete', 'events-import'], true)) {
            $businessId = $data['business_id'] ?? null;
            if (!$businessId) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Snapshot missing business id.']);
            }
            \App\Http\Controllers\EventsController::save((int) $businessId, $data['rows']);
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Restored events from snapshot {$key}."]);
        }

        if ($action === 'bfc-receive') {
            return $this->undoBfcReceive($data, $key);
        }

        if ($action === 'apply-legacy-store-credit') {
            return $this->undoApplyLegacyStoreCredit($data, $key);
        }

        // ring-backfill: a single re-rung sale. Undo voids it via the normal
        // deleteSale path, which restores stock and removes the transaction
        // (re-arming the back-fill tool's idempotency guard).
        if ($action === 'ring-backfill') {
            $txId = $data['transaction_id'] ?? null;
            $businessId = $data['business_id'] ?? null;
            if (!$txId || !$businessId) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Snapshot missing transaction/business id.']);
            }
            if (!DB::table('transactions')->where('id', $txId)->exists()) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => "Sale #{$txId} already gone — nothing to undo."]);
            }
            DB::beginTransaction();
            try {
                $res = app(\App\Utils\TransactionUtil::class)->deleteSale($businessId, $txId);
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                \Log::emergency('ring-backfill undo failed: ' . $e->getMessage());
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Undo failed, nothing changed: ' . $e->getMessage()]);
            }
            $ok = is_array($res) ? !empty($res['success']) : true;
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => $ok ? 1 : 0, 'msg' => $ok
                    ? "Voided sale #{$txId} and restored stock (snapshot {$key})."
                    : ($res['msg'] ?? 'Could not void the sale.')]);
        }

        if ($action === 'remove-label-duplicates') {
            $restored = 0;
            $skipped = 0;
            foreach ($data['rows'] as $row) {
                $id = $row['id'] ?? null;
                if (!$id) { continue; }
                if (DB::table('activity_log')->where('id', $id)->exists()) { $skipped++; continue; }
                DB::table('activity_log')->insert($row);
                $restored++;
            }
            $msg = "Re-inserted {$restored} removed label run(s) from snapshot {$key}";
            $msg .= $skipped > 0 ? "; skipped {$skipped} already present." : '.';
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => $msg]);
        }

        if ($action === 'reassign-user-created-by') {
            $toUserId = $data['to_user_id'] ?? null;
            $restored = 0;
            $skipped = 0;
            // Rows: {table, id, column, value}. Older snapshots used a bare
            // 'created_by' key with no column/value — fall back to that.
            $allowedTables = ['transactions' => 'created_by', 'products' => 'created_by', 'activity_log' => 'causer_id'];
            foreach ($data['rows'] as $row) {
                $table = $row['table'] ?? null;
                $id = $row['id'] ?? null;
                if (!isset($allowedTables[$table]) || !$id) { continue; }
                $column = $row['column'] ?? $allowedTables[$table];
                $oldVal = array_key_exists('value', $row) ? $row['value'] : ($row['created_by'] ?? null);
                $current = DB::table($table)->where('id', $id)->first();
                // Only revert if the row is still owned by the user we moved it
                // to — otherwise it's been hand-edited since; leave it alone.
                if (!$current || ($toUserId !== null && (int) $current->{$column} !== (int) $toUserId)) {
                    $skipped++;
                    continue;
                }
                $update = [$column => $oldVal];
                if (\Schema::hasColumn($table, 'updated_at')) { $update['updated_at'] = now(); }
                DB::table($table)->where('id', $id)->update($update);
                $restored++;
            }
            $msg = "Reverted {$restored} row(s) to their original owner from snapshot {$key}";
            $msg .= $skipped > 0 ? "; skipped {$skipped} changed since." : '.';
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => $msg]);
        }

        // delete-register: snapshot holds the full cash_registers row +
        // every cash_register_transactions row that was attached. Undo
        // re-inserts both (skips if a row with the same id already exists,
        // which would mean the register was somehow recreated since).
        if ($action === 'delete-register') {
            $reg = $data['register'] ?? null;
            $txns = $data['transactions'] ?? [];
            if (!$reg || empty($reg['id'])) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Snapshot missing register row.']);
            }
            $exists = DB::table('cash_registers')->where('id', $reg['id'])->exists();
            if ($exists) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => "Register #{$reg['id']} already exists — cannot restore."]);
            }
            DB::table('cash_registers')->insert($reg);
            $restoredTxns = 0;
            foreach ($txns as $t) {
                if (empty($t['id'])) continue;
                if (DB::table('cash_register_transactions')->where('id', $t['id'])->exists()) continue;
                DB::table('cash_register_transactions')->insert($t);
                $restoredTxns++;
            }
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Restored register #{$reg['id']} + {$restoredTxns} transaction row(s) from snapshot $key."]);
        }

        // remove-register-overlap: snapshot holds the FULL transactions +
        // sell_lines + payments rows that were deleted. Undo re-inserts them
        // verbatim (skips any id that already exists).
        if ($action === 'remove-register-overlap') {
            $tx = 0; $ln = 0; $pm = 0;
            foreach (($data['transactions'] ?? []) as $row) {
                if (empty($row['id']) || DB::table('transactions')->where('id', $row['id'])->exists()) { continue; }
                DB::table('transactions')->insert($row); $tx++;
            }
            foreach (($data['sell_lines'] ?? []) as $row) {
                if (empty($row['id']) || DB::table('transaction_sell_lines')->where('id', $row['id'])->exists()) { continue; }
                DB::table('transaction_sell_lines')->insert($row); $ln++;
            }
            foreach (($data['payments'] ?? []) as $row) {
                if (empty($row['id']) || DB::table('transaction_payments')->where('id', $row['id'])->exists()) { continue; }
                DB::table('transaction_payments')->insert($row); $pm++;
            }
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Restored {$tx} register sale(s), {$ln} line(s), {$pm} payment(s) from snapshot {$key}."]);
        }

        // nivessa-sheet-import: snapshot rows hold inserted transaction ids for a
        // re-imported historical sheet (e.g. Hollywood Aug 2024). Undo deletes
        // the sell lines then the transactions, scoped to type=sell + the
        // batch's import_source so nothing else is touched.
        if ($action === 'nivessa-sheet-import') {
            $ids = array_filter(array_map(function ($r) { return $r['id'] ?? null; }, $data['rows']));
            $src = $data['import_source'] ?? null;
            if (empty($ids) || !$src) {
                return redirect('/admin/admin-action-history')
                    ->with('status', ['success' => 0, 'msg' => 'Snapshot missing ids or import_source.']);
            }
            $lines = 0; $txns = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $lines += DB::table('transaction_sell_lines')
                    ->whereIn('transaction_id', $chunk)
                    ->where('import_source', $src)
                    ->delete();
                $txns += DB::table('transactions')
                    ->whereIn('id', $chunk)
                    ->where('type', 'sell')
                    ->where('import_source', $src)
                    ->delete();
            }
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Deleted {$txns} imported sale(s) + {$lines} line(s) from snapshot {$key}."]);
        }

        // reassign-import-location: snapshot rows hold {id, location_id} — the
        // store a finalized in-store import sale sat on before it was moved
        // (Hollywood). Undo restores location_id, but only if the row is still
        // on the to-location (Pico), so a later manual change isn't clobbered.
        // recategorize-audio-gear: snapshot rows hold {id, category_id,
        // sub_category_id} — each product's BEFORE category refs. Undo restores
        // them, but only for products still sitting in the Audio Gear category we
        // moved them to (so a later manual re-category isn't clobbered).
        if ($action === 'recategorize-audio-gear') {
            $audioGearId = $data['audio_gear_id'] ?? null;
            $restored = 0;
            $skipped = 0;
            foreach ($data['rows'] as $row) {
                $id = $row['id'] ?? null;
                if (!$id) { continue; }
                $current = DB::table('products')->where('id', $id)->first();
                if (!$current || ($audioGearId !== null && (int) $current->category_id !== (int) $audioGearId)) {
                    $skipped++;
                    continue;
                }
                DB::table('products')->where('id', $id)->update([
                    'category_id'     => $row['category_id'],
                    'sub_category_id' => $row['sub_category_id'],
                ]);
                $restored++;
            }
            $msg = "Reverted {$restored} product(s) out of Audio Gear from snapshot {$key}";
            $msg .= $skipped > 0 ? "; skipped {$skipped} changed since." : '.';
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => $msg]);
        }

        if ($action === 'reassign-import-location') {
            $toLocId = $data['to_location_id'] ?? null;
            $restored = 0;
            $skipped = 0;
            foreach ($data['rows'] as $row) {
                $id = $row['id'] ?? null;
                if (!$id) { continue; }
                $current = DB::table('transactions')->where('id', $id)->first();
                if (!$current || ($toLocId !== null && (int) $current->location_id !== (int) $toLocId)) {
                    $skipped++;
                    continue;
                }
                DB::table('transactions')
                    ->where('id', $id)
                    ->update(['location_id' => $row['location_id'], 'updated_at' => now()]);
                $restored++;
            }
            $msg = "Moved {$restored} sale(s) back to their original store from snapshot {$key}";
            $msg .= $skipped > 0 ? "; skipped {$skipped} changed since." : '.';
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => $msg]);
        }

        // reassign-register-user: snapshot rows hold {id, user_id} — the
        // register's original owner. Undo restores user_id, but only if the
        // register is still owned by the user we moved it to (otherwise it's
        // been reassigned again since; leave it alone).
        if ($action === 'reassign-register-user') {
            $toUserId = $data['to_user_id'] ?? null;
            $restored = 0;
            $skipped = 0;
            foreach ($data['rows'] as $row) {
                $id = $row['id'] ?? null;
                if (!$id) { continue; }
                $current = DB::table('cash_registers')->where('id', $id)->first();
                if (!$current || ($toUserId !== null && (int) $current->user_id !== (int) $toUserId)) {
                    $skipped++;
                    continue;
                }
                DB::table('cash_registers')
                    ->where('id', $id)
                    ->update(['user_id' => $row['user_id'], 'updated_at' => now()]);
                $restored++;
            }
            $msg = "Reverted {$restored} register(s) to their original cashier from snapshot {$key}";
            $msg .= $skipped > 0 ? "; skipped {$skipped} reassigned again since." : '.';
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => $msg]);
        }

        // force-close-register: snapshot rows hold the full cash_registers
        // row as it was BEFORE the force-close. Undo restores status='open'
        // and clears the close fields. We don't touch rows that have since
        // been closed again with a real closing count by a real cashier —
        // those are detected by closing_note NOT containing the snapshot key.
        if ($action === 'force-close-register') {
            $restored = 0;
            $skipped = 0;
            foreach ($data['rows'] as $row) {
                $id = $row['id'] ?? null;
                if (!$id) continue;
                $current = DB::table('cash_registers')->where('id', $id)->first();
                if (!$current) { $skipped++; continue; }
                // Only undo if this row still bears our force-close note
                // (i.e. the cashier hasn't since re-closed it for real).
                $note = $current->closing_note ?? '';
                if (stripos($note, $key) === false) {
                    $skipped++;
                    continue;
                }
                DB::table('cash_registers')
                    ->where('id', $id)
                    ->update([
                        'status'         => 'open',
                        'closed_at'      => $row['closed_at'] ?? null,
                        'closing_amount' => $row['closing_amount'] ?? null,
                        'closing_note'   => $row['closing_note'] ?? null,
                        'updated_at'     => now(),
                    ]);
                $restored++;
            }
            $msg = "Reopened $restored register(s) from snapshot $key";
            if ($skipped > 0) {
                $msg .= "; skipped $skipped that had been re-closed for real since.";
            } else {
                $msg .= '.';
            }
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => $msg]);
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

        // backfill-cash-buys: snapshot rows = [{tx_id, offer_id, ...}].
        // Delete the offer row first (FK to transactions ON DELETE SET NULL
        // but we'd rather remove it outright), then the purchase txn.
        if ($action === 'backfill-cash-buys') {
            $offerIds = array_filter(array_map(function ($r) { return $r['offer_id'] ?? null; }, $data['rows']));
            $txIds    = array_filter(array_map(function ($r) { return $r['tx_id']    ?? null; }, $data['rows']));
            $offersDeleted = $offerIds ? DB::table('buy_customer_offers')->whereIn('id', $offerIds)->delete() : 0;
            $txnsDeleted = $txIds
                ? DB::table('transactions')->whereIn('id', $txIds)->where('type', 'purchase')->delete()
                : 0;
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Removed {$offersDeleted} offer(s) + {$txnsDeleted} purchase(s) from snapshot $key."]);
        }

        // whatnot-statement-import: snapshot rows = inserted transaction IDs
        // (one sell for revenue + one or more expense for fees). Same shape
        // as qb-expense-import — delete by id, scoped to sell/expense types
        // to avoid stomping anything that happened to share an id.
        if ($action === 'whatnot-statement-import') {
            $ids = array_filter(array_map(function ($r) { return $r['id'] ?? null; }, $data['rows']));
            $deleted = DB::table('transactions')
                ->whereIn('id', $ids)
                ->whereIn('type', ['sell', 'expense', 'expense_refund'])
                ->delete();
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Deleted $deleted Whatnot statement row(s) from snapshot $key."]);
        }

        // zero-retired-stock: rows hold {id, qty_available} for each
        // variation_location_details row that was zeroed by the Zero Stock
        // Rules tool. Undo restores each row's qty_available unconditionally
        // by row id — a later manual stock edit on the same row would be
        // clobbered, but this is a narrow, deliberately-run admin action.
        if ($action === 'zero-retired-stock' || $action === 'zero-bootleg-stock') {
            $restored = 0;
            foreach (array_chunk($data['rows'], 500) as $chunk) {
                foreach ($chunk as $row) {
                    $id = $row['id'] ?? null;
                    if (!$id) { continue; }
                    DB::table('variation_location_details')->where('id', $id)->update([
                        'qty_available' => $row['qty_available'],
                        'updated_at'    => now(),
                    ]);
                    $restored++;
                }
            }
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 1, 'msg' => "Restored stock on {$restored} variation row(s) from snapshot {$key}."]);
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
                } elseif ($action === 'fix-imported-dates' || $action === 'fix-in-store-sold-dates' || $action === 'fix-web-sync-times') {
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

    // Undo a legacy store-credit bulk apply. Restores each contact's BEFORE
    // balance + balance_notes, but only if our apply line (tagged with the
    // batch key) is still present — i.e. the credit hasn't been spent or
    // hand-edited since. Reverses the website sync with the negative delta so
    // both sides stay in step.
    protected function undoApplyLegacyStoreCredit(array $data, $key)
    {
        $restored = 0;
        $skipped = 0;
        foreach ($data['rows'] as $row) {
            $cid = $row['contact_id'] ?? null;
            if (!$cid) { continue; }
            $contact = DB::table('contacts')->where('id', $cid)->first();
            if (!$contact) { $skipped++; continue; }

            // Only undo if this batch's apply line is still on the contact.
            if (stripos((string) $contact->balance_notes, $key) === false) {
                $skipped++;
                continue;
            }

            DB::table('contacts')->where('id', $cid)->update([
                'balance'       => $row['balance'],
                'balance_notes' => $row['balance_notes'],
                'updated_at'    => now(),
            ]);

            // Reverse the website-side credit too.
            $delta = (float) ($row['applied_delta'] ?? 0);
            $email = $row['email'] ?? null;
            if ($email && abs($delta) >= 0.01) {
                app(\App\Services\NivessaBackendCreditSyncService::class)->syncDeltaByEmail(
                    (string) $email,
                    -$delta,
                    "Undo legacy store credit apply (batch {$key})",
                    ['contact_id' => (int) $cid, 'action' => 'undo-apply-legacy-store-credit', 'batch' => $key]
                );
            }
            $restored++;
        }

        $msg = "Reverted {$restored} legacy store-credit apply(s) from snapshot {$key}";
        $msg .= $skipped > 0
            ? "; skipped {$skipped} that were spent or edited since (left as-is)."
            : '.';
        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Undo a category merge. Un-soft-deletes the source category, reparents
    // its sub-categories back, and reverts each product's category refs — but
    // only where they still point at the merge target, so products moved
    // elsewhere since the merge are left alone.
    protected function undoMergeCategories(array $data, $key)
    {
        $sourceId = (int) ($data['source_id'] ?? 0);
        $targetId = (int) ($data['target_id'] ?? 0);
        if ($sourceId <= 0 || $targetId <= 0) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot missing source/target category.']);
        }

        // Restore the source category itself.
        $restoredCat = DB::table('categories')->where('id', $sourceId)->whereNotNull('deleted_at')->exists();
        DB::table('categories')->where('id', $sourceId)->update(['deleted_at' => null]);

        // Revert product refs to exactly what they were before the merge. We
        // restore unconditionally by product id (rather than guarding on "still
        // points at the target") so undo reliably reverses even after the
        // products' refs were rewritten — which is what tripped up the earlier
        // guarded version.
        $restored = 0;
        foreach (array_chunk($data['rows'] ?? [], 500) as $chunk) {
            foreach ($chunk as $row) {
                $pid = $row['id'] ?? null;
                if (!$pid) { continue; }
                DB::table('products')->where('id', $pid)->update([
                    'category_id'     => $row['category_id'],
                    'sub_category_id' => $row['sub_category_id'],
                ]);
                $restored++;
            }
        }

        // Reparent the source's sub-categories back to where they were.
        $reparented = 0;
        foreach ($data['children'] ?? [] as $c) {
            $cid = $c['id'] ?? null;
            if (!$cid) { continue; }
            DB::table('categories')->where('id', $cid)->update(['parent_id' => $c['parent_id']]);
            $reparented++;
        }

        $src = $data['source_name'] ?? ('#' . $sourceId);
        $tgt = $data['target_name'] ?? ('#' . $targetId);
        $msg = "Un-merged \"{$src}\" from \"{$tgt}\": ";
        $msg .= ($restoredCat ? 'restored the category, ' : 'category already present, ');
        $msg .= "moved {$restored} product(s) back";
        $msg .= $reparented > 0 ? ", reparented {$reparented} sub-categor(y/ies)." : '.';

        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Undo a duplicate-product merge (ProductMergeController@merge). Moves the
    // sales / purchase / adjustment lines back onto the source product and
    // variation, restores on-hand stock to exactly what each side held before,
    // and reactivates the source. Reverses by row id so it works even if the
    // numbers changed since the merge.
    protected function undoMergeProducts(array $data, $key)
    {
        if ((int) ($data['source_id'] ?? 0) <= 0 || (int) ($data['target_id'] ?? 0) <= 0 || (int) ($data['source_variation_id'] ?? 0) <= 0) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot missing source/target/variation.']);
        }

        DB::beginTransaction();
        try {
            $this->reverseOneMerge($data);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Undo failed, nothing changed: ' . $e->getMessage()]);
        }

        \Cache::forget('products_index_sold_totals:' . (int) ($data['business_id'] ?? 0));

        $src = $data['source_name'] ?? ('#' . (int) $data['source_id']);
        $tgt = $data['target_name'] ?? ('#' . (int) $data['target_id']);
        $moved = count($data['sell_line_ids'] ?? []);
        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => "Un-merged \"{$src}\" from \"{$tgt}\": moved {$moved} sale line(s) back, restored stock, reactivated the duplicate."]);
    }

    // Reverse a whole batch of merges (a merge-products-bulk snapshot). Each
    // merge is reversed newest-first inside one transaction so a failure rolls
    // the entire batch back.
    protected function undoMergeProductsBulk(array $data, $key)
    {
        $merges = $data['merges'] ?? [];
        if (empty($merges)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Batch snapshot has no merges to undo.']);
        }

        DB::beginTransaction();
        try {
            foreach (array_reverse($merges) as $m) {
                $this->reverseOneMerge($m);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Batch undo failed, nothing changed: ' . $e->getMessage()]);
        }

        \Cache::forget('products_index_sold_totals:' . (int) ($data['business_id'] ?? 0));
        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => 'Un-merged a batch of ' . count($merges) . ' duplicate(s): history moved back, stock restored, duplicates reactivated.']);
    }

    // Restore product names from a product-name-cleanup batch. Only reverts a
    // row if its name still equals what we set it to, so a manual edit since
    // the cleanup isn't clobbered.
    protected function undoProductNameCleanup(array $data, $key)
    {
        $rows = $data['rows'] ?? [];
        if (empty($rows)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot has no names to restore.']);
        }

        $restored = 0;
        $skipped = 0;
        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int) ($r['id'] ?? 0);
                if (!$id || !isset($r['old'], $r['new'])) { continue; }
                $affected = DB::table('products')->where('id', $id)->where('name', $r['new'])
                    ->update(['name' => $r['old']]);
                if ($affected) { $restored++; } else { $skipped++; }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Undo failed, nothing changed: ' . $e->getMessage()]);
        }

        $msg = "Restored {$restored} product name(s)";
        $msg .= $skipped > 0 ? ", left {$skipped} that were edited since." : '.';
        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Reverse an artist backfill: put each product's artist column back to what
    // it was (blank / "N/A"), but only where it still holds the value we filled,
    // so a later manual edit isn't clobbered.
    protected function undoBackfillArtist(array $data, $key)
    {
        $rows = $data['rows'] ?? [];
        if (empty($rows)) {
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Snapshot has no artists to restore.']);
        }

        $restored = 0;
        $skipped = 0;
        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int) ($r['id'] ?? 0);
                if (!$id || !array_key_exists('old', $r) || !array_key_exists('new', $r)) { continue; }
                $affected = DB::table('products')->where('id', $id)->where('artist', $r['new'])
                    ->update(['artist' => $r['old']]);
                if ($affected) { $restored++; } else { $skipped++; }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect('/admin/admin-action-history')
                ->with('status', ['success' => 0, 'msg' => 'Undo failed, nothing changed: ' . $e->getMessage()]);
        }

        $msg = "Restored {$restored} artist value(s)";
        $msg .= $skipped > 0 ? ", left {$skipped} that were edited since." : '.';
        return redirect('/admin/admin-action-history')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Reverse a single merge given its payload (from performMerge). Assumes it
    // runs inside a DB transaction managed by the caller.
    protected function reverseOneMerge(array $m)
    {
        $sourceId = (int) ($m['source_id'] ?? 0);
        $targetId = (int) ($m['target_id'] ?? 0);
        $sourceVarId = (int) ($m['source_variation_id'] ?? 0);
        if ($sourceId <= 0 || $targetId <= 0 || $sourceVarId <= 0) {
            throw new \RuntimeException('merge payload missing source/target/variation');
        }

        // 1) Move sales / purchase / adjustment lines back by their ids.
        foreach ([
            'transaction_sell_lines' => $m['sell_line_ids'] ?? [],
            'purchase_lines'         => $m['purchase_line_ids'] ?? [],
            'stock_adjustment_lines' => $m['adj_line_ids'] ?? [],
        ] as $table => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                if (empty($chunk)) { continue; }
                DB::table($table)->whereIn('id', $chunk)->update([
                    'product_id'   => $sourceId,
                    'variation_id' => $sourceVarId,
                ]);
            }
        }

        // 2) Restore stock: drop target rows we created, put target's pre-merge
        //    quantities back, recreate the source's stock rows.
        foreach (($m['created_target_vld_ids'] ?? []) as $vldId) {
            DB::table('variation_location_details')->where('id', (int) $vldId)->delete();
        }
        foreach (($m['target_vld_before'] ?? []) as $r) {
            DB::table('variation_location_details')->where('id', (int) $r['id'])
                ->update(['qty_available' => $r['qty_available']]);
        }
        $sourcePvId = (int) (DB::table('variations')->where('id', $sourceVarId)->value('product_variation_id') ?? 0);
        foreach (($m['source_vld'] ?? []) as $r) {
            $exists = DB::table('variation_location_details')->where('id', (int) $r['id'])->exists();
            if ($exists) {
                DB::table('variation_location_details')->where('id', (int) $r['id'])
                    ->update(['qty_available' => $r['qty_available']]);
            } else {
                DB::table('variation_location_details')->insert([
                    'id' => (int) $r['id'],
                    'product_id' => $sourceId,
                    'product_variation_id' => $sourcePvId,
                    'variation_id' => $sourceVarId,
                    'location_id' => (int) $r['location_id'],
                    'qty_available' => $r['qty_available'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 3) Reactivate the source to its pre-merge flags.
        DB::table('products')->where('id', $sourceId)->update([
            'is_inactive' => (int) ($m['source_was_inactive'] ?? 0),
            'not_for_selling' => (int) ($m['source_was_not_for_selling'] ?? 0),
        ]);

        // 4) Bust denormalized stock cache (later migration; may be absent).
        if (\Schema::hasTable('product_stock_cache')) {
            DB::table('product_stock_cache')->whereIn('product_id', [$sourceId, $targetId])->delete();
        }
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
