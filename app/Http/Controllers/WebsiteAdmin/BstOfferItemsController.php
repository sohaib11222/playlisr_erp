<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Items Nivessa buys, shown as suggestions with price ranges on
 * nivessa.com/buy-sell-trade. Moved from /admin/bst-offer-items
 * (Sarah, 2026-10-15).
 */
class BstOfferItemsController extends Controller
{
    use WaContentHelpers;

    const LIMIT = 50;

    public function index(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('search', ''));
        $resp = WebsiteBridge::admin('GET', '/admin/bst-offer-items?page=' . $page . '&limit=' . self::LIMIT . ($search !== '' ? '&search=' . rawurlencode($search) : ''));
        $items = $this->listFrom($resp);
        $total = (int) ($this->pick($resp, 'pagination', [])['total'] ?? count($items));
        return view('website_admin.content.bst_offer_items', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Failed to load items.') : null,
            'items' => $items,
            'page' => $page,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / self::LIMIT)),
            'search' => $search,
        ]);
    }

    public function store(Request $request)
    {
        $this->guardStaff();
        $body = $this->body($request);
        if ($body['label'] === '') {
            return back()->withInput()->with('error', 'Label is required.');
        }
        $resp = WebsiteBridge::admin('POST', '/admin/bst-offer-items', $body);
        return $this->bridgeBack($resp, 'Item created.', 'Save failed.');
    }

    public function update(Request $request, $id)
    {
        $this->guardStaff();
        $body = $this->body($request);
        if ($body['label'] === '') {
            return back()->withInput()->with('error', 'Label is required.');
        }
        $resp = WebsiteBridge::admin('PUT', '/admin/bst-offer-items/' . rawurlencode($id), $body);
        return $this->bridgeBack($resp, 'Item saved.', 'Save failed.');
    }

    public function toggle(Request $request, $id)
    {
        $this->guardStaff();
        $active = $request->input('active') === '1';
        $resp = WebsiteBridge::admin('PUT', '/admin/bst-offer-items/' . rawurlencode($id), ['active' => $active]);
        return $this->bridgeBack($resp, $active ? 'Item is active.' : 'Item is inactive.', 'Update failed.');
    }

    public function destroy($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('DELETE', '/admin/bst-offer-items/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'Item deleted.', 'Delete failed.');
    }

    protected function body(Request $request): array
    {
        $num = function ($k) use ($request) {
            $v = $request->input($k);
            return ($v === null || $v === '') ? 0 : (float) $v;
        };
        return [
            'label' => trim((string) $request->input('label', '')),
            'keywords' => $this->csvList($request->input('keywords', '')),
            'category' => trim((string) $request->input('category', '')),
            'priceMin' => $num('priceMin'),
            'priceMax' => $num('priceMax'),
            'bstPageSlug' => trim((string) $request->input('bstPageSlug', '')),
            'displayOrder' => (int) $num('displayOrder'),
            'active' => $request->has('active'),
        ];
    }
}
