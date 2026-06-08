<?php

namespace App\Services\Ebay;

use App\Business;
use App\Category;
use App\DataTransferObjects\EbayListingDraft;
use App\Product;
use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\Schema;

class EbayProductListingMapper
{
    /** @var BusinessUtil */
    private $businessUtil;

    public function __construct(?BusinessUtil $businessUtil = null)
    {
        $this->businessUtil = $businessUtil ?: new BusinessUtil();
    }

    /**
     * Build a listing draft from product + default/first variation.
     *
     * @param int $productId
     * @param int $businessId
     * @return EbayListingDraft|null
     */
    public function fromProductId($productId, $businessId)
    {
        $product = Product::where('business_id', $businessId)->find($productId);
        if (!$product) {
            return null;
        }

        return $this->fromProduct($product, $businessId);
    }

    public function fromProduct(Product $product, $businessId)
    {
        $settings = $this->businessUtil->getApiSettings($businessId);
        $ebaySettings = $settings['ebay'] ?? [];

        $product->load(['product_locations']);

        $variation = $product->variations()
            ->with(['variation_location_details'])
            ->orderBy('id')
            ->first();

        $shipFrom = $this->resolveShipFromLocation($product, $variation, $ebaySettings);

        $draft = new EbayListingDraft();
        $draft->product_id = (int) $product->id;
        $draft->sku = $this->resolveSku($variation, $product);
        $draft->title = $this->resolveTitle($product);
        $draft->description = trim((string) ($product->product_description ?: $product->name));
        $draft->price = $variation ? (float) $variation->sell_price_inc_tax : 0;
        $draft->currency = $this->resolveCurrency($businessId);
        $draft->quantity = $shipFrom['quantity'];
        $draft->erp_location_id = $shipFrom['erp_location_id'];
        $draft->erp_location_name = $shipFrom['erp_location_name'];
        $draft->merchant_location_key = $shipFrom['merchant_location_key'];
        $draft->category_id = $this->resolveCategoryId($product, $ebaySettings);
        $draft->condition = trim((string) ($ebaySettings['default_condition'] ?? 'USED_GOOD'));
        $draft->image_urls = $this->resolveImageUrls($product);
        $draft->not_for_selling = !empty($product->not_for_selling);

        if (Schema::hasColumn('products', 'ebay_listing_id')) {
            $draft->ebay_listing_id = $product->ebay_listing_id;
        }
        if (Schema::hasColumn('products', 'listing_status')) {
            $draft->listing_status = $product->listing_status;
        }

        return $draft;
    }

    private function resolveSku($variation, Product $product)
    {
        if ($variation && !empty($variation->sub_sku)) {
            return $variation->sub_sku;
        }
        if (!empty($product->sku)) {
            return $product->sku;
        }
        return 'NIV-' . $product->id;
    }

    private function resolveTitle(Product $product)
    {
        $title = trim((string) $product->name);
        $artist = trim((string) ($product->artist ?? ''));
        if ($artist !== '' && stripos($title, $artist) === false) {
            $title = $artist . ' - ' . $title;
        }
        if (mb_strlen($title) > 80) {
            $title = mb_substr($title, 0, 80);
        }
        return $title;
    }

    /**
     * Pick ERP store + eBay merchantLocationKey from product_locations and stock.
     *
     * @return array{erp_location_id: ?int, erp_location_name: ?string, merchant_location_key: string, quantity: int}
     */
    private function resolveShipFromLocation(Product $product, $variation, array $ebaySettings)
    {
        $locationMap = $ebaySettings['location_map'] ?? [];
        $assigned = $product->product_locations;
        $assignedIds = $assigned->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $qtyByLocation = [];
        if ($variation) {
            foreach ($variation->variation_location_details as $vld) {
                $lid = (int) $vld->location_id;
                if (empty($assignedIds) || in_array($lid, $assignedIds, true)) {
                    $qtyByLocation[$lid] = (int) ($qtyByLocation[$lid] ?? 0) + (int) $vld->qty_available;
                }
            }
        }

        $pick = null;
        $maxQty = -1;
        if (!empty($assignedIds)) {
            foreach ($assigned as $loc) {
                $lid = (int) $loc->id;
                $q = (int) ($qtyByLocation[$lid] ?? 0);
                if ($q > $maxQty) {
                    $maxQty = $q;
                    $pick = $loc;
                }
            }
            if ($pick === null) {
                $pick = $assigned->first();
                $maxQty = (int) ($qtyByLocation[(int) $pick->id] ?? 0);
            }
        }

        if ($pick !== null) {
            $lid = (int) $pick->id;
            $key = $locationMap[(string) $lid] ?? EbayInventoryApiClient::merchantKeyForLocationName($pick->name, $lid);
            $qty = $maxQty > 0 ? $maxQty : max(1, (int) array_sum($qtyByLocation));
            return [
                'erp_location_id' => $lid,
                'erp_location_name' => $pick->name,
                'merchant_location_key' => $key,
                'quantity' => max(1, $qty),
            ];
        }

        $fallbackKey = $this->defaultMerchantLocationKey($locationMap);
        $totalQty = $variation
            ? max(1, (int) $variation->variation_location_details->sum('qty_available'))
            : 1;

        return [
            'erp_location_id' => null,
            'erp_location_name' => null,
            'merchant_location_key' => $fallbackKey,
            'quantity' => $totalQty,
        ];
    }

    private function defaultMerchantLocationKey(array $locationMap)
    {
        if (!empty($locationMap)) {
            return (string) reset($locationMap);
        }
        return 'nivessa-hollywood';
    }

    private function resolveImageUrls(Product $product)
    {
        $urls = [];
        if (!empty($product->image) && $product->image !== 'default.png') {
            $url = $product->image_url;
            if (is_string($url) && stripos($url, 'https://') === 0) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function resolveCategoryId(Product $product, array $ebaySettings)
    {
        foreach ([$product->sub_category_id, $product->category_id] as $catId) {
            $id = $this->categoryEbayId($catId);
            if ($id !== '') {
                return $id;
            }
        }

        $parentId = null;
        if (!empty($product->sub_category_id)) {
            $parentId = Category::where('id', $product->sub_category_id)->value('parent_id');
            if (!empty($parentId)) {
                $id = $this->categoryEbayId($parentId);
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return trim((string) ($ebaySettings['default_category_id'] ?? ''));
    }

    private function categoryEbayId($categoryId)
    {
        if (empty($categoryId) || !Schema::hasColumn('categories', 'ebay_category_ids')) {
            return '';
        }
        $raw = Category::where('id', $categoryId)->value('ebay_category_ids');
        if (empty($raw)) {
            return '';
        }
        $parts = explode(',', str_replace(' ', '', $raw));
        return trim((string) ($parts[0] ?? ''));
    }

    private function resolveCurrency($businessId)
    {
        $business = Business::find($businessId);
        if ($business && !empty($business->currency_id)) {
            $code = \App\Currency::where('id', $business->currency_id)->value('code');
            if (!empty($code)) {
                return $code;
            }
        }
        return 'USD';
    }
}
