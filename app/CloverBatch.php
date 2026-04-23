<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CloverBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payment_count' => 'integer',
        'amount_cents' => 'integer',
        'amount' => 'float',
        'deposit_cents' => 'integer',
        'deposit_total' => 'float',
        'batch_at' => 'datetime',
        'batch_on' => 'date',
    ];

    public function location()
    {
        return $this->belongsTo(\App\BusinessLocation::class, 'location_id');
    }
}

