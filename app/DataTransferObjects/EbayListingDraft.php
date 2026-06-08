<?php

namespace App\DataTransferObjects;

class EbayListingDraft
{
    /** @var int */
    public $product_id;

    /** @var string */
    public $sku;

    /** @var string */
    public $title;

    /** @var string */
    public $description;

    /** @var float */
    public $price;

    /** @var string */
    public $currency;

    /** @var int */
    public $quantity;

    /** @var string */
    public $category_id;

    /** @var string */
    public $condition;

    /** @var string[] */
    public $image_urls = [];

    /** @var bool */
    public $not_for_selling = false;

    /** @var string|null */
    public $ebay_listing_id;

    /** @var string|null */
    public $listing_status;

    /** @var int|null ERP business_locations.id ships from */
    public $erp_location_id;

    /** @var string|null e.g. Pico, Hollywood */
    public $erp_location_name;

    /** @var string|null eBay merchantLocationKey */
    public $merchant_location_key;

    public function toArray()
    {
        return [
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'category_id' => $this->category_id,
            'condition' => $this->condition,
            'image_urls' => $this->image_urls,
            'not_for_selling' => $this->not_for_selling,
            'ebay_listing_id' => $this->ebay_listing_id,
            'listing_status' => $this->listing_status,
            'erp_location_id' => $this->erp_location_id,
            'erp_location_name' => $this->erp_location_name,
            'merchant_location_key' => $this->merchant_location_key,
        ];
    }
}
