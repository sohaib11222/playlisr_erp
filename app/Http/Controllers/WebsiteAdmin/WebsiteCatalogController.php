<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Website catalog setup in the ERP: buy/sell/trade categories, collections,
 * featured product lists, the vinyl-worth featured records table and the
 * synced products browser. Mirrors client /admin/categories, /collection,
 * /featured-products, /featured-records and /synced-products.
 * (Sarah, 2026-10-15)
 */
class WebsiteCatalogController extends CatalogBaseController
{
    const CATEGORY_TYPES = [
        'vinyl' => 'Vinyl',
        'media' => 'Media',
        'collectibles' => 'Collectibles',
        'specialFormats' => 'Special Formats',
        'policies' => 'Policies',
    ];

    const FEATURED_TYPES = [
        'staff-picks' => 'Staff Picks',
        'best-sellers' => 'Best Sellers',
        'most-collected-week' => 'Most Collected This Week',
        'related-products' => 'Related Products',
    ];

    const COLLECTION_ICONS = [
        '/vinylrecord.png' => 'Vinyl Records',
        '/cd.png' => 'CDs',
        '/cassettes.png' => 'Cassettes',
        '/apparel.png' => 'Apparel & Merch',
        '/dvds.png' => 'DVDs & Blu-ray',
        '/tradingcards.png' => 'Trading Cards / VHS',
        '/books.png' => 'Books',
        '/magazines.png' => 'Magazines',
        '/comics.png' => 'Comic Books',
        '/collectibles.png' => 'Collectibles',
    ];

    // ---- Categories -------------------------------------------------------

    public function categories(Request $request)
    {
        $this->guard();
        // No "active" filter, so inactive categories show too.
        $resp = $this->bridge('GET', '/categories');
        $all = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $type = array_key_exists($request->input('type'), self::CATEGORY_TYPES) ? $request->input('type') : 'all';
        $q = mb_strtolower(trim((string) $request->input('q', '')));
        $rows = array_values(array_filter($all, function ($c) use ($type, $q) {
            if ($type !== 'all' && ($c['categoryType'] ?? '') !== $type) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            return strpos(mb_strtolower((string) ($c['title'] ?? '')), $q) !== false
                || strpos(mb_strtolower((string) ($c['slug'] ?? '')), $q) !== false;
        }));
        $counts = [];
        foreach (self::CATEGORY_TYPES as $k => $l) {
            $counts[$k] = count(array_filter($all, function ($c) use ($k) {
                return ($c['categoryType'] ?? '') === $k;
            }));
        }
        return view('website_admin.catalog.categories.index', [
            'rows' => $rows,
            'counts' => $counts,
            'types' => self::CATEGORY_TYPES,
            'type' => $type,
            'q' => $request->input('q', ''),
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load categories.') : null,
        ]);
    }

    public function categoryCreate()
    {
        $this->guard();
        return view('website_admin.catalog.categories.form', ['c' => [], 'isNew' => true, 'types' => self::CATEGORY_TYPES]);
    }

    public function categoryEdit($id)
    {
        $this->guard();
        $resp = $this->bridge('GET', '/categories/' . rawurlencode($id));
        if (empty($resp['success']) || empty($resp['data'])) {
            return redirect()->route('wa.catalog.categories')->with('error', $this->failMessage($resp, 'That category was not found.'));
        }
        return view('website_admin.catalog.categories.form', ['c' => $resp['data'], 'isNew' => false, 'types' => self::CATEGORY_TYPES]);
    }

    public function categorySave(Request $request, $id = null)
    {
        $this->guard();
        $lines = function ($k) use ($request) {
            $out = [];
            $seen = [];
            foreach (preg_split('/[\r\n]+/', (string) $request->input($k, '')) as $v) {
                $v = trim($v);
                if ($v === '' || isset($seen[mb_strtolower($v)])) {
                    continue;
                }
                $seen[mb_strtolower($v)] = true;
                $out[] = $v;
            }
            return $out;
        };
        $title = trim((string) $request->input('title'));
        if ($title === '') {
            return back()->withInput()->with('error', 'A title is required.');
        }
        $slug = trim((string) $request->input('slug'));
        if ($slug === '') {
            $slug = trim(preg_replace('/[\s_-]+/', '-', preg_replace('/[^\w\s-]/', '', mb_strtolower($title))), '-');
        }
        $image = trim((string) $request->input('featuredImage'));
        if ($request->hasFile('featuredImageFile')) {
            $uploaded = $this->uploadImage($request->file('featuredImageFile'), $err);
            if (!$uploaded) {
                return back()->withInput()->with('error', $err);
            }
            $image = $uploaded;
        }
        $body = [
            'title' => $title,
            'slug' => $slug,
            'subtitle' => (string) $request->input('subtitle', ''),
            'description' => (string) $request->input('description', ''),
            'categoryType' => array_key_exists($request->input('categoryType'), self::CATEGORY_TYPES) ? $request->input('categoryType') : 'vinyl',
            'featuredImage' => $image,
            'genreHeading' => (string) $request->input('genreHeading', ''),
            'genres' => $lines('genres'),
            'artistHeading' => (string) $request->input('artistHeading', ''),
            'notableArtists' => $lines('notableArtists'),
            'labelHeading' => (string) $request->input('labelHeading', ''),
            'labels' => $lines('labels'),
            'metaTitle' => (string) $request->input('metaTitle', ''),
            'metaDescription' => (string) $request->input('metaDescription', ''),
            'sortOrder' => (int) $request->input('sortOrder', 0),
            'isActive' => $request->has('isActive'),
        ];
        $resp = $id
            ? $this->bridge('PUT', '/categories/' . rawurlencode($id), $body)
            : $this->bridge('POST', '/categories', $body);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $this->failMessage($resp, 'The category was not saved.'));
        }
        return redirect()->route('wa.catalog.categories')->with('status', $id ? 'Category saved.' : 'Category created.');
    }

    public function categoryDelete($id)
    {
        $this->guard();
        $resp = $this->bridge('DELETE', '/categories/' . rawurlencode($id));
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Category deleted.' : $this->failMessage($resp, 'The category was not deleted.'));
    }

    // ---- Collections ------------------------------------------------------

    public function collections(Request $request)
    {
        $this->guard();
        $page = max(1, (int) $request->input('page', 1));
        $limit = 20;
        $resp = $this->bridge('GET', "/collection/pageLimit?page={$page}&limit={$limit}");
        $total = (int) ($resp['totalCount'] ?? 0);
        return view('website_admin.catalog.collections.index', [
            'rows' => is_array($resp['data'] ?? null) ? $resp['data'] : [],
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit)),
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load collections.') : null,
        ]);
    }

    public function collectionCreate()
    {
        $this->guard();
        return view('website_admin.catalog.collections.form', ['icons' => self::COLLECTION_ICONS]);
    }

    public function collectionStore(Request $request)
    {
        $this->guard();
        $name = trim((string) $request->input('name'));
        $status = in_array($request->input('status'), ['Active', 'Inactive'], true) ? $request->input('status') : '';
        if ($name === '' || $status === '') {
            return back()->withInput()->with('error', 'Enter a name and pick a status.');
        }
        $fields = ['name' => $name, 'status' => $status];
        $files = [];
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $files['image'] = $request->file('image');
        } elseif (array_key_exists((string) $request->input('presetIcon'), self::COLLECTION_ICONS)) {
            $fields['imageUrl'] = $request->input('presetIcon');
        }
        $resp = WebsiteBridge::adminUpload('POST', '/collection/create', $fields, $files);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $this->failMessage($resp, 'The collection was not added.'));
        }
        return redirect()->route('wa.catalog.collections')->with('status', 'Collection added.');
    }

    public function collectionDelete($id)
    {
        $this->guard();
        $resp = $this->bridge('DELETE', '/collection/delete/' . rawurlencode($id));
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Collection deleted.' : $this->failMessage($resp, 'The collection was not deleted.'));
    }

    // ---- Featured products (staff picks, best sellers...) -----------------

    public function featured(Request $request)
    {
        $this->guard();
        $type = array_key_exists($request->input('type'), self::FEATURED_TYPES) ? $request->input('type') : 'staff-picks';
        $resp = $this->bridge('GET', '/root/api/featured-products/admin/all?type=' . rawurlencode($type));
        $rows = is_array($resp['data'] ?? null) ? $resp['data'] : [];

        $q = trim((string) $request->input('q', ''));
        $results = [];
        $searchError = null;
        if (mb_strlen($q) >= 2) {
            $s = $this->bridge('GET', '/product/productsAll?' . http_build_query([
                'page' => max(1, (int) $request->input('rpage', 1)), 'limit' => 20, 'search' => $q,
                'availability' => 'all', 'source' => 'all', 'sortBy' => 'name', 'sortOrder' => 'asc',
            ]), null, 30);
            $results = is_array($s['data'] ?? null) ? $s['data'] : [];
            $searchError = empty($s['success']) ? $this->failMessage($s, 'Search failed.') : null;
        } elseif ($q !== '') {
            $searchError = 'Enter at least 2 characters.';
        }
        $featuredIds = [];
        foreach ($rows as $r) {
            $pid = is_array($r['productId'] ?? null) ? ($r['productId']['_id'] ?? '') : ($r['productId'] ?? '');
            if ($pid) {
                $featuredIds[$pid] = true;
            }
        }
        return view('website_admin.catalog.featured', [
            'types' => self::FEATURED_TYPES,
            'type' => $type,
            'rows' => $rows,
            'visibleOnPublic' => (int) ($resp['visibleOnPublic'] ?? 0),
            'q' => $q,
            'results' => $results,
            'featuredIds' => $featuredIds,
            'searchError' => $searchError,
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load featured products.') : null,
        ]);
    }

    public function featuredAdd(Request $request)
    {
        $this->guard();
        $type = array_key_exists($request->input('type'), self::FEATURED_TYPES) ? $request->input('type') : null;
        if (!$type || !$request->input('productId')) {
            return back()->with('error', 'Pick a product to add.');
        }
        $resp = $this->bridge('POST', '/root/api/featured-products/admin/add', ['type' => $type, 'productId' => (string) $request->input('productId')]);
        if (empty($resp['success'])) {
            return back()->with('error', $this->failMessage($resp, 'The product was not added.'));
        }
        return redirect()->route('wa.catalog.featured', ['type' => $type])->with('status', 'Product added to ' . self::FEATURED_TYPES[$type] . '.');
    }

    public function featuredOrder(Request $request, $id)
    {
        $this->guard();
        $resp = $this->bridge('PUT', '/root/api/featured-products/admin/' . rawurlencode($id), ['order' => (int) $request->input('order')]);
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Order saved.' : $this->failMessage($resp, 'The order was not saved.'));
    }

    public function featuredRemove($id)
    {
        $this->guard();
        $resp = $this->bridge('DELETE', '/root/api/featured-products/admin/' . rawurlencode($id));
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Product removed from the list.' : $this->failMessage($resp, 'The product was not removed.'));
    }

    // ---- Featured records (vinyl-worth page table) ------------------------

    /** Returns [page, sectionIndex] for the vinyl-records-table section, or an error string. */
    protected function vinylWorthPage()
    {
        $resp = $this->bridge('GET', '/pages/slug/vinyl-worth');
        $page = !empty($resp['success']) ? ($resp['data'] ?? null) : null;
        if (!$page || empty($page['_id'])) {
            return $this->failMessage($resp, 'Could not load the vinyl-worth page.');
        }
        $sections = (array) ($page['content']['sections'] ?? []);
        foreach ($sections as $i => $s) {
            if (($s['type'] ?? '') === 'vinyl-records-table') {
                return [$page, $i];
            }
        }
        return 'The vinyl-worth page has no records table section.';
    }

    public function records(Request $request)
    {
        $this->guard();
        $found = $this->vinylWorthPage();
        $records = is_array($found) ? (array) ($found[0]['content']['sections'][$found[1]]['data']['records'] ?? []) : [];
        return view('website_admin.catalog.records', [
            'records' => array_values($records),
            'editIndex' => $request->has('edit') ? (int) $request->input('edit') : null,
            'bridgeError' => is_string($found) ? $found : null,
        ]);
    }

    public function recordsSave(Request $request)
    {
        $this->guard();
        $found = $this->vinylWorthPage();
        if (is_string($found)) {
            return back()->with('error', $found);
        }
        list($page, $idx) = $found;
        $sections = $page['content']['sections'];
        $records = array_values((array) ($sections[$idx]['data']['records'] ?? []));
        $op = (string) $request->input('op');
        $row = [
            'artist' => trim((string) $request->input('artist')),
            'album' => trim((string) $request->input('album')),
            'worth' => trim((string) $request->input('worth')),
        ];
        $i = (int) $request->input('index', -1);
        if ($op === 'delete') {
            if (!isset($records[$i])) {
                return back()->with('error', 'That record is no longer there. Reload and try again.');
            }
            array_splice($records, $i, 1);
        } else {
            if ($row['artist'] === '' || $row['album'] === '' || $row['worth'] === '') {
                return back()->withInput()->with('error', 'Fill in artist, album and worth.');
            }
            if ($op === 'update') {
                if (!isset($records[$i])) {
                    return back()->with('error', 'That record is no longer there. Reload and try again.');
                }
                $records[$i] = array_merge((array) $records[$i], $row);
            } else {
                $records[] = $row;
            }
        }
        $sections[$idx]['data']['records'] = $records;
        $resp = $this->bridge('PUT', '/pages/' . rawurlencode($page['_id']), [
            'content' => ['type' => 'custom', 'sections' => $sections],
        ]);
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $this->failMessage($resp, 'The records were not saved.'));
        }
        return redirect()->route('wa.catalog.records')->with('status', 'Featured records saved.');
    }

    // ---- Synced products --------------------------------------------------

    public function synced(Request $request)
    {
        $this->guard();
        $keys = ['source', 'genre', 'style', 'format', 'location', 'minPrice', 'maxPrice', 'minQuantity', 'maxQuantity', 'showOnWebsite', 'sortBy', 'sortOrder'];
        $f = [];
        foreach ($keys as $k) {
            $f[$k] = trim((string) $request->input($k, ''));
        }
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        $params = ['page' => $page, 'per_page' => 50];
        if ($search !== '') {
            $params['search'] = $search;
        }
        if ($request->input('refresh')) {
            $params['refresh'] = 'true';
        }
        foreach ($f as $k => $v) {
            if ($v !== '' && !($k === 'sortBy' && $v === 'default') && !($k === 'sortOrder' && $v === 'asc')) {
                $params[$k] = $v;
            }
        }
        $resp = $this->bridge('GET', '/root/api/synced-products?' . http_build_query($params), null, 45);
        $d = $resp;
        $pag = (array) ($d['pagination'] ?? []);
        return view('website_admin.catalog.synced', [
            'rows' => is_array($d['products'] ?? null) ? $d['products'] : [],
            'f' => $f,
            'search' => $search,
            'page' => $page,
            'pages' => max(1, (int) ($d['totalPages'] ?? ($pag['total_pages'] ?? 1))),
            'hasNext' => !empty($pag['has_next']),
            'hasPrev' => !empty($pag['has_prev']) || $page > 1,
            'counts' => [
                'total' => (int) ($d['totalAllProducts'] ?? ($d['total'] ?? 0)),
                'discogs' => (int) ($d['discogsTotalItems'] ?? 0),
                'pos' => (int) ($d['posTotalItems'] ?? 0),
                'pageDiscogs' => (int) ($d['discogsCount'] ?? 0),
                'pagePos' => (int) ($d['posCount'] ?? 0),
                'filtered' => (int) ($pag['total_items'] ?? ($d['total'] ?? 0)),
            ],
            'cached' => !empty($d['cached']),
            'activeFilters' => count(array_filter($f, function ($v) {
                return $v !== '';
            })) - ($f['sortBy'] === 'default' ? 1 : 0) - ($f['sortOrder'] === 'asc' ? 1 : 0),
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load synced products.') : null,
        ]);
    }

    public function syncedRefresh(Request $request)
    {
        $this->guard();
        $this->bridge('POST', '/root/api/synced-products/clear-cache', []);
        $q = array_filter((array) $request->input('q', []), function ($v) {
            return $v !== '' && $v !== null;
        });
        $q['refresh'] = 1;
        return redirect()->route('wa.catalog.synced', $q)->with('status', 'Synced products refreshed.');
    }

    public function syncedToggle(Request $request)
    {
        $this->guard();
        $on = filter_var($request->input('sellOnHomepage'), FILTER_VALIDATE_BOOLEAN);
        $resp = $this->bridge('POST', '/root/api/synced-products/toggle-homepage', [
            'productId' => $request->input('productId'),
            'source' => (string) $request->input('source'),
            'sellOnHomepage' => $on,
        ]);
        return $this->jsonResult($resp, $on ? 'It will be shown on the website.' : 'It will not be shown on the website.', 'Could not update the homepage setting.');
    }
}
