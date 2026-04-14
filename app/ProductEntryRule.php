<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ProductEntryRule extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'category_id' => 'integer',
        'sub_category_id' => 'integer',
        'purchase_price' => 'float',
        'selling_price' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}

