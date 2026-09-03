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
     * Verify a Quo webhook delivery. Two schemes exist and which one an
     * account actually gets is not up to us: docs.quo.com describes a
     * Svix-style webhook-id/webhook-timestamp/webhook-signature triad, but
     * this account's numbers are still served by the legacy OpenPhone v3
     * pipeline underneath the Quo rebrand, which signs with a single
     * "openphone-signature: hmac;1;<timestamp_ms>;<base64_sig>" header
     * instead (confirmed against real deliveries — the account never sends
     * the new headers at all). We accept either.
     */
    private function verify(array $headers, string $raw): bool
    {
        $key = $this->webhookKey();
        if ($key === '') {
            Log::warning('Quo webhook received but no webhook key is configured.');
            return false;
        }

        $secretB64 = strpos($key, 'whsec_') === 0 ? substr($key, 6) : $key;
        $secretBytes = base64_decode($secretB64, true);
        if ($secretBytes === false) {
            return false;
        }

        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $signatureHeader = $headers['webhook-signature'] ?? '';
        if ($id !== '' && $timestamp !== '' && $signatureHeader !== '') {
            if (ctype_digit((string) $timestamp) && abs(time() - (int) $timestamp) <= self::MAX_AGE_SECONDS) {
                $signedContent = $id . '.' . $timestamp . '.' . $raw;
                $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));
                foreach (explode(' ', trim($signatureHeader)) as $entry) {
                    $parts = explode(',', trim($entry), 2);
                    if (count($parts) === 2 && $parts[0] === 'v1' && hash_equals($expected, $parts[1])) {
                        return true;
                    }
                }
            }
            return false;
        }

        $legacy = $headers['openphone-signature'] ?? '';
        if ($legacy !== '') {
            $parts = explode(';', trim($legacy));
            if (count($parts) === 4 && $parts[0] === 'hmac' && $parts[1] === '1') {
                $timestampMs = $parts[2];
                $signature = $parts[3];
                if (ctype_digit($timestampMs) && abs(time() - intdiv((int) $timestampMs, 1000)) <= self::MAX_AGE_SECONDS) {
                    $signedContent = $timestampMs . '.' . $raw;
                    $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));
                    if (hash_equals($expected, $signature)) {
                        return true;
                    }
                }
            }
            return false;
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
                'webhook-id: ' . $id,
                'webhook-timestamp: ' . $timestamp . ' (server now: ' . time() . ')',
                'webhook-signature: ' . $signatureHeader,
                'openphone-signature: ' . ($headers['openphone-signature'] ?? '(missing)'),
                'content-type: ' . ($headers['content-type'] ?? '(missing)'),
                'expected v1 sig: ' . $expected,
                'body length: ' . strlen($raw),
                'body preview: ' . substr($raw, 0, 120),
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

    /** Insert a pending inquiry unless one with this external_id already exists (idempotent). */
    private function logCommunication(int $business_id, int $system_user_id, string $channel, ?string $contact, string $message, ?string $externalId): bool
    {
        if ($externalId && Communication::where('business_id', $business_id)->where('external_id', $externalId)->exists()) {
            return false;
        }

        $c = new Communication();
        $c->business_id = $business_id;
        $c->channel = $channel;
        $c->topic = 'general';
        $c->status = 'pending';
        $c->contact_info = $contact;
        $c->message = $message;
        $c->external_id = $externalId;
        $c->created_by = $system_user_id;
        $c->save();

        return true;
    }

    /**
     * Admin-only: pull recent messages/calls from Quo's REST API (the same
     * OPENPHONE_API_KEY already used to send pickup-ready texts) and log
     * anything inbound as a pending inquiry. Backfill for history the live
     * webhook (set up 2026-09-02) never saw — safe to run repeatedly since
     * every insert is deduped on external_id.
     */
    public function importRecent(Request $request)
    {
        $this->requireAdmin();

        $business_id = optional(Business::first())->id;
        $system_user_id = $business_id
            ? optional(\DB::table('users')->where('business_id', $business_id)->orderBy('id')->first())->id
            : null;
        if (!$business_id || !$system_user_id) {
            return response()->json(['success' => false, 'msg' => 'No business/user found to attribute imports to.']);
        }

        $svc = new \App\Services\OpenPhoneService();
        if (!$svc->isConfigured()) {
            return response()->json(['success' => false, 'msg' => 'OpenPhone API key is not configured on the server (OPENPHONE_API_KEY).']);
        }

        $numbersResp = $svc->listPhoneNumbers();
        if (!$numbersResp['success']) {
            return response()->json(['success' => false, 'msg' => 'Could not list Quo phone numbers: ' . $numbersResp['msg']]);
        }

        // Map our known E.164 numbers -> Quo's internal phoneNumberId.
        $idsByE164 = [];
        foreach ($numbersResp['data'] as $pn) {
            $num = $svc->normalize((string) ($pn['number'] ?? ''));
            if ($num && isset($pn['id'])) {
                $idsByE164[$num] = $pn['id'];
            }
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach (Communication::QUO_NUMBERS as $e164 => $channel) {
            $phoneNumberId = $idsByE164[$e164] ?? null;
            if (!$phoneNumberId) {
                $errors[] = "$e164: not found in this workspace's phone numbers.";
                continue;
            }

            $msgResp = $svc->listRecentMessages($phoneNumberId, 30);
            if (!$msgResp['success']) {
                $errors[] = "$e164 messages: " . $msgResp['msg'];
            } else {
                foreach ($msgResp['data'] as $m) {
                    if (($m['direction'] ?? '') !== 'incoming') {
                        continue;
                    }
                    $externalId = !empty($m['id']) ? 'quo-msg-' . $m['id'] : null;
                    $ok = $this->logCommunication(
                        $business_id, $system_user_id, $channel,
                        $m['from'] ?? null, (string) ($m['text'] ?? $m['body'] ?? ''), $externalId
                    );
                    $ok ? $imported++ : $skipped++;
                }
            }

            $callResp = $svc->listRecentCalls($phoneNumberId, 30);
            if (!$callResp['success']) {
                $errors[] = "$e164 calls: " . $callResp['msg'];
            } else {
                foreach ($callResp['data'] as $call) {
                    if (($call['direction'] ?? '') !== 'incoming') {
                        continue;
                    }
                    $status = (string) ($call['status'] ?? '');
                    if (in_array($status, ['answered', 'ai-handled'], true)) {
                        continue;
                    }
                    $externalId = !empty($call['id']) ? 'quo-call-' . $call['id'] : null;
                    $message = 'Missed call' . ($status !== '' ? ' (' . $status . ')' : '') . '.';
                    if (!empty($call['hasVoicemail'])) {
                        $message .= ' Voicemail left — check Quo for the recording.';
                    }
                    $ok = $this->logCommunication(
                        $business_id, $system_user_id, $channel,
                        $call['from'] ?? null, $message, $externalId
                    );
                    $ok ? $imported++ : $skipped++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
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
            // New Quo shape nests fields under data.resource/data.context;
            // this account's legacy OpenPhone v3 delivery puts everything
            // flat on data.object instead. Normalize both into $resource.
            $legacyObject = $data['object'] ?? [];
            $resource = $data['resource'] ?? $legacyObject;
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
                // New shape: context.senderIdentifier / recipientIdentifiers.
                // Legacy (confirmed live on this account): resource.from/to.
                $sender = $context['senderIdentifier'] ?? $resource['from'] ?? null;
                $recipient = $context['recipientIdentifiers'][0] ?? $resource['to'] ?? null;
                $channel = $this->channelForNumber($recipient) ?? 'other';
                $text = $resource['text'] ?? $resource['body'] ?? '';
                $externalId = !empty($resource['id']) ? 'quo-msg-' . $resource['id'] : null;

                $this->logCommunication($business_id, $system_user_id, $channel, $sender, (string) $text, $externalId);
            } elseif ($type === 'call.completed') {
                // Quo has no subscribable "missed call" event on this plan —
                // call.completed with a non-answered status is the real
                // signal (per docs.quo.com/webhooks-event-payloads). Only
                // 'answered' and 'ai-handled' mean someone actually dealt
                // with it; everything else (unanswered, abandoned, failed,
                // forwarded, unknown) needs a callback. Unconfirmed against
                // a real legacy call payload (Quo's test-send for call
                // events wouldn't fire on this account) — resource.from/to
                // is a best-effort fallback alongside the documented
                // context.participants shape.
                $status = (string) ($resource['status'] ?? '');
                if (!in_array($status, ['answered', 'ai-handled'], true)) {
                    $workspaceNumber = $context['participants']['workspace'][0] ?? $resource['to'] ?? null;
                    $callerNumber = $context['participants']['external'][0] ?? $resource['from'] ?? null;
                    $channel = $this->channelForNumber($workspaceNumber) ?? 'other';

                    $message = 'Missed call' . ($status !== '' ? ' (' . $status . ')' : '') . '.';
                    if (!empty($resource['hasVoicemail'])) {
                        $message .= ' Voicemail left — check Quo for the recording.';
                    }
                    $externalId = !empty($resource['id']) ? 'quo-call-' . $resource['id'] : null;

                    $this->logCommunication($business_id, $system_user_id, $channel, $callerNumber, $message, $externalId);
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
