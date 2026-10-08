<?php

namespace App\Http\Controllers;

use App\Product;
use Illuminate\Http\Request;

/**
 * Price New Buys (Sarah 2026-10-07).
 *
 * Every Buy from Customer line becomes a $0 "Not for selling" product. Staff
 * used to price the copy by making a NEW listing, leaving the $0 one behind
 * forever. This page is the one place to price those exact records: set the
 * name, format and price, and the record goes live (selling, stocked, on the
 * website). Buys get handed off between people, so it shows who bought each
 * item, when, and from which offer, oldest waiting at the bottom.
 */
class PriceBuysController extends Controller
{
    protected function bfcQuery($business_id)
    {
        $q = \DB::table('products as p')
            ->join('variations as v', function ($j) { $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at'); })
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.business_id', $business_id)
            ->where('p.is_inactive', 0)
            ->where('p.not_for_selling', 1);
        if (\Schema::hasColumn('products', 'added_via')) {
            $q->where(function ($w) {
                $w->where('p.added_via', 'buy_from_customer')
                  ->orWhere('p.product_description', 'like', 'Bought from customer%');
            });
        } else {
            $q->where('p.product_description', 'like', 'Bought from customer%');
        }
        return $q;
    }

    public function index(Request $request)
    {
        if (!auth()->user()->can('product.update')) { abort(403, 'Unauthorized action.'); }
        $business_id = $request->session()->get('user.business_id');
        $categories = \DB::table('categories')->where('business_id', $business_id)
            ->where('parent_id', 0)->where('category_type', 'product')->whereNull('deleted_at')
            ->orderBy('name')->pluck('name', 'id');
        return view('products.price_buys', compact('categories'));
    }

    public function data(Request $request)
    {
        if (!auth()->user()->can('product.update')) { abort(403, 'Unauthorized action.'); }
        $business_id = $request->session()->get('user.business_id');
        $rows = $this->bfcQuery($business_id)
            ->select(
                'p.id', 'p.name', 'p.artist', 'p.sku', 'p.category_id', 'p.created_at', 'p.product_description',
                'v.dpp_inc_tax as cost',
                \DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as bought_by"),
                \DB::raw('(select group_concat(bl.name) from product_locations pl join business_locations bl on bl.id = pl.location_id where pl.product_id = p.id) as store'),
                \DB::raw('(select coalesce(sum(vld.qty_available),0) from variation_location_details vld where vld.product_id = p.id) as stock')
            )
            ->orderByDesc('p.created_at')->limit(1000)->get()
            ->map(function ($r) {
                preg_match('/offer\s+(\S+)/i', (string) $r->product_description, $o);
                preg_match('/type:\s*([^|]+)/i', (string) $r->product_description, $t);
                preg_match('/grade:\s*([^|]+)/i', (string) $r->product_description, $g);
                return [
                    'id' => (int) $r->id, 'name' => $r->name, 'artist' => $r->artist, 'sku' => $r->sku,
                    'category_id' => (int) $r->category_id, 'cost' => (float) $r->cost,
                    'bought_by' => trim((string) $r->bought_by), 'bought_on' => substr((string) $r->created_at, 0, 10),
                    'days' => (int) floor((time() - strtotime((string) $r->created_at)) / 86400),
                    'store' => $r->store, 'stock' => (float) $r->stock,
                    'offer' => trim($o[1] ?? ''), 'type' => trim(str_replace('_', ' ', $t[1] ?? '')), 'grade' => trim($g[1] ?? ''),
                ];
            });
        return response()->json(['success' => true, 'rows' => $rows]);
    }

    public function save(Request $request, $id)
    {
        if (!auth()->user()->can('product.update')) { abort(403, 'Unauthorized action.'); }
        $business_id = $request->session()->get('user.business_id');
        $row = $this->bfcQuery($business_id)->where('p.id', (int) $id)->select('p.id', 'v.id as variation_id', 'v.product_variation_id')->first();
        if (!$row) {
            return response()->json(['success' => false, 'msg' => 'Already priced or not found.']);
        }
        $price = round((float) $request->input('price'), 2);
        $name = trim((string) $request->input('name'));
        $categoryId = (int) $request->input('category_id');
        if ($price <= 0) { return response()->json(['success' => false, 'msg' => 'Enter a price.']); }
        if ($name === '') { return response()->json(['success' => false, 'msg' => 'Enter a name.']); }
        if (!$categoryId) { return response()->json(['success' => false, 'msg' => 'Pick a format.']); }

        $artist = trim((string) $request->input('artist'));
        // Same naming standard as the product form: "Artist - Title".
        $canon = \App\Services\ProductNameNormalizer::canonical($artist, $name);
        if ($canon['confident']) {
            $name = $canon['name'];
            $artist = \App\Services\ProductNameNormalizer::properArtistCase($artist);
        }

        $barcode = preg_replace('/\D+/', '', (string) $request->input('barcode'));
        $update = [
            'name' => $name, 'category_id' => $categoryId, 'not_for_selling' => 0, 'updated_at' => now(),
        ];
        if ($artist !== '') { $update['artist'] = $artist; }
        if ($barcode !== '') {
            if (strlen($barcode) < 8) { return response()->json(['success' => false, 'msg' => 'That barcode looks too short.']); }
            $update['sku'] = $barcode;
        }

        $stockNote = null;
        \DB::beginTransaction();
        try {
            \DB::table('products')->where('id', $row->id)->update($update);
            \DB::table('variations')->where('id', $row->variation_id)->update([
                'default_sell_price' => $price, 'sell_price_inc_tax' => $price,
                'sub_sku' => $barcode !== '' ? $barcode : \DB::raw('sub_sku'),
            ]);
            // The buy's purchase is usually left as a draft, so the copy never
            // got stock. Pricing it means it's on the shelf: give it the
            // quantity that was bought, at the store it was bought into.
            $have = (float) \DB::table('variation_location_details')->where('variation_id', $row->variation_id)->sum('qty_available');
            if ($have <= 0) {
                $line = \DB::table('purchase_lines')->where('variation_id', $row->variation_id)->orderByDesc('id')->first();
                $qty = $line ? max(1, (float) $line->quantity) : 1;
                $locId = (int) \DB::table('product_locations')->where('product_id', $row->id)->value('location_id');
                if ($locId) {
                    $existing = \DB::table('variation_location_details')->where('variation_id', $row->variation_id)->where('location_id', $locId)->first();
                    if ($existing) {
                        \DB::table('variation_location_details')->where('id', $existing->id)->update(['qty_available' => $qty]);
                    } else {
                        \DB::table('variation_location_details')->insert([
                            'product_id' => $row->id, 'product_variation_id' => $row->product_variation_id,
                            'variation_id' => $row->variation_id, 'location_id' => $locId, 'qty_available' => $qty,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    $stockNote = 'Price New Buys: stock set to ' . $qty . ' (bought copy priced)';
                }
            }
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::emergency('price-buys save failed: ' . $e->getMessage());
            $msg = stripos($e->getMessage(), 'Duplicate') !== false ? 'That barcode is already on another product.' : 'Save failed, nothing changed.';
            return response()->json(['success' => false, 'msg' => $msg]);
        }

        try {
            $product = Product::find($row->id);
            activity()->performedOn($product)->causedBy(auth()->user())
                ->withProperties(['update_note' => $product->name . ' - priced at $' . number_format($price, 2) . ' on Price New Buys' . ($stockNote ? '; ' . $stockNote : '')])
                ->log('edited');
        } catch (\Throwable $e) {}
        try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([(int) $row->id]); } catch (\Throwable $e) {}

        return response()->json(['success' => true, 'name' => $name]);
    }

    /** "Can't find it" — the copy isn't in the bag/bin. Retire the record. */
    public function missing(Request $request, $id)
    {
        if (!auth()->user()->can('product.update')) { abort(403, 'Unauthorized action.'); }
        $business_id = $request->session()->get('user.business_id');
        $row = $this->bfcQuery($business_id)->where('p.id', (int) $id)->select('p.id')->first();
        if (!$row) { return response()->json(['success' => false, 'msg' => 'Already handled.']); }
        \DB::table('products')->where('id', $row->id)->update(['is_inactive' => 1, 'updated_at' => now()]);
        try {
            $product = Product::find($row->id);
            activity()->performedOn($product)->causedBy(auth()->user())
                ->withProperties(['update_note' => $product->name . ' - marked not found on Price New Buys (retired)'])
                ->log('edited');
        } catch (\Throwable $e) {}
        return response()->json(['success' => true]);
    }
}
