<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-shot cleanup: a sync (Clover/server clock drift, suspected) wrote some
// products.created_at / updated_at into the future. Those rows pollute the
// /products report's "Last updated at" / "Created at" columns and distort the
// default Created-at-desc sort. This page nulls out the bad timestamps
// (display layer already renders NULL as "—") and snapshots the BEFORE state
// so /admin/admin-action-history can undo it.
class FixFutureProductDatesController extends Controller
{
    public function index()
    {
        return view('admin.fix_future_product_dates', $this->buildContext(null));
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');
        $now = now();

        if (!$commit) {
            return view('admin.fix_future_product_dates', $this->buildContext('preview'));
        }

        $futureRows = DB::table('products')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($now) {
                $q->where('created_at', '>', $now)
                  ->orWhere('updated_at', '>', $now);
            })
            ->get(['id', 'created_at', 'updated_at']);

        $snapshotRows = $futureRows->map(function ($r) {
            return [
                'id' => $r->id,
                'created_at' => (string) $r->created_at,
                'updated_at' => (string) $r->updated_at,
            ];
        })->all();

        $createdCleared = 0;
        $updatedCleared = 0;
        foreach ($futureRows as $r) {
            $set = [];
            if ($r->created_at && strtotime($r->created_at) > $now->getTimestamp()) {
                $set['created_at'] = null;
                $createdCleared++;
            }
            if ($r->updated_at && strtotime($r->updated_at) > $now->getTimestamp()) {
                $set['updated_at'] = null;
                $updatedCleared++;
            }
            if (!empty($set)) {
                DB::table('products')->where('id', $r->id)->update($set);
            }
        }

        if (!empty($snapshotRows)) {
            $key = 'future-product-dates-' . $now->format('Y-m-d_His');
            Storage::disk('local')->put(
                "admin-snapshots/{$key}.json",
                json_encode([
                    'timestamp' => $now->toDateTimeString(),
                    'action' => 'future-product-dates',
                    'business_id' => $businessId,
                    'rows' => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );
        }

        return view('admin.fix_future_product_dates', $this->buildContext('commit', [
            'created_cleared' => $createdCleared,
            'updated_cleared' => $updatedCleared,
            'rows_touched' => count($snapshotRows),
        ]));
    }

    private function buildContext($mode, array $extra = [])
    {
        $businessId = request()->session()->get('user.business_id');
        $now = now();

        $counts = DB::table('products')
            ->where('business_id', $businessId)
            ->selectRaw('SUM(created_at > ?) as future_created, SUM(updated_at > ?) as future_updated', [$now, $now])
            ->first();

        $samples = DB::table('products')
            ->where('business_id', $businessId)
            ->where(function ($q) use ($now) {
                $q->where('created_at', '>', $now)
                  ->orWhere('updated_at', '>', $now);
            })
            ->orderBy('id')
            ->limit(15)
            ->get(['id', 'name', 'sku', 'created_at', 'updated_at']);

        return array_merge([
            'mode' => $mode,
            'future_created' => (int) ($counts->future_created ?? 0),
            'future_updated' => (int) ($counts->future_updated ?? 0),
            'samples' => $samples,
        ], $extra);
    }
}
