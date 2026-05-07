<?php

namespace App\Http\Controllers;

use App\System;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Sling (getsling.com) connection — single source of truth for the auth
 * token + org id used by the Employee Productivity report's "Hours Worked"
 * column. Sling free has no programmatic API key; instead we use the same
 * `/v1/account/login` exchange Sling support recommends — Sarah types her
 * Sling login once, the ERP swaps it for an Authorization token and
 * persists it in the system table. When the token expires (Sling rotates
 * them), Sarah re-visits this page and logs in again.
 *
 * Password is NOT stored — only the resulting token + org id.
 */
class SlingController extends Controller
{
    private const TOKEN_KEY = 'sling_auth_token';
    private const ORG_KEY = 'sling_org_id';
    private const USER_KEY = 'sling_user_id';
    private const EMAIL_KEY = 'sling_account_email';
    private const SAVED_AT_KEY = 'sling_token_saved_at';

    public function loginForm()
    {
        $token = (string) (System::getProperty(self::TOKEN_KEY) ?? '');
        $orgId = (string) (System::getProperty(self::ORG_KEY) ?? '');
        $email = (string) (System::getProperty(self::EMAIL_KEY) ?? '');
        $savedAt = (string) (System::getProperty(self::SAVED_AT_KEY) ?? '');
        $connected = $token !== '' && $orgId !== '';
        return view('admin.sling_login', compact('connected', 'orgId', 'email', 'savedAt'));
    }

    public function login(Request $request)
    {
        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return back()->with('status_error', 'Email and password are both required.');
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post('https://api.getsling.com/v1/account/login', [
                    'email' => $email,
                    'password' => $password,
                ]);
        } catch (\Throwable $e) {
            \Log::warning('Sling login failed: ' . $e->getMessage());
            return back()->with('status_error', 'Could not reach Sling: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            $msg = 'Sling rejected the login (HTTP ' . $response->status() . ').';
            $body = $response->json();
            if (is_array($body) && !empty($body['message'])) {
                $msg .= ' ' . $body['message'];
            }
            return back()->with('status_error', $msg);
        }

        $token = $response->header('Authorization');
        if (!$token) {
            return back()->with('status_error', 'Sling did not return an Authorization token. Check the email/password.');
        }

        $body = $response->json() ?: [];
        $userId = $body['user']['id'] ?? null;
        $orgId = null;
        if (!empty($body['user']['orgs']) && is_array($body['user']['orgs'])) {
            $orgId = $body['user']['orgs'][0]['id'] ?? null;
        }
        if (!$orgId && !empty($body['orgs']) && is_array($body['orgs'])) {
            $orgId = $body['orgs'][0]['id'] ?? null;
        }
        if (!$orgId && !empty($body['user']['org']['id'])) {
            $orgId = $body['user']['org']['id'];
        }

        System::addProperty(self::TOKEN_KEY, $token);
        if ($orgId) {
            System::addProperty(self::ORG_KEY, (string) $orgId);
        }
        if ($userId) {
            System::addProperty(self::USER_KEY, (string) $userId);
        }
        System::addProperty(self::EMAIL_KEY, $email);
        System::addProperty(self::SAVED_AT_KEY, now()->toDateTimeString());

        $msg = 'Connected to Sling.';
        if (!$orgId) {
            $msg .= ' (Could not auto-detect org id — Hours Worked may need a manual org id; ping Jon.)';
        }
        return back()->with('status_success', $msg);
    }

    public function disconnect()
    {
        System::removeProperty(self::TOKEN_KEY);
        System::removeProperty(self::ORG_KEY);
        System::removeProperty(self::USER_KEY);
        System::removeProperty(self::EMAIL_KEY);
        System::removeProperty(self::SAVED_AT_KEY);
        return back()->with('status_success', 'Disconnected.');
    }
}
