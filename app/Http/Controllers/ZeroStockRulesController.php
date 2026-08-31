<?php

namespace App\Http\Controllers;

use App\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sets CURRENT STOCK to 0 for named groups of products that shouldn't show
 * as sellable anymore. Same preview/commit + snapshot/undo pattern as
 * CostPriceRulesController: GET/commit=0 shows what WOULD change, POST
 * commit=1 writes it and snapshots the BEFORE qty_available on every
 * variation_location_details row touched — reversible at
 * /admin/admin-action-history (action 'zero-retired-stock').
 *
 * Rules (edit here to add/change what gets zeroed):
 *   1. Name starts with "RETIRED:" and the product is NOT in the Apparel
 *      category (apparel keeps its stock; only retired media/other items
 *      get zeroed).
 *   2. Kanye West - Graduation, Vinyl and Cassette formats only.
 *   3. Record Store Day titles, matched by name. No structured RSD flag
 *      exists in the schema (same gap InventoryCheckService::isRsdTitle
 *      documents), so this uses the same name markers.
 *   4. Products behind a cancelled web order whose cancellation reason
 *      mentioned RSD / Sold Out / Sold in Store / Bootleg / Sold by Golden —
 *      pulled from Sarah's cancelled-orders export (2026-08-30) into
 *      cancelled_orders_zero_stock_2026_08_30.json. Two titles were
 *      truncated in that export (cut off mid-parenthetical) and match by
 *      name PREFIX instead of exact name — everything else is an exact,
 *      case-insensitive product name match so this only ever touches the
 *      specific product that order was for.
 */
class ZeroStockRulesController extends Controller
{
    const CANCELLED_ORDERS_FILE = 'cancelled_orders_zero_stock_2026_08_30.json';

    protected function cancelledOrderTitles()
    {
        $path = app_path('Services/data/' . self::CANCELLED_ORDERS_FILE);
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?: [];
    }

    protected function apparelCategoryIds($businessId)
    {
        return DB::table('categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['apparel'])
            ->pluck('id')
            ->map(function ($v) { return (int) $v; })
            ->all();
    }

    protected function vinylCassetteCategoryIds($businessId)
    {
        return DB::table('categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('name', 'LIKE', '%Vinyl%')
                  ->orWhere('name', 'LIKE', '%Cassette%');
            })
            ->pluck('id')
            ->map(function ($v) { return (int) $v; })
            ->all();
    }

    protected function rules($businessId)
    {
        $apparelIds = $this->apparelCategoryIds($businessId);
        $vinylCassetteIds = $this->vinylCassetteCategoryIds($businessId);

        return [
            [
                'key'   => 'retired-prefix',
                'label' => 'Retired items (name starts with "RETIRED:", not Apparel)',
                'query' => function () use ($businessId, $apparelIds) {
                    $q = Product::where('business_id', $businessId)
                        ->where('name', 'LIKE', 'RETIRED:%')
                        ->where('enable_stock', 1);
                    if (!empty($apparelIds)) {
                        $q->where(function ($qq) use ($apparelIds) {
                            $qq->whereNotIn('category_id', $apparelIds)->orWhereNull('category_id');
                        })->where(function ($qq) use ($apparelIds) {
                            $qq->whereNotIn('sub_category_id', $apparelIds)->orWhereNull('sub_category_id');
                        });
                    }
                    return $q;
                },
            ],
            [
                'key'   => 'kanye-graduation',
                'label' => 'Kanye West - Graduation (Vinyl & Cassette)',
                'query' => function () use ($businessId, $vinylCassetteIds) {
                    $q = Product::where('business_id', $businessId)
                        ->where('enable_stock', 1)
                        ->where('name', 'LIKE', '%Graduation%')
                        ->where(function ($qq) {
                            $qq->where('name', 'LIKE', '%Kanye%')
                               ->orWhere('artist', 'LIKE', '%Kanye%');
                        });
                    if (!empty($vinylCassetteIds)) {
                        $q->where(function ($qq) use ($vinylCassetteIds) {
                            $qq->whereIn('category_id', $vinylCassetteIds)
                               ->orWhereIn('sub_category_id', $vinylCassetteIds);
                        });
                    }
                    return $q;
                },
            ],
            [
                'key'   => 'record-store-day',
                'label' => 'Record Store Day titles',
                'query' => function () use ($businessId) {
                    return Product::where('business_id', $businessId)
                        ->where('enable_stock', 1)
                        ->where(function ($qq) {
                            $qq->where('name', 'LIKE', '%RSD%')
                               ->orWhere('name', 'LIKE', '%Record Store Day%');
                        });
                },
            ],
            [
                'key'   => 'cancelled-order-flagged',
                'label' => 'Cancelled web orders (RSD / Sold Out / Sold in Store / Bootleg / Sold by Golden)',
                'query' => function () use ($businessId) {
                    $titles = $this->cancelledOrderTitles();
                    $q = Product::where('business_id', $businessId)->where('enable_stock', 1);
                    if (empty($titles)) {
                        return $q->whereRaw('1 = 0');
                    }
                    $q->where(function ($qq) use ($titles) {
                        foreach ($titles as $t) {
                            if (($t['match'] ?? 'exact') === 'prefix') {
                                $qq->orWhere('name', 'LIKE', $t['title'] . '%');
                            } else {
                                $qq->orWhereRaw('LOWER(name) = ?', [mb_strtolower($t['title'])]);
                            }
                        }
                    });
                    return $q;
                },
                // Shows which cancelled-order title matched each product, so
                // Sarah can visually confirm it's the right one before zeroing.
                'annotate' => function ($productName) {
                    $lower = mb_strtolower($productName);
                    foreach ($this->cancelledOrderTitles() as $t) {
                        if (($t['match'] ?? 'exact') === 'prefix') {
                            if (str_starts_with($lower, mb_strtolower($t['title']))) {
                                return $t['title'];
                            }
                        } elseif ($lower === mb_strtolower($t['title'])) {
                            return $t['title'];
                        }
                    }
                    return null;
                },
            ],
        ];
    }

    public function index()
    {
        return $this->run(request()->merge(['commit' => false]));
    }

    public function run(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');

        $results = [];
        $snapshotRows = [];
        $grandZeroed = 0;
        $grandRows = 0;

        foreach ($this->rules($businessId) as $rule) {
            $productIds = $rule['query']()->pluck('id')->all();

            $rows = collect();
            if (!empty($productIds)) {
                $rows = DB::table('variation_location_details as vld')
                    ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                    ->whereIn('v.product_id', $productIds)
                    ->where('vld.qty_available', '>', 0)
                    ->select('vld.id', 'vld.qty_available', 'v.product_id')
                    ->get();
            }

            $productNames = empty($productIds) ? [] : Product::whereIn('id', $productIds)
                ->pluck('name', 'id');

            $preview = $rows->groupBy('product_id')->map(function ($group, $productId) use ($productNames, $rule) {
                $name = $productNames[$productId] ?? '(unknown)';
                return [
                    'id'     => $productId,
                    'name'   => $name,
                    'stock'  => $group->sum('qty_available'),
                    'source' => isset($rule['annotate']) ? $rule['annotate']($name) : null,
                ];
            })->values()->sortBy('name')->values();

            $zeroed = 0;
            if ($commit && $rows->isNotEmpty()) {
                foreach ($rows as $r) {
                    $snapshotRows[] = ['id' => $r->id, 'qty_available' => $r->qty_available];
                }
                foreach ($rows->pluck('id')->chunk(500) as $chunk) {
                    $zeroed += DB::table('variation_location_details')
                        ->whereIn('id', $chunk->all())
                        ->update(['qty_available' => 0, 'updated_at' => now()]);
                }
                $grandZeroed += $zeroed;
            }

            $grandRows += $rows->count();

            $results[] = [
                'label'           => $rule['label'],
                'matched_products' => count($productIds),
                'rows_with_stock' => $rows->count(),
                'stock_to_clear'  => (float) $rows->sum('qty_available'),
                'zeroed'          => $zeroed,
                'preview'         => $preview,
            ];
        }

        $snapshotKey = null;
        if ($commit && !empty($snapshotRows)) {
            $timestamp = now()->format('Y-m-d_His');
            $snapshotKey = "zero-retired-stock-{$timestamp}";
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp'   => now()->toDateTimeString(),
                    'action'      => 'zero-retired-stock',
                    'business_id' => $businessId,
                    'rows'        => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );
        }

        return view('admin.zero_stock_rules', [
            'results'      => $results,
            'mode'         => $commit ? 'commit' : 'preview',
            'grand_zeroed' => $grandZeroed,
            'grand_rows'   => $grandRows,
            'snapshot_key' => $snapshotKey,
        ]);
    }
}
