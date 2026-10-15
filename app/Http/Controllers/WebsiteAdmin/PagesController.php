<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com CMS pages (homepage, landing pages, category pages), moved
 * from /admin/pages (Sarah, 2026-10-15).
 */
class PagesController extends Controller
{
    use WaContentHelpers;

    public function index(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('search', ''));
        $status = (string) $request->input('status', 'all');
        $type = (string) $request->input('type', 'all');
        if (!in_array($status, ['all', 'published', 'draft'], true)) {
            $status = 'all';
        }
        if (!in_array($type, ['all', 'regular', 'category'], true)) {
            $type = 'all';
        }
        $typeQs = $type === 'category' ? '&onlyCategories=true' : ($type === 'regular' ? '&excludeCategories=true' : '');
        $qs = 'page=' . $page . '&limit=10' . $typeQs
            . ($search !== '' ? '&search=' . rawurlencode($search) : '')
            . ($status !== 'all' ? '&status=' . $status : '');
        $resp = WebsiteBridge::admin('GET', '/pages/admin/all?' . $qs, null, 30);
        $pg = $this->pick($resp, 'pagination', []) ?: [];

        // Published/draft counts for the current page type, from the
        // pagination totals instead of loading every page like the website did.
        $counts = [];
        foreach (['published', 'draft'] as $st) {
            $c = WebsiteBridge::admin('GET', '/pages/admin/all?page=1&limit=1&status=' . $st . $typeQs);
            $counts[$st] = (int) ($this->pick($c, 'pagination', [])['count'] ?? 0);
        }

        return view('website_admin.content.pages_index', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load pages.') : null,
            'pages' => $this->listFrom($resp),
            'page' => (int) ($pg['current'] ?? $page),
            'totalPages' => max(1, (int) ($pg['total'] ?? 1)),
            'total' => (int) ($pg['count'] ?? 0),
            'counts' => $counts,
            'search' => $search,
            'status' => $status,
            'type' => $type,
        ]);
    }

    public function toggleStatus(Request $request, $id)
    {
        $this->guardStaff();
        $to = $request->input('to') === 'published' ? 'published' : 'draft';
        $resp = WebsiteBridge::admin('PUT', '/pages/' . rawurlencode($id), ['status' => $to]);
        if (!empty($resp['success'])) {
            $this->revalidate($this->pick($resp, 'data', [])['slug'] ?? '');
        }
        return $this->bridgeBack($resp, $to === 'published' ? 'Page published.' : 'Page unpublished.', 'Failed to update the page status.');
    }

    public function duplicate($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/pages/' . rawurlencode($id) . '/duplicate');
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to duplicate the page.');
        }
        return redirect()->route('wa.content.pages')->with('status', 'Page duplicated. The copy is a draft.');
    }

    public function destroy($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('DELETE', '/pages/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'Page deleted.', 'Failed to delete the page.');
    }

    public function syncCategories()
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('POST', '/pages/sync-categories', [], 120);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Failed to sync categories.');
        }
        $s = $this->pick($resp, 'stats', []) ?: [];
        return back()->with('status', 'Categories synced. Created ' . ($s['created'] ?? 0) . ', updated ' . ($s['updated'] ?? 0) . ', skipped ' . ($s['skipped'] ?? 0) . '.');
    }

    public function create()
    {
        $this->guardStaff();
        return view('website_admin.content.pages_form', ['pageData' => null]);
    }

    public function edit($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('GET', '/pages/' . rawurlencode($id), null, 30);
        $data = $this->pick($resp, 'data');
        if (empty($resp['success']) || !is_array($data)) {
            return redirect()->route('wa.content.pages')->with('error', $resp['message'] ?? 'Page not found.');
        }
        return view('website_admin.content.pages_form', ['pageData' => $data]);
    }

    /** JSON from the page editor. */
    public function store(Request $request)
    {
        $this->guardStaff();
        $body = $this->bodyFrom($request);
        $err = $this->validatePage($body);
        if ($err) {
            return response()->json(['success' => false, 'message' => $err], 422);
        }
        $resp = WebsiteBridge::admin('POST', '/pages', $body, 45);
        if (!empty($resp['success'])) {
            $resp['redirect'] = route('wa.content.pages');
        }
        return $this->jsonFrom($resp);
    }

    public function update(Request $request, $id)
    {
        $this->guardStaff();
        $body = $this->bodyFrom($request);
        $resp = WebsiteBridge::admin('PUT', '/pages/' . rawurlencode($id), $body, 45);
        if (!empty($resp['success']) && $body['status'] === 'published') {
            // Same ISR purge the website editor fires after a publish, so
            // edits like homepage store hours show up right away.
            $this->revalidate($this->pick($resp, 'data', [])['slug'] ?? $body['slug']);
        }
        return $this->jsonFrom($resp);
    }

    public function uploadImage(Request $request)
    {
        $this->guardStaff();
        $r = $this->uploadImageTo('/upload/image', $request->file('image'));
        return response()->json($r, !empty($r['success']) ? 200 : 422);
    }

    protected function bodyFrom(Request $request): array
    {
        // Read the raw JSON so section data keeps empty strings and nulls
        // exactly as the editor sent them (the middleware would turn "" into null).
        $in = json_decode($request->getContent(), true);
        if (!is_array($in)) {
            $in = [];
        }
        $seo = is_array($in['seo'] ?? null) ? $in['seo'] : [];
        $content = is_array($in['content'] ?? null) ? $in['content'] : ['type' => 'custom', 'sections' => []];
        $content['sections'] = array_values(is_array($content['sections'] ?? null) ? $content['sections'] : []);
        $title = trim((string) ($in['title'] ?? ''));
        $slug = trim((string) ($in['slug'] ?? ''));
        if ($slug === '') {
            $slug = trim(preg_replace('/-+/', '-', preg_replace('/\s+/', '-', preg_replace('/[^a-z0-9\s-]/', '', strtolower($title)))), '-');
        }
        return [
            'title' => $title,
            'slug' => $slug,
            'status' => ($in['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'showInNavbar' => !empty($in['showInNavbar']),
            'navbarOrder' => (int) ($in['navbarOrder'] ?? 0),
            'seo' => [
                'title' => (string) ($seo['title'] ?? ''),
                'description' => (string) ($seo['description'] ?? ''),
                'image' => (string) ($seo['image'] ?? ''),
                'keywords' => is_array($seo['keywords'] ?? null) ? $seo['keywords'] : [],
            ],
            'content' => $content,
        ];
    }

    /** Same checks as the website's pageUtils.validatePageData (new pages). */
    protected function validatePage(array $p)
    {
        if ($p['title'] === '') {
            return 'Title is required.';
        }
        if (trim($p['seo']['title']) === '') {
            return 'SEO title is required.';
        }
        if (trim($p['seo']['description']) === '') {
            return 'SEO description is required.';
        }
        if (mb_strlen($p['seo']['title']) > 60) {
            return 'SEO title must be 60 characters or less.';
        }
        if (mb_strlen($p['seo']['description']) > 160) {
            return 'SEO description must be 160 characters or less.';
        }
        if ($p['slug'] !== '' && !preg_match('/^[a-z0-9-]+$/', $p['slug'])) {
            return 'Slug can only contain lowercase letters, numbers, and hyphens.';
        }
        return null;
    }

    /** Purges nivessa.com's cached copy of a CMS page (non-destructive). */
    protected function revalidate(string $slug)
    {
        try {
            $ch = curl_init('https://nivessa.com/api/revalidate/content');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['slug' => $slug]),
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
        }
    }
}
