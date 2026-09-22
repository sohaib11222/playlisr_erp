<?php

namespace App\Console\Commands;

use App\PendingAssignmentText;
use App\Services\OpenPhoneService;
use App\SlingShift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends queued task/project-assignment texts (see
 * TaskController::textAssignees / ProjectController::textAssignees) a few
 * minutes before the assignee's next scheduled Sling shift, instead of the
 * moment the task/project was created — so someone assigned something at
 * 9pm isn't woken up by a text, they get it right before they actually
 * clock in.
 *
 * Reads sling_shifts (synced daily by sling:sync-shifts) rather than
 * calling Sling's API live — no HTTP call in this command at all.
 *
 * Per pending row, each run:
 *   - looks up that user's next upcoming shift (dtstart >= now)
 *   - sends once that shift is within LEAD_MINUTES
 *   - if no shift is found (not on Sling, or not synced yet) OR sends keep
 *     failing, gives up waiting and just sends once the row is older than
 *     MAX_WAIT_HOURS — so an assignee never goes silently un-notified
 *     forever just because shift data isn't available for them.
 */
class SendPendingAssignmentTexts extends Command
{
    protected $signature = 'notify:pending-assignment-texts';

    protected $description = 'Text assignees whose next Sling shift is starting soon (queued at task/project creation).';

    /** Send once the assignee's next shift is this close (minutes). Run every 5 min, so actual lead time lands between (this - 5) and this minutes. */
    const LEAD_MINUTES = 10;

    /** Safety net: send anyway after waiting this long, even with no shift found / repeated send failures. */
    const MAX_WAIT_HOURS = 24;

    public function handle()
    {
        $now = now();
        $pending = PendingAssignmentText::with('user')->whereNull('sent_at')->get();

        if ($pending->isEmpty()) {
            return;
        }

        $sms = app(OpenPhoneService::class);
        $sentCount = 0;

        foreach ($pending as $p) {
            $shift = SlingShift::where('erp_user_id', $p->user_id)
                ->where('event_type', SlingShift::TYPE_SHIFT)
                ->where('published', true)
                ->where('dtstart', '>=', $now)
                ->orderBy('dtstart')
                ->first();

            $shiftIsClose = $shift && (($shift->dtstart->getTimestamp() - $now->getTimestamp()) / 60) <= self::LEAD_MINUTES;
            $giveUpWaiting = $p->created_at->diffInHours($now) >= self::MAX_WAIT_HOURS;

            if (!$shiftIsClose && !$giveUpWaiting) {
                continue; // keep waiting for the shift to get close (or the fallback deadline)
            }

            $phone = trim((string) ($p->user->contact_number ?? ''));
            if ($phone === '') {
                Log::info("SendPendingAssignmentTexts: user {$p->user_id} has no phone on file, giving up on pending text #{$p->id}");
                $p->sent_at = $now;
                $p->save();
                continue;
            }

            $result = $sms->send($phone, $p->message);
            if ($result['success']) {
                $p->sent_at = $now;
                $p->save();
                $sentCount++;
            } elseif ($giveUpWaiting) {
                Log::warning("SendPendingAssignmentTexts: giving up on pending text #{$p->id} after " . self::MAX_WAIT_HOURS . "h, last error: " . $result['msg']);
                $p->sent_at = $now;
                $p->save();
            } else {
                Log::info("SendPendingAssignmentTexts: send failed for pending text #{$p->id}, will retry: " . $result['msg']);
            }
        }

        if ($sentCount > 0) {
            $this->info("Sent {$sentCount} assignment text(s).");
        }
    }
}
