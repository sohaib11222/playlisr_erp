<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * "Do we have it?" (Sarah 2026-10-08: "i should not have to see all this to
 * verify if i have an album in stock across 2 stores").
 * Search an album, get one line per format: Hollywood count, Pico count,
 * price(s). Duplicate listings of the same format are added together.
 * Read-only; any staff member who can see products can use it.
 */
class StockLookupController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('product.view') && !auth()->user()->can('sell.create')) { abort(403); }
        return view('products.stock_lookup');
    }

    public function data(Request $request)
    {
        if (!auth()->user()->can('product.view') && !auth()->user()->can('sell.create')) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        $q = trim((string) $request->query('q'));
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)), function ($w) { return mb_strlen($w) >= 2; }));
        if (!$words) return response()->json(['groups' => []]);

        $locNames = \DB::table('business_locations')->where('business_id', $business_id)->pluck('name', 'id')->all();
        $query = \DB::table('products as p')
            ->join('variations as v', function ($j) { $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at'); })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0);
        foreach ($words as $w) {
            $query->where(function ($x) use ($w) {
                $like = '%' . $w . '%';
                $x->whereRaw('LOWER(p.name) LIKE ?', [$like])->orWhereRaw('LOWER(p.artist) LIKE ?', [$like])->orWhere('p.sku', 'like', $like);
            });
        }
        $rows = $query->groupBy('p.id')->limit(300)
            ->select('p.id', 'p.name', 'p.sku', \DB::raw('MAX(c.name) as cat'), \DB::raw('MAX(v.sell_price_inc_tax) as price'),
                \DB::raw("(select group_concat(concat(vld.location_id, ':', vld.qty_available) separator '|') from variation_location_details vld where vld.product_id = p.id) as stock"))
            ->get();

        $groups = [];
        foreach ($rows as $r) {
            $hw = 0; $pico = 0;
            foreach (array_filter(explode('|', (string) $r->stock)) as $part) {
                [$lid, $qty] = array_pad(explode(':', $part, 2), 2, 0);
                $n = mb_strtolower((string) ($locNames[(int) $lid] ?? ''));
                if (strpos($n, 'hollywood') !== false) $hw += max(0, (float) $qty);
                elseif (strpos($n, 'pico') !== false) $pico += max(0, (float) $qty);
            }
            $fmt = $r->cat ?: 'Other';
            if (!isset($groups[$fmt])) $groups[$fmt] = ['format' => $fmt, 'hw' => 0, 'pico' => 0, 'prices' => [], 'listings' => []];
            $groups[$fmt]['hw'] += $hw;
            $groups[$fmt]['pico'] += $pico;
            if (($hw + $pico) > 0 && (float) $r->price > 0) $groups[$fmt]['prices'][] = round((float) $r->price, 2);
            $groups[$fmt]['listings'][] = ['id' => (int) $r->id, 'name' => $r->name, 'hw' => $hw, 'pico' => $pico, 'price' => round((float) $r->price, 2)];
        }
        foreach ($groups as &$g) {
            $g['prices'] = array_values(array_unique($g['prices']));
            sort($g['prices']);
            usort($g['listings'], function ($a, $b) { return ($b['hw'] + $b['pico']) <=> ($a['hw'] + $a['pico']); });
        }
        unset($g);
        $groups = array_values($groups);
        usort($groups, function ($a, $b) { return ($b['hw'] + $b['pico']) <=> ($a['hw'] + $a['pico']); });
        return response()->json(['groups' => $groups, 'listings' => count($rows)]);
    }
}
