<?php

namespace App\Http\Controllers;

use App\SlingShift;
use App\User;
use App\WeeklyTask;
use App\Utils\BusinessUtil;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Read-only Team Progress view layered on top of Tasks (TaskController).
 * Never writes to weekly_tasks / task_assignees — it only reads them, plus
 * task_completion_logs and sling_shifts, so nothing here changes how the
 * Tasks list, start/end shift, or register-close prompts behave.
 *
 * Owner rule: a task's owner is its assignee(s). A task with no assignee is
 * owned by whoever Sling had on a Cashier shift at that task's store that
 * day (any shift at that store if no Cashier shift). Company-wide
 * (store = null) tasks with no assignee stay "Unassigned".
 */
class TeamProgressController extends Controller
{
    const STORE_LABELS = TaskController::STORE_LABELS;

    // Sling location_name => task store key.
    const SLING_LOCATIONS = [
        'hollywood' => 'hollywood',
        'pico'      => 'pico',
    ];

    const DAYS = 7;

    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        if (!self::canView()) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $today = Carbon::today();
        $windowStart = $today->copy()->subDays(self::DAYS);
        $yesterday = $today->copy()->subDay();

        $tasks = WeeklyTask::with('assignees')->where('business_id', $business_id)->get();
        // Overdue tasks can predate the scorecard window, so pull Sling back
        // far enough to name who was on shift the day each one was due.
        $oldestOpenDue = $tasks->where('status', '!=', 'complete')->pluck('end_date')->filter()->min();
        $shiftFrom = $oldestOpenDue && $oldestOpenDue->lt($windowStart) ? $oldestOpenDue->copy() : $windowStart;
        $onShift = self::onShiftByDateAndStore($shiftFrom, $today);
        $names = [];

        // Tasks with no assignee, but Sling can still name who was on shift.
        $ownersFor = function (WeeklyTask $t, Carbon $date) use ($onShift, &$names) {
            if ($t->assignees->isNotEmpty()) {
                foreach ($t->assignees as $u) {
                    $names[$u->id] = trim($u->first_name . ' ' . $u->last_name);
                }
                return ['ids' => $t->assignees->pluck('id')->all(), 'via' => 'assigned'];
            }
            $ids = [];
            // Unassigned project tasks are project work, not floor duties:
            // never hand them to whoever happens to be on shift.
            if ($t->store && empty($t->project_id)) {
                foreach ($onShift[$date->toDateString()][$t->store] ?? [] as $uid => $name) {
                    $ids[] = $uid;
                    $names[$uid] = $names[$uid] ?? $name;
                }
            }
            return ['ids' => $ids, 'via' => $ids ? 'sling' : 'none'];
        };

        // --- Open today + overdue -------------------------------------
        $openToday = [];
        $overdue = [];
        foreach ($tasks as $t) {
            if ($t->status === 'complete') {
                continue;
            }
            $due = self::dueAt($t);
            if ($due && $due->lt(now())) {
                $overdue[] = ['task' => $t, 'owner' => $ownersFor($t, $t->end_date->copy()), 'days' => $t->end_date->diffInDays($today), 'due' => $due];
            } elseif ($t->start_date && $t->start_date->lte($today)) {
                $openToday[] = ['task' => $t, 'owner' => $ownersFor($t, $today)];
            }
        }
        usort($overdue, function ($a, $b) { return $b['days'] <=> $a['days']; });
        usort($openToday, function ($a, $b) {
            return [$a['task']->store, self::priorityRank($a['task']->priority)] <=> [$b['task']->store, self::priorityRank($b['task']->priority)];
        });

        // --- Scorecard (last DAYS days + today) -----------------------
        $score = [];
        $bump = function ($uid, $field, $n = 1) use (&$score) {
            if (!$uid) {
                return;
            }
            if (!isset($score[$uid])) {
                $score[$uid] = ['done' => 0, 'late' => 0, 'missed' => 0, 'missed_shift' => 0];
            }
            $score[$uid][$field] += $n;
        };

        // Completions sitting on the live rows (one-off tasks, weekly repeat
        // instances, and today's state of daily repeats).
        foreach ($tasks as $t) {
            if ($t->status === 'complete' && $t->completed_at && $t->completed_at->gte($windowStart)) {
                $bump($t->completed_by, 'done');
                $due = self::dueAt($t);
                if ($due && $t->completed_at->gt($due)) {
                    $bump($t->completed_by, 'late');
                }
            }
        }

        // Past days of daily repeats live in task_completion_logs.
        $logs = \DB::table('task_completion_logs')
            ->where('business_id', $business_id)
            ->whereDate('log_date', '>=', $windowStart->toDateString())
            ->get();
        $completedLog = [];
        foreach ($logs as $l) {
            if ($l->status === 'complete') {
                $bump($l->completed_by, 'done');
                $completedLog[$l->weekly_task_id . '|' . $l->log_date] = true;
            }
        }

        $missedRows = [];
        $recordMiss = function (WeeklyTask $t, Carbon $date) use ($ownersFor, $bump, &$missedRows) {
            $owner = $ownersFor($t, $date);
            foreach ($owner['ids'] as $uid) {
                $bump($uid, $owner['via'] === 'sling' ? 'missed_shift' : 'missed');
            }
            $missedRows[] = ['task' => $t, 'date' => $date, 'owner' => $owner];
        };

        foreach ($tasks as $t) {
            if ($t->repeat_daily && !$t->repeat_of) {
                // A daily repeat not completed on a past day = missed that day.
                $first = Carbon::parse($t->created_at)->startOfDay()->max($windowStart);
                for ($d = $first->copy(); $d->lte($yesterday); $d->addDay()) {
                    if (empty($completedLog[$t->id . '|' . $d->toDateString()])) {
                        $recordMiss($t, $d->copy());
                    }
                }
            } elseif ($t->status !== 'complete' && $t->end_date
                && $t->end_date->gte($windowStart) && $t->end_date->lte($yesterday)) {
                $recordMiss($t, $t->end_date->copy());
            }
        }
        usort($missedRows, function ($a, $b) { return $b['date'] <=> $a['date']; });

        $missingNames = array_diff(array_keys($score), array_keys($names));
        if ($missingNames) {
            foreach (User::whereIn('id', $missingNames)->get() as $u) {
                $names[$u->id] = trim($u->first_name . ' ' . $u->last_name);
            }
        }
        foreach ($score as $uid => &$s) {
            $s['name'] = $names[$uid] ?? ('User #' . $uid);
            $total = $s['done'] + $s['missed'] + $s['missed_shift'];
            $s['rate'] = $total ? round(100 * ($s['done'] - $s['late']) / $total) : null;
        }
        unset($s);
        uasort($score, function ($a, $b) {
            return [$b['done'], $a['name']] <=> [$a['done'], $b['name']];
        });

        $noOwnerCount = $tasks->filter(function ($t) {
            return $t->status !== 'complete' && $t->assignees->isEmpty();
        })->count();

        $storeLabels = self::STORE_LABELS;
        $days = self::DAYS;

        return view('tasks.team_progress', compact(
            'openToday', 'overdue', 'score', 'missedRows', 'names', 'noOwnerCount', 'storeLabels', 'days', 'windowStart', 'today'
        ));
    }

    // Owners who may not hold the Admin role on every login.
    const OWNER_EMAILS = ['jonhedvat@gmail.com', 'sarah@nivessa.com'];

    /** Admins, the owners (Jon, Sarah) and the store managers (Zakary, Luis). */
    public static function canView()
    {
        $u = auth()->user();
        if (!$u) {
            return false;
        }
        return $u->hasRole('Admin#' . $u->business_id)
            || in_array(strtolower(trim((string) $u->email)), self::OWNER_EMAILS, true)
            || ManagerChecklistController::currentManagerKey() !== null;
    }

    /** Only whoever created a task/project, an admin, or an owner can delete it. */
    public static function canDelete($item)
    {
        $u = auth()->user();
        if (!$u || !$item) {
            return false;
        }
        return (int) $item->created_by === (int) $u->id
            || $u->hasRole('Admin#' . $u->business_id)
            || in_array(strtolower(trim((string) $u->email)), self::OWNER_EMAILS, true);
    }

    /** When a task is due: its end date at due_time, or end of that day if no time is set. */
    public static function dueAt(WeeklyTask $t)
    {
        if (!$t->end_date) {
            return null;
        }
        $due = $t->end_date->copy();
        return !empty($t->due_time)
            ? $due->setTimeFromTimeString($t->due_time)
            : $due->endOfDay();
    }

    const AVATAR_COLORS = ['#e8384f', '#fd612c', '#fd9a00', '#d9b100', '#8fbf1f', '#37c5ab', '#20aaea', '#4186e0', '#7a6ff0', '#aa62e3', '#d94fd9', '#ea4e9d'];

    /** Asana-style initials avatar, colored by user id. */
    public static function avatar($id, $name, $size = '')
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        $ini = mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '');
        $color = self::AVATAR_COLORS[((int) $id) % count(self::AVATAR_COLORS)];
        return '<span class="as-avatar ' . e($size) . '" style="background:' . $color . '" title="' . e($name) . '">' . e($ini) . '</span>';
    }

    const CHECK_SVG = '<svg viewBox="0 0 12 12" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 6.5l2.3 2.3L9.5 3.8"/></svg>';

    public static function priorityRank($p)
    {
        return ['high' => 0, 'medium' => 1, 'low' => 2][$p] ?? 3;
    }

    /**
     * [date => [store => [erp_user_id => name]]] from synced Sling shifts.
     * Cashier shifts win; a store/day with no Cashier shift falls back to
     * everyone scheduled there.
     */
    public static function onShiftByDateAndStore(Carbon $from, Carbon $to)
    {
        $shifts = SlingShift::where('event_type', SlingShift::TYPE_SHIFT)
            ->where('published', 1)
            ->whereNotNull('erp_user_id')
            ->whereDate('dtstart', '>=', $from->toDateString())
            ->whereDate('dtstart', '<=', $to->toDateString())
            ->get();

        $cashiers = [];
        $everyone = [];
        foreach ($shifts as $s) {
            $store = self::SLING_LOCATIONS[strtolower(trim((string) $s->location_name))] ?? null;
            if (!$store) {
                continue;
            }
            $date = $s->dtstart->toDateString();
            $name = $s->user_name ?: ('User #' . $s->erp_user_id);
            $everyone[$date][$store][$s->erp_user_id] = $name;
            if (stripos((string) $s->position_name, 'cashier') !== false) {
                $cashiers[$date][$store][$s->erp_user_id] = $name;
            }
        }

        $out = [];
        foreach ($everyone as $date => $stores) {
            foreach ($stores as $store => $people) {
                $out[$date][$store] = $cashiers[$date][$store] ?? $people;
            }
        }
        return $out;
    }
}
