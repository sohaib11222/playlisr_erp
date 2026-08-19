<?php

namespace App\Http\Controllers\Api;

use App\Coupon;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Coupon bridge for the Nivessa website API (jonhedvat/server).
 *
 * The ERP is the single source of truth for coupon codes and usage counts —
 * the website never stores its own copy, it validates and redeems live
 * against these endpoints on every checkout that has a code applied. See
 * CouponController for the admin-facing CRUD this data comes from.
 */
class NivessaCouponController extends Controller
{
    /** @return int */
    private function businessId(): int
    {
        return (int) config('services.nivessa_web.business_id');
    }

    /**
     * POST /api/v1/nivessa-web/coupons/validate
     * Body: { "code": "FALL10", "subtotal": 123.45 }
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $code = strtoupper(trim($data['code']));
        $subtotal = (float) $data['subtotal'];

        $coupon = Coupon::where('business_id', $this->businessId())
            ->where('code', $code)
            ->first();

        if (!$coupon) {
            return response()->json(['success' => false, 'error' => 'not_found'], 404);
        }

        if (!$coupon->isValid($subtotal)) {
            $reason = 'invalid';
            if ($coupon->status !== 'active') {
                $reason = 'inactive';
            } elseif ($coupon->expiry_date && $coupon->expiry_date < now()->toDateString()) {
                $reason = 'expired';
            } elseif ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
                $reason = 'limit_reached';
            } elseif ($coupon->min_order_amount && $subtotal < (float) $coupon->min_order_amount) {
                $reason = 'below_minimum';
            }

            return response()->json([
                'success' => false,
                'error' => $reason,
                'min_order_amount' => $coupon->min_order_amount ? (float) $coupon->min_order_amount : null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'discount' => $coupon->discountFor($subtotal),
        ]);
    }

    /**
     * POST /api/v1/nivessa-web/coupons/redeem
     * Body: { "code": "FALL10" }
     *
     * Called once, after payment succeeds. Atomic increment under a row
     * lock so two near-simultaneous checkouts against the last remaining
     * use can't both succeed.
     */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
        ]);

        $code = strtoupper(trim($data['code']));

        try {
            $result = DB::transaction(function () use ($code) {
                $coupon = Coupon::where('business_id', $this->businessId())
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->first();

                if (!$coupon) {
                    return ['error' => 'not_found', 'status' => 404];
                }
                if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
                    return ['error' => 'limit_reached', 'status' => 409];
                }

                $coupon->times_used += 1;
                $coupon->save();

                return ['times_used' => $coupon->times_used, 'usage_limit' => $coupon->usage_limit];
            });
        } catch (\Throwable $e) {
            Log::error('nivessa_web coupon redeem failed', ['code' => $code, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => 'server_error'], 500);
        }

        if (isset($result['error'])) {
            return response()->json(['success' => false, 'error' => $result['error']], $result['status']);
        }

        return response()->json(array_merge(['success' => true], $result));
    }
}
