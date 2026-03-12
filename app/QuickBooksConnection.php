<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class QuickBooksConnection extends Model
{
    protected $table = 'quickbooks_connections';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'token_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
    ];
}

