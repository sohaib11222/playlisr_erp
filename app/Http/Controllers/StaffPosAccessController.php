<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\User;

// Diagnoses why a staff member can't ring sales on the POS. Shows every
// staff user with the four things that gate /pos/create:
//   1. status = 'active'         (LoginController + everywhere)
//   2. allow_login = 1           (CheckUserLogin middleware)
//   3. user_type = 'user'        (CheckUserLogin middleware)
//   4. has 'sell.create' perm    (SellPosController@create + @store)
// Plus whether they have a currently-open cash register, since POS create
// redirects to /cash-register/create when they don't.
//
// Triggered by Sarah 2026-04-30 after Luis had to ring under Jon's account
// because his own login wouldn't open POS.
class StaffPosAccessController extends Controller
{
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $highlight = trim((string) request()->query('user', ''));

        $users = User::where('users.business_id', $business_id)
            ->where('users.user_type', 'user')
            ->whereNull('users.deleted_at')
            ->orderBy('users.first_name')
            ->get(['id', 'username', 'first_name', 'last_name', 'email', 'status', 'allow_login', 'user_type']);

        $rows = [];
        foreach ($users as $u) {
            // Spatie permission lookup — same call SellPosController makes.
            $can_sell_create = $u->can('sell.create');
            $can_sell_view   = $u->can('sell.view');
            $is_admin        = $u->hasRole('Admin#' . $business_id);

            $role = optional($u->roles->first())->name;

            // Strip the "#<business_id>" suffix Spatie tacks on so the table
            // is readable. "Cashier#5" -> "Cashier".
            $role_display = $role ? preg_replace('/#\d+$/', '', $role) : '—';

            $open_register = DB::table('cash_registers')
                ->where('user_id', $u->id)
                ->where('status', 'open')
                ->orderByDesc('id')
                ->first(['id', 'location_id', 'created_at']);

            $open_register_location = null;
            if ($open_register) {
                $open_register_location = DB::table('business_locations')
                    ->where('id', $open_register->location_id)
                    ->value('name');
            }

            // The four POS gates, summarised.
            $blockers = [];
            if ($u->status !== 'active')      $blockers[] = 'status≠active';
            if (!$u->allow_login)              $blockers[] = 'allow_login=0';
            if ($u->user_type !== 'user')      $blockers[] = 'user_type≠user';
            if (!$can_sell_create && !$is_admin) $blockers[] = 'no sell.create';

            $rows[] = (object) [
                'id'              => $u->id,
                'username'        => $u->username,
                'name'            => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'email'           => $u->email,
                'status'          => $u->status,
                'allow_login'     => (int) $u->allow_login,
                'user_type'       => $u->user_type,
                'role'            => $role_display,
                'is_admin'        => $is_admin,
                'can_sell_create' => $can_sell_create,
                'can_sell_view'   => $can_sell_view,
                'has_open_reg'    => (bool) $open_register,
                'open_reg_loc'    => $open_register_location,
                'open_reg_at'     => $open_register ? $open_register->created_at : null,
                'blockers'        => $blockers,
                'highlight'       => $highlight !== '' && (
                    stripos($u->username ?? '', $highlight) !== false
                    || stripos($u->first_name ?? '', $highlight) !== false
                    || stripos($u->last_name ?? '', $highlight) !== false
                    || stripos($u->email ?? '', $highlight) !== false
                ),
            ];
        }

        usort($rows, function ($a, $b) {
            // Highlighted first, then anyone with blockers, then by name.
            if ($a->highlight !== $b->highlight) return $a->highlight ? -1 : 1;
            $aBlocked = !empty($a->blockers);
            $bBlocked = !empty($b->blockers);
            if ($aBlocked !== $bBlocked) return $aBlocked ? -1 : 1;
            return strcasecmp($a->name, $b->name);
        });

        // Roles defined for this business — useful when fixing someone's role.
        $roles = DB::table('roles')
            ->where('business_id', $business_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.staff_pos_access', [
            'rows'      => $rows,
            'roles'     => $roles,
            'highlight' => $highlight,
        ]);
    }
}
