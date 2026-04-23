<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\GiftCard;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gift card bridge for the Nivessa website API (jonhedvat/server).
 *
 * Design: the ERP is the single source of truth for gift-card balances, so
 * the website stops owning its own giftcards table and proxies every lookup
 * / charge / issue through these endpoints. A card issued from the website
 * therefore immediately works at the POS, and vice-versa.
 *
 * All methods scope by a config business_id; no business_id leaks over HTTP.
 */
class NivessaGiftCardController extends Controller
{
    /** @return int */
    private function businessId(): int
    {
        return (int) config('services.nivessa_web.business_id');
    }

    /**
     * POST /api/v1/nivessa-web/gift-cards/lookup
     * Body: { "card_number": "GC123456" }
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => 'required|string|max:64',
        ]);

        $card = GiftCard::where('business_id', $this->businessId())
            ->where('card_number', $data['card_number'])
            ->first();

        if (!$card) {
            return response()->json(['success' => false, 'error' => 'not_found'], 404);
        }

        return response()->json([
            'success' => true,
            'card_number'   => $card->card_number,
            'balance'       => (float) $card->balance,
            'initial_value' => (float) $card->initial_value,
            'status'        => $card->status,
            'expiry_date'   => $card->expiry_date ? Carbon::parse($card->expiry_date)->toDateString() : null,
            'is_valid'      => $card->isValid(),
            'contact_id'    => $card->contact_id,
        ]);
    }

    /**
     * POST /api/v1/nivessa-web/gift-cards/charge
     * Body: { "card_number": "GC123456", "amount": 12.50, "reference": "web-order-abc" }
     *
     * Atomic debit. Caller MUST treat a 409 `insufficient_balance` response as
     * the authoritative balance (returned in `balance`) so the website can
     * retry with an adjusted amount or fall through to card payment.
     */
    public function charge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => 'required|string|max:64',
            'amount'      => 'required|numeric|min:0.01',
            'reference'   => 'nullable|string|max:255',
        ]);

        $amount = round((float) $data['amount'], 2);

        try {
            $result = DB::transaction(function () use ($data, $amount) {
                // Row-lock to prevent double-spend if website + POS hit the same card concurrently.
                $card = GiftCard::where('business_id', $this->businessId())
                    ->where('card_number', $data['card_number'])
                    ->lockForUpdate()
                    ->first();

                if (!$card) {
                    return ['error' => 'not_found', 'status' => 404];
                }
                if (!$card->isValid()) {
                    return ['error' => 'invalid', 'status' => 409, 'balance' => (float) $card->balance];
                }
                if ((float) $card->balance < $amount) {
                    return ['error' => 'insufficient_balance', 'status' => 409, 'balance' => (float) $card->balance];
                }

                $card->balance = round((float) $card->balance - $amount, 2);
                if ($card->balance <= 0) {
                    $card->balance = 0;
                    $card->status  = 'used';
                }
                // Best-effort audit trail; the notes column is free-form text.
                if (!empty($data['reference'])) {
                    $stamp = now()->toDateTimeString();
                    $card->notes = trim(($card->notes ?? '') . "\n[{$stamp}] charged {$amount} ref={$data['reference']}");
                }
                $card->save();

                return [
                    'card_number'       => $card->card_number,
                    'charged'           => $amount,
                    'remaining_balance' => (float) $card->balance,
                    'status'            => $card->status,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('nivessa_web gift-card charge failed', [
                'card_number' => $data['card_number'],
                'amount'      => $amount,
                'error'       => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'server_error'], 500);
        }

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'error'   => $result['error'],
                'balance' => $result['balance'] ?? null,
            ], $result['status']);
        }

        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * POST /api/v1/nivessa-web/gift-cards/issue
     * Body: {
     *   "initial_value": 100.00,
     *   "expiry_date":   "2028-04-22" (optional, defaults to +2 years),
     *   "contact_email": "foo@bar.com" (optional — links to ERP Contact if found),
     *   "notes":         "nivessa.com order 6420abc",
     *   "card_number":   "CUSTOM-CODE" (optional — auto-generated if omitted)
     * }
     *
     * Idempotency: if `card_number` is provided and already exists, we return
     * 409 `duplicate` so the website can retry or reconcile.
     */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'initial_value' => 'required|numeric|min:0.01',
            'expiry_date'   => 'nullable|date',
            'contact_email' => 'nullable|email|max:255',
            'notes'         => 'nullable|string|max:1000',
            'card_number'   => 'nullable|string|max:64',
        ]);

        $business_id = $this->businessId();
        $initial     = round((float) $data['initial_value'], 2);
        $expiry      = !empty($data['expiry_date'])
            ? Carbon::parse($data['expiry_date'])->toDateString()
            : Carbon::now()->addYears(2)->toDateString();

        $card_number = $data['card_number'] ?? GiftCard::generateCardNumber($business_id);

        if (!empty($data['card_number'])) {
            $exists = GiftCard::where('business_id', $business_id)
                ->where('card_number', $card_number)
                ->exists();
            if ($exists) {
                return response()->json(['success' => false, 'error' => 'duplicate'], 409);
            }
        }

        $contact_id = null;
        if (!empty($data['contact_email'])) {
            $contact_id = Contact::where('business_id', $business_id)
                ->where('email', $data['contact_email'])
                ->value('id');
        }

        $card = new GiftCard();
        $card->business_id   = $business_id;
        $card->card_number   = $card_number;
        $card->contact_id    = $contact_id;
        $card->initial_value = $initial;
        $card->balance       = $initial;
        $card->expiry_date   = $expiry;
        $card->status        = 'active';
        $card->notes         = $data['notes'] ?? null;
        $card->created_by    = null;
        $card->save();

        return response()->json([
            'success'       => true,
            'card_number'   => $card->card_number,
            'balance'       => (float) $card->balance,
            'initial_value' => (float) $card->initial_value,
            'expiry_date'   => $card->expiry_date ? Carbon::parse($card->expiry_date)->toDateString() : null,
            'contact_id'    => $card->contact_id,
        ], 201);
    }
}
