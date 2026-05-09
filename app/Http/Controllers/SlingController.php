<?php

namespace App\Http\Controllers;

use App\SlingShift;
use App\System;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

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

        $headers = [];
        $body = null;
        $httpCode = 0;
        $curlError = '';
        try {
            // Use the no-/v1/ path: /v1/account/login is captcha-gated
            // for human/UI logins, but /account/login (matching Sling's
            // own bash example) accepts plain email + password POSTs.
            $ch = curl_init('https://api.getsling.com/account/login');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'email' => $email,
                'password' => $password,
            ]));
            // Headers EXACTLY as Sling's published bash example uses them.
            // A custom User-Agent / Accept appears to flip them into the
            // captcha-required code path; keeping it minimal mirrors the
            // working script.
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'accept: */*',
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError = (string) curl_error($ch);
            curl_close($ch);

            if ($curlError !== '' || !is_string($raw)) {
                throw new \RuntimeException($curlError !== '' ? $curlError : 'Empty response');
            }

            $rawHeaders = substr($raw, 0, $headerSize);
            $bodyStr = substr($raw, $headerSize);
            foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) as $line) {
                if (strpos($line, ':') === false) continue;
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            $body = $bodyStr !== '' ? json_decode($bodyStr, true) : null;
        } catch (\Throwable $e) {
            \Log::warning('Sling login failed: ' . $e->getMessage());
            return back()->with('status_error', 'Could not reach Sling: ' . $e->getMessage());
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = 'Sling rejected the login (HTTP ' . $httpCode . ').';
            if (is_array($body) && !empty($body['message'])) {
                $msg .= ' ' . $body['message'];
            }
            return back()->with('status_error', $msg);
        }

        $token = $headers['authorization'] ?? null;
        if (!$token) {
            return back()->with('status_error', 'Sling did not return an Authorization token. Check the email/password.');
        }

        $body = is_array($body) ? $body : [];
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

    /**
     * Manual paste — Sarah runs Sling's bash login script locally
     * (residential IP, no captcha) and pastes the resulting token here.
     */
    public function saveToken(Request $request)
    {
        $token = trim((string) $request->input('token'));
        $orgId = trim((string) $request->input('org_id'));

        if ($token === '') {
            return back()->with('status_error', 'Token is required.');
        }
        // Save the FULL Authorization header value as captured (e.g. "Bearer eyJ...").
        // Sling's API rejects a bare JWT — the scheme prefix is required.
        System::addProperty(self::TOKEN_KEY, $token);
        if ($orgId !== '') {
            System::addProperty(self::ORG_KEY, $orgId);
        }
        System::addProperty(self::SAVED_AT_KEY, now()->toDateTimeString());

        return back()->with('status_success', 'Token saved.');
    }

    /**
     * Accept a "Copy as cURL" paste from Chrome DevTools and pull the
     * Authorization header out of it. Bullet-proof: the cURL command
     * contains the EXACT header value Sling's app sends, scheme prefix
     * and all. No bookmarklet needed.
     */
    public function saveFromCurl(Request $request)
    {
        $curl = (string) $request->input('curl');
        if (trim($curl) === '') {
            return back()->with('status_error', 'Paste something first.');
        }
        // Find an Authorization header in any common copy-paste format
        // Chrome DevTools produces: cURL (-H 'authorization: ...'),
        // fetch ("authorization": "..."), PowerShell, raw HAR JSON, etc.
        $token = null;
        $patterns = [
            // -H 'authorization: VALUE'  /  -H "authorization: VALUE"  / -H $'authorization: VALUE'
            '/-H\s+\$?[\'"]?\s*authorization\s*:\s*([^\'"\r\n]+?)\s*[\'"]/i',
            // "authorization": "VALUE"  (fetch / HAR)
            '/[\'"]\s*authorization\s*[\'"]\s*:\s*[\'"]([^\'"\r\n]+)[\'"]/i',
            // bare "authorization: VALUE" line
            '/^\s*authorization\s*:\s*(.+?)\s*$/im',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $curl, $m)) {
                $token = trim($m[1]);
                break;
            }
        }
        if (!$token) {
            return back()->with('status_error', 'No Authorization header found. Make sure the request you copied was to api.getsling.com (not app.getsling.com). Any of these Chrome menu options work: Copy as cURL, Copy as fetch, Copy as PowerShell.');
        }
        System::addProperty(self::TOKEN_KEY, $token);
        // Also extract org id from the URL if present (e.g. /v1/901214/...).
        if (preg_match('#/v1/(\d+)/#', $curl, $om)) {
            System::addProperty(self::ORG_KEY, $om[1]);
        }
        System::addProperty(self::SAVED_AT_KEY, now()->toDateTimeString());
        return back()->with('status_success', 'Token extracted from cURL and saved.');
    }

    public function testConnection()
    {
        $client = new \App\Services\SlingClient();
        $result = $client->ping();
        if ($result['ok']) {
            return back()->with('status_success', 'Sling test: ' . $result['message']);
        }
        return back()->with('status_error', 'Sling test failed: ' . $result['message']);
    }

    /**
     * Synced shifts page — shows the table that the daily cron fills, with
     * a "Sync now" button that triggers an ad-hoc pull. Default window is
     * the next 14 days (what's coming up); the controls let Sarah scan
     * back to any prior month.
     */
    public function shiftsIndex(Request $request)
    {
        $connected = (new \App\Services\SlingClient())->isConfigured();

        // First-run state: the table doesn't exist yet because the migration
        // hasn't been dispatched via the GH workflow. Show a setup banner
        // with a button that creates the table directly via Schema (same
        // structure as the migration file). Avoids forcing a CI dance for
        // a one-table change.
        if (!Schema::hasTable('sling_shifts')) {
            return view('admin.sling_shifts', [
                'shifts' => collect(),
                'start' => Carbon::today('America/Los_Angeles')->subDays(7),
                'end' => Carbon::today('America/Los_Angeles')->addDays(30),
                'lastSyncedAt' => null,
                'totalCount' => 0,
                'connected' => $connected,
                'tableExists' => false,
            ]);
        }

        $tz = 'America/Los_Angeles';
        $start = $request->input('start')
            ? Carbon::parse($request->input('start'), $tz)->startOfDay()
            : Carbon::today($tz)->subDays(7);
        $end = $request->input('end')
            ? Carbon::parse($request->input('end'), $tz)->endOfDay()
            : Carbon::today($tz)->addDays(30);

        $shifts = SlingShift::query()
            ->whereBetween('dtstart', [$start, $end])
            ->orderBy('dtstart', 'asc')
            ->limit(1000)
            ->get();

        $lastSyncedAt = SlingShift::max('last_synced_at');
        $totalCount = SlingShift::count();

        return view('admin.sling_shifts', compact(
            'shifts', 'start', 'end', 'lastSyncedAt', 'totalCount', 'connected'
        ) + ['tableExists' => true]);
    }

    /**
     * One-click setup for the sling_shifts table — creates it inline using
     * the same structure as the migration file. Idempotent: if the table
     * already exists this is a no-op. Exists so Sarah doesn't have to
     * dispatch the GH "Run migrations" workflow for what is, structurally,
     * just one new empty table.
     */
    public function setupTable(Request $request)
    {
        if (Schema::hasTable('sling_shifts')) {
            return back()->with('status_success', 'Already set up — nothing to do.');
        }
        try {
            Schema::create('sling_shifts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('sling_shift_id', 64)->unique();
                $table->string('sling_user_id', 64)->nullable()->index();
                $table->string('user_email', 191)->nullable()->index();
                $table->string('user_name', 191)->nullable();
                $table->unsignedInteger('erp_user_id')->nullable()->index();
                $table->string('location_name', 191)->nullable();
                $table->string('position_name', 191)->nullable();
                $table->dateTime('dtstart')->index();
                $table->dateTime('dtend')->nullable();
                $table->decimal('hours', 8, 2)->default(0);
                $table->boolean('published')->default(true);
                $table->longText('raw_payload')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
                $table->index(['dtstart', 'dtend']);
                $table->index(['erp_user_id', 'dtstart']);
                $table->foreign('erp_user_id')->references('id')->on('users')->onDelete('set null');
            });
            return back()->with('status_success', 'sling_shifts table created. You can click Sync now.');
        } catch (\Throwable $e) {
            \Log::warning('Sling setupTable failed: ' . $e->getMessage());
            return back()->with('status_error', 'Setup failed: ' . $e->getMessage());
        }
    }

    /**
     * "Sync now" button. Runs the artisan command synchronously so Sarah
     * can refresh the page and immediately see whether the pull worked.
     */
    public function syncShifts(Request $request)
    {
        $client = new \App\Services\SlingClient();
        if (!$client->isConfigured()) {
            return back()->with('status_error', 'Connect Sling first (Status box above).');
        }
        try {
            Artisan::call('sling:sync-shifts');
            $output = trim((string) Artisan::output());
            $lastLine = '';
            foreach (preg_split("/\r\n|\n|\r/", $output) as $line) {
                $line = trim($line);
                if ($line !== '') $lastLine = $line;
            }
            $msg = 'Sync complete.' . ($lastLine !== '' ? ' ' . $lastLine : '');
            return back()->with('status_success', $msg);
        } catch (\Throwable $e) {
            \Log::warning('Sling Sync now failed: ' . $e->getMessage());
            return back()->with('status_error', 'Sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Probe a handful of candidate Sling endpoints for shifts and dump the
     * HTTP code + first chunk of body for each. Used when the sync returns
     * 0 records and we need to see which URL Sling actually accepts on
     * this org / plan tier. Read-only.
     */
    public function diagnoseShifts(Request $request)
    {
        $token = (string) (System::getProperty(self::TOKEN_KEY) ?? '');
        $orgId = (string) (System::getProperty(self::ORG_KEY) ?? '');
        if ($token === '' || $orgId === '') {
            return back()->with('status_error', 'Connect Sling first.');
        }

        $tz = 'America/Los_Angeles';
        $start = Carbon::today($tz)->subDays(7)->toDateString();
        $end = Carbon::today($tz)->addDays(30)->toDateString();

        // First fetch a real user id so the per-user calendar probe is
        // meaningful. Per Sling's docs, calendar is keyed per user.
        $client = new \App\Services\SlingClient();
        $users = $client->users();
        $firstUserId = null;
        foreach ($users as $u) {
            if (!empty($u['id'])) { $firstUserId = $u['id']; break; }
        }

        $apiRoot = 'https://api.getsling.com';
        $dates = $start . '/' . $end;
        $candidates = [
            'users (no /v1, control)' => "{$apiRoot}/users",
            'calendar/{org}/users/{userId}?dates= (DOCS FORMAT)' => $firstUserId
                ? "{$apiRoot}/calendar/{$orgId}/users/{$firstUserId}?dates=" . rawurlencode($dates)
                : "{$apiRoot}/calendar/{$orgId}/users/?dates=" . rawurlencode($dates),
            'reports/timesheets?dates=' => "{$apiRoot}/reports/timesheets?dates=" . rawurlencode($dates),
            'groups (no /v1)' => "{$apiRoot}/groups",
            'concise (no /v1)' => "{$apiRoot}/concise",
        ];

        $results = [];
        foreach ($candidates as $label => $url) {
            $det = $this->rawGet($url, $token);
            $bodySnippet = mb_substr((string) $det['body'], 0, 800);
            $count = null;
            if ($det['http_code'] >= 200 && $det['http_code'] < 300) {
                $decoded = json_decode($det['body'], true);
                if (is_array($decoded)) {
                    if (isset($decoded[0])) $count = count($decoded);
                    elseif (isset($decoded['shifts']) && is_array($decoded['shifts'])) $count = count($decoded['shifts']);
                    elseif (isset($decoded['data']) && is_array($decoded['data'])) $count = count($decoded['data']);
                    elseif (isset($decoded['events']) && is_array($decoded['events'])) $count = count($decoded['events']);
                    else $count = 0;
                }
            }
            $results[] = [
                'label' => $label,
                'url' => $url,
                'http_code' => $det['http_code'],
                'count' => $count,
                'body' => $bodySnippet,
            ];
        }

        return view('admin.sling_diagnose', [
            'results' => $results,
            'start' => $start,
            'end' => $end,
        ]);
    }

    private function rawGet(string $url, string $token): array
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $token,
                'Accept: application/json',
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['http_code' => $http, 'body' => is_string($body) ? $body : ''];
        } catch (\Throwable $e) {
            return ['http_code' => 0, 'body' => $e->getMessage()];
        }
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
