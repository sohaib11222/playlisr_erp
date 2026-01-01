<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbayOauthToken extends Model
{
    protected $fillable = [
        'access_token',
        'token_type',
        'expires_in',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];
} 