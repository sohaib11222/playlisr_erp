<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One cash deposit (safe drop) the cashier moved to the safe. Carries the
 * per-store running deposit number written on the post-it, plus who/when/
 * how much. See database/migrations/..._create_cash_deposits_table.php.
 */
class CashDeposit extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount'       => 'float',
        'deposited_at' => 'datetime',
    ];
}
