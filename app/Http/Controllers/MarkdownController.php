<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use DB;
use Illuminate\Http\Request;

/**
 * Markdowns (Sarah, 2026-10-26): cut prices on old stock to turn it over and
 * free up cash, without selling below what we paid.
 *  - Age counts from the ORIGINAL listed date (products.listed_at, falls back
 *    to created_at), so a restock or transfer never resets the clock.
 *  - Each step is a % off the ORIGINAL price (the price before the first
 *    markdown), so steps never compound.
 *  - Floor: never below cost x (1 + min margin). No cost on file: never more
 *    than no_cost_max_off % off.
 *  - Every cut is a product_markdowns row; new tags print with the original
 *    listed date (labels/show?listed=1).
 * The Markdown List (ABC report, 30% off C sellers) is a separate report.
 */
class MarkdownController extends Controller
{
    const DEFAULT_RULES = [
        'tiers' => [[90, 10], [180, 20], [270, 30], [365, 40], [540, 50]],
        'min_margin_pct' => 0,
        'no_cost_max_off' => 30,
        'min_price' => 1.99,
        'round_99' => true,
        'exclude_category_ids' => [],
    ];
    const APPLY_MAX = 500;
    const SCAN_MAX = 30000;

    protected function guard()
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected function canApply(): bool
    {
        return BuyFromCustomerController::canApproveOverpay(auth()->user());
    }

    protected function rulesPath(int $business_id): string
    {
        return storage_path('app/markdown-rules-' . $business_id . '.json');
    }

    public function rules(int $business_id): array
    {
        $saved = @json_decode((string) @file_get_contents($this->rulesPath($business_id)), true);
        $rules = array_merge(self::DEFAULT_RULES, is_array($saved) ? $saved : []);
        usort($rules['tiers'], function ($a, $b) { return $a[0] <=> $b[0]; });
        return $rules;
    }

    public function index(Request $request)
    {
        $this->guard();
        $business_id = (int) $request->session()->get('user.business_id');
        $rules = $this->rules($business_id);
        $locations = array_map('ucfirst', BusinessLocation::forDropdown($business_id)->toArray());
        $location_id = (int) $request->input('location_id', 0);
        if (!isset($locations[$location_id])) {
            $location_id = (int) $request->session()->get('pos_duty_location_id', 0) ?: (int) $request->session()->get('user.business_location_id', 0);
            if (!isset($locations[$location_id])) {
                $location_id = (int) (array_keys($locations)[0] ?? 0);
            }
        }
        $categories = Category::where('business_id', $business_id)->where('category_type', 'product')->where('parent_id', 0)->orderBy('name')->pluck('name', 'id')->toArray();
        $category_id = (int) $request->input('category_id', 0);
        $min_age = max(0, (int) $request->input('min_age', 0));

        [$rows, $stats] = $this->candidates($business_id, $location_id, $category_id, $min_age, $rules);
        $shown = array_slice($rows, 0, self::APPLY_MAX);
        $history = $this->history($business_id, 50);
        $canApply = $this->canApply();
        $isAdmin = auth()->user()->hasRole('Admin#' . $business_id);

        return view('markdowns.index', compact('rules', 'locations', 'location_id', 'categories', 'category_id', 'min_age',
            'rows', 'shown', 'stats', 'history', 'canApply', 'isAdmin'));
    }

    /**
     * In-stock items at the store that are due a cut, oldest first, with the
     * suggested price. Nothing is written.
     */
    public function candidates(int $business_id, int $location_id, int $category_id, int $min_age, array $rules, array $onlyVariationIds = []): array
    {
        $firstTier = (int) ($rules['tiers'][0][0] ?? 90);
        $cutoff = now()->subDays(max($firstTier, $min_age))->toDateTimeString();

        $q = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $business_id)
            ->where('vld.location_id', $location_id)
            ->where('vld.qty_available', '>', 0)
            ->whereNull('v.deleted_at')
            ->whereRaw('COALESCE(p.listed_at, p.created_at) <= ?', [$cutoff])
            ->orderByRaw('COALESCE(p.listed_at, p.created_at) asc')
            ->limit(self::SCAN_MAX)
            ->select('p.id as product_id', 'v.id as variation_id', 'p.name', 'v.sub_sku', 'vld.qty_available as qty',
                'v.sell_price_inc_tax as price', 'v.default_purchase_price as cost', 'c.name as category', 'sc.name as sub_category',
                DB::raw('COALESCE(p.listed_at, p.created_at) as listed_at'),
                DB::raw('(SELECT md.original_price FROM product_markdowns md WHERE md.variation_id = v.id ORDER BY md.id ASC LIMIT 1) as original_price'),
                DB::raw('(SELECT MAX(md.created_at) FROM product_markdowns md WHERE md.variation_id = v.id) as last_markdown_at'));
        if ($category_id) {
            $q->where('p.category_id', $category_id);
        }
        if (!empty($rules['exclude_category_ids'])) {
            $q->whereNotIn('p.category_id', array_map('intval', $rules['exclude_category_ids']));
        }
        if ($onlyVariationIds) {
            $q->whereIn('v.id', $onlyVariationIds);
        }

        $rows = [];
        $stats = ['scanned' => 0, 'due' => 0, 'qty' => 0, 'cost_tied' => 0.0, 'value_now' => 0.0, 'value_new' => 0.0,
            'at_floor' => 0, 'cost_over_price' => 0, 'no_cost' => 0, 'capped' => false];
        $now = now();
        foreach ($q->get() as $r) {
            $stats['scanned']++;
            $calc = $this->suggest($r, $rules, $now);
            if ($calc['cost_over_price']) {
                $stats['cost_over_price']++;
            }
            if (!$calc['due']) {
                continue;
            }
            $qty = (float) $r->qty;
            $stats['due']++;
            $stats['qty'] += $qty;
            $stats['cost_tied'] += $qty * max(0, (float) $r->cost);
            $stats['value_now'] += $qty * (float) $r->price;
            $stats['value_new'] += $qty * $calc['new_price'];
            if ($calc['hit_floor']) $stats['at_floor']++;
            if ($calc['no_cost']) $stats['no_cost']++;
            $rows[] = array_merge([
                'product_id' => (int) $r->product_id,
                'variation_id' => (int) $r->variation_id,
                'name' => $r->name,
                'sku' => (string) $r->sub_sku,
                'category' => trim($r->category . ($r->sub_category ? ' / ' . $r->sub_category : '')),
                'qty' => $qty,
                'price' => (float) $r->price,
                'cost' => (float) $r->cost,
                'listed' => \Carbon::parse($r->listed_at)->format('m/d/y'),
                'last_markdown' => $r->last_markdown_at ? \Carbon::parse($r->last_markdown_at)->format('m/d/y') : '',
            ], $calc);
        }
        $stats['capped'] = $stats['scanned'] >= self::SCAN_MAX;
        return [$rows, $stats];
    }

    /** The price this item should be at today, and whether that is a cut. */
    public function suggest($r, array $rules, $now = null): array
    {
        $now = $now ?: now();
        $price = round((float) $r->price, 2);
        $cost = (float) $r->cost;
        $original = $r->original_price !== null ? round((float) $r->original_price, 2) : $price;
        $age = (int) \Carbon::parse($r->listed_at)->diffInDays($now);
        $pct = 0;
        foreach ($rules['tiers'] as $t) {
            if ($age >= (int) $t[0]) {
                $pct = (int) $t[1];
            }
        }
        $noCost = $cost <= 0;
        $floor = $noCost
            ? $original * (1 - ((float) $rules['no_cost_max_off']) / 100)
            : $cost * (1 + ((float) $rules['min_margin_pct']) / 100);
        $floor = max($floor, (float) $rules['min_price']);
        $target = $original * (1 - $pct / 100);
        $new = max($target, $floor);
        if (!empty($rules['round_99']) && $new >= 1) {
            $new = ceil(round($new, 2)) - 0.01; // up to the next .99, so never under the floor
        }
        $new = round($new, 2);
        $due = $pct > 0 && $new <= $price - 0.5;
        return [
            'age_days' => $age,
            'pct' => $pct,
            'original_price' => $original,
            'floor' => round($floor, 2),
            'new_price' => $new,
            'pct_off_now' => $price > 0 ? (int) round((1 - $new / $price) * 100) : 0,
            'hit_floor' => $floor > $target + 0.001,
            'no_cost' => $noCost,
            'cost_over_price' => !$noCost && $cost >= $price,
            'due' => $due,
        ];
    }

    public function apply(Request $request)
    {
        $this->guard();
        if (!$this->canApply()) {
            abort(403, 'Only a manager can apply markdowns.');
        }
        $business_id = (int) $request->session()->get('user.business_id');
        $rules = $this->rules($business_id);
        $location_id = (int) $request->input('location_id');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $request->input('variation_ids', []))))), 0, self::APPLY_MAX);
        if (!$ids) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Pick at least one item.']);
        }

        // Re-price on the server; never trust the numbers on the page.
        [$rows] = $this->candidates($business_id, $location_id, 0, 0, $rules, $ids);
        $productIds = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $v = \App\Variation::where('id', $row['variation_id'])->lockForUpdate()->first();
                if (!$v || abs((float) $v->sell_price_inc_tax - $row['price']) > 0.005) {
                    continue; // price changed since the page loaded
                }
                $v->default_sell_price = $row['new_price'];
                $v->sell_price_inc_tax = $row['new_price'];
                $v->save();
                DB::table('product_markdowns')->insert([
                    'business_id' => $business_id,
                    'product_id' => $row['product_id'],
                    'variation_id' => $row['variation_id'],
                    'original_price' => $row['original_price'],
                    'old_price' => $row['price'],
                    'new_price' => $row['new_price'],
                    'cost' => $row['cost'],
                    'age_days' => $row['age_days'],
                    'pct_off' => $row['pct'],
                    'hit_floor' => $row['hit_floor'] ? 1 : 0,
                    'listed_at' => \Carbon::createFromFormat('m/d/y', $row['listed'])->toDateString(),
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $productIds[] = $row['product_id'];
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Markdown apply failed: ' . $e->getMessage());
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Nothing changed, the markdown failed: ' . $e->getMessage()]);
        }

        $productIds = array_values(array_unique($productIds));
        foreach (array_chunk($productIds, 100) as $chunk) {
            try { (new \App\Services\NivessaStockNotifier())->pushProductChanged($chunk); } catch (\Throwable $e) {}
        }
        try {
            activity()->causedBy(auth()->user())
                ->withProperties(['markdown_products' => count($productIds), 'location_id' => $location_id])
                ->log('markdowns_applied');
        } catch (\Throwable $e) {}

        $labels = url('/labels/show?listed=1&product_ids=' . implode(',', $productIds));
        return redirect('/markdowns?location_id=' . $location_id)
            ->with('status', ['success' => 1, 'msg' => sprintf('Marked down %d item%s. The website has the new prices.', count($productIds), count($productIds) === 1 ? '' : 's')])
            ->with('markdown_labels_url', $productIds ? $labels : null);
    }

    public function saveRules(Request $request)
    {
        $this->guard();
        $business_id = (int) $request->session()->get('user.business_id');
        if (!auth()->user()->hasRole('Admin#' . $business_id)) {
            abort(403, 'Only an admin can change the markdown rules.');
        }
        $tiers = [];
        foreach ((array) $request->input('tiers', []) as $t) {
            $days = (int) ($t['days'] ?? 0);
            $pct = (int) ($t['pct'] ?? 0);
            if ($days > 0 && $pct > 0 && $pct < 100) {
                $tiers[] = [$days, $pct];
            }
        }
        if (!$tiers) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Add at least one step (days and % off).']);
        }
        $rules = [
            'tiers' => $tiers,
            'min_margin_pct' => max(0, (float) $request->input('min_margin_pct', 0)),
            'no_cost_max_off' => min(90, max(0, (float) $request->input('no_cost_max_off', 30))),
            'min_price' => max(0, (float) $request->input('min_price', 1.99)),
            'round_99' => (bool) $request->input('round_99'),
            'exclude_category_ids' => array_values(array_map('intval', (array) $request->input('exclude_category_ids', []))),
        ];
        file_put_contents($this->rulesPath($business_id), json_encode($rules, JSON_PRETTY_PRINT));
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Markdown rules saved.']);
    }

    protected function history(int $business_id, int $limit): array
    {
        return DB::table('product_markdowns as md')
            ->join('products as p', 'p.id', '=', 'md.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'md.created_by')
            ->where('md.business_id', $business_id)
            ->orderBy('md.id', 'desc')
            ->limit($limit)
            ->get(['md.*', 'p.name', 'u.first_name', 'u.last_name'])
            ->map(function ($r) {
                return (object) [
                    'date' => \Carbon::parse($r->created_at)->format('m/d/y'),
                    'name' => $r->name,
                    'listed' => $r->listed_at ? \Carbon::parse($r->listed_at)->format('m/d/y') : '',
                    'original' => (float) $r->original_price,
                    'old' => (float) $r->old_price,
                    'new' => (float) $r->new_price,
                    'cost' => (float) $r->cost,
                    'pct' => (int) $r->pct_off,
                    'floor' => (bool) $r->hit_floor,
                    'by' => trim($r->first_name . ' ' . $r->last_name),
                ];
            })->all();
    }
}
