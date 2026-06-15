<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per login attempt. Written from LoginController on both success and
 * failure. Used by the Outside-Store Logins admin report.
 */
class LoginActivity extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'username',
        'is_admin',
        'ip_address',
        'user_agent',
        'successful',
    ];

    protected $casts = [
        'is_admin'   => 'boolean',
        'successful' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
