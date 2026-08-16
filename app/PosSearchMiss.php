<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A product search at the register that came back with nothing.
 *
 * Every row is effectively a customer request we couldn't fill: someone asked
 * for an artist or title at the counter, the cashier searched, and the POS had
 * no match at all. Surfaced in the "POS Requests" report as a buying list.
 */
class PosSearchMiss extends Model
{
    protected $table = 'pos_search_misses';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'is_scan' => 'bool',
    ];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    public function location()
    {
        return $this->belongsTo(\App\BusinessLocation::class, 'location_id');
    }
}
