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
        do {
            $card_number = 'GC' . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('business_id', $business_id)
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


