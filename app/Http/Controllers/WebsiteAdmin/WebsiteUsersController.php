<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * nivessa.com accounts, moved from /admin/users (customer accounts) and
 * /admin/user-management (website admin logins, admin only)
 * (Sarah, 2026-10-15).
 */
class WebsiteUsersController extends Controller
{
    use WaContentHelpers;

    const LIMIT = 9;
    const SEARCH_FETCH = 1000;

    const ROLES = [
        'editor' => 'Editor',
        'viewer' => 'Viewer',
        'velocity-viewer' => 'External, Sealed Velocity only',
        'party-prep' => 'Staff, Listening Party Prep only',
    ];

    public function index(Request $request)
    {
        $this->guardStaff();
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        // The website has no server-side customer search, so like its admin
        // page this pulls a large first page and filters by name/email.
        $resp = $search !== ''
            ? WebsiteBridge::admin('GET', '/customer/pageLimit?page=1&limit=' . self::SEARCH_FETCH, null, 45)
            : WebsiteBridge::admin('GET', '/customer/pageLimit?page=' . $page . '&limit=' . self::LIMIT);
        $users = $this->listFrom($resp);
        if ($search !== '') {
            $q = mb_strtolower($search);
            $users = array_values(array_filter($users, function ($u) use ($q) {
                return mb_strpos(mb_strtolower((string) ($u['name'] ?? '')), $q) !== false
                    || mb_strpos(mb_strtolower((string) ($u['email'] ?? '')), $q) !== false;
            }));
        }
        $total = (int) $this->pick($resp, 'totalCount', 0);
        return view('website_admin.content.users_index', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load users.') : null,
            'users' => $users,
            'search' => $search,
            'page' => $search !== '' ? 1 : $page,
            'total' => $total,
            'totalPages' => $search !== '' ? 1 : max(1, (int) ceil($total / self::LIMIT)),
            'searchCap' => self::SEARCH_FETCH,
        ]);
    }

    public function show($id)
    {
        $this->guardStaff();
        $u = WebsiteBridge::admin('GET', '/customer/user/' . rawurlencode($id));
        $user = $this->pick($u, 'user');
        if (empty($u['success']) || !$user) {
            return redirect()->route('wa.content.users')->with('error', $u['message'] ?? 'User not found.');
        }
        // The orders route answers 404 when there are none.
        $o = WebsiteBridge::admin('GET', '/order/user/' . rawurlencode($id), null, 30);
        return view('website_admin.content.users_show', [
            'user' => $user,
            'orders' => !empty($o['success']) ? $this->listFrom($o) : [],
            'ordersError' => (empty($o['success']) && ($o['_http'] ?? 0) !== 404) ? ($o['message'] ?? 'Could not load orders.') : null,
        ]);
    }

    public function admins()
    {
        $this->guardAdmin();
        $resp = WebsiteBridge::admin('GET', '/user/all');
        $rows = array_map(function ($u) {
            unset($u['password']);
            return $u;
        }, $this->listFrom($resp));
        return view('website_admin.content.admins_index', [
            'bridgeError' => (empty($resp['success']) && ($resp['_http'] ?? 0) !== 404) ? ($resp['message'] ?? 'Could not load admin accounts.') : null,
            'admins' => $rows,
        ]);
    }

    public function adminCreate()
    {
        $this->guardAdmin();
        return view('website_admin.content.admins_form', ['admin' => null, 'roles' => self::ROLES]);
    }

    public function adminStore(Request $request)
    {
        $this->guardAdmin();
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', '');
        if ($name === '' || $email === '' || trim($password) === '' || $role === '') {
            return back()->withInput($request->except('password'))->with('error', 'Please fill in all required fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withInput($request->except('password'))->with('error', 'Please enter a valid email address.');
        }
        if (!array_key_exists($role, self::ROLES)) {
            return back()->withInput($request->except('password'))->with('error', 'Pick a role.');
        }
        // /user/register is blocked on the admin proxy, so this uses the ERP
        // bridge route that runs the same register handler.
        $resp = WebsiteBridge::erp('POST', '/admin-users', compact('name', 'email', 'password', 'role'));
        if (empty($resp['success'])) {
            return back()->withInput($request->except('password'))->with('error', $resp['message'] ?? 'Failed to create the account.');
        }
        return redirect()->route('wa.content.admins')->with('status', $resp['message'] ?? 'Account created.');
    }

    public function adminEdit($id)
    {
        $this->guardAdmin();
        $resp = WebsiteBridge::admin('GET', '/user/admin/' . rawurlencode($id));
        $admin = $this->pick($resp, 'user');
        if (empty($resp['success']) || !$admin) {
            return redirect()->route('wa.content.admins')->with('error', $resp['message'] ?? 'Account not found.');
        }
        unset($admin['password']);
        return view('website_admin.content.admins_form', ['admin' => $admin, 'roles' => self::ROLES]);
    }

    public function adminUpdate(Request $request, $id)
    {
        $this->guardAdmin();
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        if ($name === '' || $email === '') {
            return back()->withInput($request->except('password'))->with('error', 'Name and email are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withInput($request->except('password'))->with('error', 'Please enter a valid email address.');
        }
        $body = ['name' => $name, 'email' => $email, 'role' => (string) $request->input('role', '')];
        $password = (string) $request->input('password', '');
        if (trim($password) !== '') {
            $body['password'] = $password;
        }
        $resp = WebsiteBridge::admin('PUT', '/user/update/' . rawurlencode($id), $body);
        if (empty($resp['success'])) {
            return back()->withInput($request->except('password'))->with('error', $resp['message'] ?? 'Failed to update the user.');
        }
        return redirect()->route('wa.content.admins')->with('status', $resp['message'] ?? 'User updated.');
    }

    public function adminDestroy($id)
    {
        $this->guardAdmin();
        $resp = WebsiteBridge::admin('DELETE', '/user/delete/' . rawurlencode($id));
        return $this->bridgeBack($resp, 'Admin account deleted.', 'Failed to delete the admin account.');
    }
}
