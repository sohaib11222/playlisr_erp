<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Reassign a day's sales + listings from one user to another, for when staff
// rang up / listed under the wrong account (e.g. 2026-06-19: Zak was logged in
// as Clark by mistake, so all of "Clark's" activity that day is really Zak's).
//
// Picks transactions.created_by and products.created_by — the columns the
// leaderboard, commission and listing-pay reports credit by — and moves the
// selected rows from the wrong user to the right one.
//
// Preview lists every row with time + amount and a checkbox (all on by
// default) so if the wrong-account user ALSO worked their own shift that day,
// the genuinely-theirs rows can be unchecked before applying.
//
// Each apply snapshots the BEFORE created_by to storage/admin-snapshots/ so
// it's one-click undoable at /admin/admin-action-history (action
// 'reassign-user-created-by'). No data is ever deleted.
class ReassignUserActivityController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');

        $users = DB::table('users')
            ->where('business_id', $businessId)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'username']);

        $fromUserId = (int) $request->input('from_user_id', 0);
        $toUserId   = (int) $request->input('to_user_id', 0);
        $date       = trim((string) $request->input('date', '')) ?: now()->toDateString();

        $sales = collect();
        $listings = collect();
        $labels = collect();
        $previewed = false;

        if ($fromUserId && $toUserId && $fromUserId !== $toUserId) {
            $previewed = true;
            [$start, $end] = $this->dayBounds($date);

            $sales = DB::table('transactions as t')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->where('t.business_id', $businessId)
                ->where('t.created_by', $fromUserId)
                ->whereBetween('t.transaction_date', [$start, $end])
                ->orderBy('t.transaction_date')
                ->get([
                    't.id', 't.invoice_no', 't.type', 't.status',
                    't.final_total', 't.transaction_date',
                    DB::raw("CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) as contact_name"),
                ]);

            // Sell value per listing = sum of its variations' sell price, so
            // the listings total here can be sanity-checked against the
            // labeled-value figure from the leaderboard/listing report.
            $listings = DB::table('products as p')
                ->leftJoin('variations as v', 'v.product_id', '=', 'p.id')
                ->where('p.business_id', $businessId)
                ->where('p.created_by', $fromUserId)
                ->whereBetween('p.created_at', [$start, $end])
                ->whereNull('v.deleted_at')
                ->groupBy('p.id', 'p.name', 'p.sku', 'p.created_at')
                ->orderBy('p.created_at')
                ->get([
                    'p.id', 'p.name', 'p.sku', 'p.created_at',
                    DB::raw('COALESCE(SUM(v.default_sell_price), 0) as sell_value'),
                ]);

            // "Labeled" credit isn't a product column — it's activity_log rows
            // (description=labels_printed, causer_id=who printed) with qty +
            // value in the properties JSON. This is what the shift summary /
            // productivity report counts, so it's reassigned by causer_id.
            $labels = DB::table('activity_log')
                ->where('description', 'labels_printed')
                ->where('business_id', $businessId)
                ->where('causer_id', $fromUserId)
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('created_at')
                ->get(['id', 'created_at', 'properties'])
                ->map(function ($r) {
                    $d = json_decode($r->properties, true) ?: [];
                    return (object) [
                        'id'         => $r->id,
                        'created_at' => $r->created_at,
                        'qty'        => (int) ($d['qty'] ?? 0),
                        'value'      => (float) ($d['value'] ?? 0),
                    ];
                });
        }

        return view('admin.reassign_user_activity', [
            'users'      => $users,
            'fromUserId' => $fromUserId,
            'toUserId'   => $toUserId,
            'date'       => $date,
            'sales'      => $sales,
            'listings'   => $listings,
            'labels'     => $labels,
            'previewed'  => $previewed,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);

        $businessId = $request->session()->get('user.business_id');
        $fromUserId = (int) $request->input('from_user_id');
        $toUserId   = (int) $request->input('to_user_id');
        $date       = trim((string) $request->input('date', '')) ?: now()->toDateString();
        $txIds      = array_filter(array_map('intval', (array) $request->input('tx_ids', [])));
        $prodIds    = array_filter(array_map('intval', (array) $request->input('prod_ids', [])));
        $labelIds   = array_filter(array_map('intval', (array) $request->input('label_ids', [])));

        if (!$fromUserId || !$toUserId) {
            return back()->with('status', ['success' => 0, 'msg' => 'Pick both a "from" and a "to" user.']);
        }
        if ($fromUserId === $toUserId) {
            return back()->with('status', ['success' => 0, 'msg' => 'From and To are the same user.']);
        }
        if (empty($txIds) && empty($prodIds) && empty($labelIds)) {
            return back()->with('status', ['success' => 0, 'msg' => 'Nothing selected to reassign.']);
        }

        $toUser = DB::table('users')->where('id', $toUserId)->where('business_id', $businessId)->first();
        if (!$toUser) {
            return back()->with('status', ['success' => 0, 'msg' => 'Target user not found for this business.']);
        }

        // Re-resolve the rows from the DB (don't trust posted ids blindly):
        // must belong to this business AND still be owned by the from-user, so
        // a stale form or tampered id can't move someone else's rows.
        $txRows = empty($txIds) ? collect() : DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('created_by', $fromUserId)
            ->whereIn('id', $txIds)
            ->get(['id', 'created_by']);

        $prodRows = empty($prodIds) ? collect() : DB::table('products')
            ->where('business_id', $businessId)
            ->where('created_by', $fromUserId)
            ->whereIn('id', $prodIds)
            ->get(['id', 'created_by']);

        // Labels = activity_log 'labels_printed' rows; reassigned by causer_id.
        $labelRows = empty($labelIds) ? collect() : DB::table('activity_log')
            ->where('description', 'labels_printed')
            ->where('business_id', $businessId)
            ->where('causer_id', $fromUserId)
            ->whereIn('id', $labelIds)
            ->get(['id', 'causer_id']);

        if ($txRows->isEmpty() && $prodRows->isEmpty() && $labelRows->isEmpty()) {
            return back()->with('status', ['success' => 0, 'msg' => 'Selected rows no longer belong to the from-user (already moved?).']);
        }

        // Snapshot BEFORE state so the move is undoable. Uniform row shape:
        // {table, id, column, value} — value is the original owner id.
        $snapRows = [];
        foreach ($txRows as $r)    { $snapRows[] = ['table' => 'transactions', 'id' => $r->id, 'column' => 'created_by', 'value' => $r->created_by]; }
        foreach ($prodRows as $r)  { $snapRows[] = ['table' => 'products',     'id' => $r->id, 'column' => 'created_by', 'value' => $r->created_by]; }
        foreach ($labelRows as $r) { $snapRows[] = ['table' => 'activity_log', 'id' => $r->id, 'column' => 'causer_id',  'value' => $r->causer_id]; }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "reassign-user-created-by-{$timestamp}-{$fromUserId}-to-{$toUserId}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'    => $timestamp,
                'action'       => 'reassign-user-created-by',
                'user_id'      => auth()->id(),
                'business_id'  => $businessId,
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'scope_date'   => $date,
                'rows'         => $snapRows,
            ], JSON_PRETTY_PRINT)
        );

        $txMoved = 0;
        if ($txRows->isNotEmpty()) {
            $txMoved = DB::table('transactions')
                ->whereIn('id', $txRows->pluck('id')->all())
                ->update(['created_by' => $toUserId, 'updated_at' => now()]);
        }

        $prodMoved = 0;
        if ($prodRows->isNotEmpty()) {
            $prodMoved = DB::table('products')
                ->whereIn('id', $prodRows->pluck('id')->all())
                ->update(['created_by' => $toUserId, 'updated_at' => now()]);
        }

        $labelsMoved = 0;
        if ($labelRows->isNotEmpty()) {
            $labelsMoved = DB::table('activity_log')
                ->whereIn('id', $labelRows->pluck('id')->all())
                ->update(['causer_id' => $toUserId]);
        }

        $toName = trim(($toUser->first_name ?? '') . ' ' . ($toUser->last_name ?? '')) ?: ($toUser->username ?? "#{$toUserId}");

        return redirect('/admin/reassign-user-activity')
            ->with('status', [
                'success' => 1,
                'msg' => "Reassigned {$txMoved} sale(s) + {$prodMoved} listing(s) + {$labelsMoved} label run(s) to {$toName}. Snapshot: {$snapshotKey} (undo at /admin/admin-action-history).",
            ]);
    }

    // Day bounds for the given Y-m-d in the app timezone.
    protected function dayBounds($date)
    {
        $start = \Carbon::parse($date)->startOfDay()->toDateTimeString();
        $end   = \Carbon::parse($date)->endOfDay()->toDateTimeString();
        return [$start, $end];
    }
}
