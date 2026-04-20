<?php

namespace App\Services;

use App\BusinessLocation;
use App\Category;
use App\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryCheckService
{
    /**
     * Resolve preset into filter defaults (location_id, category_ids, dates, supplier_id).
     */
    public function resolvePreset(int $business_id, string $presetKey): array
    {
        $presets = config('inventory_check.presets', []);
        if (!isset($presets[$presetKey])) {
            return [];
        }
        $p = $presets[$presetKey];
        $out = [
            'preset_key' => $presetKey,
            'sale_days' => $p['sale_days'] ?? 90,
        ];

        $locPattern = $p['location_name_pattern'] ?? '';
        if ($locPattern !== '') {
            $loc = BusinessLocation::where('business_id', $business_id)
                ->where('name', 'like', '%' . $locPattern . '%')
                ->orderBy('id')
                ->first();
            if ($loc) {
                $out['location_id'] = $loc->id;
            }
        }

        $catPattern = $p['category_name_pattern'] ?? '';
        if ($catPattern !== '') {
            $ids = Category::where('business_id', $business_id)
                ->where('category_type', 'product')
                ->where('name', 'like', '%' . $catPattern . '%')
                ->pluck('id')
                ->all();
            $out['category_ids'] = $ids;
        }

        $days = (int) ($p['sale_days'] ?? 90);
        $out['sale_end'] = Carbon::now()->format('Y-m-d');
        $out['sale_start'] = Carbon::now()->subDays($days)->format('Y-m-d');

        $supplierPattern = config('inventory_check.default_supplier_name_pattern', 'AMS');
        if ($supplierPattern) {
            $sup = Contact::where('business_id', $business_id)
                ->where(function ($q) {
                    $q->where('type', 'supplier')->orWhere('type', 'both');
                })
                ->where(function ($q) use ($supplierPattern) {
                    $q->where('name', 'like', '%' . $supplierPattern . '%')
                        ->orWhere('supplier_business_name', 'like', '%' . $supplierPattern . '%');
                })
                ->orderBy('id')
                ->first();
            if ($sup) {
                $out['supplier_id'] = $sup->id;
            }
        }

        return $out;
    }

    /**
     * Sold quantity per variation at location in date window.
     *
     * @return array<int,float> variation_id => qty
     */
    public function getSoldQtyByVariation(int $business_id, int $location_id, string $saleStart, string $saleEnd, $permittedLocations): array
    {
        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $location_id)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->groupBy('tsl.variation_id')
            ->select(
                'tsl.variation_id',
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty')
            );

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $rows = $q->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->variation_id] = (float) $row->sold_qty;
        }

        return $map;
    }

    /**
     * Average days from purchase to sell (fast seller metric), optional supplier filter on purchase.
     *
     * @return array<int,array{avg_days:float,count:int}>
     */
    public function getAvgSellDaysByVariation(
        int $business_id,
        int $location_id,
        string $saleStart,
        string $saleEnd,
        ?int $supplierId,
        bool $excludeZeroDay,
        $permittedLocations
    ): array {
        $q = DB::table('transaction_sell_lines_purchase_lines as tslp')
            ->join('purchase_lines as pl', 'pl.id', '=', 'tslp.purchase_line_id')
            ->join('transactions as purchase', 'purchase.id', '=', 'pl.transaction_id')
            ->join('variations as v', 'v.id', '=', 'pl.variation_id')
            ->leftJoin('transaction_sell_lines as sl', 'sl.id', '=', 'tslp.sell_line_id')
            ->leftJoin('transactions as sale', 'sale.id', '=', 'sl.transaction_id')
            ->leftJoin('stock_adjustment_lines as sal', 'sal.id', '=', 'tslp.stock_adjustment_line_id')
            ->leftJoin('transactions as stock_adj', 'stock_adj.id', '=', 'sal.transaction_id')
            ->where('purchase.business_id', $business_id)
            ->where('purchase.location_id', $location_id)
            ->whereNotNull('purchase.transaction_date');

        if ($supplierId) {
            $q->join('contacts as suppliers', 'purchase.contact_id', '=', 'suppliers.id')
                ->where('suppliers.id', $supplierId);
        }

        if ($permittedLocations !== 'all') {
            $q->whereIn('purchase.location_id', $permittedLocations);
        }

        $q->where(function ($outer) use ($saleStart, $saleEnd) {
            $outer->where(function ($q) use ($saleStart, $saleEnd) {
                $q->whereNotNull('sale.transaction_date')
                    ->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$saleStart, $saleEnd]);
            })->orWhere(function ($q) use ($saleStart, $saleEnd) {
                $q->whereNotNull('stock_adj.transaction_date')
                    ->whereBetween(DB::raw('DATE(stock_adj.transaction_date)'), [$saleStart, $saleEnd]);
            });
        });

        $rows = $q->select(
            'pl.variation_id',
            DB::raw('
                DATEDIFF(
                    IFNULL(sale.transaction_date, stock_adj.transaction_date),
                    purchase.transaction_date
                ) as sell_days
            ')
        )->get();

        $sums = [];
        foreach ($rows as $row) {
            $days = max(0.0, (float) $row->sell_days);
            if ($excludeZeroDay && $days <= 0) {
                continue;
            }
            $vid = (int) $row->variation_id;
            if (!isset($sums[$vid])) {
                $sums[$vid] = ['sum' => 0.0, 'count' => 0];
            }
            $sums[$vid]['sum'] += $days;
            $sums[$vid]['count']++;
        }

        $out = [];
        foreach ($sums as $vid => $agg) {
            if ($agg['count'] > 0) {
                $out[$vid] = [
                    'avg_days' => $agg['sum'] / $agg['count'],
                    'count' => $agg['count'],
                ];
            }
        }

        return $out;
    }

    /**
     * Build unified order candidates for the assistant UI.
     *
     * @param  array<string,mixed>  $input
     * @return array{candidates: array<int,array>, meta: array<string,mixed>}
     */
    public function buildCandidates(int $business_id, array $input, $permittedLocations): array
    {
        $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
        if (!$locationId) {
            return ['candidates' => [], 'meta' => ['error' => 'location_required']];
        }

        $saleStart = $input['sale_start'] ?? Carbon::now()->subDays(90)->format('Y-m-d');
        $saleEnd = $input['sale_end'] ?? Carbon::now()->format('Y-m-d');

        $categoryId = !empty($input['category_id']) ? (int) $input['category_id'] : null;
        $categoryIds = [];
        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            $categoryIds = array_map('intval', $input['category_ids']);
        } elseif ($categoryId) {
            $categoryIds = [$categoryId];
        }

        $supplierId = isset($input['supplier_id']) && $input['supplier_id'] !== '' && $input['supplier_id'] !== null
            ? (int) $input['supplier_id'] : null;

        $excludeZero = config('inventory_check.exclude_zero_day_sell_speed', true);

        $soldMap = $this->getSoldQtyByVariation($business_id, $locationId, $saleStart, $saleEnd, $permittedLocations);
        $fastMap = $this->getAvgSellDaysByVariation(
            $business_id,
            $locationId,
            $saleStart,
            $saleEnd,
            $supplierId,
            $excludeZero,
            $permittedLocations
        );

        $targetStock = (int) config('inventory_check.default_target_stock', 3);
        $maxLine = (int) config('inventory_check.max_order_line_qty', 25);
        $emptyMax = (float) config('inventory_check.empty_tab_max_stock', 1);
        $emptyMinSold = (float) config('inventory_check.empty_tab_min_sold_window', 2);
        $mostSoldMin = (float) config('inventory_check.most_sold_min_qty', 1);
        $fastMaxDays = (float) config('inventory_check.fast_seller_max_avg_days', 21);

        $maxRows = (int) config('inventory_check.max_candidate_rows', 2000);

        $q = DB::table('product_stock_cache as psc')
            ->join('products as p', 'p.id', '=', 'psc.product_id')
            ->where('psc.business_id', $business_id)
            ->where('p.business_id', $business_id)
            ->where('psc.location_id', $locationId);

        if ($permittedLocations !== 'all') {
            $q->whereIn('psc.location_id', $permittedLocations);
        }

        if (!empty($categoryIds)) {
            $q->whereIn('psc.category_id', $categoryIds);
        }

        $rows = $q->select([
            'psc.variation_id',
            'psc.product_id',
            'psc.location_id',
            'psc.stock',
            'psc.sku',
            'psc.product',
            'psc.type',
            'psc.product_variation',
            'psc.variation_name',
            'psc.location_name',
            'psc.category_name',
            'psc.product_custom_field1',
            'psc.total_sold',
            'p.format as product_format',
        ])
            ->orderByDesc('psc.total_sold')
            ->limit($maxRows)
            ->get();

        $candidates = [];
        foreach ($rows as $row) {
            $vid = (int) $row->variation_id;
            $stock = (float) ($row->stock ?? 0);
            $soldWindow = $soldMap[$vid] ?? 0.0;

            $tags = [];
            $reasons = [];

            if ($soldWindow >= $mostSoldMin) {
                $tags[] = 'most_sold';
                $reasons[] = 'sold_' . round($soldWindow, 2) . '_in_window';
            }

            if (isset($fastMap[$vid]) && $fastMap[$vid]['avg_days'] <= $fastMaxDays) {
                $tags[] = 'fast_seller';
                $reasons[] = 'avg_sell_days_' . round($fastMap[$vid]['avg_days'], 1);
            }

            if ($stock <= $emptyMax && $soldWindow >= $emptyMinSold) {
                $tags[] = 'empty_tab';
                $reasons[] = 'low_stock_high_movement';
            }

            if (empty($tags)) {
                continue;
            }

            $suggested = max(0, $targetStock - $stock);
            $suggested = min($maxLine, $suggested);
            if ($suggested < 1 && $soldWindow >= $mostSoldMin) {
                $suggested = min($maxLine, 1);
            }

            $artist = $row->product_custom_field1 ?: '';

            $candidates[] = [
                'variation_id' => $vid,
                'product_id' => (int) $row->product_id,
                'location_id' => (int) $row->location_id,
                'sku' => $row->sku,
                'product' => $row->product,
                'artist' => $artist,
                'format' => $row->product_format,
                'category_name' => $row->category_name,
                'location_name' => $row->location_name,
                'stock' => $stock,
                'sold_qty_window' => round($soldWindow, 4),
                'avg_sell_days' => isset($fastMap[$vid]) ? round($fastMap[$vid]['avg_days'], 2) : null,
                'tags' => array_values(array_unique($tags)),
                'reasons' => $reasons,
                'suggested_qty' => (int) $suggested,
                'variation_label' => $row->type === 'variable'
                    ? trim(($row->product_variation ?? '') . ' — ' . ($row->variation_name ?? ''), ' —')
                    : '',
            ];
        }

        usort($candidates, function ($a, $b) {
            $ca = count($a['tags']);
            $cb = count($b['tags']);
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }

            return ($b['sold_qty_window'] <=> $a['sold_qty_window']);
        });

        return [
            'candidates' => $candidates,
            'meta' => [
                'sale_start' => $saleStart,
                'sale_end' => $saleEnd,
                'location_id' => $locationId,
                'category_ids' => $categoryIds,
                'supplier_id' => $supplierId,
                'counts' => [
                    'rows' => count($candidates),
                ],
            ],
        ];
    }
}
