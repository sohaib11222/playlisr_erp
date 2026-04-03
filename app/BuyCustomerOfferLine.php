<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BuyCustomerOfferLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'float',
        'discogs_median_price' => 'float',
        'grade_multiplier' => 'float',
        'standard_multiplier' => 'float',
        'unit_rate' => 'float',
        'line_cash_total' => 'float',
        'line_credit_total' => 'float',
    ];

    public function offer()
    {
        return $this->belongsTo(\App\BuyCustomerOffer::class, 'offer_id');
    }
}

