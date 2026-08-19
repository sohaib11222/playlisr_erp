<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'code',
        'type',
        'value',
        'min_order_amount',
        'usage_limit',
        'times_used',
        'expiry_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $dates = ['expiry_date', 'deleted_at'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Checks status, expiry, and usage limit. Pass $subtotal to also enforce
     * min_order_amount — omit it where only the coupon's own state matters
     * (e.g. the admin list).
     */
    public function isValid($subtotal = null)
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expiry_date && $this->expiry_date < now()->toDateString()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) {
            return false;
        }

        if ($subtotal !== null && $this->min_order_amount && (float) $subtotal < (float) $this->min_order_amount) {
            return false;
        }

        return true;
    }

    /**
     * Discount amount for a given subtotal. Never exceeds the subtotal
     * itself, so a fixed-amount coupon can't push a total negative.
     */
    public function discountFor($subtotal)
    {
        $subtotal = (float) $subtotal;

        $discount = $this->type === 'percent'
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        return round(min(max($discount, 0), $subtotal), 2);
    }
}
