<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ManualItemPriceRule extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}

