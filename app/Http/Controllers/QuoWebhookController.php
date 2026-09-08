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

        $api_svc = new \App\Services\OpenPhoneService();
        $api_masked = $api_svc->maskedKey();
        $api_env_locked = $api_svc->keyIsEnvLocked();

        return view('communications.quo_settings', compact('masked', 'env_locked', 'webhook_url', 'api_masked', 'api_env_locked'));
    }

    /** Save the Quo/OpenPhone REST API key used to backfill recent inquiries (admin only). */
    public function saveApiKey(Request $request)
    {
        $this->requireAdmin();
        $key = trim((string) $request->input('api_key'));

        if ($key !== '' && strlen($key) < 10) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => 'That looks too short to be a Quo API key.',
            ]);
        }

        (new \App\Services\OpenPhoneService())->saveApiKey($key);

        return redirect()->back()->with('status', [
            'success' => 1,
            'msg' => $key === '' ? 'API key cleared.' : 'Quo API key saved.',
        ]);
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

    /**
     * Find the inquiry a reply is actually responding to: the customer's
     * most recent inquiry created AT OR BEFORE the reply itself. A customer
     * can have more than one open thing going (e.g. a missed call and then
     * a text minutes later) — without the time cutoff, a reply to the
     * older one would wrongly attach to whichever is more recent overall.
     * Falls back to most-recent-overall only if nothing qualifies (a reply
     * that arrived before we have any record of contact from them at all).
     * Matches on the last 10 digits rather than an exact string —
     * contact_info can be stored with or without a leading "+1" depending
     * on the source event.
     */
    private function findRecentByContact(int $business_id, ?string $rawNumber, ?string $beforeTime = null): ?Communication
    {
        $target = substr(preg_replace('/\D/', '', (string) $rawNumber), -10);
        if ($target === '' || strlen($target) < 7) {
            return null;
        }

        $candidates = Communication::where('business_id', $business_id)
            ->whereNotNull('contact_info')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->filter(function ($c) use ($target) {
                return substr(preg_replace('/\D/', '', (string) $c->contact_info), -10) === $target;
            });

        if ($beforeTime) {
            // A small grace window: a missed call and its immediate
            // callback can land within seconds of each other, close enough
            // that API timestamp precision alone can put the call's
            // created_at a few seconds AFTER the reply that's answering it.
            $cutoff = \Carbon::parse($beforeTime)->addMinutes(2);
            $eligible = $candidates->filter(function ($c) use ($cutoff) {
                return $c->created_at && $c->created_at->lte($cutoff);
            });
            // Prefer one that hasn't already gotten a reply — if the real
            // inbound message this is answering fell outside our fetch
            // depth, piling onto something already-answered is a worse
            // guess than at least flagging the newest unanswered one.
            $match = $eligible->first(function ($c) {
                return empty($c->resolution_notes);
            }) ?? $eligible->first();
            if ($match) {
                return $match;
            }
        }

        return $candidates->first();
    }

    /**
     * Attach an outbound reply to the customer's most recent inquiry (any
     * status), or create a standalone resolved record if none exists so
     * the reply is never silently dropped. Shared by the live webhook
     * (message.delivered) and the historical backfill import.
     */
    private function attachReply(int $business_id, int $system_user_id, $recipient, $sender, string $text, ?string $externalId, ?string $sentAt = null): void
    {
        // The webhook payload's "to" is a bare string; the REST API's
        // /v1/messages "to" is an array of recipients. Normalize both.
        $recipient = is_array($recipient) ? ($recipient[0] ?? null) : $recipient;
        $sender = is_array($sender) ? ($sender[0] ?? null) : $sender;

        $text = trim($text);
        if ($text === '') {
            return;
        }

        // Use when the reply was actually sent, not when this code ran —
        // matters both for the displayed timestamp and for finding which
        // inquiry it was actually responding to (a backfill run today can
        // be attaching a reply that was really sent days ago).
        // Quo's API returns UTC ("...Z"); parsing it without converting
        // leaves the Carbon instance internally tagged UTC. Eloquent's
        // date mutator writes out the wall-clock digits of whatever
        // timezone the instance currently holds, then re-reads that same
        // string later assuming it's in the app's timezone (Pacific) — so
        // an unconverted UTC timestamp silently becomes 7-8 hours wrong
        // once round-tripped, breaking chronological comparisons against
        // natively-created (already-Pacific) rows.
        $eventTime = null;
        try {
            $eventTime = $sentAt ? \Carbon::parse($sentAt)->setTimezone(config('app.timezone')) : now();
        } catch (\Throwable $e) {
            $eventTime = now();
        }

        $target = $this->findRecentByContact($business_id, $recipient, $eventTime->toDateTimeString());
        $stamp = $eventTime->format('n/j g:ia');
        // The target's own external_id is usually already taken by the
        // inbound message it was created from, so dedupe a retried/re-run
        // delivery by looking for this reply's id inside the notes
        // themselves rather than the external_id column.
        $marker = $externalId ? '<!--' . $externalId . '-->' : '';

        if ($target) {
            if ($marker !== '' && strpos((string) $target->resolution_notes, $marker) !== false) {
                return;
            }
            $target->resolution_notes = trim(
                ($target->resolution_notes ? $target->resolution_notes . "\n" : '') . "[$stamp] " . $text . $marker
            );
            $target->save();
            return;
        }

        if ($externalId && Communication::where('business_id', $business_id)->where('external_id', $externalId)->exists()) {
            return;
        }

        // No real inquiry to attach this to, and it's a known automated
        // business text (purchase receipt, sync-issue notice, etc.) rather
        // than a staff reply — fabricating a standalone "resolved" inquiry
        // out of a routine receipt text is pure noise, not a customer
        // interaction worth tracking here.
        if (Communication::isAutoReplyText($text)) {
            return;
        }

        $channel = $this->channelForNumber($sender) ?? 'other';
        $c = new Communication();
        $c->business_id = $business_id;
        $c->channel = $channel;
        $c->topic = 'general';
        $c->status = 'resolved';
        $c->contact_info = $recipient;
        $c->message = '(no inbound message logged for this contact)';
        $c->resolution_notes = "[$stamp] " . $text;
        $c->external_id = $externalId;
        $c->created_by = $system_user_id;
        $c->save();
    }

    /** Insert a pending inquiry unless one with this external_id already exists (idempotent). */
    private function logCommunication(int $business_id, int $system_user_id, string $channel, ?string $contact, string $message, ?string $externalId, ?string $occurredAt = null): bool
    {
        if ($externalId) {
            $existing = Communication::where('business_id', $business_id)->where('external_id', $externalId)->first();
            if ($existing) {
                // Self-heal a row from before real timestamps were captured
                // (created_at used to default to "whenever this import ran").
                // Never touch anything staff may have already hand-edited.
                if ($occurredAt) {
                    try {
                        // Quo's API is UTC — must convert before comparing/
                        // storing, or this "fix" just re-corrupts the row by
                        // another ~7-8hr offset every time it runs (see the
                        // note on the identical conversion in attachReply()).
                        $real = \Carbon::parse($occurredAt)->setTimezone(config('app.timezone'));
                        if (!$existing->created_at || abs($existing->created_at->diffInMinutes($real)) > 2) {
                            $existing->created_at = $real;
                            $existing->save();
                        }
                    } catch (\Throwable $e) {
                    }
                }
                return false;
            }
        }

        $c = new Communication();
        $c->business_id = $business_id;
        $c->channel = $channel;
        $c->topic = Communication::guessTopic($message);
        $c->status = 'pending';
        $c->contact_info = $contact;
        $c->message = $message;
        $c->external_id = $externalId;
        $c->created_by = $system_user_id;
        // Backfilled history needs its REAL contact time, not "whenever this
        // import happened to run" — matters for sort order, the 1hr-overdue
        // flag, and matching a reply to the right inquiry.
        if ($occurredAt) {
            try {
                $c->created_at = \Carbon::parse($occurredAt)->setTimezone(config('app.timezone'));
            } catch (\Throwable $e) {
            }
        }
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

        // This walks up to ~40 sequential Quo API calls and can take a
        // couple minutes — keep running even if the browser tab that
        // triggered it navigates away or gets closed mid-request.
        ignore_user_abort(true);
        set_time_limit(0);

        $business_id = optional(Business::first())->id;
        $system_user_id = $business_id
            ? optional(\DB::table('users')->where('business_id', $business_id)->orderBy('id')->first())->id
            : null;
        if (!$business_id || !$system_user_id) {
            return response()->json(['success' => false, 'msg' => 'No business/user found to attribute imports to.']);
        }

        $svc = new \App\Services\OpenPhoneService();
        if (!$svc->isReadConfigured()) {
            return response()->json(['success' => false, 'msg' => 'No OpenPhone/Quo API key set yet — add one under Quo Setup.']);
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

            // /v1/messages and /v1/calls are thread-scoped (they require a
            // participant) — there's no inbox-wide "recent" feed on this
            // API. /v1/conversations gives the recently-active threads
            // without needing to know a participant up front, so it's the
            // entry point: walk the most recent threads on each line, then
            // pull each thread's messages/calls. Pulling only 5 per thread
            // (the original depth) meant a customer with an active back-
            // and-forth had replies with no real inbound match in our DB —
            // the fallback then misattached them to whatever unrelated
            // record was nearest. 30 covers realistic conversation lengths.
            $convResp = $svc->listRecentConversations($phoneNumberId, 15);
            if (!$convResp['success']) {
                $errors[] = "$e164 conversations: " . $convResp['msg'];
                continue;
            }

            foreach ($convResp['data'] as $conv) {
                $participant = $conv['participants'][0] ?? null;
                if (!$participant) {
                    continue;
                }

                $msgResp = $svc->listRecentMessages($phoneNumberId, $participant, 30);
                if (!$msgResp['success']) {
                    $errors[] = "$e164 messages ($participant): " . $msgResp['msg'];
                } else {
                    // Quo's API returns messages newest-first; attaching
                    // replies in that order made the resolution_notes log
                    // read backwards (oldest reply last). Sort oldest-first
                    // so replies get appended in true chronological order,
                    // matching how a live webhook naturally appends them.
                    $sortedMsgs = $msgResp['data'];
                    usort($sortedMsgs, function ($a, $b) {
                        return strtotime($a['createdAt'] ?? '') <=> strtotime($b['createdAt'] ?? '');
                    });
                    foreach ($sortedMsgs as $m) {
                        $direction = $m['direction'] ?? '';
                        $text = (string) ($m['text'] ?? $m['body'] ?? '');

                        if ($direction === 'incoming') {
                            $externalId = !empty($m['id']) ? 'quo-msg-' . $m['id'] : null;
                            $ok = $this->logCommunication(
                                $business_id, $system_user_id, $channel,
                                $m['from'] ?? $participant, $text, $externalId,
                                $m['createdAt'] ?? null
                            );
                            $ok ? $imported++ : $skipped++;
                        } elseif ($direction === 'outgoing') {
                            // A reply staff already sent, possibly before this
                            // backfill ever ran — attach it the same way a
                            // live message.delivered webhook would, so a
                            // conversation that's actually been handled stops
                            // showing as untouched.
                            $externalId = !empty($m['id']) ? 'quo-reply-' . $m['id'] : null;
                            $this->attachReply(
                                $business_id, $system_user_id,
                                $m['to'] ?? $participant, $m['from'] ?? null, $text, $externalId,
                                $m['createdAt'] ?? null
                            );
                        }
                    }
                }

                $callResp = $svc->listRecentCalls($phoneNumberId, $participant, 30);
                if (!$callResp['success']) {
                    $errors[] = "$e164 calls ($participant): " . $callResp['msg'];
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
                            $call['from'] ?? $participant, $message, $externalId,
                            $call['createdAt'] ?? null
                        );
                        $ok ? $imported++ : $skipped++;
                    }
                }
            }
        }

        // Re-categorize anything still sitting on the default "General
        // Inquiry" topic — covers both what this run just imported and
        // whatever was already logged before auto-categorization existed.
        // Missed-call placeholders and the no-inbound-record placeholder
        // have no real content to classify, so they're left alone.
        $recategorized = 0;
        Communication::where('business_id', $business_id)
            ->where('topic', 'general')
            ->whereNotNull('message')
            ->where('message', '!=', '')
            ->where('message', 'not like', 'Missed call%')
            ->where('message', 'not like', '(no inbound message logged%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$recategorized) {
                foreach ($rows as $row) {
                    $guess = Communication::guessTopic($row->message);
                    if ($guess !== 'general' && $guess !== $row->topic) {
                        $row->topic = $guess;
                        $row->save();
                        $recategorized++;
                    }
                }
            });

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'recategorized' => $recategorized,
            'errors' => array_slice(array_values(array_unique($errors)), 0, 5),
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
            } elseif ($type === 'message.delivered') {
                // An outbound text — attach it to the customer's most recent
                // inquiry (any status) so staff can see it got a reply,
                // without auto-resolving (a quick reply doesn't always close
                // it out). If there's no matching inquiry at all — a
                // proactive text with nothing logged yet — create one so the
                // reply is never silently dropped.
                $recipient = $context['recipientIdentifiers'][0] ?? $resource['to'] ?? null;
                $recipient = is_array($recipient) ? ($recipient[0] ?? null) : $recipient;
                $sender = $context['senderIdentifier'] ?? $resource['from'] ?? null;
                $text = (string) ($resource['text'] ?? $resource['body'] ?? '');
                $externalId = !empty($resource['id']) ? 'quo-reply-' . $resource['id'] : null;

                $this->attachReply($business_id, $system_user_id, $recipient, $sender, $text, $externalId);
            }
            // Other event types (ringing, tasks, contacts, etc.) are
            // acknowledged but not logged — nothing for staff to act on.
        } catch (\Throwable $e) {
            Log::emergency('Quo webhook processing failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }
}
