<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Nivessa (Sarah 2026-05): before using the POS screens, staff must pick
 * what they are doing (cashier vs shipping vs inventory) so reports can
 * attribute activity without relying on Clover clock-ins. Stored only in
 * session + activity log — does not change auth roles.
 */
class EnsurePosDuty
{
    private const DUTIES = ['cashier', 'shipping', 'inventory'];

    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return $next($request);
        }

        $duty = session('pos_duty');
        if (in_array($duty, self::DUTIES, true)) {
            return $next($request);
        }

        $intended = $request->fullUrl();
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'pos_duty_required' => true,
                'redirect' => action('SellPosController@selectPosDuty', ['intended' => $intended]),
            ], 409);
        }

        return redirect()->action('SellPosController@selectPosDuty', ['intended' => $intended]);
    }
}
