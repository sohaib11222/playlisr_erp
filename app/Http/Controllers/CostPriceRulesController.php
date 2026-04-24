<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CostPriceRulesController extends Controller
{
    // Sarah's category → cost rules. Category names match on lower(trim(name)).
    // Applied only to variations whose default_purchase_price is NULL or 0
    // (never overwrites an existing cost).
    const RULES = [
        ['label' => 'New Vinyl',             'match' => ['new vinyl', 'sealed vinyl'],             'cost' => 17.00],
        ['label' => 'Used Vinyl',            'match' => ['used vinyl'],                            'cost' => 0.10],
        ['label' => 'CDs (used)',            'match' => ['used cds', 'cds (used)', 'used cd'],     'cost' => 0.10],
        ['label' => 'New CDs',               'match' => ['new cds', 'new cd', 'sealed cd', 'cd (sealed)'], 'cost' => 6.00],
        ['label' => 'Cassettes (used)',      'match' => ['used cassettes', 'cassettes (used)', 'used cassette', 'cassettes'], 'cost' => 0.30],
        ['label' => 'New Cassettes',         'match' => ['new cassettes', 'new cassette', 'cassettes - sealed', 'sealed cassettes'], 'cost' => 6.00],
        ['label' => 'VHS',                   'match' => ['vhs'],                                   'cost' => 0.10],
        ['label' => 'Damaged Vinyl & CDs',   'match' => ['damaged', 'damaged vinyl', 'damaged cds', 'damaged vinyl & cds'], 'cost' => 0.00],
    ];

    public function index()
    {
        return view('admin.cost_price_rules', [
            'rules' => self::RULES,
            'results' => null,
            'mode' => null,
            'categories' => $this->allCategories(),
        ]);
    }

    private function allCategories()
    {
        $businessId = request()->session()->get('user.business_id');
        return DB::table('categories')
            ->where('business_id', $businessId)
            ->where('parent_id', 0)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');

        $results = [];
        $grandUpdated = 0;
        $grandMatchedCategory = 0;

        foreach (self::RULES as $rule) {
            $categoryIds = DB::table('categories')
                ->where('business_id', $businessId)
                ->where('parent_id', 0)
                ->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(TRIM(name))'), $rule['match'])
                ->pluck('id')
                ->all();

            if (empty($categoryIds)) {
                $results[] = [
                    'label' => $rule['label'],
                    'cost' => $rule['cost'],
                    'category_ids' => [],
                    'eligible' => 0,
                    'updated' => 0,
                    'note' => 'No ERP category matched — rename a category to "' . $rule['label'] . '" or tell me the right name',
                ];
                continue;
            }

            // Variations whose product is in a matched top-level category AND
            // have missing cost. Rules map to parent categories only.
            $eligibleQuery = DB::table('variations')
                ->join('products', 'products.id', '=', 'variations.product_id')
                ->whereIn('products.category_id', $categoryIds)
                ->where(function ($q) {
                    $q->whereNull('variations.default_purchase_price')
                      ->orWhere('variations.default_purchase_price', 0);
                })
                ->whereNull('variations.deleted_at')
                ->whereNull('products.deleted_at');

            $eligible = (clone $eligibleQuery)->count('variations.id');
            $grandMatchedCategory += $eligible;

            $updated = 0;
            if ($commit && $eligible > 0) {
                $ids = (clone $eligibleQuery)->pluck('variations.id')->all();
                foreach (array_chunk($ids, 500) as $chunk) {
                    $updated += DB::table('variations')
                        ->whereIn('id', $chunk)
                        ->update([
                            'default_purchase_price' => $rule['cost'],
                            'dpp_inc_tax'            => $rule['cost'],
                            'updated_at'             => now(),
                        ]);
                }
                $grandUpdated += $updated;
            }

            $results[] = [
                'label' => $rule['label'],
                'cost' => $rule['cost'],
                'category_ids' => $categoryIds,
                'eligible' => $eligible,
                'updated' => $updated,
                'note' => null,
            ];
        }

        return view('admin.cost_price_rules', [
            'rules' => self::RULES,
            'results' => $results,
            'mode' => $commit ? 'commit' : 'preview',
            'grand_matched' => $grandMatchedCategory,
            'grand_updated' => $grandUpdated,
            'categories' => $this->allCategories(),
        ]);
    }
}
