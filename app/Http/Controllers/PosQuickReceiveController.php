<?php

namespace App\Http\Controllers;

use App\Product;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Cashier-side "quick receive at the till": when a customer brings up
// 10 records and one of them shows out-of-stock in the POS, the cashier
// can receive 1 unit at the current store and add it to the sale in one
// click instead of typing it as a manual line. Every quick-receive is
// logged here so Sarah/Jon can spot patterns (lots at one store, one
// cashier, one cluster of titles, etc.).
//
// Pattern mirrors PosPriceOverrideController:
//   - setup() creates the audit table + grants a permission. Idempotent.
//   - store() is the cashier-side write (single VLD increment + audit row).
//   - index() is the admin audit page at /admin/pos-quick-receives.
//
// Setup is a button on the admin page, not a migration — same reasoning
// as price-overrides: dispatching the migrations workflow has broken
// the site before.
class PosQuickReceiveController extends Controller
{
    // One-time install: create the audit table and grant the quick-receive
    // permission to every role so cashiers can use it. Idempotent.
    public function setup(Request $request)
    {
        try {
            if (!Schema::hasTable('pos_quick_receives')) {
                Schema::create('pos_quick_receives', function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->unsignedInteger('business_id')->index();
                    $table->unsignedInteger('business_location_id')->nullable()->index();
                    $table->unsignedInteger('transaction_id')->nullable()->index();
                    $table->unsignedInteger('product_id')->nullable()->index();
                    $table->unsignedInteger('variation_id')->nullable();
                    $table->string('product_name', 191)->nullable();
                    $table->string('artist', 191)->nullable();
                    $table->string('sub_sku', 191)->nullable();
                    $table->decimal('qty', 22, 4)->default(1);
                    $table->text('note')->nullable();
                    $table->unsignedInteger('user_id')->nullable()->index();
                    $table->timestamp('undone_at')->nullable();
                    $table->unsignedInteger('undone_by_user_id')->nullable();
                    $table->timestamps();
                    $table->index(['business_id', 'created_at']);
                });
            } else {
                // Idempotent add for installs that ran before the undo feature.
                if (!Schema::hasColumn('pos_quick_receives', 'undone_at')) {
                    Schema::table('pos_quick_receives', function (Blueprint $table) {
                        $table->timestamp('undone_at')->nullable()->after('user_id');
                    });
                }
                if (!Schema::hasColumn('pos_quick_receives', 'undone_by_user_id')) {
                    Schema::table('pos_quick_receives', function (Blueprint $table) {
                        $table->unsignedInteger('undone_by_user_id')->nullable()->after('undone_at');
                    });
                }
            }

            $perm = Permission::where('name', 'pos_quick_receive')
                ->where('guard_name', 'web')
                ->first();
            if (!$perm) {
                $perm = Permission::create([
                    'name' => 'pos_quick_receive',
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

            return redirect('/admin/pos-quick-receives')->with('status_success',
                'Setup complete. ' . ($rolesTouched > 0
                    ? 'Granted quick-receive permission to ' . $rolesTouched . ' role(s). '
                    : 'Permission already granted on all roles. ')
                . 'Cashiers can now quick-receive out-of-stock items at the POS after a hard refresh.');
        } catch (\Exception $e) {
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'Setup failed: ' . $e->getMessage());
        }
    }

    // Cashier-side write: receive `qty` (default 1) of a variation at a
    // specific location and log it. Returns JSON for the POS to act on.
    //
    // Wired into the POS search/scan flow: when an item shows out of stock,
    // the cashier confirms and we POST here. We bump qty_available by qty
    // (single atomic increment on variation_location_details), log the row,
    // and the POS then re-fetches the product row to add it to the cart.
    public function store(Request $request)
    {
        if (!Schema::hasTable('pos_quick_receives')) {
            return response()->json([
                'success' => false,
                'msg' => 'Quick-receive is not set up yet. Ask the manager to visit /admin/pos-quick-receives and click "Set it up".',
            ], 400);
        }

        $variation_id = (int) $request->input('variation_id');
        $location_id = (int) $request->input('location_id');
        $qty = (float) $request->input('qty', 1);
        $note = trim((string) $request->input('note', ''));

        if ($variation_id <= 0 || $location_id <= 0) {
            return response()->json([
                'success' => false,
                'msg' => 'Missing variation or location.',
            ], 422);
        }
        if ($qty <= 0 || $qty > 50) {
            $qty = 1;
        }

        $business_id = $request->session()->get('user.business_id');
        $user_id = auth()->id();

        // Look up the variation + product. Confirm it belongs to this
        // business (otherwise refuse — don't let a stale or stolen
        // variation_id touch stock for someone else's catalog).
        $variation = Variation::with('product')->find($variation_id);
        if (!$variation || !$variation->product || (int) $variation->product->business_id !== (int) $business_id) {
            return response()->json([
                'success' => false,
                'msg' => 'Product not found for this business.',
            ], 404);
        }
        $product = $variation->product;

        if ((int) $product->enable_stock !== 1) {
            return response()->json([
                'success' => false,
                'msg' => 'This product does not track stock — no receive needed; just add it to the sale.',
            ], 400);
        }

        try {
            DB::transaction(function () use (
                $variation, $product, $location_id, $qty, $note,
                $business_id, $user_id, $request
            ) {
                // Find-or-create the (variation, product, location) row and
                // atomically increment qty_available by qty. Mirror the
                // logic in ProductUtil::updateProductQuantity but inline so
                // we don't pull in the wider purchase/cost pipeline.
                $vld = VariationLocationDetails::where('variation_id', $variation->id)
                    ->where('product_id', $product->id)
                    ->where('product_variation_id', $variation->product_variation_id)
                    ->where('location_id', $location_id)
                    ->lockForUpdate()
                    ->first();
                if (!$vld) {
                    $vld = new VariationLocationDetails();
                    $vld->variation_id = $variation->id;
                    $vld->product_id = $product->id;
                    $vld->product_variation_id = $variation->product_variation_id;
                    $vld->location_id = $location_id;
                    $vld->qty_available = 0;
                }
                $vld->qty_available = (float) $vld->qty_available + (float) $qty;
                $vld->save();

                DB::table('pos_quick_receives')->insert([
                    'business_id' => $business_id,
                    'business_location_id' => $location_id,
                    'transaction_id' => null,
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'product_name' => mb_substr((string) ($product->name ?? ''), 0, 191),
                    'artist' => isset($product->artist) ? mb_substr((string) $product->artist, 0, 191) : null,
                    'sub_sku' => mb_substr((string) ($variation->sub_sku ?? ''), 0, 191),
                    'qty' => $qty,
                    'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
                    'user_id' => $user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('PosQuickReceive store failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg' => 'Receive failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'variation_id' => $variation->id,
            'msg' => 'Received 1 — adding to sale.',
        ]);
    }

    // Admin undo: when a quick-receive was clicked by mistake (or the line
    // was deleted from the cart and the unit was never sold), this rolls
    // back the stock bump and marks the audit row as undone. Decrement is
    // atomic via DB::raw so it can't double-undo on a double-click race.
    public function undo(Request $request)
    {
        if (!Schema::hasTable('pos_quick_receives')) {
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'Quick-receive table does not exist yet.');
        }
        if (!Schema::hasColumn('pos_quick_receives', 'undone_at')) {
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'Undo column is missing — click "Set it up" once on this page.');
        }

        $id = (int) $request->input('id');
        if ($id <= 0) {
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'Missing row id.');
        }
        $businessId = $request->session()->get('user.business_id');
        $userId = auth()->id();

        $row = DB::table('pos_quick_receives')
            ->where('id', $id)
            ->where('business_id', $businessId)
            ->first();
        if (!$row) {
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'Row not found.');
        }
        if (!empty($row->undone_at)) {
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'This receive was already undone on ' . \Carbon\Carbon::parse($row->undone_at)->format('M j, g:i a') . '.');
        }

        try {
            DB::transaction(function () use ($row, $userId) {
                // Atomic decrement. We don't refuse if it would go negative
                // — negative stock is itself the signal that the unit was
                // already sold (or the catalog is out of sync elsewhere),
                // and Sarah needs to see that, not have the undo silently
                // refuse and leave the audit row marked as active.
                $variation = \App\Variation::find($row->variation_id);
                $productVariationId = $variation ? $variation->product_variation_id : null;

                $vldQuery = \App\VariationLocationDetails::where('variation_id', $row->variation_id)
                    ->where('product_id', $row->product_id)
                    ->where('location_id', $row->business_location_id);
                if (!is_null($productVariationId)) {
                    $vldQuery->where('product_variation_id', $productVariationId);
                }
                $vld = $vldQuery->lockForUpdate()->first();
                if ($vld) {
                    $vld->qty_available = (float) $vld->qty_available - (float) $row->qty;
                    $vld->save();
                }

                DB::table('pos_quick_receives')
                    ->where('id', $row->id)
                    ->update([
                        'undone_at' => now(),
                        'undone_by_user_id' => $userId,
                        'updated_at' => now(),
                    ]);
            });
        } catch (\Exception $e) {
            \Log::error('PosQuickReceive undo failed: ' . $e->getMessage());
            return redirect('/admin/pos-quick-receives')->with('status_error',
                'Undo failed: ' . $e->getMessage());
        }

        $label = trim(($row->artist ? $row->artist . ' — ' : '') . ($row->product_name ?? ''));
        return redirect('/admin/pos-quick-receives')->with('status_success',
            'Undone. Stock for "' . ($label ?: 'item') . '" decreased by ' . rtrim(rtrim(number_format($row->qty, 2), '0'), '.') . '.');
    }

    public function index(Request $request)
    {
        if (!Schema::hasTable('pos_quick_receives')) {
            return view('admin.pos_quick_receives', [
                'tableExists' => false,
                'rows' => collect(),
                'totals' => null,
                'filters' => ['days' => 30, 'user' => '', 'location' => ''],
                'locations' => collect(),
            ]);
        }

        $businessId = $request->session()->get('user.business_id');

        $days = (int) $request->input('days', 30);
        if ($days <= 0 || $days > 365) { $days = 30; }
        $userFilter = trim((string) $request->input('user', ''));
        $locationFilter = (int) $request->input('location', 0);

        $q = DB::table('pos_quick_receives as r')
            ->where('r.business_id', $businessId)
            ->where('r.created_at', '>=', now()->subDays($days))
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'r.business_location_id');

        if ($userFilter !== '') {
            $q->where(function ($w) use ($userFilter) {
                $w->where('u.username', 'like', '%' . $userFilter . '%')
                  ->orWhere('u.first_name', 'like', '%' . $userFilter . '%')
                  ->orWhere('u.surname', 'like', '%' . $userFilter . '%');
            });
        }
        if ($locationFilter > 0) {
            $q->where('r.business_location_id', $locationFilter);
        }

        $hasUndoCol = Schema::hasColumn('pos_quick_receives', 'undone_at');
        $selects = [
            'r.id', 'r.created_at',
            'r.product_id', 'r.variation_id',
            'r.product_name', 'r.artist', 'r.sub_sku',
            'r.qty', 'r.note',
            'u.username as cashier_username',
            'u.first_name as cashier_first',
            'u.surname as cashier_last',
            'bl.name as location_name',
            'r.business_location_id',
        ];
        if ($hasUndoCol) {
            $selects[] = 'r.undone_at';
            $selects[] = 'r.undone_by_user_id';
        }
        $rows = $q->orderByDesc('r.created_at')->limit(500)->get($selects);
        if (!$hasUndoCol) {
            $rows = $rows->map(function ($r) {
                $r->undone_at = null;
                $r->undone_by_user_id = null;
                return $r;
            });
        }

        // Note: Collection::whereNull doesn't exist on this Laravel version,
        // hence the manual filter() closures.
        $activeRows = $rows->filter(function ($r) { return is_null($r->undone_at); });
        $undoneRows = $rows->filter(function ($r) { return !is_null($r->undone_at); });
        $totals = (object) [
            'count' => $activeRows->count(),
            'qty_total' => round($activeRows->sum('qty'), 2),
            'distinct_products' => $activeRows->pluck('product_id')->filter()->unique()->count(),
            'distinct_cashiers' => $activeRows->pluck('cashier_username')->filter()->unique()->count(),
            'undone_count' => $undoneRows->count(),
        ];

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.pos_quick_receives', [
            'tableExists' => true,
            'rows' => $rows,
            'totals' => $totals,
            'filters' => [
                'days' => $days,
                'user' => $userFilter,
                'location' => $locationFilter,
            ],
            'locations' => $locations,
        ]);
    }
}
