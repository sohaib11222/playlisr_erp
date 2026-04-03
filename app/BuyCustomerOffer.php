<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BuyCustomerOffer extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'calculated_cash_total' => 'float',
        'calculated_credit_total' => 'float',
        'starting_offer_cash' => 'float',
        'starting_offer_credit' => 'float',
        'second_offer_cash' => 'float',
        'second_offer_credit' => 'float',
        'final_offer_cash' => 'float',
        'final_offer_credit' => 'float',
    ];

    public function lines()
    {
        return $this->hasMany(\App\BuyCustomerOfferLine::class, 'offer_id')->orderBy('line_order');
    }

    public function contact()
    {
        return $this->belongsTo(\App\Contact::class, 'contact_id');
    }

    public function location()
    {
        return $this->belongsTo(\App\BusinessLocation::class, 'location_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function acceptedPurchase()
    {
        return $this->belongsTo(\App\Transaction::class, 'accepted_purchase_id');
    }
}

