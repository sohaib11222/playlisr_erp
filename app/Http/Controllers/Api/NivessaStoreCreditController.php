<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NivessaStoreCreditController extends Controller
{
    /** @return int */
    private function businessId(): int
    {
        return (int) config('services.nivessa_web.business_id');
    }

    /**
     * POST /api/v1/nivessa-web/store-credit/adjust
     * Body: { email, delta, reason?, source?, metadata? }
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'delta' => 'required|numeric|not_in:0',
            'reason' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:64',
            'metadata' => 'nullable|array',
        ]);

        $email = strtolower(trim((string) $data['email']));
        $delta = round((float) $data['delta'], 2);
        $business_id = $this->businessId();

        $contact = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$contact) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found by email',
            ], 404);
        }

        $prev = (float) ($contact->balance ?? 0);
        $next = round($prev + $delta, 2);
        if ($next < 0) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient store credit balance',
                'current_balance' => $prev,
            ], 409);
        }

        $contact->balance = $next;
        if (Schema::hasColumn('contacts', 'balance_notes')) {
            $stamp = now()->format('Y-m-d H:i');
            $source = (string) ($data['source'] ?? 'backend');
            $reason = trim((string) ($data['reason'] ?? ''));
            $line = sprintf(
                '[%s] synced %s$%s from %s -> new balance $%s%s',
                $stamp,
                $delta >= 0 ? '+' : '-',
                number_format(abs($delta), 2),
                $source,
                number_format($next, 2),
                $reason !== '' ? '. Reason: ' . $reason : ''
            );
            $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
        }
        $contact->save();

        return response()->json([
            'success' => true,
            'email' => $email,
            'delta' => $delta,
            'previous_balance' => $prev,
            'new_balance' => $next,
        ]);
    }
}
