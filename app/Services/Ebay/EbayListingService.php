<?php

namespace App\Services\Ebay;

use App\Product;
use App\Services\EbayService;
use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EbayListingService
{
    /** @var int */
    private $businessId;

    /** @var EbayService */
    private $ebayService;

    /** @var EbayProductListingMapper */
    private $mapper;

    /** @var EbayListingValidator */
    private $validator;

    /** @var EbayInventoryApiClient */
    private $apiClient;

    public function __construct($businessId, EbayService $ebayService = null)
    {
        $this->businessId = (int) $businessId;
        $this->ebayService = $ebayService ?: new EbayService($businessId);
        $this->mapper = new EbayProductListingMapper(new BusinessUtil());
        $this->validator = new EbayListingValidator($this->ebayService);
        $this->apiClient = new EbayInventoryApiClient($this->businessId, $this->ebayService);
    }

    /**
     * Preflight validation for a single product.
     *
     * @param int $productId
     * @return array
     */
    public function preflight($productId)
    {
        $draft = $this->mapper->fromProductId($productId, $this->businessId);
        $result = $this->validator->validate($draft, true);

        return [
            'ok' => $result['ok'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'draft' => $draft ? $draft->toArray() : null,
        ];
    }

    /**
     * Listing readiness for the business (credentials, seller, policies).
     *
     * @return array
     */
    public function readiness()
    {
        return $this->apiClient->getReadinessSummary();
    }

    /**
     * List one product to eBay.
     *
     * @param int $productId
     * @return array
     */
    public function listProduct($productId)
    {
        $draft = $this->mapper->fromProductId($productId, $this->businessId);
        $validation = $this->validator->validate($draft, false);

        if (!$validation['ok']) {
            $this->markError($productId);
            return [
                'success' => false,
                'msg' => implode(' ', $validation['errors']),
            ];
        }

        $result = $this->apiClient->publishListing($draft);

        if (!empty($result['success'])) {
            $this->persistSuccess($productId, $result);
            return [
                'success' => true,
                'msg' => 'Product listed to eBay successfully! Listing ID: ' . ($result['listing_id'] ?? ''),
                'listing_id' => $result['listing_id'] ?? null,
                'offer_id' => $result['offer_id'] ?? null,
            ];
        }

        $this->markError($productId);
        return [
            'success' => false,
            'msg' => $result['msg'] ?? 'Failed to list product to eBay',
            'offer_id' => $result['offer_id'] ?? null,
        ];
    }

    /**
     * Bulk list products sequentially.
     *
     * @param int[] $productIds
     * @return array
     */
    public function bulkList(array $productIds)
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return [
                'success' => false,
                'msg' => 'No products selected',
                'results' => [],
            ];
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($productIds as $productId) {
            $result = $this->listProduct($productId);
            $results[] = [
                'product_id' => $productId,
                'success' => !empty($result['success']),
                'msg' => $result['msg'] ?? '',
                'listing_id' => $result['listing_id'] ?? null,
            ];
            if (!empty($result['success'])) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return [
            'success' => true,
            'msg' => "Listed {$successCount} products successfully. {$errorCount} failed.",
            'results' => $results,
        ];
    }

    private function persistSuccess($productId, array $result)
    {
        try {
            $product = Product::where('business_id', $this->businessId)->find($productId);
            if (!$product) {
                return;
            }
            if (Schema::hasColumn('products', 'ebay_listing_id')) {
                $product->ebay_listing_id = $result['listing_id'] ?? null;
            }
            if (Schema::hasColumn('products', 'ebay_offer_id')) {
                $product->ebay_offer_id = $result['offer_id'] ?? null;
            }
            if (Schema::hasColumn('products', 'listing_status')) {
                $product->listing_status = !empty($result['listing_id']) ? 'listed' : 'error';
            }
            $product->save();
        } catch (\Exception $e) {
            Log::error('eBay listing persist error: ' . $e->getMessage(), ['product_id' => $productId]);
        }
    }

    private function markError($productId)
    {
        try {
            if (!Schema::hasColumn('products', 'listing_status')) {
                return;
            }
            $product = Product::where('business_id', $this->businessId)->find($productId);
            if ($product) {
                $product->listing_status = 'error';
                $product->save();
            }
        } catch (\Exception $e) {
            Log::error('eBay listing status error: ' . $e->getMessage(), ['product_id' => $productId]);
        }
    }
}
