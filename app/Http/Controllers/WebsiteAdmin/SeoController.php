<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com SEO Manager (meta titles, descriptions, robots per page),
 * moved from /admin/seo (Sarah, 2026-10-15).
 */
class SeoController extends Controller
{
    use WaContentHelpers;

    const PAGE_TYPES = [
        'homepage' => 'Homepage', 'products' => 'Products Page', 'product-detail' => 'Product Detail',
        'category' => 'Category Page', 'about' => 'About Page', 'contact' => 'Contact Page',
        'careers' => 'Careers Page', 'faq' => 'FAQ Page', 'privacy' => 'Privacy Policy',
        'terms' => 'Terms of Service', 'returns' => 'Returns Policy', 'shipping' => 'Shipping Info',
        'custom' => 'Custom Page',
    ];

    const ROBOTS = [
        'index,follow' => 'Index, Follow (default)', 'noindex,follow' => 'No Index, Follow',
        'index,nofollow' => 'Index, No Follow', 'noindex,nofollow' => 'No Index, No Follow',
    ];

    public function index(Request $request)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('GET', '/seo');
        $unlinked = WebsiteBridge::admin('GET', '/seo/unlinked-pages');
        $items = $this->listFrom($resp);
        $q = mb_strtolower(trim((string) $request->input('search', '')));
        if ($q !== '') {
            $items = array_values(array_filter($items, function ($i) use ($q) {
                foreach (['pageName', 'pageSlug', 'title'] as $k) {
                    if (mb_strpos(mb_strtolower((string) ($i[$k] ?? '')), $q) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }
        return view('website_admin.content.seo', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load SEO settings.') : null,
            'items' => $items,
            'unlinked' => $this->listFrom($unlinked),
            'search' => (string) $request->input('search', ''),
            'pageTypes' => self::PAGE_TYPES,
            'robots' => self::ROBOTS,
        ]);
    }

    public function store(Request $request)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/seo', $this->body($request));
        return $this->bridgeBack($resp, 'SEO settings created.', 'Failed to save the SEO settings.');
    }

    public function update(Request $request, $id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('PUT', '/seo/' . rawurlencode($id), $this->body($request));
        return $this->bridgeBack($resp, 'SEO settings updated.', 'Failed to save the SEO settings.');
    }

    public function destroy($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('DELETE', '/seo/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'SEO settings deleted.', 'Failed to delete the SEO settings.');
    }

    public function defaults()
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/seo/default', []);
        return $this->bridgeBack($resp, $resp['message'] ?? 'Default SEO settings created.', 'Failed to create the defaults.');
    }

    public function sync()
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/seo/sync-pages', [], 60);
        return $this->bridgeBack($resp, $resp['message'] ?? 'Pages synced.', 'Failed to sync pages.');
    }

    protected function body(Request $request): array
    {
        $type = (string) $request->input('pageType', 'custom');
        $robots = (string) $request->input('robots', 'index,follow');
        $s = function ($k) use ($request) {
            return (string) $request->input($k, '');
        };
        return [
            'pageType' => array_key_exists($type, self::PAGE_TYPES) ? $type : 'custom',
            'pageSlug' => $s('pageSlug'),
            'pageName' => $s('pageName'),
            'title' => mb_substr($s('title'), 0, 60),
            'description' => mb_substr($s('description'), 0, 160),
            'keywords' => $s('keywords'),
            'ogTitle' => $s('ogTitle'),
            'ogDescription' => $s('ogDescription'),
            'ogImage' => $s('ogImage'),
            'canonical' => $s('canonical'),
            'robots' => array_key_exists($robots, self::ROBOTS) ? $robots : 'index,follow',
            'isActive' => $request->has('isActive'),
        ];
    }
}
