<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Read-only audit log of cashier-edited prices at the POS.
// Born after inline price edit was opened up to cashiers (no manager floor
// staff to gate overrides); this lets Sarah scan recent overrides without
// digging through transactions one by one.
class PosPriceOverrideController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('pos_price_overrides')) {
            return view('admin.pos_price_overrides', [
                'tableExists' => false,
                'rows' => collect(),
                'totals' => null,
                'filters' => [
                    'days' => 30, 'user' => '', 'direction' => '',
                ],
                'users' => collect(),
            ]);
        }

        $businessId = request()->session()->get('user.business_id');

        $days = (int) $request->input('days', 30);
        if ($days <= 0 || $days > 365) { $days = 30; }
        $userFilter = trim((string) $request->input('user', ''));
        $direction = $request->input('direction', '');

        $q = DB::table('pos_price_overrides as o')
            ->where('o.business_id', $businessId)
            ->where('o.created_at', '>=', now()->subDays($days))
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('transactions as t', 't.id', '=', 'o.transaction_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'o.business_location_id');

        if ($userFilter !== '') {
            $q->where(function ($w) use ($userFilter) {
                $w->where('u.username', 'like', '%' . $userFilter . '%')
                  ->orWhere('u.first_name', 'like', '%' . $userFilter . '%')
                  ->orWhere('u.surname', 'like', '%' . $userFilter . '%');
            });
        }
        if ($direction === 'down') {
            $q->where('o.diff', '<', 0);
        } elseif ($direction === 'up') {
            $q->where('o.diff', '>', 0);
        }

        $rows = $q->orderByDesc('o.created_at')
            ->limit(500)
            ->get([
                'o.id', 'o.created_at',
                'o.transaction_id', 'o.product_name', 'o.artist',
                'o.system_price', 'o.sold_price', 'o.diff',
                'u.username as cashier_username',
                'u.first_name as cashier_first',
                'u.surname as cashier_last',
                't.invoice_no',
                'bl.name as location_name',
            ]);

        $totals = (object) [
            'count' => $rows->count(),
            'down_count' => $rows->where('diff', '<', 0)->count(),
            'up_count' => $rows->where('diff', '>', 0)->count(),
            'net' => round($rows->sum('diff'), 2),
            'absolute' => round($rows->sum(function ($r) { return abs($r->diff); }), 2),
        ];

        $users = DB::table('pos_price_overrides as o')
            ->where('o.business_id', $businessId)
            ->where('o.created_at', '>=', now()->subDays(90))
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->whereNotNull('u.id')
            ->distinct()
            ->orderBy('u.first_name')
            ->get(['u.id', 'u.username', 'u.first_name', 'u.surname']);

        return view('admin.pos_price_overrides', [
            'tableExists' => true,
            'rows' => $rows,
            'totals' => $totals,
            'filters' => [
                'days' => $days,
                'user' => $userFilter,
                'direction' => $direction,
            ],
            'users' => $users,
        ]);
    }
}
