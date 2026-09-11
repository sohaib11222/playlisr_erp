<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SourcingTarget extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'target_buy_price' => 'float',
    ];

    public function category()
    {
        return $this->belongsTo(\App\Category::class, 'category_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(\App\User::class, 'updated_by');
    }
}
