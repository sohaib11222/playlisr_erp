<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "What are you working on today?" picker shown right after login.
 *
 * Picking Cashier (with a location) sets that user as
 * business_locations.current_cashier_id for the chosen location. Every POS
 * sale at that location then attributes to them regardless of who is
 * clicking the screen — which is correct because there's exactly one
 * cashier at the front desk per location at any given moment, and
 * inventory / shipping / managers may have a session open at the same
 * machine.
 *
 * The other roles (Manager, Inventory, Shipping) just store a session
 * tag for UX (header label, future page-level access). They do not change
 * the active cashier.
 *
 * Manager mode is restricted to first names Sarah, Jon, Fatteen, Lashyn
 * (per Sarah, 2026-04-29). Other staff don't see that button.
 */
class ChooseRoleController extends Controller
{
    /**
     * First-name allow-list for "Manager" mode. Match is case-insensitive.
     * Stays a code constant rather than a config row because the set is small
     * and the consequences of a leak are real (admin access).
     */
    const MANAGER_FIRST_NAMES = ['Sarah', 'Jon', 'Fatteen', 'Lashyn'];

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $locations = BusinessLocation::where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name']);

        $user = auth()->user();
        $can_manager = self::userCanManager($user);

        // Look up who the active cashier already is at each location, so
        // the picker can show "Currently: Manolo" next to each store and the
        // person about to take over knows whether they're the handoff.
        $current_cashiers = [];
        foreach ($locations as $loc) {
            if (!empty($loc->current_cashier_id)) {
                $u = User::find($loc->current_cashier_id);
                if ($u) {
                    $current_cashiers[$loc->id] = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                }
            }
        }

        return view('auth.choose_role', [
            'locations' => $locations,
            'can_manager' => $can_manager,
            'current_cashiers' => $current_cashiers,
        ]);
    }

    public function set(Request $request)
    {
        $role = (string) $request->input('role');
        $allowed = ['cashier', 'manager', 'inventory', 'shipping'];
        if (!in_array($role, $allowed, true)) {
            return back()->with('status', ['success' => 0, 'msg' => 'Pick what you are working on.']);
        }

        $user = auth()->user();

        if ($role === 'manager' && !self::userCanManager($user)) {
            return back()->with('status', ['success' => 0, 'msg' => 'Manager mode is limited to authorized staff.']);
        }

        if ($role === 'cashier') {
            $location_id = (int) $request->input('location_id');
            $business_id = $request->session()->get('user.business_id');
            $loc = BusinessLocation::where('business_id', $business_id)->where('id', $location_id)->first();
            if (!$loc) {
                return back()->with('status', ['success' => 0, 'msg' => 'Pick which store you are at.']);
            }
            // Take over the cashier slot for this location. The previous
            // cashier (if any) just stops being attributed; we don't clear
            // their own session role, since they may still be doing back
            // office work.
            DB::table('business_locations')
                ->where('id', $loc->id)
                ->update([
                    'current_cashier_id'  => $user->id,
                    'cashier_assigned_at' => Carbon::now(),
                ]);
            $request->session()->put('shift_role', 'cashier');
            $request->session()->put('shift_location_id', $loc->id);
            return redirect('/pos/create');
        }

        // Non-cashier roles: just stamp the session, leave attribution alone.
        $request->session()->put('shift_role', $role);
        $request->session()->forget('shift_location_id');
        return redirect('/home');
    }

    /**
     * Check whether the given user is allowed to pick Manager mode.
     * Matches on first_name (case-insensitive) against the small allow list.
     */
    public static function userCanManager($user)
    {
        if (!$user) return false;
        $first = strtolower(trim((string) ($user->first_name ?? '')));
        if ($first === '') return false;
        foreach (self::MANAGER_FIRST_NAMES as $allowed) {
            if (strtolower($allowed) === $first) return true;
        }
        return false;
    }
}
