<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Products feed for the Nivessa website newsletter (jonhedvat/server).
 *
 * The website's weekly newsletter used to pick "new arrivals" from its own
 * Mongo product collection, which is ~127k bulk Discogs/POS imports that all
 * share an import date - so "newest" there is meaningless and surfaces junk.
 *
 * This endpoint serves the ERP's real signals instead:
 *   - newest:      recently *received* inventory (first_purchase_date / created_at)
 *   - bestsellers: what's actually selling in-store over the last 30 days
 * ...restricted to active, for-sale, in-stock, priced, imaged products.
 *
 * Read-only. Scoped to the configured business_id. Guarded by the shared
 * nivessa_web bearer token (same bridge as gift cards), so in-store sales
 * figures never go fully public. Every query is wrapped so a failure returns
 * an empty list (HTTP 200) rather than 500 - the newsletter then just falls
 * back to its old behaviour instead of breaking.
 */
class NivessaProductsFeedController extends Controller
{
    private function businessId(): int
    {
        // Single-tenant install; fall back to 1 if the bridge env isn't set so
        // the public feed still works without the nivessa_web token configured.
        return (int) (config('services.nivessa_web.business_id') ?: 1);
    }

    /**
     * GET /abc-a-products.json  (PUBLIC, off the /api/ path like /products-feed.json)
     *
     * Returns the normalized ARTISTS and TITLES of the A-class products from the
     * latest imported ABC classification (sales-based,
     * storage/app/abc-import/latest.json global_map). The nivessa.com homepage
     * "New Arrivals" uses these sets to surface arrivals by an A-selling artist
     * or title first, then fall back to plain newest.
     *
     * We return artist/title (not the ABC product ids) on purpose: the ABC list
     * is keyed by internal ERP product ids, which do NOT line up with the
     * website's posProductId, and a brand-new arrival is a different product
     * record than the historically-sold A copy anyway. Matching on
     * artist/title lets a fresh copy of an A-selling release still rank first.
     *
     * Read-only, CORS-open, graceful-empty (HTTP 200) when no ABC file exists.
     */
    public function abcAProducts(Request $request): JsonResponse
    {
        $artists = [];
        $titles  = [];
        try {
            // Use report_rows (every A row from the CSV, matched or not) so this
            // exactly mirrors /reports/abc-full-report. The global_map only holds
            // rows that matched an ERP product, which silently dropped A-sellers
            // whose CSV row never matched (e.g. Rosalia, Frank Ocean).
            $svc = new \App\Services\AbcImportService();
            $aRows = collect($svc->loadReportRows())
                ->filter(function ($r) {
                    return strtoupper((string) ($r['class'] ?? '')) === 'A';
                })
                ->values();

            // Clean artist/title for matched rows come from the products table
            // (Discogs fills the artist column); mirror the report's fallback.
            $ids = $aRows->pluck('matched_id')->filter()->unique()->values()->all();
            $prodById = [];
            if (!empty($ids)) {
                foreach (Product::whereIn('id', $ids)->select('id', 'name', 'artist')->get() as $p) {
                    $prodById[(int) $p->id] = $p;
                }
            }

            foreach ($aRows as $r) {
                $pid  = $r['matched_id'] ?? null;
                $name = (string) ($r['product'] ?? '');
                $artist = '';
                $title  = '';

                if ($pid && isset($prodById[(int) $pid])) {
                    $artist = (string) ($prodById[(int) $pid]->artist ?? '');
                    $title  = (string) ($prodById[(int) $pid]->name ?? '');
                }
                // Legacy/unmatched "Title / Artist": split on the last " / ".
                if ($artist === '' && strpos($name, ' / ') !== false) {
                    $pos = strrpos($name, ' / ');
                    $artist = trim(substr($name, $pos + 3));
                    if ($title === '') {
                        $title = trim(substr($name, 0, $pos));
                    }
                }
                if ($title === '') {
                    $title = $name;
                }

                $a = $this->normalizeMatchKey($artist);
                $t = $this->normalizeMatchKey($title);
                if ($a !== '') {
                    $artists[$a] = true;
                }
                if ($t !== '') {
                    $titles[$t] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[abc-a-products] load failed: ' . $e->getMessage());
        }

        return response()->json([
            'artists'      => array_keys($artists),
            'titles'       => array_keys($titles),
            'artistCount'  => count($artists),
            'titleCount'   => count($titles),
            'generated_at' => now()->toIso8601String(),
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control'               => 'public, max-age=600',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lowercase, strip punctuation, collapse whitespace. Mirrors the website's
     * normalizeText so artist/title sets match across ERP and nivessa.com.
     */
    private function normalizeMatchKey($value): string
    {
        $s = strtolower(trim((string) $value));
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * GET /products-feed.json  (PUBLIC, off the /api/ path like /events-feed.json)
     *
     * Newest arrivals only - the same catalogue that's already public on
     * nivessa.com, so no auth needed. In-store SALES figures stay behind the
     * token-guarded index() endpoint below.
     */
    public function publicFeed(Request $request): JsonResponse
    {
        $businessId  = $this->businessId();
        $newestLimit = min(60, max(0, (int) $request->query('newest', 40)));

        $newest = [];
        try {
            $newest = $newestLimit ? $this->newest($businessId, $newestLimit) : [];
        } catch (\Throwable $e) {
            Log::warning('[products-feed] public newest failed: ' . $e->getMessage());
        }

        return response()->json([
            'newest'       => $newest,
            'bestsellers'  => [], // sales data stays behind the token bridge
            'generated_at' => now()->toIso8601String(),
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control'               => 'public, max-age=300',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/v1/nivessa-web/products-feed?newest=40&best=12
     */
    public function index(Request $request): JsonResponse
    {
        $businessId  = $this->businessId();
        $newestLimit = min(60, max(0, (int) $request->query('newest', 40)));
        $bestLimit   = min(30, max(0, (int) $request->query('best', 12)));

        $newest = [];
        $bestsellers = [];

        try {
            $newest = $newestLimit ? $this->newest($businessId, $newestLimit) : [];
        } catch (\Throwable $e) {
            Log::warning('[products-feed] newest failed: ' . $e->getMessage());
        }

        try {
            $bestsellers = $bestLimit ? $this->bestsellers($businessId, $bestLimit) : [];
        } catch (\Throwable $e) {
            Log::warning('[products-feed] bestsellers failed: ' . $e->getMessage());
        }

        return response()->json([
            'newest'       => $newest,
            'bestsellers'  => $bestsellers,
            'generated_at' => now()->toIso8601String(),
        ], 200, [
            'Cache-Control' => 'private, max-age=300',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Recently received, in-stock, priced, imaged products.
     * Ordered by receipt date (first_purchase_date), falling back to created_at.
     */
    private function newest(int $businessId, int $limit): array
    {
        $products = Product::where('products.business_id', $businessId)
            ->where('products.is_inactive', 0)
            ->where('products.not_for_selling', 0)
            ->whereNotNull('products.image')
            ->where('products.image', '!=', '')
            ->with([
                'variations' => function ($q) {
                    $q->whereNull('deleted_at');
                },
                'variations.variation_location_details',
            ])
            ->orderByRaw('COALESCE(products.first_purchase_date, DATE(products.created_at)) DESC')
            ->orderBy('products.created_at', 'desc')
            ->limit(max($limit * 4, 80))
            ->get();

        $out = [];
        foreach ($products as $p) {
            $card = $this->toCard($p);
            if ($card) {
                $out[] = $card;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Top sellers over the last 30 days (store-wide, all locations), that are
     * still in stock. Ordered by net units sold.
     */
    private function bestsellers(int $businessId, int $limit): array
    {
        $ids = DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 't.id', '=', 'tsl.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.transaction_date', '>=', now()->subDays(30)->toDateTimeString())
            ->groupBy('tsl.product_id')
            ->orderByRaw('SUM(tsl.quantity) - COALESCE(SUM(tsl.quantity_returned), 0) DESC')
            ->limit($limit * 5)
            ->pluck('tsl.product_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $products = Product::whereIn('products.id', $ids->all())
            ->where('products.business_id', $businessId)
            ->where('products.is_inactive', 0)
            ->where('products.not_for_selling', 0)
            ->whereNotNull('products.image')
            ->where('products.image', '!=', '')
            ->with([
                'variations' => function ($q) {
                    $q->whereNull('deleted_at');
                },
                'variations.variation_location_details',
            ])
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($ids as $pid) { // preserve best-selling order
            $p = $products->get($pid);
            if (!$p) {
                continue;
            }
            $card = $this->toCard($p);
            if ($card) {
                $out[] = $card;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Flatten a product to a newsletter card, or null if not sellable/in-stock. */
    private function toCard(Product $p): ?array
    {
        $variation = $p->variations->first();
        if (!$variation) {
            return null;
        }

        $price = (float) ($variation->sell_price_inc_tax ?? 0);
        if ($price <= 0) {
            return null;
        }

        $qty = 0.0;
        foreach ($p->variations as $v) {
            foreach ($v->variation_location_details as $vld) {
                $qty += (float) $vld->qty_available;
            }
        }
        if ($qty <= 0) {
            return null;
        }

        return [
            'sku'        => $p->sku,
            'name'       => $p->name,
            'artist'     => $p->artist,
            'format'     => $p->format,
            'price'      => round($price, 2),
            'image'      => $p->image_url, // absolute (asset())
            'created_at' => optional($p->created_at)->toIso8601String(),
        ];
    }
}
