<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GiftCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'card_number',
        'contact_id',
        'initial_value',
        'balance',
        'expiry_date',
        'status',
        'notes',
        'created_by'
    ];

    protected $dates = ['expiry_date', 'deleted_at'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate a unique card number
     */
    public static function generateCardNumber($business_id)
    {
        // 6 characters, letters + numbers mixed, no look-alikes (no O/0,
        // no I/1) so it's easy to write on a card. Always has at least one
        // number and one letter past F, so it can never match a nivessa.com
        // online code (those are 8 hex characters).
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $pastF = 'GHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;
        do {
            $chars = [
                $pastF[random_int(0, strlen($pastF) - 1)],
                $digits[random_int(0, strlen($digits) - 1)],
            ];
            for ($i = 0; $i < 4; $i++) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            shuffle($chars);
            $card_number = implode('', $chars);
        } while (self::withTrashed()->where('business_id', $business_id)
            ->where('card_number', $card_number)
            ->exists());

        return $card_number;
    }

    /**
     * Check if card is valid (not expired, active, has balance)
     */
    public function isValid()
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expiry_date && $this->expiry_date < now()->toDateString()) {
            return false;
        }

        if ($this->balance <= 0) {
            return false;
        }

        return true;
    }
}


