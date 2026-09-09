<?php

namespace App\Http\Controllers;

use App\Business;
use App\Communication;
use Illuminate\Http\Request;
use Log;

/**
 * Receives Instagram DM webhook deliveries (Meta Graph API "messaging"
 * events) and logs them into the Communications Hub as pending inquiries
 * — same idea as QuoWebhookController for the phone lines.
 *
 * Meta requires this to be reachable over HTTPS with a real signature
 * check, but does NOT require App Review to actually receive messages
 * for the business's own connected Instagram account: App Review is
 * only needed before an app can message accounts other than the ones
 * with a role on that same Meta app (its Admins/Developers/Testers).
 * Since this is Nivessa's own app talking to Nivessa's own Instagram
 * account, "Development Mode" access is sufficient — no review, no
 * waiting on Meta. (Full Review only matters if this were ever meant to
 * run for OTHER businesses' Instagram accounts too.)
 *
 * Credentials (App Secret, webhook Verify Token, Page Access Token) are
 * pasted through an admin-only settings screen and stored in a
 * gitignored JSON file — same pattern as the Quo webhook key, since
 * there's no SSH access to hand-edit the server .env.
 */
class InstagramWebhookController extends Controller
{
    private function settingsFile(): string
    {
        return storage_path('app/instagram-webhook.json');
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

    private function appSecret(): string
    {
        return trim((string) ($this->settings_data()['app_secret'] ?? ''));
    }

    private function verifyToken(): string
    {
        return trim((string) ($this->settings_data()['verify_token'] ?? ''));
    }

    private function pageAccessToken(): string
    {
        return trim((string) ($this->settings_data()['page_access_token'] ?? ''));
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

    /** Settings screen: paste the App Secret / Verify Token / Page Access Token (admin only). */
    public function settings()
    {
        $this->requireAdmin();
        $data = $this->settings_data();
        $webhook_url = url('/webhooks/instagram');

        return view('communications.instagram_settings', [
            'webhook_url' => $webhook_url,
            'app_secret_masked' => !empty($data['app_secret']) ? '…' . substr($data['app_secret'], -6) : '',
            'verify_token' => $data['verify_token'] ?? '',
            'page_token_masked' => !empty($data['page_access_token']) ? '…' . substr($data['page_access_token'], -8) : '',
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->requireAdmin();
        $data = $this->settings_data();

        if ($request->filled('app_secret')) {
            $data['app_secret'] = trim($request->app_secret);
        }
        if ($request->filled('verify_token')) {
            $data['verify_token'] = trim($request->verify_token);
        }
        if ($request->filled('page_access_token')) {
            $data['page_access_token'] = trim($request->page_access_token);
        }

        $file = $this->settingsFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Saved.']);
    }

    /** Meta's webhook verification handshake (GET, done once when you register the callback URL). */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token !== '' && hash_equals($this->verifyToken(), (string) $token)) {
            return response((string) $challenge, 200);
        }

        return response('forbidden', 403);
    }

    /** Meta's actual message delivery (POST). */
    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $signatureHeader = $request->header('X-Hub-Signature-256', '');

        if (!$this->verifySignature($raw, $signatureHeader)) {
            Log::warning('Instagram webhook signature mismatch.');
            return response('forbidden', 403);
        }

        try {
            $body = json_decode($raw, true) ?: [];
            $business_id = optional(Business::first())->id;
            $system_user_id = $business_id
                ? optional(\DB::table('users')->where('business_id', $business_id)->orderBy('id')->first())->id
                : null;
            if (!$business_id || !$system_user_id) {
                return response()->json(['success' => true]);
            }

            foreach (($body['entry'] ?? []) as $entry) {
                foreach (($entry['messaging'] ?? []) as $event) {
                    $senderId = $event['sender']['id'] ?? null;
                    $text = $event['message']['text'] ?? null;
                    $messageId = $event['message']['mid'] ?? null;
                    $isEcho = !empty($event['message']['is_echo']);

                    if (!$senderId || $text === null || $text === '') {
                        continue; // attachments-only, read receipts, etc. — nothing to log yet
                    }
                    if ($isEcho) {
                        continue; // our own outbound message echoed back — not a new inquiry
                    }

                    $externalId = $messageId ? 'ig-msg-' . $messageId : null;
                    if ($externalId && Communication::where('business_id', $business_id)->where('external_id', $externalId)->exists()) {
                        continue;
                    }

                    $c = new Communication();
                    $c->business_id = $business_id;
                    $c->channel = 'instagram';
                    $c->topic = Communication::guessTopic($text);
                    $c->contact_info = 'IG user ' . $senderId;
                    $c->message = $text;
                    $c->is_priority = $c->topic === 'unhappy_customer' ? 1 : 0;
                    $c->status = 'pending';
                    $c->external_id = $externalId;
                    $c->created_by = $system_user_id;
                    $c->save();
                }
            }
        } catch (\Throwable $e) {
            Log::emergency('Instagram webhook processing failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    private function verifySignature(string $raw, string $header): bool
    {
        $secret = $this->appSecret();
        if ($secret === '' || $header === '') {
            return false;
        }
        if (strpos($header, 'sha256=') !== 0) {
            return false;
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, substr($header, 7));
    }
}
