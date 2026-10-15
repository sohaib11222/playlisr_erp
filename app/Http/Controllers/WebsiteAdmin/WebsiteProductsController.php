<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Services\WebsiteBridge;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Website product admin in the ERP: list, detail, edit, publish/hide,
 * on-sale, bulk edit, unpublish-by-price, missing images, metadata and
 * out-of-stock requests. Mirrors client /admin/products* pages.
 * Creating products is NOT here on purpose: new products only come from the
 * ERP's Add Product flow and the POS-to-website sync.
 * (Sarah, 2026-10-15)
 */
class WebsiteProductsController extends CatalogBaseController
{
    const SORTS = ['name', 'category', 'quantity', 'price'];
    const SOURCES = ['all', 'discogs', 'pos', 'manual', 'both'];

    // Product type ids the old admin printed by name.
    const TYPE_NAMES = [
        '67a1b3f49871f32e1153dd92' => 'Vinyl Records',
        '67b385d5d5dbd1cabbe9c627' => 'Cassettes',
        '6868398b842ca6e99ab6b514' => 'CDs',
        '67b1f4c1b8f30e6ed6e976c8' => 'DVDs & Blu-ray Discs',
        '67b1f4b8b8f30e6ed6e9762d' => 'VHS Tapes',
        '67b2ca3e34442ba9af48987e' => 'Nivessa Merch',
        '67b37ed7aa3f7db6e2e4b0f2' => 'Magazines',
        '68842151fbbf8156c6c82193' => 'Comic Books',
        '6884216afbbf8156c6c8219e' => 'Collectibles',
    ];

    public function index(Request $request)
    {
        $this->guard();
        $f = [
            'search' => trim((string) $request->input('search', '')),
            'availability' => in_array($request->input('availability'), ['all', 'In Stock', 'Out Of Stock'], true) ? $request->input('availability') : 'all',
            'source' => in_array($request->input('source'), self::SOURCES, true) ? $request->input('source') : 'all',
            'sortBy' => in_array($request->input('sortBy'), self::SORTS, true) ? $request->input('sortBy') : 'name',
            'sortOrder' => $request->input('sortOrder') === 'desc' ? 'desc' : 'asc',
            'limit' => in_array((int) $request->input('limit'), [15, 25, 50, 100, 200], true) ? (int) $request->input('limit') : 25,
        ];
        $page = max(1, (int) $request->input('page', 1));

        $resp = $this->bridge('GET', '/product/productsAll?' . http_build_query([
            'page' => $page, 'limit' => $f['limit'], 'search' => $f['search'], 'availability' => $f['availability'],
            'source' => $f['source'], 'sortBy' => $f['sortBy'], 'sortOrder' => $f['sortOrder'],
        ]), null, 30);
        $products = !empty($resp['success']) && is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $total = (int) ($resp['totalCount'] ?? 0);

        $filters = $this->bridge('GET', '/product/filters');
        $filterMeta = [
            'productTypes' => $this->names($filters['productTypes'] ?? ($filters['data']['productTypes'] ?? [])),
            'genres' => $this->names($filters['genres'] ?? ($filters['data']['genres'] ?? [])),
            'formats' => $this->names($filters['format'] ?? ($filters['data']['format'] ?? [])),
        ];

        return view('website_admin.catalog.products.index', [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $f['limit'])),
            'f' => $f,
            'filterMeta' => $filterMeta,
            'typeNames' => self::TYPE_NAMES,
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load products.') : null,
            'preview' => session('wa_catalog_price_preview'),
            'undoIds' => session('wa_catalog_price_undo', []),
        ]);
    }

    protected function names($list): array
    {
        $out = [];
        foreach ((array) $list as $row) {
            $n = is_array($row) ? ($row['name'] ?? '') : (string) $row;
            if ($n !== '') {
                $out[] = $n;
            }
        }
        return $out;
    }

    public function show($id)
    {
        $this->guard();
        $resp = $this->bridge('GET', '/product/productByIdAdmin/' . rawurlencode($id));
        $product = !empty($resp['success']) ? ($resp['data'] ?? null) : null;
        if (!$product) {
            return redirect()->route('wa.catalog.products')->with('error', $this->failMessage($resp, 'That product was not found on the website.'));
        }
        return view('website_admin.catalog.products.show', ['p' => $product, 'typeNames' => self::TYPE_NAMES]);
    }

    public function edit($id)
    {
        $this->guard();
        $resp = $this->bridge('GET', '/product/productByIdAdmin/' . rawurlencode($id));
        $product = !empty($resp['success']) ? ($resp['data'] ?? null) : null;
        if (!$product) {
            return redirect()->route('wa.catalog.products')->with('error', $this->failMessage($resp, 'That product was not found on the website.'));
        }
        $meta = $this->bridge('GET', '/product/metadata');
        $metadata = is_array($meta['data'] ?? null) ? $meta['data'] : [];
        return view('website_admin.catalog.products.edit', [
            'p' => $product,
            'metadata' => [
                'productType' => (array) ($metadata['productType'] ?? []),
                'format' => (array) ($metadata['format'] ?? []),
                'genre' => (array) ($metadata['genre'] ?? []),
            ],
            'typeNames' => self::TYPE_NAMES,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->guard();
        $in = function ($k) use ($request) {
            return trim((string) $request->input($k, ''));
        };
        $location = $in('location');
        if ($location === '' && $in('warehouseStore') !== '') {
            // The website requires a location; the warehouse fields replace it anyway.
            $location = $in('warehouseStore');
        }
        $fields = [
            'parentCollection' => $in('parentCollection'),
            'sku' => $in('sku'),
            'name' => $in('name'),
            'label' => $in('label'),
            'categoryNumber' => $in('categoryNumber'),
            'location' => $location,
            'ebayid' => $in('ebayid'),
            'condition' => $in('condition'),
            'genre' => $in('genre'),
            'format' => $in('format'),
            'artist' => $in('artist'),
            'price' => $in('price'),
            'quantity' => $in('quantity'),
            'availability' => $in('availability'),
            'isPreorder' => $request->has('isPreorder') ? 'true' : 'false',
            'description' => $in('description'),
        ];
        if ($request->has('isPreorder') && $in('preorderShipDate') !== '') {
            $fields['preorderShipDate'] = $in('preorderShipDate');
        }
        if ($in('warehouseStore') !== '' || $in('warehouseZone') !== '') {
            $fields['warehouseStore'] = $in('warehouseStore');
            $fields['warehouseZone'] = $in('warehouseZone');
            $fields['warehouseSubLocation'] = $in('warehouseSubLocation');
            $fields['warehouseShelfDetails'] = $in('warehouseShelfDetails');
        }

        $files = [];
        $tmp = null;
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $files['image'] = $request->file('image');
        } elseif ($request->has('use_discogs_cover') && $in('discogsReleaseId') !== '') {
            $tmp = $this->discogsCover($in('discogsReleaseId'), $err);
            if (!$tmp) {
                return back()->withInput()->with('error', $err);
            }
            $files['image'] = $tmp;
        } elseif ($in('image_url') !== '') {
            $fields['existingImage'] = $in('image_url');
        }

        $resp = WebsiteBridge::adminUpload('POST', '/product/update/' . rawurlencode($id), $fields, $files);
        if ($tmp) {
            @unlink($tmp->getRealPath());
        }
        if (empty($resp['success'])) {
            return back()->withInput()->with('error', $this->failMessage($resp, 'The product was not updated.'));
        }
        return redirect()->route('wa.catalog.products.edit', $id)->with('status', $this->failMessage($resp, 'Product updated.'));
    }

    /** Pulls the cover the website's Discogs image route serves into a temp upload. */
    protected function discogsCover(string $releaseId, &$error = null)
    {
        $base = trim((string) config('constants.nivessa_api')) ?: trim((string) env('NIVESSA_API', ''));
        $base = rtrim($base !== '' ? $base : 'https://nivessa.com/api/v1', '/');
        try {
            $ch = curl_init($base . '/discogs-image?release_id=' . rawurlencode($releaseId));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true]);
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        } catch (\Throwable $e) {
            $error = 'Could not reach Discogs.';
            return null;
        }
        if ($raw === false || $code >= 400 || strpos($type, 'image/') !== 0 || strlen((string) $raw) === 0) {
            $error = 'Discogs has no cover for release ' . $releaseId . '.';
            return null;
        }
        $ext = preg_replace('/[^a-z0-9]/i', '', explode('/', $type)[1] ?? 'jpg') ?: 'jpg';
        $stub = tempnam(sys_get_temp_dir(), 'dgs');
        @unlink($stub);
        $path = $stub . '.' . $ext;
        file_put_contents($path, $raw);
        return new UploadedFile($path, 'discogs-' . $releaseId . '.' . $ext, $type, null, true);
    }

    /** Publish/draft. toggle-publish sets adminUnpublished, so a hide wins over the POS sync. */
    public function togglePublish(Request $request, $id)
    {
        $this->guard();
        $published = filter_var($request->input('published'), FILTER_VALIDATE_BOOLEAN);
        $resp = $this->bridge('PATCH', '/product/toggle-publish/' . rawurlencode($id), ['published' => $published]);
        if ($request->expectsJson()) {
            return $this->jsonResult($resp, $published ? 'Product published.' : 'Product hidden from the website.', 'Could not change the publish status.');
        }
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? ($published ? 'Product published.' : 'Product hidden from the website.')
            : $this->failMessage($resp, 'Could not change the publish status.'));
    }

    public function toggleSale(Request $request, $id)
    {
        $this->guard();
        $onSale = filter_var($request->input('onSale'), FILTER_VALIDATE_BOOLEAN);
        $resp = $this->bridge('PATCH', '/product/toggle-sale/' . rawurlencode($id), ['onSale' => $onSale]);
        if ($request->expectsJson()) {
            return $this->jsonResult($resp, 'Sale status updated.', 'Could not update the sale status.');
        }
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Sale status updated.' : $this->failMessage($resp, 'Could not update the sale status.'));
    }

    public function destroy($id)
    {
        $this->guard();
        $resp = $this->bridge('DELETE', '/product/delete/' . rawurlencode($id));
        if (empty($resp['success'])) {
            return back()->with('error', $this->failMessage($resp, 'The product was not deleted.'));
        }
        return redirect()->route('wa.catalog.products')->with('status', 'Product deleted from the website.');
    }

    /** Bulk edit or delete the checked rows, one website call per product like the old page. */
    public function bulk(Request $request)
    {
        $this->guard();
        @set_time_limit(600);
        $ids = array_values(array_filter((array) $request->input('ids', []), function ($v) {
            return preg_match('/^[A-Za-z0-9_]+$/', (string) $v);
        }));
        $action = (string) $request->input('action');
        $value = trim((string) $request->input('value', ''));
        $prices = (array) $request->input('prices', []);
        if (!$ids) {
            return back()->with('error', 'Select at least one product first.');
        }

        $ok = 0;
        $failed = 0;
        foreach ($ids as $id) {
            if ($action === 'delete') {
                $resp = $this->bridge('DELETE', '/product/delete/' . $id);
            } else {
                $data = $this->bulkData($action, $value, (float) ($prices[$id] ?? 0), $err);
                if ($data === null) {
                    return back()->with('error', $err);
                }
                $resp = $this->bridge('PATCH', '/product/bulk-update/' . $id, $data);
            }
            !empty($resp['success']) ? $ok++ : $failed++;
        }
        $verb = $action === 'delete' ? 'Deleted' : 'Updated';
        $msg = "{$verb} {$ok} product" . ($ok === 1 ? '' : 's') . '.' . ($failed ? " {$failed} failed." : '');
        return back()->with($failed && !$ok ? 'error' : 'status', $msg);
    }

    protected function bulkData(string $action, string $value, float $price, &$err = null)
    {
        $num = is_numeric($value) ? (float) $value : null;
        switch ($action) {
            case 'publish': return ['published' => true];
            case 'unpublish': return ['published' => false];
            case 'onSale': return ['onSale' => true];
            case 'offSale': return ['onSale' => false];
            case 'changePrice':
                if ($num === null) { $err = 'Enter a valid price.'; return null; }
                return ['price' => $num];
            case 'changeQuantity':
                if ($num === null) { $err = 'Enter a valid quantity.'; return null; }
                return ['quantity' => (int) $num];
            case 'increasePrice':
                if ($num === null) { $err = 'Enter a valid percentage.'; return null; }
                return ['price' => round($price + ($price * $num / 100), 2)];
            case 'decreasePrice':
                if ($num === null) { $err = 'Enter a valid percentage.'; return null; }
                return ['price' => max(0, round($price - ($price * $num / 100), 2))];
            case 'changeProductType':
                if ($value === '') { $err = 'Pick a product type.'; return null; }
                return ['subCategory' => $value];
            case 'changeGenre':
                if ($value === '') { $err = 'Pick a genre.'; return null; }
                return ['genre' => $value];
            case 'changeFormat':
                if ($value === '') { $err = 'Pick a format.'; return null; }
                return ['format' => $value];
        }
        $err = 'Pick an action.';
        return null;
    }

    /** Unpublish-by-price, step 1: collect every published product at or over the price. */
    public function priceUnpublishPreview(Request $request)
    {
        $this->guard();
        @set_time_limit(300);
        $threshold = (float) $request->input('threshold');
        if ($threshold <= 0) {
            return back()->with('error', 'Enter a valid price.');
        }
        $items = [];
        $seen = [];
        for ($p = 1; $p <= 100; $p++) {
            $resp = $this->bridge('GET', '/product/filtered?' . http_build_query([
                'page' => $p, 'limit' => 100, 'minPrice' => $threshold, 'availability' => 'all', 'source' => 'all',
            ]), null, 30);
            if (empty($resp['success'])) {
                return back()->with('error', $this->failMessage($resp, 'Could not load products to unpublish.'));
            }
            $batch = is_array($resp['data'] ?? null) ? $resp['data'] : [];
            foreach ($batch as $row) {
                $id = (string) ($row['_id'] ?? '');
                if ($id === '' || isset($seen[$id]) || (float) ($row['price'] ?? 0) < $threshold || (isset($row['published']) && $row['published'] === false)) {
                    continue;
                }
                $seen[$id] = true;
                $items[] = ['id' => $id, 'name' => (string) ($row['name'] ?? ''), 'price' => (float) ($row['price'] ?? 0)];
            }
            if (count($batch) < 100) {
                break;
            }
        }
        session(['wa_catalog_price_preview' => ['threshold' => $threshold, 'items' => $items]]);
        return back();
    }

    public function priceUnpublishRun(Request $request)
    {
        $this->guard();
        @set_time_limit(600);
        $preview = session('wa_catalog_price_preview');
        session()->forget('wa_catalog_price_preview');
        if ($request->input('cancel') || empty($preview['items'])) {
            return back();
        }
        $done = [];
        foreach ($preview['items'] as $item) {
            $resp = $this->bridge('PATCH', '/product/toggle-publish/' . rawurlencode($item['id']), ['published' => false]);
            if (!empty($resp['success'])) {
                $done[] = $item['id'];
            }
        }
        session(['wa_catalog_price_undo' => $done]);
        $failed = count($preview['items']) - count($done);
        return back()->with($failed ? 'error' : 'status', 'Unpublished ' . count($done) . ' product(s) over $' . number_format($preview['threshold'], 2) . '.' . ($failed ? " {$failed} failed." : ''));
    }

    public function priceUnpublishUndo()
    {
        $this->guard();
        @set_time_limit(600);
        $ids = (array) session('wa_catalog_price_undo', []);
        session()->forget('wa_catalog_price_undo');
        $ok = 0;
        foreach ($ids as $id) {
            $resp = $this->bridge('PATCH', '/product/toggle-publish/' . rawurlencode($id), ['published' => true]);
            if (!empty($resp['success'])) {
                $ok++;
            }
        }
        return back()->with('status', "Re-published {$ok} product(s).");
    }

    /** Popular products missing an image (client /admin/product-images). */
    public function images(Request $request)
    {
        $this->guard();
        $limit = in_array((int) $request->input('limit'), [50, 100, 200, 500, 9999], true) ? (int) $request->input('limit') : 50;
        $resp = $this->bridge('GET', '/product/missing-images?limit=' . $limit, null, 40);
        $rows = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $sort = in_array($request->input('sort'), ['name', 'artist', 'sku', 'price', 'quantity', 'location'], true) ? $request->input('sort') : null;
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        if ($sort) {
            usort($rows, function ($a, $b) use ($sort, $dir) {
                $av = $sort === 'location' ? self::locationText($a['location'] ?? null) : ($a[$sort] ?? null);
                $bv = $sort === 'location' ? self::locationText($b['location'] ?? null) : ($b[$sort] ?? null);
                if ($av === null) return 1;
                if ($bv === null) return -1;
                $c = is_string($av) ? strcasecmp($av, (string) $bv) : ($av <=> $bv);
                return $dir === 'asc' ? $c : -$c;
            });
        }
        return view('website_admin.catalog.products.images', [
            'rows' => $rows,
            'limit' => $limit,
            'sort' => $sort,
            'dir' => $dir,
            'stats' => array_merge(['total' => 0, 'publishedInStock' => 0, 'unpublishedOrOutOfStock' => 0], (array) ($resp['statistics'] ?? [])),
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load products.') : null,
        ]);
    }

    public function uploadImage(Request $request, $id)
    {
        $this->guard();
        $url = trim((string) $request->input('imageUrl'));
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return back()->with('error', 'Enter a valid image URL.');
        }
        $resp = $this->bridge('POST', '/product/upload-image/' . rawurlencode($id), ['imageUrl' => $url]);
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? 'Image saved.' : $this->failMessage($resp, 'The image was not saved.'));
    }

    public static function locationText($loc): string
    {
        if (!$loc) {
            return 'N/A';
        }
        if (is_string($loc)) {
            return $loc;
        }
        if (is_array($loc) && !empty($loc['store'])) {
            return implode(' - ', array_filter([$loc['store'], $loc['sub_location'] ?? '', $loc['shelf_details'] ?? '']));
        }
        return 'N/A';
    }

    /** Product types, formats and genres used by the edit form (client /admin/product-metadata). */
    public function metadata()
    {
        $this->guard();
        $resp = $this->bridge('GET', '/product/metadata');
        $m = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        return view('website_admin.catalog.products.metadata', [
            'metadata' => [
                'productType' => (array) ($m['productType'] ?? []),
                'format' => (array) ($m['format'] ?? []),
                'genre' => (array) ($m['genre'] ?? []),
            ],
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load metadata.') : null,
        ]);
    }

    public function metadataChange(Request $request)
    {
        $this->guard();
        $type = (string) $request->input('type');
        $value = trim((string) $request->input('value'));
        if (!in_array($type, ['productType', 'format', 'genre'], true) || $value === '') {
            return back()->with('error', 'Enter a value.');
        }
        $remove = $request->input('op') === 'remove';
        $resp = $this->bridge('POST', '/product/metadata/' . ($remove ? 'remove-value' : 'add-value'), ['type' => $type, 'value' => $value]);
        return back()->with(!empty($resp['success']) ? 'status' : 'error', !empty($resp['success'])
            ? ($remove ? "Removed {$value}." : "Added {$value}.")
            : $this->failMessage($resp, $remove ? 'Could not remove it.' : 'Could not add it.'));
    }

    /** Back-in-stock requests from shoppers (client /admin/request-outofstock). */
    public function requests()
    {
        $this->guard();
        $resp = $this->bridge('GET', '/product/all-requests-outofstock');
        $rows = $this->payload($resp);
        return view('website_admin.catalog.products.requests', [
            'rows' => is_array($rows) ? $rows : [],
            'bridgeError' => empty($resp['success']) ? $this->failMessage($resp, 'Could not load requests.') : null,
        ]);
    }
}
