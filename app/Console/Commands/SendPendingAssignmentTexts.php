<?php

namespace App\Console\Commands;

use App\Http\Controllers\MyTasksController;
use App\Http\Controllers\TaskController;
use App\PendingAssignmentText;
use App\Project;
use App\Services\OpenPhoneService;
use App\SlingShift;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Start-of-shift task text. Never texts anyone off work hours:
 *
 *   - Only fires for someone whose Sling shift starts today, from
 *     LEAD_MINUTES before the start until LATE_MINUTES after it. Shifts
 *     that start before the store opens wait until opening time.
 *   - Only if they have tasks due today (or past due) assigned to them
 *     at the store they're working today (or "Both"). Unassigned store
 *     tasks never trigger a text.
 *   - At most one text per person per day.
 *
 * Assignment rows queued by TaskController / ProjectController
 * (pending_assignment_texts) are no longer sent one by one. They're folded
 * into this text as "N new" and marked sent when it goes out. No shift, no
 * text; there is no off-hours fallback anymore.
 *
 * The daily text itself is logged as a pending_assignment_texts row with no
 * task/project, which is what stops a second text the same day.
 *
 * The message reports two different numbers, on purpose (manager ask
 * 2026-09-24 — the due-today count alone read as "how much do I have
 * overall"): tasks due today (the trigger for sending at all), and a
 * separate "assigned in total" count across both tasks and projects with
 * no date filter, so the message can't be misread as a full workload count.
 */
class SendPendingAssignmentTexts extends Command
{
    protected $signature = 'notify:pending-assignment-texts {--dry : Print what would be sent, send nothing} {--at= : Pretend it is this time today, e.g. 09:25 (with --dry)}';

    protected $description = 'Text people at the start of their Sling shift if they have tasks due today.';

    /** Earliest send: this many minutes before the shift starts. Runs every 5 min. */
    const LEAD_MINUTES = 10;

    /** Still send if we're this many minutes past the shift start (missed runs, deploys). */
    const LATE_MINUTES = 60;

    /** Store open times. Early/prep shifts wait until the store opens. */
    const STORE_OPEN = ['pico' => '10:00', 'default' => '09:30'];

    public function handle()
    {
        $dry = (bool) $this->option('dry');
        $now = ($dry && $this->option('at')) ? Carbon::today()->setTimeFromTimeString($this->option('at')) : now();
        $today = Carbon::today();

        $shifts = SlingShift::with('user')
            ->where('event_type', SlingShift::TYPE_SHIFT)
            ->where('published', true)
            ->whereNotNull('erp_user_id')
            ->whereDate('dtstart', $today->toDateString())
            ->where('dtstart', '<=', $now->copy()->addMinutes(self::LEAD_MINUTES))
            ->orderBy('dtstart')
            ->get()
            ->unique('erp_user_id')
            // Send window starts at the shift start or store open, whichever
            // is later, so nobody gets texted before the store is open.
            ->filter(function ($shift) use ($now, $today) {
                $key = stripos((string) $shift->location_name, 'pico') !== false ? 'pico' : 'default';
                $open = $today->copy()->setTimeFromTimeString(self::STORE_OPEN[$key]);
                $anchor = $shift->dtstart->greaterThan($open) ? $shift->dtstart->copy() : $open;
                return $now->greaterThanOrEqualTo($anchor->copy()->subMinutes(self::LEAD_MINUTES))
                    && $now->lessThanOrEqualTo($anchor->copy()->addMinutes(self::LATE_MINUTES));
            });

        if ($shifts->isEmpty()) {
            return;
        }

        $sms = app(OpenPhoneService::class);
        $sentCount = 0;
        try {
            $slingPhones = (new \App\Services\SlingClient())->userPhones();
        } catch (\Throwable $e) {
            $slingPhones = [];
            Log::info('SendPendingAssignmentTexts: could not load Sling phones: ' . $e->getMessage());
        }

        foreach ($shifts as $shift) {
            $user = $shift->user;
            if (!$user || $user->status !== 'active') {
                continue;
            }

            $alreadyToday = PendingAssignmentText::where('user_id', $user->id)
                ->whereNull('weekly_task_id')
                ->whereNull('project_id')
                ->whereDate('created_at', $today->toDateString())
                ->exists();
            if ($alreadyToday) {
                continue;
            }

            // Only tasks with them as an owner (shared/unassigned store tasks
            // never trigger a text), and only for the store they're working
            // at today, plus "Both" tasks (store = null).
            $shiftStore = \App\Http\Controllers\TeamProgressController::SLING_LOCATIONS[strtolower(trim((string) $shift->location_name))] ?? null;
            $sections = MyTasksController::sectionsFor($user->business_id, $user->id, []);
            $dueRows = array_filter(array_merge($sections['past_due'], $sections['today']), function ($r) use ($shiftStore) {
                $store = $r['task']->store;
                return !$r['via_shift'] && ($store === null || $store === '' || $store === $shiftStore);
            });
            $dueToday = count($dueRows);
            if ($dueToday === 0) {
                continue;
            }

            $queued = PendingAssignmentText::where('user_id', $user->id)
                ->whereNull('sent_at')
                ->where(function ($q) {
                    $q->whereNotNull('weekly_task_id')->orWhereNotNull('project_id');
                })
                ->get();

            // Due-today count above is tasks only (projects have no due date
            // in this schema, so "due today" can't mean anything for them).
            // "Assigned in total" is the broader, no-date-filter number
            // people asked for: every open task assigned to them, plus every
            // open project they're assigned to (project_assignees, not
            // project_contributors — "assigned" means put on it, not "opted
            // in to").
            $assignedTasksTotal = TaskController::myOpenAssignedTasks($user->business_id, $user->id)->count();
            $assignedProjectsTotal = Project::where('business_id', $user->business_id)
                ->where('status', '!=', 'complete')
                ->whereHas('assignees', function ($q) use ($user) {
                    $q->where('users.id', $user->id);
                })
                ->count();
            $totalAssigned = $assignedTasksTotal + $assignedProjectsTotal;

            $first = trim((string) $user->first_name) ?: 'there';
            $message = "Hi {$first}, you have {$dueToday} " . ($dueToday === 1 ? 'task' : 'tasks') . ' due today'
                . ($shiftStore ? ' at ' . (TaskController::STORE_LABELS[$shiftStore] ?? ucfirst($shiftStore)) : '')
                . ($queued->count() ? " ({$queued->count()} new)" : '')
                . ", and {$totalAssigned} tasks/projects assigned in total"
                . '. Check them in the ERP: ' . self::myTasksUrl()
                . ($shiftStore === 'pico' ? '. Not sure what to do? Contact Zak.' : '. Not sure what to do? Contact Luis.');

            // ERP number first; otherwise the phone on their Sling profile.
            $phone = trim((string) ($user->contact_number ?? ''));
            if ($phone === '' && !empty($shift->sling_user_id)) {
                $phone = $slingPhones[(string) $shift->sling_user_id] ?? '';
            }
            if ($dry) {
                $this->line("[dry] {$user->first_name} (#{$user->id}) shift {$shift->dtstart->format('g:ia')} {$shift->location_name} phone=" . ($phone !== '' ? 'yes' : 'NONE') . " -> {$message}");
                continue;
            }
            if ($phone === '') {
                Log::info("SendPendingAssignmentTexts: user {$user->id} has no phone on file, skipping today's task text");
                $this->logDaily($user->id, $message, $now);
                continue;
            }

            // Same Quo lines the customer texts use: the store they're working at.
            $line = array_search(stripos((string) $shift->location_name, 'pico') !== false ? 'phone_1' : 'phone_2', \App\Communication::QUO_NUMBERS, true);
            $result = $sms->sendFrom((string) $line, $phone, $message);
            if (!$result['success']) {
                // Not logged, so the next run (every 5 min) retries until LATE_MINUTES.
                Log::info("SendPendingAssignmentTexts: send failed for user {$user->id}, will retry: " . $result['msg']);
                continue;
            }

            $this->logDaily($user->id, $message, $now);
            PendingAssignmentText::whereIn('id', $queued->pluck('id'))->update(['sent_at' => $now]);
            $sentCount++;
        }

        if ($sentCount > 0) {
            $this->info("Sent {$sentCount} start-of-shift task text(s).");
        }
    }

    private static function myTasksUrl()
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '' || stripos($base, 'localhost') !== false) {
            $base = 'https://playlist.nivessa.com';
        }
        return $base . '/tasks/my';
    }

    private function logDaily($userId, $message, $now)
    {
        PendingAssignmentText::create([
            'user_id' => $userId,
            'message' => $message,
            'sent_at' => $now,
        ]);
    }
}
