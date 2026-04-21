<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CloverPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount_cents' => 'integer',
        'tip_cents' => 'integer',
        'tax_cents' => 'integer',
        'amount' => 'float',
        'paid_at' => 'datetime',
        'paid_on' => 'date',
    ];

    public function location()
    {
        return $this->belongsTo(\App\BusinessLocation::class, 'location_id');
    }
}
