<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\LoginActivity;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * All Utils instance.
     *
     */
    protected $businessUtil;
    protected $moduleUtil;
    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    // protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(BusinessUtil $businessUtil, ModuleUtil $moduleUtil)
    {
        $this->middleware('guest')->except('logout');
        $this->businessUtil = $businessUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Change authentication from email to username
     *
     * @return void
     */
    public function username()
    {
        return 'username';
    }

    public function logout()
    {
        $this->businessUtil->activityLog(auth()->user(), 'logout');

        request()->session()->flush();
        \Auth::logout();
        return redirect('/login');
    }

    /**
     * The user has been authenticated.
     * Check if the business is active or not.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return mixed
     */
    protected function authenticated(Request $request, $user)
    {
        $this->businessUtil->activityLog($user, 'login', null, [], false, $user->business_id);

        // Record the login (with client IP + device) for the Outside-Store
        // Logins report. Never store the password — only the username typed.
        $this->recordLoginActivity($request, $user, true);

        // Fresh POS duty pick after each login (Sarah 2026-05 — cashier vs
        // shipping vs inventory, separate from auth roles).
        $request->session()->forget(['pos_duty', 'pos_duty_location_id', 'pos_duty_location_label']);

        if (!$user->business->is_active) {
            \Auth::logout();
            return redirect('/login')
              ->with(
                  'status',
                  ['success' => 0, 'msg' => __('lang_v1.business_inactive')]
              );
        } elseif ($user->status != 'active') {
            \Auth::logout();
            return redirect('/login')
              ->with(
                  'status',
                  ['success' => 0, 'msg' => __('lang_v1.user_inactive')]
              );
        } elseif (!$user->allow_login) {
            \Auth::logout();
            return redirect('/login')
                ->with(
                    'status',
                    ['success' => 0, 'msg' => __('lang_v1.login_not_allowed')]
                );
        } elseif (($user->user_type == 'user_customer') && !$this->moduleUtil->hasThePermissionInSubscription($user->business_id, 'crm_module')) {
            \Auth::logout();
            return redirect('/login')
                ->with(
                    'status',
                    ['success' => 0, 'msg' => __('lang_v1.business_dont_have_crm_subscription')]
                );
        }
    }

    protected function redirectTo()
    {
        $user = \Auth::user();
        if (!$user->can('dashboard.data') && $user->can('sell.create')) {
            return '/pos/create';
        }

        if ($user->user_type == 'user_customer') {
            return 'contact/contact-dashboard';
        }

        return '/home';
    }

    /**
     * Record failed login attempts too — a stranger guessing a cashier's login
     * from outside a store is exactly what the report is meant to surface.
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        $this->recordLoginActivity($request, null, false);

        // NOTE: sendFailedLoginResponse comes from the AuthenticatesUsers trait
        // (flattened into this class), NOT a parent class — so `parent::` can't
        // reach it and throws BadMethodCallException. Inline the trait's behavior.
        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    /**
     * Persist one row per login attempt for the Outside-Store Logins report.
     * On a failed attempt $user is null, so resolve business/admin context from
     * the username that was typed (if it matches a real user).
     */
    protected function recordLoginActivity(Request $request, $user, $successful)
    {
        // Logging must never block authentication. Swallow any error (e.g. the
        // table not yet migrated on a fresh deploy) so logins keep working.
        try {
            $username = $request->input($this->username());

            if (empty($user) && !empty($username)) {
                $user = User::where('username', $username)->first();
            }

            LoginActivity::create([
                'business_id' => $user->business_id ?? null,
                'user_id'     => $user->id ?? null,
                'username'    => $username,
                'is_admin'    => $user ? $this->businessUtil->is_admin($user, $user->business_id) : false,
                'ip_address'  => $this->clientIp($request),
                'user_agent'  => substr((string) $request->userAgent(), 0, 1000),
                'successful'  => $successful,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to record login activity: ' . $e->getMessage());
        }
    }

    /**
     * Best-effort real client IP. The app trusts no proxies by default, so when
     * it sits behind Cloudflare/hPanel request()->ip() would be the proxy. For a
     * report (not an auth gate) reading the forwarded hop is appropriate.
     */
    protected function clientIp(Request $request)
    {
        $cf = $request->headers->get('CF-Connecting-IP');
        if (!empty($cf)) {
            return $cf;
        }

        $xff = $request->headers->get('X-Forwarded-For');
        if (!empty($xff)) {
            return trim(explode(',', $xff)[0]);
        }

        return $request->ip();
    }
}
