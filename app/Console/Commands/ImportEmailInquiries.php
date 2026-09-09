<?php

namespace App\Console\Commands;

use App\Business;
use App\Communication;
use App\Services\EmailInboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Live sync for the Communications Hub's email channel. Polls hello@ and
 * orders@nivessa.com (or whatever mailboxes are configured under Email
 * Setup) every few minutes: new INBOX mail becomes a pending inquiry, new
 * Sent Mail becomes a reply attached to the matching inquiry — same
 * inbound/outbound split as the Quo webhook, just IMAP instead of a push
 * webhook since Gmail doesn't give us one here.
 *
 * Idempotent: dedupes by the email's Message-ID header (external_id), so
 * re-running (or a missed run picked up on the next poll) never
 * double-logs.
 */
class ImportEmailInquiries extends Command
{
    protected $signature = 'communications:import-email';
    protected $description = 'Poll configured mailboxes and log new customer emails / staff replies into the Communications Hub';

    public function handle()
    {
        $svc = new EmailInboxService();
        if (!$svc->isConfigured()) {
            $this->warn('No email mailboxes configured yet — add one under Email Setup.');
            return 0;
        }

        $business_id = optional(Business::first())->id;
        $system_user_id = $business_id
            ? optional(DB::table('users')->where('business_id', $business_id)->orderBy('id')->first())->id
            : null;
        if (!$business_id || !$system_user_id) {
            $this->warn('No business/user found to attribute imports to.');
            return 0;
        }

        $imported = 0;
        $replied = 0;

        foreach ($svc->mailboxes() as $key => $account) {
            $ownAddress = strtolower((string) ($account['username'] ?? ''));

            foreach ($svc->fetchRecent($account, 'INBOX') as $msg) {
                if (strcasecmp($msg['from'], $ownAddress) === 0) {
                    continue; // a copy of our own outbound landing in INBOX
                }
                if ($msg['body'] === '') {
                    continue;
                }
                $ok = $this->logInbound($business_id, $system_user_id, $msg);
                if ($ok) {
                    $imported++;
                }
            }

            foreach ($svc->fetchRecent($account, 'SENT') as $msg) {
                if ($msg['body'] === '') {
                    continue;
                }
                $ok = $this->attachReply($business_id, $msg);
                if ($ok) {
                    $replied++;
                }
            }
        }

        $this->info("Email import: {$imported} new inquiries, {$replied} replies attached.");
        return 0;
    }

    private function logInbound(int $business_id, int $system_user_id, array $msg): bool
    {
        $externalId = 'email-msg-' . md5($msg['message_id']);
        if (Communication::where('business_id', $business_id)->where('external_id', $externalId)->exists()) {
            return false;
        }

        $eventTime = $this->parseDate($msg['date']);

        $c = new Communication();
        $c->business_id = $business_id;
        $c->channel = 'email';
        $c->topic = Communication::guessTopic($msg['subject'] . ' ' . $msg['body']);
        $c->contact_info = $msg['from'];
        $c->message = trim(($msg['subject'] ? $msg['subject'] . ' — ' : '') . $msg['body']);
        $c->is_priority = $c->topic === 'unhappy_customer' ? 1 : 0;
        $c->status = 'pending';
        $c->external_id = $externalId;
        $c->created_by = $system_user_id;
        $c->created_at = $eventTime;
        $c->save();

        return true;
    }

    private function attachReply(int $business_id, array $msg): bool
    {
        $marker = '<!--email-reply-' . md5($msg['message_id']) . '-->';
        $recipient = strtolower($msg['to']);
        if ($recipient === '') {
            return false;
        }

        $eventTime = $this->parseDate($msg['date']);

        $target = Communication::where('business_id', $business_id)
            ->where('channel', 'email')
            ->where('created_at', '<=', $eventTime->copy()->addMinutes(2))
            ->get()
            ->filter(function ($c) use ($recipient) {
                return stripos((string) $c->contact_info, $recipient) !== false;
            })
            ->sortByDesc(function ($c) {
                return empty($c->resolution_notes) ? 1 : 0; // prefer unreplied
            })
            ->first();

        if (!$target) {
            return false;
        }
        if (strpos((string) $target->resolution_notes, $marker) !== false) {
            return false;
        }

        $stamp = $eventTime->format('n/j g:ia');
        $target->resolution_notes = trim(
            ($target->resolution_notes ? $target->resolution_notes . "\n" : '') . "[$stamp] " . $msg['body'] . $marker
        );
        $target->save();

        if (!Communication::isAutoReplyText($msg['body'])) {
            Communication::notifyStaff($business_id, new \App\Notifications\CommunicationRepliedNotification($target));
        }

        return true;
    }

    /** IMAP dates are UTC-offset RFC2822 strings — convert to app timezone
     * so this doesn't fall into the same silent-corruption trap the Quo
     * import hit (see QuoWebhookController for the full writeup). */
    private function parseDate(string $raw): \Carbon\Carbon
    {
        try {
            return \Carbon\Carbon::parse($raw)->setTimezone(config('app.timezone'));
        } catch (\Throwable $e) {
            return now();
        }
    }
}
