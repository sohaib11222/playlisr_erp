<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BuyCustomerOffer extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['accepted_at'];

    protected $casts = [
        'calculated_cash_total' => 'float',
        'calculated_credit_total' => 'float',
        'starting_offer_cash' => 'float',
        'starting_offer_credit' => 'float',
        'second_offer_cash' => 'float',
        'second_offer_credit' => 'float',
        'final_offer_cash' => 'float',
        'final_offer_credit' => 'float',
        'compliance_items_owned' => 'boolean',
        'compliance_sales_final' => 'boolean',
    ];

    /**
     * Human-readable buy record number (e.g. BFC-000042).
     */
    public function getBuyRecordNumberAttribute()
    {
        if (empty($this->id)) {
            return '—';
        }

        return 'BFC-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

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

    /**
     * The offer lines the cashier actually filled in. The create form seeds
     * several blank default rows (qty 1, no title/price) that shouldn't show
     * in the "what we bought" breakdown, so filter those out. Uses the loaded
     * relation when present to avoid an extra query.
     */
    public function getMeaningfulLinesAttribute()
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->filter(function ($l) {
            return !empty($l->title)
                || (float) $l->line_cash_total > 0
                || (float) $l->line_credit_total > 0
                || (float) $l->discogs_median_price > 0
                || (float) $l->quantity > 1;
        })->values();
    }
}

