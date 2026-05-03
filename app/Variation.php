<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Variation extends Model
{
    use SoftDeletes;

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
        'combo_variations' => 'array',
    ];

    // Nivessa invariant: with the resale certificate, the exc-tax and inc-tax
    // columns are the same number (purchase = what we paid; sticker = what the
    // customer pays before Clover adds sales tax). Two prior incidents shipped
    // tax math that broke this — once on the product form (Apr 27) and once on
    // the purchase save path (May 1, undercharged customers by the tax amount
    // on every sale of the affected variation). This hook locks the invariant
    // at the model layer so any future code path that forgets to mirror gets
    // auto-corrected at save time, and POS can never undercharge again.
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($variation) {
            self::mirrorPair($variation, 'default_sell_price', 'sell_price_inc_tax', 'sell');
            self::mirrorPair($variation, 'default_purchase_price', 'dpp_inc_tax', 'purchase');
        });
    }

    private static function mirrorPair($variation, $excCol, $incCol, $kind)
    {
        $excDirty = $variation->isDirty($excCol);
        $incDirty = $variation->isDirty($incCol);
        $exc = (float) $variation->{$excCol};
        $inc = (float) $variation->{$incCol};

        if (!$excDirty && !$incDirty) {
            return;
        }
        if (round($exc, 4) === round($inc, 4)) {
            return;
        }

        // Only one side changed — mirror it onto the other.
        if ($excDirty && !$incDirty) {
            $variation->{$incCol} = $variation->{$excCol};
            return;
        }
        if ($incDirty && !$excDirty) {
            $variation->{$excCol} = $variation->{$incCol};
            return;
        }

        // Both changed to different values — the caller did tax math. Pick the
        // safer direction: for selling, the larger (never undercharge); for
        // purchase, the smaller non-zero (never inflate cost above what we paid).
        if ($kind === 'sell') {
            $value = max($exc, $inc);
        } else {
            $nonZero = array_values(array_filter([$exc, $inc], function ($v) { return $v > 0; }));
            $value = empty($nonZero) ? 0 : min($nonZero);
        }
        $variation->{$excCol} = $value;
        $variation->{$incCol} = $value;
    }

    public function product_variation()
    {
        return $this->belongsTo(\App\ProductVariation::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }

    /**
     * Get the sell lines associated with the variation.
     */
    public function sell_lines()
    {
        return $this->hasMany(\App\TransactionSellLine::class);
    }

    /**
     * Get the location wise details of the the variation.
     */
    public function variation_location_details()
    {
        return $this->hasMany(\App\VariationLocationDetails::class);
    }

    /**
     * Get Selling price group prices.
     */
    public function group_prices()
    {
        return $this->hasMany(\App\VariationGroupPrice::class, 'variation_id');
    }

    public function media()
    {
        return $this->morphMany(\App\Media::class, 'model');
    }

    public function getFullNameAttribute()
    {
        $name = $this->product->name;
        if ($this->product->type == 'variable') {
            $name .= ' - ' . $this->product_variation->name . ' - ' . $this->name;
        }
        $name .= ' (' . $this->sub_sku . ')';

        return $name;
    }
}
