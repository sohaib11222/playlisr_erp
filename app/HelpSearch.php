<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class HelpSearch extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'result_count' => 'int',
    ];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }
}
