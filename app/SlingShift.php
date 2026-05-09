<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SlingShift extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'dtstart' => 'datetime',
        'dtend' => 'datetime',
        'hours' => 'float',
        'published' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public const TYPE_SHIFT = 'shift';
    public const TYPE_TIME_OFF = 'time_off';
    public const TYPE_AVAILABILITY = 'availability';
    public const TYPE_UNKNOWN = 'unknown';

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'erp_user_id');
    }
}
