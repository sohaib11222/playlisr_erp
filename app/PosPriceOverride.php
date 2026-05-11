<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PosPriceOverride extends Model
{
    protected $table = 'pos_price_overrides';

    protected $fillable = [
        'business_id',
        'business_location_id',
        'transaction_id',
        'transaction_sell_line_id',
        'product_id',
        'variation_id',
        'product_name',
        'artist',
        'system_price',
        'sold_price',
        'diff',
        'reason',
        'user_id',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
