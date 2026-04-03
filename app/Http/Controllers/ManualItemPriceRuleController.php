<?php

namespace App\Http\Controllers;

use App\ManualItemPriceRule;
use Illuminate\Http\Request;

class ManualItemPriceRuleController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $rules = ManualItemPriceRule::where('business_id', $business_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('manual_item_price_rules.index', compact('rules'));
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'label' => 'required|string|max:255',
            'keywords' => 'required|string|max:500',
            'price' => 'required|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|in:0,1',
        ]);

        ManualItemPriceRule::create([
            'business_id' => $request->session()->get('user.business_id'),
            'label' => trim((string) $request->input('label')),
            'keywords' => trim((string) $request->input('keywords')),
            'price' => (float) $request->input('price'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'is_active' => (int) $request->input('is_active', 1),
        ]);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Manual item price rule added.']);
    }

    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'label' => 'required|string|max:255',
            'keywords' => 'required|string|max:500',
            'price' => 'required|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|in:0,1',
        ]);

        $business_id = $request->session()->get('user.business_id');
        $rule = ManualItemPriceRule::where('business_id', $business_id)->findOrFail($id);
        $rule->label = trim((string) $request->input('label'));
        $rule->keywords = trim((string) $request->input('keywords'));
        $rule->price = (float) $request->input('price');
        $rule->sort_order = (int) $request->input('sort_order', 0);
        $rule->is_active = (int) $request->input('is_active', 1);
        $rule->save();

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Manual item price rule updated.']);
    }

    public function destroy(Request $request, $id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $rule = ManualItemPriceRule::where('business_id', $business_id)->findOrFail($id);
        $rule->delete();

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Manual item price rule deleted.']);
    }
}

