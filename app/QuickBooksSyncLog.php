<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class QuickBooksSyncLog extends Model
{
    protected $table = 'quickbooks_sync_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}

