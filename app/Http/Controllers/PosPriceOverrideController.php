<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Read-only audit log of cashier-edited prices at the POS.
// Born after inline price edit was opened up to cashiers (no manager floor
// staff to gate overrides); this lets Sarah scan recent overrides without
// digging through transactions one by one.
//
// Schema setup is an admin button on this page, not a migration — Sarah
// doesn't want to dispatch the migrations workflow because past runs broke
// the site. setup() creates one new empty table and grants one permission;
// it doesn't touch any existing data.
class PosPriceOverrideController extends Controller
{
    // One-time install: create the audit table and grant the "edit price at
    // POS" permission to every role so cashiers can edit prices inline.
    // Idempotent — safe to click more than once.
    public function setup(Request $request)
    {
        try {
            if (!Schema::hasTable('pos_price_overrides')) {
                Schema::create('pos_price_overrides', function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->unsignedInteger('business_id')->index();
                    $table->unsignedInteger('business_location_id')->nullable()->index();
                    $table->unsignedInteger('transaction_id')->index();
                    $table->unsignedBigInteger('transaction_sell_line_id')->nullable();
                    $table->unsignedInteger('product_id')->nullable()->index();
                    $table->unsignedInteger('variation_id')->nullable();
                    $table->string('product_name', 191)->nullable();
                    $table->string('artist', 191)->nullable();
                    $table->decimal('system_price', 22, 4)->default(0);
                    $table->decimal('sold_price', 22, 4)->default(0);
                    $table->decimal('diff', 22, 4)->default(0);
                    $table->text('reason')->nullable();
                    $table->unsignedInteger('user_id')->nullable()->index();
                    $table->timestamps();
                    $table->index(['business_id', 'created_at']);
                });
            } elseif (!Schema::hasColumn('pos_price_overrides', 'reason')) {
                Schema::table('pos_price_overrides', function (Blueprint $table) {
                    $table->text('reason')->nullable()->after('diff');
                });
            }

            $perm = Permission::where('name', 'edit_product_price_from_pos_screen')
                ->where('guard_name', 'web')
                ->first();
            if (!$perm) {
                $perm = Permission::create([
                    'name' => 'edit_product_price_from_pos_screen',
                    'guard_name' => 'web',
                ]);
            }
            $rolesTouched = 0;
            foreach (Role::all() as $role) {
                if (!$role->hasPermissionTo($perm)) {
                    $role->givePermissionTo($perm);
                    $rolesTouched++;
                }
            }

            return redirect('/admin/pos-overrides')->with('status_success',
                'Setup complete. ' . ($rolesTouched > 0
                    ? 'Granted price-edit permission to ' . $rolesTouched . ' role(s). '
                    : 'Permission already granted on all roles. ')
                . 'Cashiers can now edit prices inline after a hard refresh of the POS.');
        } catch (\Exception $e) {
            return redirect('/admin/pos-overrides')->with('status_error',
                'Setup failed: ' . $e->getMessage());
        }
    }


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

        $hasReasonColumn = Schema::hasColumn('pos_price_overrides', 'reason');
        $selects = [
            'o.id', 'o.created_at',
            'o.transaction_id', 'o.product_name', 'o.artist',
            'o.system_price', 'o.sold_price', 'o.diff',
            'u.username as cashier_username',
            'u.first_name as cashier_first',
            'u.surname as cashier_last',
            't.invoice_no',
            'bl.name as location_name',
        ];
        if ($hasReasonColumn) {
            $selects[] = 'o.reason';
        }
        $rows = $q->orderByDesc('o.created_at')
            ->limit(500)
            ->get($selects);
        // Make `reason` always present on the row objects so the view can
        // render it unconditionally.
        if (!$hasReasonColumn) {
            $rows = $rows->map(function ($r) { $r->reason = null; return $r; });
        }

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

        // Diagnostic: show the last 20 sell lines from the most recent sales,
        // comparing the rung price (unit_price_inc_tax) against the catalog
        // sticker (variations.sell_price_inc_tax). This makes it obvious why
        // a sale did or didn't produce an override row — and surfaces the
        // edge cases (zero sticker, manual product) without a DB query.
        $recentLines = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->leftJoin('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.created_at', '>=', now()->subDays(2))
            ->whereNull('tsl.parent_sell_line_id')
            ->orderByDesc('t.created_at')
            ->limit(30)
            ->get([
                't.id as tx_id', 't.invoice_no', 't.created_at as tx_at',
                'tsl.id as line_id', 'tsl.product_id', 'tsl.variation_id',
                'tsl.unit_price_inc_tax as sold_inc',
                'v.sell_price_inc_tax as sticker_inc',
                'p.name as product_name',
                'u.first_name as cashier_first',
                'u.surname as cashier_last',
            ]);
        $overrideLineIds = DB::table('pos_price_overrides')
            ->where('business_id', $businessId)
            ->whereIn('transaction_sell_line_id', $recentLines->pluck('line_id'))
            ->pluck('transaction_sell_line_id')
            ->all();
        $loggedSet = array_flip($overrideLineIds);
        $recentLines = $recentLines->map(function ($r) use ($loggedSet) {
            $sold = (float) ($r->sold_inc ?? 0);
            $stk  = (float) ($r->sticker_inc ?? 0);
            $r->diff = round($sold - $stk, 4);
            $r->logged = isset($loggedSet[$r->line_id]);
            if (!$r->product_id || $r->product_id <= 0) {
                $r->verdict = 'skipped: manual product (no catalog baseline)';
            } elseif ($stk <= 0) {
                $r->verdict = 'skipped: sticker is $0 / unknown';
            } elseif (abs($r->diff) < 0.01) {
                $r->verdict = 'no diff: sold = sticker';
            } elseif ($r->logged) {
                $r->verdict = 'logged ✓';
            } else {
                $r->verdict = 'DIFF but NOT logged — investigate';
            }
            return $r;
        });

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
            'recentLines' => $recentLines,
        ]);
    }
}
