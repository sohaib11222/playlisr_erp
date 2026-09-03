<?php

namespace App\Http\Controllers;

use App\Business;
use App\Communication;
use Illuminate\Http\Request;
use Log;

/**
 * Receives Quo (my.quo.com, formerly OpenPhone) webhook deliveries and logs
 * message.received / call.missed events straight into the Communications
 * Hub as pending inquiries. No Quo API key needed — the store's own numbers
 * (Communication::QUO_NUMBERS) are matched directly off the payload.
 *
 * The signing secret can't live in the server .env (Sarah doesn't have SSH
 * access to hand-edit it there), so — same pattern as the shift-notes Slack
 * webhook — it's pasted through an admin-only settings screen and stored in
 * a gitignored JSON file on disk instead.
 */
class QuoWebhookController extends Controller
{
    const MAX_AGE_SECONDS = 300;

    private function settingsFile(): string
    {
        return storage_path('app/quo-webhook-key.json');
    }

    /** Resolved Quo webhook signing key: .env wins, else the admin-set file. */
    private function webhookKey(): string
    {
        $env = trim((string) config('nivessa.quo_webhook_key', ''));
        if ($env !== '') {
            return $env;
        }
        try {
            $file = $this->settingsFile();
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true) ?: [];
                return trim((string) ($data['webhook_key'] ?? ''));
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /** Admin-only guard, same permission check as the shift-notes settings screen. */
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

    /** Settings screen: paste the Quo webhook signing key (admin only). */
    public function settings()
    {
        $this->requireAdmin();
        $key = $this->webhookKey();
        $masked = $key !== '' ? '…' . substr($key, -8) : '';
        $env_locked = trim((string) config('nivessa.quo_webhook_key', '')) !== '';
        $webhook_url = url('/webhooks/quo');

        return view('communications.quo_settings', compact('masked', 'env_locked', 'webhook_url'));
    }

    /** Save the webhook signing key to the gitignored settings file (admin only). */
    public function saveSettings(Request $request)
    {
        $this->requireAdmin();
        $key = trim((string) $request->input('webhook_key'));

        // Quo's dashboard shows the signing secret as a bare base64 string
        // (no "whsec_" prefix) — verify() below already handles either form,
        // so we just need something non-trivially short to reject typos.
        if ($key !== '' && strlen($key) < 16) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => 'That looks too short to be a Quo webhook signing secret.',
            ]);
        }

        $file = $this->settingsFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode(['webhook_key' => $key], JSON_PRETTY_PRINT));

        return redirect()->back()->with('status', [
            'success' => 1,
            'msg' => $key === '' ? 'Webhook key cleared.' : 'Quo webhook key saved.',
        ]);
    }

    /**
     * Verify a Quo webhook delivery: HMAC-SHA256 over
     * "{webhook-id}.{webhook-timestamp}.{raw-body}", keyed by the base64
     * bytes behind the whsec_ prefix. Rejects deliveries older than 5
     * minutes to guard against replay.
     */
    private function verify(array $headers, string $raw): bool
    {
        $key = $this->webhookKey();
        if ($key === '') {
            Log::warning('Quo webhook received but no webhook key is configured.');
            return false;
        }

        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $signatureHeader = $headers['webhook-signature'] ?? '';
        if ($id === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }

        if (!ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        $secretB64 = strpos($key, 'whsec_') === 0 ? substr($key, 6) : $key;
        $secretBytes = base64_decode($secretB64, true);
        if ($secretBytes === false) {
            return false;
        }

        $signedContent = $id . '.' . $timestamp . '.' . $raw;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

        foreach (explode(' ', trim($signatureHeader)) as $entry) {
            $parts = explode(',', trim($entry), 2);
            if (count($parts) === 2 && $parts[0] === 'v1' && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * TEMPORARY diagnostic: Sarah has no SSH access to tail storage/logs on
     * this box, so on a verification failure we write what we can safely
     * show (never the actual key) into the Hub itself as a one-off pending
     * row, readable from the browser. Remove once Quo delivery is confirmed
     * working end to end.
     */
    private function logVerifyDebug(array $headers, string $raw): void
    {
        try {
            $business_id = optional(Business::first())->id;
            if (!$business_id) {
                return;
            }
            $system_user_id = optional(
                \DB::table('users')->where('business_id', $business_id)->orderBy('id')->first()
            )->id;
            if (!$system_user_id) {
                return;
            }

            $key = $this->webhookKey();
            $id = $headers['webhook-id'] ?? '(missing)';
            $timestamp = $headers['webhook-timestamp'] ?? '(missing)';
            $signatureHeader = $headers['webhook-signature'] ?? '(missing)';

            $expected = '(key not set)';
            if ($key !== '' && $id !== '(missing)' && $timestamp !== '(missing)') {
                $secretB64 = strpos($key, 'whsec_') === 0 ? substr($key, 6) : $key;
                $secretBytes = base64_decode($secretB64, true);
                if ($secretBytes !== false) {
                    $signedContent = $id . '.' . $timestamp . '.' . $raw;
                    $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));
                }
            }

            $lines = [
                'QUO WEBHOOK DEBUG (temporary)',
                'key length: ' . strlen($key),
                'header keys seen: ' . implode(', ', array_keys($headers)),
                'webhook-id: ' . $id,
                'webhook-timestamp: ' . $timestamp . ' (server now: ' . time() . ')',
                'webhook-signature: ' . $signatureHeader,
                'expected v1 sig: ' . $expected,
                'raw body: ' . substr($raw, 0, 500),
            ];

            $c = new Communication();
            $c->business_id = $business_id;
            $c->channel = 'other';
            $c->topic = 'general';
            $c->status = 'pending';
            $c->contact_info = 'quo-webhook-debug';
            $c->message = implode("\n", $lines);
            $c->created_by = $system_user_id;
            $c->save();
        } catch (\Throwable $e) {
            Log::emergency('Quo webhook debug logging failed: ' . $e->getMessage());
        }
    }

    /** Map a Quo E.164-ish number string to our channel code, or null if unknown. */
    private function channelForNumber(?string $number): ?string
    {
        if (empty($number)) {
            return null;
        }
        $normalized = '+' . preg_replace('/\D/', '', $number);
        return Communication::QUO_NUMBERS[$normalized] ?? null;
    }

    /**
     * Quo webhook endpoint. Public/unauthenticated — protected by the HMAC
     * signature instead of session auth (Quo has no ERP login).
     */
    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = is_array($values) ? ($values[0] ?? '') : $values;
        }

        if (!$this->verify($headers, $raw)) {
            Log::warning('Quo webhook signature mismatch or missing key.');
            $this->logVerifyDebug($headers, $raw);
            return response('forbidden', 403);
        }

        try {
            $body = json_decode($raw, true) ?: [];
            $type = $body['type'] ?? '';
            $data = $body['data'] ?? [];
            $resource = $data['resource'] ?? [];
            $context = $data['context'] ?? [];

            $business_id = optional(Business::first())->id;
            if (!$business_id) {
                Log::warning('Quo webhook: no business found to attach the inquiry to.');
                return response()->json(['success' => true]);
            }

            $system_user_id = optional(
                \DB::table('users')->where('business_id', $business_id)->orderBy('id')->first()
            )->id;
            if (!$system_user_id) {
                Log::warning('Quo webhook: no user found on the business to attribute the inquiry to.');
                return response()->json(['success' => true]);
            }

            if ($type === 'message.received') {
                $recipient = $context['recipientIdentifiers'][0] ?? null;
                $channel = $this->channelForNumber($recipient) ?? 'other';

                $c = new Communication();
                $c->business_id = $business_id;
                $c->channel = $channel;
                $c->topic = 'general';
                $c->status = 'pending';
                $c->contact_info = $context['senderIdentifier'] ?? null;
                $c->message = (string) ($resource['text'] ?? '');
                $c->created_by = $system_user_id;
                $c->save();
            } elseif ($type === 'call.completed') {
                // Quo has no subscribable "missed call" event on this plan —
                // call.completed with a non-answered status is the real
                // signal (per docs.quo.com/webhooks-event-payloads). Only
                // 'answered' and 'ai-handled' mean someone actually dealt
                // with it; everything else (unanswered, abandoned, failed,
                // forwarded, unknown) needs a callback.
                $status = (string) ($resource['status'] ?? '');
                if (!in_array($status, ['answered', 'ai-handled'], true)) {
                    $workspaceNumber = $context['participants']['workspace'][0] ?? null;
                    $callerNumber = $context['participants']['external'][0] ?? null;
                    $channel = $this->channelForNumber($workspaceNumber) ?? 'other';

                    $message = 'Missed call (' . ($status !== '' ? $status : 'unknown') . ').';
                    if (!empty($resource['hasVoicemail'])) {
                        $message .= ' Voicemail left — check Quo for the recording.';
                    }

                    $c = new Communication();
                    $c->business_id = $business_id;
                    $c->channel = $channel;
                    $c->topic = 'general';
                    $c->status = 'pending';
                    $c->contact_info = $callerNumber;
                    $c->message = $message;
                    $c->created_by = $system_user_id;
                    $c->save();
                }
            }
            // Other event types (delivered, ringing, tasks, contacts, etc.)
            // are acknowledged but not logged — nothing for staff to act on.
        } catch (\Throwable $e) {
            Log::emergency('Quo webhook processing failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }
}
