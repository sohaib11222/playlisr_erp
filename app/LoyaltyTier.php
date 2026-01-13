<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoyaltyTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'min_lifetime_purchases',
        'discount_percentage',
        'points_multiplier',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'min_lifetime_purchases' => 'decimal:2',
        'discount_percentage' => 'integer',
        'points_multiplier' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get tier for a given lifetime purchase amount
     */
    public static function getTierForPurchaseAmount($business_id, $lifetime_purchases)
    {
        return self::where('business_id', $business_id)
            ->where('is_active', true)
            ->where('min_lifetime_purchases', '<=', $lifetime_purchases)
            ->orderBy('min_lifetime_purchases', 'desc')
            ->first();
    }

    /**
     * Get all active tiers ordered by minimum purchases
     */
    public static function getActiveTiers($business_id)
    {
        return self::where('business_id', $business_id)
            ->where('is_active', true)
            ->orderBy('min_lifetime_purchases', 'asc')
            ->get();
    }
}

