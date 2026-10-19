<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per store-credit event. See the migration
 * 2026_07_10_120000_create_store_credit_logs_table for the rationale.
 */
class StoreCreditLog extends Model
{
    protected $fillable = [
        'business_id',
        'contact_id',
        'user_id',
        'source',
        'amount',
        'balance_after',
        'reason',
        'buy_customer_offer_id',
        'transaction_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'balance_after' => 'float',
    ];

    /** Sources that give a customer credit (redeem is spending it). */
    const EMAIL_SOURCES = ['manual_add', 'manual_adjust', 'buy_from_customer', 'spend_reward'];

    /**
     * Email the customer "You have store credit" whenever they're given
     * credit (Sarah, 2026-10-19). Sent after the response so a slow mail
     * server never holds up the register; failures are only logged.
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function (StoreCreditLog $log) {
            if ($log->amount <= 0 || !in_array($log->source, self::EMAIL_SOURCES, true)) {
                return;
            }
            $logId = $log->id;
            app()->terminating(function () use ($logId) {
                self::sendCreditEmail($logId);
            });
        });
    }

    public static function sendCreditEmail($logId)
    {
        try {
            $log = self::find($logId);
            $contact = $log ? Contact::find($log->contact_id) : null;
            if (!$contact || !empty($contact->is_default)) {
                return;
            }
            $email = strtolower(trim((string) $contact->email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/@nivessa\.com$/', $email)) {
                return;
            }
            $first = trim((string) ($contact->first_name ?: strtok((string) $contact->name, ' ')));
            if (in_array(strtolower($first), ['website', 'customer', 'walk-in', 'walkin', 'clover', 'discogs'], true)) {
                $first = '';
            }
            $resp = app(\App\Http\Controllers\WebsiteOrdersController::class)->bridgePost('/erp/store-credit/email', [
                'to' => $email,
                'firstName' => $first,
                'amount' => round((float) $log->amount, 2),
                'balance' => round((float) $log->balance_after, 2),
            ], 15);
            if (empty($resp['success'])) {
                \Log::warning('store credit email failed for log ' . $logId . ': ' . ($resp['message'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            \Log::warning('store credit email error for log ' . $logId . ': ' . $e->getMessage());
        }
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** True when this credit was NOT backed by a buy-from-customer purchase form. */
    public function getHasNoPurchaseFormAttribute()
    {
        return $this->source !== 'buy_from_customer';
    }
}
