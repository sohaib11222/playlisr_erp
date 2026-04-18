<?php

namespace App\Http\Controllers;

use App\Category;
use App\ProductEntryRule;
use App\Services\ProductEntryRuleService;
use Illuminate\Http\Request;

class ProductEntryRuleController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $rules = ProductEntryRule::where('business_id', $business_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $category_combos = Category::flattenedProductCategoryCombos($business_id);

        return view('product_entry_rules.index', compact('rules', 'category_combos'));
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate([
            'trigger_type' => 'required|in:title,category_combo',
            'trigger_value' => 'required|string|max:255',
            'artist' => 'nullable|string|max:255',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'category_combo' => 'nullable|string',
            'is_active' => 'nullable|in:0,1',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $combo = Category::parseCategoryComboValue($request->input('category_combo'));
        ProductEntryRule::create([
            'business_id' => $request->session()->get('user.business_id'),
            'trigger_type' => $request->input('trigger_type'),
            'trigger_value' => trim((string) $request->input('trigger_value')),
            'artist' => trim((string) $request->input('artist')),
            'purchase_price' => $request->filled('purchase_price') ? (float) $request->input('purchase_price') : null,
            'selling_price' => $request->filled('selling_price') ? (float) $request->input('selling_price') : null,
            'category_id' => $combo['category_id'],
            'sub_category_id' => $combo['sub_category_id'],
            'is_active' => (int) $request->input('is_active', 1),
            'sort_order' => (int) $request->input('sort_order', 0),
        ]);
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Product entry rule added.']);
    }

    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $request->validate([
            'trigger_type' => 'required|in:title,category_combo',
            'trigger_value' => 'required|string|max:255',
            'artist' => 'nullable|string|max:255',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'category_combo' => 'nullable|string',
            'is_active' => 'nullable|in:0,1',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $business_id = $request->session()->get('user.business_id');
        $rule = ProductEntryRule::where('business_id', $business_id)->findOrFail($id);
        $combo = Category::parseCategoryComboValue($request->input('category_combo'));
        $rule->trigger_type = $request->input('trigger_type');
        $rule->trigger_value = trim((string) $request->input('trigger_value'));
        $rule->artist = trim((string) $request->input('artist'));
        $rule->purchase_price = $request->filled('purchase_price') ? (float) $request->input('purchase_price') : null;
        $rule->selling_price = $request->filled('selling_price') ? (float) $request->input('selling_price') : null;
        $rule->category_id = $combo['category_id'];
        $rule->sub_category_id = $combo['sub_category_id'];
        $rule->is_active = (int) $request->input('is_active', 1);
        $rule->sort_order = (int) $request->input('sort_order', 0);
        $rule->save();
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Product entry rule updated.']);
    }

    public function destroy(Request $request, $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');
        $rule = ProductEntryRule::where('business_id', $business_id)->findOrFail($id);
        $rule->delete();
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Product entry rule deleted.']);
    }

    public function resolve(Request $request, ProductEntryRuleService $service)
    {
        if (!auth()->user()) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');
        $resolved = $service->resolve(
            $business_id,
            $request->input('title', ''),
            $request->input('category_id'),
            $request->input('sub_category_id')
        );
        return response()->json(['success' => true, 'rule' => $resolved]);
    }
}

