<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InventoryCheckSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sale_start' => 'date',
        'sale_end' => 'date',
    ];
}
