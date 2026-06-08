<?php

namespace App\Services\Ebay;

use App\DataTransferObjects\EbayListingDraft;
use App\Services\EbayService;

class EbayListingValidator
{
    /** @var EbayService */
    private $ebayService;

    public function __construct(EbayService $ebayService)
    {
        $this->ebayService = $ebayService;
    }

    /**
     * @param EbayListingDraft|null $draft
     * @param bool $warnIfListed
     * @return array{ok: bool, errors: string[], warnings: string[]}
     */
    public function validate($draft, $warnIfListed = true)
    {
        $errors = [];
        $warnings = [];

        if (!$this->ebayService->isConfigured()) {
            $errors[] = 'eBay API credentials not configured. Set App ID, Cert ID, and Dev ID in Business Settings → Integrations.';
        }

        if ($this->ebayService->isConfigured() && !$this->ebayService->isSellerConnected()) {
            $errors[] = 'Your eBay seller account is not connected. Go to /admin/ebay-seller and connect (re-connect if you connected before listing permissions were added).';
        }

        if (!$draft) {
            $errors[] = 'Product not found.';
            return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if ($draft->not_for_selling) {
            $errors[] = 'This product is marked "not for selling" and cannot be listed.';
        }

        if (empty(trim((string) $draft->title))) {
            $errors[] = 'Product has no name to list on eBay.';
        }

        if (!is_numeric($draft->price) || (float) $draft->price <= 0) {
            $errors[] = 'Product needs a sell price greater than 0 on its variation.';
        }

        if ((int) $draft->quantity < 1) {
            $errors[] = 'Product needs stock quantity of at least 1.';
        }

        if (empty($draft->image_urls)) {
            $errors[] = 'eBay requires at least one product image with an https:// URL.';
        }

        if (trim((string) $draft->category_id) === '') {
            $errors[] = 'No eBay category mapped. Set eBay Category IDs on the product category, or set a default in Business Settings → Integrations → eBay.';
        }

        if (empty(trim((string) $draft->sku))) {
            $errors[] = 'Missing SKU for eBay listing.';
        }

        if (empty(trim((string) $draft->merchant_location_key))) {
            $errors[] = 'No eBay inventory location mapped for this product\'s store. Create Pico + Hollywood locations at /admin/ebay-seller.';
        }

        if ($warnIfListed && !empty($draft->ebay_listing_id) && $draft->listing_status === 'listed') {
            $warnings[] = 'This product already has an eBay listing (ID: ' . $draft->ebay_listing_id . '). Listing again may create a duplicate.';
        }

        if (!empty($draft->erp_location_name) && !empty($draft->merchant_location_key)) {
            $warnings[] = 'Will list from ' . $draft->erp_location_name . ' (eBay location: ' . $draft->merchant_location_key . ').';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
