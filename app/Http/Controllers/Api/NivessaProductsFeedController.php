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
