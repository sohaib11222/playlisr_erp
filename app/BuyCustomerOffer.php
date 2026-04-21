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
        'final_price_paid' => 'float',
        'items_lp_count' => 'integer',
        'items_45_count' => 'integer',
        'items_cd_count' => 'integer',
        'items_cassette_count' => 'integer',
        'items_dvd_count' => 'integer',
        'items_bluray_count' => 'integer',
        'items_other_count' => 'integer',
        'condition_mint_nm_count' => 'integer',
        'condition_vg_plus_count' => 'integer',
        'condition_g_below_count' => 'integer',
        'compliance_confirmed_ownership' => 'boolean',
        'compliance_ack_final_sale' => 'boolean',
    ];

    // Allowed payment-method values (enum lives in app code, not the DB, so we
    // can add more without a migration). Cashier-facing labels come from the
    // key => label map in BuyFromCustomerController::paymentMethodOptions().
    const PAYMENT_METHODS = ['cash', 'store_credit', 'zelle_jon', 'venmo_jon'];

    const ID_TYPES = ['drivers_license', 'passport', 'state_id', 'other'];

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

