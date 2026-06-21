<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Store-wide scan for duplicate label print runs, which inflate the "labeled"
// count + value the Employee Productivity report credits — and therefore
// commission. Each label run is one activity_log row (description=labels_printed)
// with qty + value in properties; the printer endpoint logging twice produces
// two near-identical rows for the same employee.
//
// properties holds only qty/value/categories (no item ids), so a "duplicate"
// can't be proven exactly — it's flagged heuristically: same employee, same
// qty AND same value as the immediately-preceding run, within a short time
// window (default 120s). Results are for review; removal is a separate,
// snapshotted + undoable action (action 'remove-label-duplicates').
class LabelDuplicatesController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');

        $from   = trim((string) $request->input('from', '')) ?: now()->startOfMonth()->toDateString();
        $to     = trim((string) $request->input('to', '')) ?: now()->toDateString();
        $window = (int) $request->input('window', 120);
        if ($window < 1) { $window = 120; }

        $start = \Carbon::parse($from)->startOfDay()->toDateTimeString();
        $end   = \Carbon::parse($to)->endOfDay()->toDateTimeString();

        $rows = DB::table('activity_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.causer_id')
            ->where('a.description', 'labels_printed')
            ->where('a.business_id', $businessId)
            ->whereBetween('a.created_at', [$start, $end])
            ->orderBy('a.causer_id')
            ->orderBy('a.created_at')
            ->get([
                'a.id', 'a.causer_id', 'a.properties', 'a.created_at',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as emp_name"),
                'u.username',
            ]);

        // Group by employee, walk each employee's runs in time order, and build
        // "clusters" of identical back-to-back runs: the FIRST run in a cluster
        // is the real one (kept), and any following run that matches it on
        // count + value + category mix within the window is a duplicate copy
        // (removable). Tracking the kept run lets the page SHOW the original
        // next to each duplicate, so it's clear nothing real is deleted.
        $byEmp = [];
        $cur = []; // per employee: current cluster anchor + last-seen timestamp
        foreach ($rows as $r) {
            $d = json_decode($r->properties, true) ?: [];
            $qty = (int) ($d['qty'] ?? 0);
            $val = round((float) ($d['value'] ?? 0), 2);
            $cid = (int) $r->causer_id;
            $ts  = \Carbon::parse($r->created_at);
            $cats = $d['categories'] ?? [];
            ksort($cats);
            $catSig = json_encode($cats);

            $name = trim($r->emp_name) ?: ($r->username ?: "User #{$cid}");
            if (!isset($byEmp[$cid])) {
                $byEmp[$cid] = [
                    'causer_id' => $cid, 'name' => $name,
                    'runs' => 0, 'items' => 0, 'value' => 0.0,
                    'dup_runs' => 0, 'dup_items' => 0, 'dup_value' => 0.0,
                    'clusters' => [],
                ];
            }
            $byEmp[$cid]['runs']  += 1;
            $byEmp[$cid]['items'] += $qty;
            $byEmp[$cid]['value'] += $val;

            $a = $cur[$cid] ?? null;
            $matches = $a
                && $a['qty'] === $qty
                && abs($a['val'] - $val) < 0.005
                && $a['cat'] === $catSig
                && $ts->diffInSeconds($a['lastTs']) <= $window;

            if ($matches) {
                // Duplicate copy of the current cluster's kept run.
                $idx = $a['idx'];
                $byEmp[$cid]['clusters'][$idx]['dups'][] = [
                    'id' => $r->id, 'time' => $r->created_at, 'qty' => $qty, 'value' => $val,
                ];
                $byEmp[$cid]['dup_runs']  += 1;
                $byEmp[$cid]['dup_items'] += $qty;
                $byEmp[$cid]['dup_value'] += $val;
                $cur[$cid]['lastTs'] = $ts; // chain within window from previous copy
            } else {
                // Start a new cluster anchored on this (kept) run.
                $byEmp[$cid]['clusters'][] = [
                    'kept' => ['time' => $r->created_at, 'qty' => $qty, 'value' => $val],
                    'dups' => [],
                ];
                $cur[$cid] = [
                    'qty' => $qty, 'val' => $val, 'cat' => $catSig,
                    'lastTs' => $ts, 'idx' => count($byEmp[$cid]['clusters']) - 1,
                ];
            }
        }

        // Keep only clusters that actually have duplicates for the detail view.
        foreach ($byEmp as $cid => &$e) {
            $e['clusters'] = array_values(array_filter($e['clusters'], function ($c) {
                return !empty($c['dups']);
            }));
        }
        unset($e);

        // Employees with at least one suspected dup first, biggest dup value on top.
        $employees = collect($byEmp)->map(function ($e) { return (object) $e; })
            ->sortByDesc(function ($e) { return [$e->dup_runs > 0 ? 1 : 0, $e->dup_value]; })
            ->values();

        $totalDupRuns  = $employees->sum('dup_runs');
        $totalDupItems = $employees->sum('dup_items');
        $totalDupValue = $employees->sum('dup_value');

        return view('admin.label_duplicates', [
            'from' => $from, 'to' => $to, 'window' => $window,
            'employees' => $employees,
            'totalDupRuns' => $totalDupRuns,
            'totalDupItems' => $totalDupItems,
            'totalDupValue' => $totalDupValue,
        ]);
    }

    public function remove(Request $request)
    {
        @set_time_limit(0);

        $businessId = $request->session()->get('user.business_id');
        $ids = array_filter(array_map('intval', (array) $request->input('dup_ids', [])));

        if (empty($ids)) {
            return back()->with('status', ['success' => 0, 'msg' => 'No duplicate rows selected.']);
        }

        // Re-resolve, scoped to this business + the labels_printed marker, so a
        // tampered id can't delete an arbitrary activity_log row.
        $rows = DB::table('activity_log')
            ->where('business_id', $businessId)
            ->where('description', 'labels_printed')
            ->whereIn('id', $ids)
            ->get();

        if ($rows->isEmpty()) {
            return back()->with('status', ['success' => 0, 'msg' => 'Selected rows not found (already removed?).']);
        }

        // Snapshot the FULL rows so undo can re-insert them verbatim.
        $snapRows = $rows->map(function ($r) { return (array) $r; })->all();

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "remove-label-duplicates-{$timestamp}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'   => $timestamp,
                'action'      => 'remove-label-duplicates',
                'user_id'     => auth()->id(),
                'business_id' => $businessId,
                'rows'        => $snapRows,
            ], JSON_PRETTY_PRINT)
        );

        $deleted = DB::table('activity_log')->whereIn('id', $rows->pluck('id')->all())->delete();

        return redirect('/admin/label-duplicates')
            ->with('status', [
                'success' => 1,
                'msg' => "Removed {$deleted} duplicate label run(s). Snapshot: {$snapshotKey} (undo at /admin/admin-action-history).",
            ]);
    }
}
