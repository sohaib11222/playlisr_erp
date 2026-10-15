<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com flat shipping rates (USA / International), moved from the
 * website's /admin/rates and /admin/rates/add (Sarah, 2026-10-15).
 */
class ShippingRatesController extends Controller
{
    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    protected function body(Request $request): ?array
    {
        $usa = trim((string) $request->input('usa'));
        $intl = trim((string) $request->input('international'));
        if ($usa === '' || $intl === '' || !is_numeric($usa) || !is_numeric($intl)) {
            return null;
        }
        return ['usa' => $usa, 'international' => $intl];
    }

    public function index()
    {
        $this->guard();
        $resp = WebsiteBridge::admin('GET', '/rates/all');
        // The website answers 404 "No rates are found." when the list is empty.
        $empty = ($resp['_http'] ?? 0) === 404;
        return view('website_admin.warehouse.rates', [
            'rates' => ($resp['success'] ?? false) ? ($resp['data'] ?? []) : [],
            'error' => ($resp['success'] ?? false) || $empty ? null : ($resp['message'] ?? 'Could not load shipping rates.'),
        ]);
    }

    public function create()
    {
        $this->guard();
        return view('website_admin.warehouse.rates_add');
    }

    public function store(Request $request)
    {
        $this->guard();
        $body = $this->body($request);
        if (!$body) {
            return redirect()->back()->withInput()->with('error', 'Both the USA and International rates are required.');
        }
        $resp = WebsiteBridge::admin('POST', '/rates/add', $body);
        if (!($resp['success'] ?? false)) {
            return redirect()->back()->withInput()->with('error', $resp['message'] ?? 'Could not add the shipping rates.');
        }
        return redirect()->route('wa.warehouse.rates')->with('status', 'Shipping rates added.');
    }

    public function update(Request $request, string $id)
    {
        $this->guard();
        $body = $this->body($request);
        if (!$body) {
            return redirect()->back()->with('error', 'Both the USA and International rates are required.');
        }
        $resp = WebsiteBridge::admin('PUT', '/rates/update/' . rawurlencode($id), $body);
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? 'Shipping rates saved.' : ($resp['message'] ?? 'Could not save the shipping rates.'));
    }

    public function destroy(string $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('DELETE', '/rates/delete/' . rawurlencode($id));
        $ok = ($resp['success'] ?? false) === true;
        return redirect()->back()->with($ok ? 'status' : 'error', $ok ? 'Shipping rate deleted.' : ($resp['message'] ?? 'Could not delete the shipping rate.'));
    }
}
