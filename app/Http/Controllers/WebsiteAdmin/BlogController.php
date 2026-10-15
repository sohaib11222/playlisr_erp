<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com blog posts and categories, moved from /admin/blog
 * (Sarah, 2026-10-15).
 */
class BlogController extends Controller
{
    use WaContentHelpers;

    const LIMIT = 10;

    public function index(Request $request)
    {
        $this->guardStaff();
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('search', ''));
        $resp = WebsiteBridge::admin('GET', '/blog/admin/all?page=' . $page . '&limit=' . self::LIMIT . '&search=' . rawurlencode($search));
        $total = (int) $this->pick($resp, 'total', 0);
        return view('website_admin.content.blog_index', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load posts.') : null,
            'blogs' => $this->listFrom($resp),
            'page' => $page,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / self::LIMIT)),
            'search' => $search,
        ]);
    }

    public function create()
    {
        $this->guardStaff();
        return view('website_admin.content.blog_form', [
            'blog' => null,
            'categories' => $this->categoryList(),
            'loadWarning' => null,
        ]);
    }

    public function edit($id)
    {
        $this->guardStaff();
        [$blog, $warning] = $this->loadBlog($id);
        if (!$blog) {
            return redirect()->route('wa.content.blog')->with('error', $warning ?: 'Blog not found.');
        }
        return view('website_admin.content.blog_form', [
            'blog' => $blog,
            'categories' => $this->categoryList(),
            'loadWarning' => $warning,
        ]);
    }

    public function store(Request $request)
    {
        $this->guardStaff();
        [$body, $err] = $this->bodyFrom($request);
        if ($err) {
            return back()->withInput()->with('error', $err);
        }
        $resp = WebsiteBridge::admin('POST', '/blog', $body, 45);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'Failed to create the blog post.');
        }
        return redirect()->route('wa.content.blog')->with('status', 'Blog post created.');
    }

    public function update(Request $request, $id)
    {
        $this->guardStaff();
        [$body, $err] = $this->bodyFrom($request);
        if ($err) {
            return back()->withInput()->with('error', $err);
        }
        $resp = WebsiteBridge::admin('PUT', '/blog/' . rawurlencode($id), $body, 45);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $resp['message'] ?? 'Failed to update the blog post.');
        }
        return redirect()->route('wa.content.blog')->with('status', 'Blog post updated.');
    }

    public function togglePublish(Request $request, $id)
    {
        $this->guardStaff();
        $publish = $request->input('publish') === '1';
        $resp = WebsiteBridge::admin('PUT', '/blog/' . rawurlencode($id), ['isPublished' => $publish]);
        return $this->bridgeBack($resp, $publish ? 'Blog post published.' : 'Blog post unpublished.', 'Failed to update the blog post.');
    }

    public function destroy($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('DELETE', '/blog/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'Blog post deleted.', 'Failed to delete the blog post.');
    }

    public function uploadImage(Request $request)
    {
        $this->guardStaff();
        $r = $this->uploadImageTo('/blog/upload-image', $request->file('image'));
        if (!empty($r['success'])) {
            $r['preview'] = self::siteImage($r['url']);
        }
        return response()->json($r, !empty($r['success']) ? 200 : 422);
    }

    public function categories()
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('GET', '/blog/categories');
        return view('website_admin.content.blog_categories', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load categories.') : null,
            'categories' => $this->listFrom($resp),
        ]);
    }

    public function categoryStore(Request $request)
    {
        $this->guardStaff();
        $body = $this->categoryBody($request);
        if ($body['name'] === '') {
            return back()->withInput()->with('error', 'Category name is required.');
        }
        $resp = WebsiteBridge::admin('POST', '/blog/categories', $body);
        return $this->bridgeBack($resp, 'Category created.', 'Failed to save the category.');
    }

    public function categoryUpdate(Request $request, $id)
    {
        $this->guardStaff();
        $body = $this->categoryBody($request);
        if ($body['name'] === '') {
            return back()->withInput()->with('error', 'Category name is required.');
        }
        $resp = WebsiteBridge::admin('PUT', '/blog/categories/' . rawurlencode($id), $body);
        return $this->bridgeBack($resp, 'Category updated.', 'Failed to save the category.');
    }

    public function categoryDestroy($id)
    {
        $this->guardStaff();
        $resp = WebsiteBridge::admin('DELETE', '/blog/categories/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'Category deleted.', 'Failed to delete the category.');
    }

    protected function categoryBody(Request $request): array
    {
        $color = (string) $request->input('color', '#3B2E2A');
        return [
            'name' => trim((string) $request->input('name', '')),
            'description' => trim((string) $request->input('description', '')),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#3B2E2A',
        ];
    }

    protected function categoryList(): array
    {
        return $this->listFrom(WebsiteBridge::admin('GET', '/blog/categories'));
    }

    /**
     * The post with its content. The admin list leaves content out, so this
     * uses the admin by-id route, falling back to the public slug route
     * (published posts only) until that route is deployed.
     */
    protected function loadBlog($id): array
    {
        $resp = WebsiteBridge::admin('GET', '/blog/admin/by-id/' . rawurlencode($id));
        $blog = !empty($resp['success']) ? $this->pick($resp, 'data') : null;
        if (is_array($blog) && array_key_exists('content', $blog)) {
            return [$blog, null];
        }
        // Find it in the admin list (newest first, 50 a page) to get the slug.
        $found = null;
        for ($p = 1; $p <= 20 && !$found; $p++) {
            $list = WebsiteBridge::admin('GET', '/blog/admin/all?page=' . $p . '&limit=50');
            $rows = $this->listFrom($list);
            foreach ($rows as $b) {
                if (($b['_id'] ?? '') === $id) {
                    $found = $b;
                    break;
                }
            }
            if (count($rows) < 50) {
                break;
            }
        }
        if (!$found) {
            return [null, 'Blog not found.'];
        }
        $bySlug = WebsiteBridge::admin('GET', '/blog/' . rawurlencode($found['slug'] ?? ''));
        $full = !empty($bySlug['success']) ? $this->pick($bySlug, 'data') : null;
        if (is_array($full) && isset($full['content'])) {
            return [$full, null];
        }
        $found['content'] = '';
        return [$found, "This draft's content could not be loaded until the website update that adds the admin blog lookup is deployed. Saving now would keep the old content only if you leave the content box empty."];
    }

    protected function bodyFrom(Request $request): array
    {
        $title = trim((string) $request->input('title', ''));
        $content = (string) $request->input('content', '');
        $image = trim((string) $request->input('featuredImage', ''));
        $isPublished = $request->has('isPublished');
        if ($title === '') {
            return [null, 'Enter a title.'];
        }
        if ($request->input('_mode') === 'create' && (trim(strip_tags($content, '<img>')) === '')) {
            return [null, 'Enter the content.'];
        }
        if ($image === '') {
            return [null, 'Upload a featured image or paste an image URL.'];
        }
        $scheduled = null;
        $local = trim((string) $request->input('scheduledPublishAt', ''));
        if (!$isPublished && $local !== '') {
            try {
                // The form field is Pacific time (the ERP's stores' zone).
                $scheduled = \Carbon\Carbon::parse($local, 'America/Los_Angeles')->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable $e) {
                return [null, 'That schedule date is not valid.'];
            }
        }
        $body = [
            'title' => $title,
            'featuredImage' => $image,
            'author' => trim((string) $request->input('author', '')) ?: 'Sarah',
            'isPublished' => $isPublished,
            'metaDescription' => mb_substr((string) $request->input('metaDescription', ''), 0, 160),
            'category' => (string) $request->input('category', '') ?: null,
            'tags' => $this->csvList($request->input('tags', '')),
            'scheduledPublishAt' => $scheduled,
        ];
        // The website ignores an empty content on update, which keeps the old text.
        if (trim($content) !== '') {
            $body['content'] = $content;
        }
        return [$body, null];
    }
}
