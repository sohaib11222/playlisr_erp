<?php

namespace App\Http\Controllers;

use App\Category;
use App\DocumentAndNote;
use App\SourcingTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sourcing dashboard: merchandise categories (toys, comics, cards, etc.)
 * that get actively hunted for rather than reordered from a supplier —
 * demand swings category by category, so this replaces "click and ship"
 * with priority + buy-price guidance + recent sales + crowd-sourced tips
 * on where to find each category. Shared across both stores, not
 * store-scoped.
 *
 * Sarah 2026-09-11: the whole point is letting sourcing staff judge demand
 * themselves, so the sales numbers here are visible to any logged-in
 * employee — a deliberate exception to the aggregated-sales-is-admin-only
 * rule used elsewhere (see ReportController::categorySalesReport).
 * Editing priority/target buy price requires the 'sourcing.manage'
 * permission (Admins get it automatically via Gate::before).
 */
class SourcingController extends Controller
{
    const PRIORITY_LABELS = [
        'high'   => 'High',
        'medium' => 'Medium',
        'low'    => 'Low',
    ];

    const DAY_RANGES = [30, 90, 180, 365];

    private function canManage()
    {
        return auth()->user()->can('sourcing.manage');
    }

    public function index(Request $request)
    {
        $businessId = auth()->user()->business_id;

        $days = (int) $request->get('days', 90);
        if (!in_array($days, self::DAY_RANGES)) {
            $days = 90;
        }
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();

        $targets = SourcingTarget::where('business_id', $businessId)->get()->keyBy('category_id');
        $categoryIds = $targets->keys()->all();

        $categories = Category::whereIn('id', $categoryIds)->get();
        $parents = $categories->where('parent_id', 0)->sortBy('name');
        $childrenByParent = $categories->where('parent_id', '!=', 0)->groupBy('parent_id');

        $sales = $this->salesByCategory($businessId, $categoryIds, $startDate, $endDate);
        $avgSellPrices = $this->avgSellPriceByCategory($categoryIds);

        $notes = DocumentAndNote::where('notable_type', Category::class)
            ->whereIn('notable_id', $categoryIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('notable_id');

        $groups = [];
        foreach ($parents as $parent) {
            $children = ($childrenByParent[$parent->id] ?? collect())->sortBy('name');
            $groups[] = [
                'parent' => $this->buildRow($parent, $targets, $sales, $avgSellPrices, $notes),
                'children' => $children->map(function ($child) use ($targets, $sales, $avgSellPrices, $notes) {
                    return $this->buildRow($child, $targets, $sales, $avgSellPrices, $notes);
                })->values(),
            ];
        }

        return view('sourcing.index', [
            'groups' => $groups,
            'priorityLabels' => self::PRIORITY_LABELS,
            'dayRanges' => self::DAY_RANGES,
            'days' => $days,
            'canManage' => $this->canManage(),
        ]);
    }

    public function updatePriority(Request $request, $categoryId)
    {
        if (!$this->canManage()) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate(['priority' => 'required|in:high,medium,low']);

        $businessId = auth()->user()->business_id;
        $category = Category::where('business_id', $businessId)->findOrFail($categoryId);

        $target = SourcingTarget::firstOrNew(['business_id' => $businessId, 'category_id' => $category->id]);
        $target->priority = $request->priority;
        $target->updated_by = auth()->id();
        $target->save();

        return back()->with('status', ['success' => true, 'msg' => "{$category->name} priority updated."]);
    }

    public function updateTargetPrice(Request $request, $categoryId)
    {
        if (!$this->canManage()) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate(['target_buy_price' => 'nullable|numeric|min:0']);

        $businessId = auth()->user()->business_id;
        $category = Category::where('business_id', $businessId)->findOrFail($categoryId);

        $target = SourcingTarget::firstOrNew(['business_id' => $businessId, 'category_id' => $category->id]);
        $target->target_buy_price = $request->target_buy_price !== '' ? $request->target_buy_price : null;
        $target->updated_by = auth()->id();
        $target->save();

        return back()->with('status', ['success' => true, 'msg' => "{$category->name} target buy price updated."]);
    }

    public function storeIdea(Request $request, $categoryId)
    {
        $request->validate(['description' => 'required|string|max:2000']);

        $businessId = auth()->user()->business_id;
        $category = Category::where('business_id', $businessId)->findOrFail($categoryId);

        DocumentAndNote::create([
            'business_id' => $businessId,
            'notable_id' => $category->id,
            'notable_type' => Category::class,
            'description' => $request->description,
            'is_private' => 0,
            'created_by' => auth()->id(),
        ]);

        return back()->with('status', ['success' => true, 'msg' => 'Sourcing tip added.']);
    }

    public function destroyIdea($id)
    {
        $note = DocumentAndNote::where('notable_type', Category::class)->findOrFail($id);

        if (!$this->canManage() && $note->created_by != auth()->id()) {
            abort(403, 'Unauthorized action.');
        }

        $note->delete();

        return back()->with('status', ['success' => true, 'msg' => 'Sourcing tip removed.']);
    }

    private function buildRow($category, $targets, $sales, $avgSellPrices, $notes)
    {
        $target = $targets->get($category->id);

        return [
            'category' => $category,
            'priority' => $target->priority ?? 'medium',
            'target_buy_price' => $target->target_buy_price ?? null,
            'avg_sell_price' => $avgSellPrices[$category->id] ?? null,
            'units_sold' => $sales[$category->id]['units_sold'] ?? 0,
            'revenue' => $sales[$category->id]['revenue'] ?? 0,
            'notes' => $notes->get($category->id, collect()),
        ];
    }

    /**
     * Units sold + revenue per category over the given window. A category
     * row picks up sales from products tagged with it either as their
     * top-level category or their sub-category, so a parent row totals
     * across all its children while a child row shows just that subtype.
     */
    private function salesByCategory($businessId, array $categoryIds, $startDate, $endDate)
    {
        if (empty($categoryIds)) {
            return [];
        }

        $rows = DB::table('categories')
            ->whereIn('categories.id', $categoryIds)
            ->join('products', function ($join) {
                $join->on('products.category_id', '=', 'categories.id')
                    ->orOn('products.sub_category_id', '=', 'categories.id');
            })
            ->join('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
            ->join('transactions', function ($join) use ($businessId, $startDate, $endDate) {
                $join->on('transaction_sell_lines.transaction_id', '=', 'transactions.id')
                    ->where('transactions.business_id', $businessId)
                    ->where('transactions.type', 'sell')
                    ->where('transactions.status', 'final')
                    ->whereBetween(DB::raw('DATE(transactions.transaction_date)'), [$startDate, $endDate]);
            })
            ->select(
                'categories.id as category_id',
                DB::raw('SUM(transaction_sell_lines.quantity) as units_sold'),
                DB::raw('SUM(transaction_sell_lines.quantity * transaction_sell_lines.unit_price_inc_tax) as revenue')
            )
            ->groupBy('categories.id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->category_id] = [
                'units_sold' => (float) $row->units_sold,
                'revenue' => (float) $row->revenue,
            ];
        }

        return $out;
    }

    private function avgSellPriceByCategory(array $categoryIds)
    {
        if (empty($categoryIds)) {
            return [];
        }

        $rows = DB::table('categories')
            ->whereIn('categories.id', $categoryIds)
            ->join('products', function ($join) {
                $join->on('products.category_id', '=', 'categories.id')
                    ->orOn('products.sub_category_id', '=', 'categories.id');
            })
            ->join('variations', 'variations.product_id', '=', 'products.id')
            ->select('categories.id as category_id', DB::raw('AVG(variations.default_sell_price) as avg_sell_price'))
            ->groupBy('categories.id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->category_id] = (float) $row->avg_sell_price;
        }

        return $out;
    }
}
