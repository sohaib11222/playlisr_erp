<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Log;

/**
 * Stores the TikTok Login Kit OAuth credentials (client key/secret, access
 * token, refresh token) used by ReportController to pull real, live
 * follower/likes/video counts for the Archer performance report — same
 * pattern as InstagramWebhookController: a gitignored JSON file written
 * through an admin-only settings screen, since there's no SSH access to
 * hand-edit the server .env.
 *
 * TikTok access tokens are short-lived (24h); validAccessToken() refreshes
 * automatically using the stored refresh_token (valid ~1 year) and writes
 * the new pair back to the file, so nothing needs to be re-pasted until the
 * refresh token itself expires.
 */
class TiktokAuthController extends Controller
{
    private function settingsFile(): string
    {
        return storage_path('app/tiktok-oauth.json');
    }

    private function settings_data(): array
    {
        try {
            $file = $this->settingsFile();
            if (is_file($file)) {
                return json_decode((string) file_get_contents($file), true) ?: [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    private function saveData(array $data): void
    {
        $file = $this->settingsFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function requireAdmin(): void
    {
        $u = auth()->user();
        $is_admin = false;
        try {
            $is_admin = $u && ($u->can('superadmin') || $u->hasAnyPermission('Admin#' . $u->business_id));
        } catch (\Throwable $e) {
        }
        if (!$is_admin) {
            abort(403, 'Unauthorized action.');
        }
    }

    /** Settings screen: paste the Client Key / Client Secret / Access Token / Refresh Token (admin only). */
    public function settings()
    {
        $this->requireAdmin();
        $data = $this->settings_data();

        return view('communications.tiktok_settings', [
            'client_key' => $data['client_key'] ?? '',
            'client_secret_masked' => !empty($data['client_secret']) ? '…' . substr($data['client_secret'], -6) : '',
            'access_token_masked' => !empty($data['access_token']) ? '…' . substr($data['access_token'], -8) : '',
            'refresh_token_masked' => !empty($data['refresh_token']) ? '…' . substr($data['refresh_token'], -8) : '',
            'expires_at' => !empty($data['expires_at']) ? \Carbon::createFromTimestamp($data['expires_at'])->toDateTimeString() : '',
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->requireAdmin();
        $data = $this->settings_data();

        if ($request->filled('client_key')) {
            $data['client_key'] = trim($request->client_key);
        }
        if ($request->filled('client_secret')) {
            $data['client_secret'] = trim($request->client_secret);
        }
        if ($request->filled('access_token')) {
            $data['access_token'] = trim($request->access_token);
        }
        if ($request->filled('refresh_token')) {
            $data['refresh_token'] = trim($request->refresh_token);
        }
        if ($request->filled('expires_at')) {
            $data['expires_at'] = (int) $request->expires_at;
        }

        $this->saveData($data);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Saved.']);
    }

    /**
     * Returns a currently-valid access token, transparently refreshing (and
     * persisting the new pair) if the stored one has expired or is about
     * to. Returns null if nothing is configured or the refresh fails —
     * callers should fall back to non-live behavior, never throw.
     */
    public static function validAccessToken(): ?string
    {
        $self = new self();
        $data = $self->settings_data();
        if (empty($data['access_token']) || empty($data['client_key']) || empty($data['client_secret'])) {
            return null;
        }

        if (!empty($data['expires_at']) && (int) $data['expires_at'] > time() + 300) {
            return $data['access_token'];
        }

        if (empty($data['refresh_token'])) {
            return null;
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://open.tiktokapis.com/v2/oauth/token/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'client_key' => $data['client_key'],
                'client_secret' => $data['client_secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $data['refresh_token'],
            ]));
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !is_string($body)) {
                return null;
            }
            $decoded = json_decode($body, true);
            if (empty($decoded['access_token'])) {
                Log::warning('TiktokAuthController refresh failed: ' . $body);
                return null;
            }

            $data['access_token'] = $decoded['access_token'];
            $data['refresh_token'] = $decoded['refresh_token'] ?? $data['refresh_token'];
            $data['expires_at'] = time() + (int) ($decoded['expires_in'] ?? 86400);
            $self->saveData($data);

            return $data['access_token'];
        } catch (\Throwable $e) {
            Log::warning('TiktokAuthController refresh exception: ' . $e->getMessage());
            return null;
        }
    }
}
