<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Stores the Facebook Page access token used by ReportController to pull
 * real, live follower counts for the Archer performance report — a
 * separate store from InstagramWebhookController's token because that one
 * is an Instagram-Login (IGAA-prefixed) token, which graph.facebook.com
 * rejects; this is a traditional Facebook Page token from "Facebook Login
 * for Business", obtained via the OAuth flow against the Nivessa Page.
 *
 * Same pattern as TiktokAuthController: a gitignored JSON file written
 * through an admin-only settings screen, since there's no SSH access to
 * hand-edit the server .env. No refresh flow needed — a Page token minted
 * from a long-lived user token doesn't expire in normal use; if it ever
 * does, just redo the OAuth flow and repaste here.
 */
class FacebookAuthController extends Controller
{
    private function settingsFile(): string
    {
        return storage_path('app/facebook-oauth.json');
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

    /** Settings screen: paste the Page ID / Page Access Token (admin only). */
    public function settings()
    {
        $this->requireAdmin();
        $data = $this->settings_data();

        return view('communications.facebook_settings', [
            'page_id' => $data['page_id'] ?? '',
            'page_access_token_masked' => !empty($data['page_access_token']) ? '…' . substr($data['page_access_token'], -8) : '',
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->requireAdmin();
        $data = $this->settings_data();

        if ($request->filled('page_id')) {
            $data['page_id'] = trim($request->page_id);
        }
        if ($request->filled('page_access_token')) {
            $data['page_access_token'] = trim($request->page_access_token);
        }

        $this->saveData($data);

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Saved.']);
    }

    /** Returns the stored Page access token, or '' if nothing is configured. */
    public static function storedPageAccessToken(): string
    {
        $self = new self();
        $data = $self->settings_data();
        return (string) ($data['page_access_token'] ?? '');
    }

    /** Returns the stored Page ID, or '' if nothing is configured. */
    public static function storedPageId(): string
    {
        $self = new self();
        $data = $self->settings_data();
        return (string) ($data['page_id'] ?? '');
    }
}
