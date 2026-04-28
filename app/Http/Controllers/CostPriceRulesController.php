<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CostPriceRulesController extends Controller
{
    // Sarah's category → cost rules. Category names match on lower(trim(name)).
    // Applied only to variations whose default_purchase_price is NULL or 0
    // (never overwrites an existing cost).
    // Labels match Nivessa's actual ERP category names (visible at the bottom
    // of the cost-price-rules page). 'match' values are lowercase aliases that
    // the matcher accepts — kept liberal so renames upstream don't break us.
    const RULES = [
        ['label' => 'Sealed Vinyl',          'match' => ['sealed vinyl', 'new vinyl'],             'cost' => 17.00],
        ['label' => 'Used Vinyl',            'match' => ['used vinyl'],                            'cost' => 0.35],
        ['label' => 'Used CD',               'match' => ['used cd', 'used cds', 'cds (used)'],     'cost' => 0.10],
        ['label' => 'Sealed CD / CD (Sealed)', 'match' => ['sealed cd', 'cd (sealed)', 'new cds', 'new cd'], 'cost' => 6.00],
        ['label' => 'Cassettes',             'match' => ['cassettes', 'used cassettes', 'cassettes (used)', 'used cassette'], 'cost' => 0.30],
        ['label' => 'Cassettes - Sealed',    'match' => ['cassettes - sealed', 'sealed cassettes', 'new cassettes', 'new cassette'], 'cost' => 6.00],
        ['label' => 'VHS',                   'match' => ['vhs'],                                   'cost' => 0.10],
        ['label' => '7", 45 RPM',            'match' => ['7", 45 rpm', '7"', '45 rpm', '7 inch'],  'cost' => 0.15],
        ['label' => '8 track',               'match' => ['8 track', '8-track', 'eight track'],     'cost' => 0.25],
        ['label' => 'DVD/Blu Ray',           'match' => ['dvd/blu ray', 'dvd', 'blu ray', 'dvd / blu ray', 'dvd-blu ray'], 'cost' => 0.25],
        ['label' => 'Books & Magazines',     'match' => ['books & magazines', 'books and magazines', 'books'], 'cost' => 0.40],
        ['label' => 'Movies',                'match' => ['movies'],                                'cost' => 0.25],
        ['label' => 'Trading Cards',         'match' => ['trading cards'],                         'cost' => 6.00],
        ['label' => 'Apparel',               'match' => ['apparel'],                               'cost' => 3.00],
        ['label' => 'Video Games',           'match' => ['video games'],                           'cost' => 1.25],
        ['label' => 'Laser Disc',            'match' => ['laser disc', 'laserdisc'],               'cost' => 0.20],
        ['label' => 'Record Players',        'match' => ['record players', 'record player'],       'cost' => 35.00],
        ['label' => 'Magazines',             'match' => ['magazines'],                             'cost' => 1.50],
        ['label' => 'Audio Gear',            'match' => ['audio gear'],                            'cost' => 20.00],
        ['label' => 'Gift Items',            'match' => ['gift items'],                            'cost' => 4.00],
        ['label' => 'Toys',                  'match' => ['toys'],                                  'cost' => 3.00],
        ['label' => 'Accessories & Novelties', 'match' => ['accessories & novelties', 'acessories & novelties', 'accessories and novelties'], 'cost' => 2.00],
        ['label' => 'Pictures & Posters',    'match' => ['pictures & posters', 'pictures and posters'], 'cost' => 5.00],
        ['label' => 'Clothing',              'match' => ['clothing'],                              'cost' => 3.00],
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
        $snapshotRows = []; // BEFORE state for undo, only collected when committing.

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
            // NEVER overwrites existing non-zero costs — those are real values
            // someone entered, leave them alone.
            $eligibleQuery = DB::table('variations')
                ->join('products', 'products.id', '=', 'variations.product_id')
                ->whereIn('products.category_id', $categoryIds)
                ->where(function ($q) {
                    $q->whereNull('variations.default_purchase_price')
                      ->orWhere('variations.default_purchase_price', 0);
                })
                ->whereNull('variations.deleted_at');

            $eligible = (clone $eligibleQuery)->count('variations.id');
            $grandMatchedCategory += $eligible;

            $updated = 0;
            if ($commit && $eligible > 0) {
                $ids = (clone $eligibleQuery)->pluck('variations.id')->all();

                // Snapshot BEFORE state of every row we touch — required by
                // /admin/admin-action-history undo, per post-incident rules.
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

        // Persist the snapshot file AFTER all rules ran, when committing.
        if ($commit && !empty($snapshotRows)) {
            $timestamp = now()->format('Y-m-d_His');
            $snapshotKey = "cost-price-rules-{$timestamp}";
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp' => now()->toDateTimeString(),
                    'action' => 'cost-price-rules',
                    'business_id' => $businessId,
                    'rows' => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );
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
