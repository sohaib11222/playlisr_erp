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

    // Cashiers can type either a bare Discogs release ID (e.g. "12345678")
    // or a full URL into discogs_link — normalize to a clickable URL either way.
    public function getDiscogsUrlAttribute()
    {
        $value = trim((string) $this->discogs_link);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        if (ctype_digit($value)) {
            return 'https://www.discogs.com/release/' . $value;
        }
        return null;
    }
}

