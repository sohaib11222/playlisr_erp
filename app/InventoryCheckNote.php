<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InventoryCheckNote extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'reference_date' => 'date',
    ];
}
