<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BuyCustomerOffer extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['accepted_at', 'storage_location_updated_at'];

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
        'is_donated' => 'boolean',
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

    /**
     * Total item count = sum of each line's quantity, not the number of
     * line rows entered — a row for "50x sealed 7-inches" is 50 items, not 1.
     */
    public function getTotalItemQuantityAttribute()
    {
        return (int) round($this->lines->sum('quantity'));
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

    public function processingStatusUpdatedBy()
    {
        return $this->belongsTo(\App\User::class, 'processing_status_updated_by');
    }

    /**
     * Status-line shown under the processing-status dropdown: in progress
     * shows who's on it and that anyone can pick it up; complete lists every
     * employee who ever touched the status, not just the last one.
     */
    public function getProcessingStatusMetaAttribute()
    {
        $status = $this->processing_status ?: 'not_started';
        $contributors = json_decode($this->processing_status_contributors ?: '[]', true) ?: [];
        $lastUpdater = optional($this->processingStatusUpdatedBy)->user_full_name ?? optional($this->processingStatusUpdatedBy)->username;

        if ($status === 'complete') {
            if ($contributors) {
                return 'Completed by: ' . implode(', ', $contributors);
            }
            return $lastUpdater ? ('Completed by: ' . $lastUpdater) : '—';
        }

        if ($status === 'in_progress') {
            return $lastUpdater
                ? ('In progress by ' . $lastUpdater . ' — any team member can complete it')
                : 'In progress — any team member can complete it';
        }

        return $lastUpdater ?: '—';
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

