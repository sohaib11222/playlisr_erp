<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One record per customer (Sarah, 2026-10-20: "make sure all customers have
 * 1 record w all their history on it"). Customers sharing an email are
 * merged into the one with the most sales (oldest on a tie): every row that
 * points at a duplicate (sales, store credit log, pickups, preorders, ...)
 * is moved to the kept record, store credit and points are added together,
 * and the duplicate is soft-deleted. Every change is saved to
 * storage/app/customer-merges/ so a merge can be undone.
 */
class CustomerMergeController extends Controller
{
    const SUM = ['balance', 'lifetime_purchases', 'loyalty_points', 'total_rp', 'total_rp_expired', 'total_rp_used'];

    private function guard()
    {
        if (!auth()->user()->can('customer.update')) abort(403, 'Unauthorized action.');
    }

    /** Tables (other than contacts) with a contact_id column. */
    private function refTables(): array
    {
        $db = DB::getDatabaseName();
        return DB::table('information_schema.columns')->where('table_schema', $db)->where('column_name', 'contact_id')
            ->where('table_name', '!=', 'contacts')->pluck('table_name')->unique()->values()->all();
    }

    /** [email => [contact rows, kept first]] */
    private function groups(int $biz): array
    {
        $rows = DB::table('contacts')->where('business_id', $biz)->whereNull('deleted_at')
            ->whereIn('type', ['customer', 'both'])->where(function ($q) { $q->whereNull('is_default')->orWhere('is_default', 0); })
            ->whereNotNull('email')->where('email', '!=', '')->where('email', 'not like', '%@nivessa.com')
            ->whereIn(DB::raw('LOWER(TRIM(email))'), function ($q) use ($biz) {
                $q->select(DB::raw('LOWER(TRIM(email))'))->from('contacts')->where('business_id', $biz)->whereNull('deleted_at')
                    ->whereIn('type', ['customer', 'both'])->whereNotNull('email')->where('email', '!=', '')
                    ->groupBy(DB::raw('LOWER(TRIM(email))'))->havingRaw('COUNT(*) > 1');
            })
            ->get(['id', 'name', 'email', 'mobile', 'contact_id', 'balance', 'created_at']);
        if ($rows->isEmpty()) return [];
        $sales = DB::table('transactions')->whereIn('contact_id', $rows->pluck('id'))->groupBy('contact_id')
            ->select('contact_id', DB::raw('COUNT(*) n'))->pluck('n', 'contact_id');
        $out = [];
        foreach ($rows->groupBy(fn($r) => strtolower(trim($r->email))) as $email => $g) {
            $g = $g->map(function ($r) use ($sales) { $r->sales = (int) ($sales[$r->id] ?? 0); return $r; })
                ->sort(fn($a, $b) => [$b->sales, $a->id] <=> [$a->sales, $b->id])->values();
            if ($g->count() > 1) $out[$email] = $g->all();
        }
        ksort($out);
        return $out;
    }

    public function index(Request $request)
    {
        $this->guard();
        $groups = $this->groups((int) $request->session()->get('user.business_id'));
        $undo = collect(glob(storage_path('app/customer-merges/*.json')) ?: [])->sort()->last();
        return view('contact.merge_customers', ['groups' => $groups, 'tables' => $this->refTables(), 'lastRun' => $undo ? basename($undo) : null]);
    }

    public function merge(Request $request)
    {
        $this->guard();
        @set_time_limit(0);
        $biz = (int) $request->session()->get('user.business_id');
        $tables = $this->refTables();
        $log = ['at' => date('c'), 'by' => auth()->user()->id, 'merges' => []];
        $done = 0;
        foreach ($this->groups($biz) as $email => $g) {
            $keep = array_shift($g);
            DB::transaction(function () use ($keep, $g, $tables, &$log) {
                $entry = ['keep' => $keep->id, 'email' => $keep->email, 'dups' => []];
                $keepRow = (array) DB::table('contacts')->where('id', $keep->id)->lockForUpdate()->first();
                $add = array_fill_keys(self::SUM, 0);
                foreach ($g as $d) {
                    $dupRow = (array) DB::table('contacts')->where('id', $d->id)->lockForUpdate()->first();
                    $moved = [];
                    foreach ($tables as $t) {
                        $ids = DB::table($t)->where('contact_id', $d->id)->pluck('id')->all();
                        if ($ids) {
                            DB::table($t)->whereIn('id', $ids)->update(['contact_id' => $keep->id]);
                            $moved[$t] = $ids;
                        }
                    }
                    foreach (self::SUM as $c) if (array_key_exists($c, $dupRow)) $add[$c] += (float) $dupRow[$c];
                    DB::table('contacts')->where('id', $d->id)->update([
                        'deleted_at' => now(),
                        'balance_notes' => trim(($dupRow['balance_notes'] ?? '') . "\n" . date('Y-m-d H:i') . ' merged into #' . $keep->id . ' (same email)'),
                    ] + array_fill_keys(array_values(array_intersect(self::SUM, array_keys($dupRow))), 0));
                    $entry['dups'][] = ['id' => $d->id, 'row' => $dupRow, 'moved' => $moved];
                }
                $upd = [];
                foreach (self::SUM as $c) if (array_key_exists($c, $keepRow) && $add[$c] != 0) $upd[$c] = (float) $keepRow[$c] + $add[$c];
                if ($upd) {
                    $upd['balance_notes'] = trim(($keepRow['balance_notes'] ?? '') . "\n" . date('Y-m-d H:i') . ' merged ' . count($g) . ' duplicate record(s) with the same email; credit and points added');
                    DB::table('contacts')->where('id', $keep->id)->update($upd);
                }
                $entry['keep_before'] = array_intersect_key($keepRow, array_flip(array_merge(self::SUM, ['balance_notes'])));
                $log['merges'][] = $entry;
            });
            $done++;
        }
        $dir = storage_path('app/customer-merges');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents($dir . '/' . date('Ymd-His') . '.json', json_encode($log));
        return redirect('/admin/merge-customers')->with('status', ['success' => 1, 'msg' => "Merged {$done} customers. Each now has one record with all their history."]);
    }

    /** Undo the latest merge run from its saved file. */
    public function undo(Request $request)
    {
        $this->guard();
        $file = collect(glob(storage_path('app/customer-merges/*.json')) ?: [])->sort()->last();
        if (!$file) return redirect('/admin/merge-customers');
        $log = json_decode(file_get_contents($file), true);
        foreach (array_reverse($log['merges'] ?? []) as $m) {
            DB::transaction(function () use ($m) {
                foreach ($m['dups'] as $d) {
                    foreach ($d['moved'] as $t => $ids) DB::table($t)->whereIn('id', $ids)->update(['contact_id' => $d['id']]);
                    $restore = array_intersect_key($d['row'], array_flip(array_merge(self::SUM, ['balance_notes', 'deleted_at'])));
                    DB::table('contacts')->where('id', $d['id'])->update($restore);
                }
                DB::table('contacts')->where('id', $m['keep'])->update($m['keep_before']);
            });
        }
        rename($file, $file . '.undone');
        return redirect('/admin/merge-customers')->with('status', ['success' => 1, 'msg' => 'Undid the last merge.']);
    }
}
