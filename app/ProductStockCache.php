<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductStockCache extends Model
{
    protected $table = 'product_stock_cache';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'total_sold' => 'decimal:4',
        'total_transfered' => 'decimal:4',
        'total_adjusted' => 'decimal:4',
        'stock_price' => 'decimal:4',
        'stock' => 'decimal:4',
        'total_mfg_stock' => 'decimal:4',
        'alert_quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'enable_stock' => 'boolean',
        'is_inactive' => 'boolean',
        'not_for_selling' => 'boolean',
        'calculated_at' => 'datetime',
    ];

    /**
     * Get the product that owns the stock cache.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Get the variation that owns the stock cache.
     */
    public function variation()
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /**
     * Get the business location.
     */
    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    /**
     * Get the category.
     */
    public function category()
    {
        return $this->belongsTo(\App\Category::class, 'category_id');
    }
}

