<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class QuickBooksEntityMap extends Model
{
    protected $table = 'quickbooks_entity_maps';

    protected $guarded = ['id'];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}

