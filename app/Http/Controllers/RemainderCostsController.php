<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Lists every category that still has $0-cost variations after the
// cost-price-rules pass, with counts. Inline form lets Sarah enter a
// cost per category and apply them all in one go (skipping any she
// leaves blank). Same snapshot-then-undo guardrails as cost-price-rules.
class RemainderCostsController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        // For each top-level category, count variations whose cost is still 0/null.
        $rows = DB::table('categories as c')
            ->leftJoin('products as p', function ($j) {
                $j->on('p.category_id', '=', 'c.id');
            })
            ->leftJoin('variations as v', function ($j) {
                $j->on('v.product_id', '=', 'p.id')
                  ->whereNull('v.deleted_at');
            })
            ->where('c.business_id', $businessId)
            ->where('c.parent_id', 0)
            ->whereNull('c.deleted_at')
            ->where(function ($q) {
                $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
            })
            ->where(function ($q) {
                $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
            })
            ->groupBy('c.id', 'c.name')
            ->select('c.id', 'c.name', DB::raw('COUNT(v.id) as zero_count'))
            ->having('zero_count', '>', 0)
            ->orderBy('zero_count', 'desc')
            ->get();

        // Also count uncategorized variations (NULL category_id).
        $uncategorized = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->whereNull('p.category_id')
            ->where(function ($q) {
                $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
            })
            ->where(function ($q) {
                $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
            })
            ->count();

        return view('admin.remainder_costs', [
            'rows' => $rows,
            'uncategorized' => $uncategorized,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = $request->session()->get('user.business_id');
        $costs = $request->input('cost', []); // ['<category_id>' => '<cost>']

        $snapshotRows = [];
        $totalUpdated = 0;
        $perCategory = [];

        foreach ($costs as $categoryId => $costStr) {
            $costStr = trim((string) $costStr);
            if ($costStr === '') continue;
            if (!is_numeric($costStr)) continue;
            $cost = (float) $costStr;
            if ($cost < 0) continue;

            $categoryId = (int) $categoryId;
            if ($categoryId <= 0) continue;

            // Find variations in this category with $0/null cost.
            $eligibleQuery = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('p.business_id', $businessId)
                ->where('p.category_id', $categoryId)
                ->whereNull('v.deleted_at')
                ->where(function ($q) {
                    $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
                })
                ->where(function ($q) {
                    $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
                });

            $ids = (clone $eligibleQuery)->pluck('v.id')->all();
            if (empty($ids)) {
                $perCategory[$categoryId] = 0;
                continue;
            }

            // Snapshot BEFORE.
            $beforeForChunk = DB::table('variations')
                ->whereIn('id', $ids)
                ->select('id', 'default_purchase_price', 'dpp_inc_tax', 'updated_at')
                ->get();
            foreach ($beforeForChunk as $r) {
                $snapshotRows[] = [
                    'id' => $r->id,
                    'default_purchase_price' => $r->default_purchase_price,
                    'dpp_inc_tax' => $r->dpp_inc_tax,
                    'updated_at' => (string) $r->updated_at,
                ];
            }

            // Apply.
            $rowsForCat = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $rowsForCat += DB::table('variations')
                    ->whereIn('id', $chunk)
                    ->update([
                        'default_purchase_price' => $cost,
                        'dpp_inc_tax'            => $cost,
                        'updated_at'             => now(),
                    ]);
            }
            $perCategory[$categoryId] = $rowsForCat;
            $totalUpdated += $rowsForCat;
        }

        // Persist snapshot for undo.
        if (!empty($snapshotRows)) {
            $timestamp = now()->format('Y-m-d_His');
            $snapshotKey = "remainder-costs-{$timestamp}";
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp' => now()->toDateTimeString(),
                    'action' => 'cost-price-rules', // re-use existing undo handler
                    'business_id' => $businessId,
                    'rows' => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );
        }

        return redirect('/admin/remainder-costs')
            ->with('status', ['success' => 1, 'msg' => "Updated $totalUpdated variation(s) across " . count(array_filter($perCategory)) . " categories. Undo at /admin/admin-action-history if needed."]);
    }
}
