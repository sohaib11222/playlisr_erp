<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InStoreOrder extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['notified_at'];

    protected $casts = [
        'is_paid' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
