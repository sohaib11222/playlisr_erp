<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per store-credit event. See the migration
 * 2026_07_10_120000_create_store_credit_logs_table for the rationale.
 */
class StoreCreditLog extends Model
{
    protected $fillable = [
        'business_id',
        'contact_id',
        'user_id',
        'source',
        'amount',
        'balance_after',
        'reason',
        'buy_customer_offer_id',
        'transaction_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'balance_after' => 'float',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** True when this credit was NOT backed by a buy-from-customer purchase form. */
    public function getHasNoPurchaseFormAttribute()
    {
        return $this->source !== 'buy_from_customer';
    }
}
