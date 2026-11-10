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
     * Find the ERP customer for a website account: by email first, then by
     * phone (last 10 digits of mobile/alternate/landline) for customers who
     * only gave us a phone number in store. Among phone matches, a contact
     * holding credit wins, then the oldest, so balance() and adjust() always
     * pick the same one.
     *
     * @return array{0: ?Contact, 1: ?string}
     */
    private function findContact(?string $email, ?string $phone): array
    {
        $business_id = $this->businessId();
        $base = function () use ($business_id) {
            return Contact::where('business_id', $business_id)->whereIn('type', ['customer', 'both']);
        };

        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $c = $base()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($c) {
                return [$c, 'email'];
            }
        }

        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        if (strlen($digits) === 10) {
            $norm = function ($col) {
                return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col,''),' ',''),'-',''),'(',''),')',''),'.',''),'+','')";
            };
            $like = '%' . $digits;
            $c = $base()
                ->where(function ($w) use ($norm, $like) {
                    $w->whereRaw($norm('mobile') . ' LIKE ?', [$like])
                      ->orWhereRaw($norm('alternate_number') . ' LIKE ?', [$like])
                      ->orWhereRaw($norm('landline') . ' LIKE ?', [$like]);
                })
                ->orderByRaw('CASE WHEN COALESCE(balance,0) > 0 THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->first();
            if ($c) {
                return [$c, 'phone'];
            }
        }

        return [null, null];
    }

    /**
     * POST /api/v1/nivessa-web/store-credit/adjust
     * Body: { email, delta, reason?, source?, metadata? }
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required_without:phone|nullable|email|max:255',
            'phone' => 'nullable|string|max:32',
            'delta' => 'required|numeric|not_in:0',
            'reason' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:64',
            'metadata' => 'nullable|array',
        ]);

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $delta = round((float) $data['delta'], 2);

        [$contact] = $this->findContact($email, $data['phone'] ?? null);

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

    /**
     * POST /api/v1/nivessa-web/store-credit/balance
     * Body: { email }
     * The website reads a customer's store credit live from here so the ERP
     * stays the one place the balance lives. Same contact match as adjust().
     */
    public function balance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required_without:phone|nullable|email|max:255',
            'phone' => 'nullable|string|max:32',
        ]);

        [$contact, $matchedBy] = $this->findContact($data['email'] ?? null, $data['phone'] ?? null);

        if (!$contact) {
            return response()->json([
                'success' => true,
                'found' => false,
                'balance' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'found' => true,
            'matched_by' => $matchedBy,
            'balance' => round((float) ($contact->balance ?? 0), 2),
        ]);
    }
}
