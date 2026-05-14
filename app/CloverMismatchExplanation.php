<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Cashier / admin note attached to a Clover ↔ ERP reconciliation row.
 * Read on the recent_feed view via $clover_explanations, written via
 * SellPosController::mismatchExplain (POST /pos/mismatch-explain).
 *
 * source is the entry-point flag — 'register_reconciliation' for notes
 * left during the daily reconcile sweep, 'pos_feed' for in-the-moment
 * cashier explanations.
 */
class CloverMismatchExplanation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'transaction_id' => 'integer',
        'clover_payment_id' => 'integer',
        'business_id' => 'integer',
        'explained_by' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'explained_by');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function cloverPayment()
    {
        return $this->belongsTo(CloverPayment::class, 'clover_payment_id');
    }
}
