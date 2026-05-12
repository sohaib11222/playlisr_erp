<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PosQuickReceive extends Model
{
    protected $table = 'pos_quick_receives';

    protected $fillable = [
        'business_id',
        'business_location_id',
        'transaction_id',
        'product_id',
        'variation_id',
        'product_name',
        'artist',
        'sub_sku',
        'qty',
        'note',
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
