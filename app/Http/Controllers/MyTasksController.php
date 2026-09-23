<?php

namespace App\Http\Controllers;

use App\SlingShift;
use App\WeeklyTask;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Asana-style "My Tasks": everything on the signed-in person's plate,
 * grouped Past due / Today / Upcoming / Later, plus what they finished
 * recently. Reads Tasks (TaskController) and marks things complete through
 * TaskController@updateStatus, so the photo gate and status rules are the
 * same ones the Tasks list uses.
 *
 * "Mine" = tasks assigned to me, plus unassigned tasks at the store Sling
 * has me on shift at today (same owner rule as Team Progress).
 */
class MyTasksController extends Controller
{
    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $userId = auth()->id();
        $now = now();
        $today = Carbon::today();

        // Also rolls repeating tasks over to today, same as the Tasks list.
        $assigned = TaskController::myOpenAssignedTasks($business_id, $userId);
        $assigned->load('assignees');

        $shiftStores = $this->myShiftStoresToday($userId);
        $onShift = collect();
        if ($shiftStores) {
            $onShift = WeeklyTask::with('assignees')
                ->where('business_id', $business_id)
                ->where('status', '!=', 'complete')
                ->whereDoesntHave('assignees')
                ->whereDate('start_date', '<=', $today->toDateString())
                ->where(function ($q) use ($shiftStores) {
                    $q->whereIn('store', array_keys($shiftStores))->orWhereNull('store');
                })
                ->get();
        }

        $sections = ['past_due' => [], 'today' => [], 'upcoming' => [], 'later' => []];
        $seen = [];
        foreach ([[$assigned, false], [$onShift, true]] as [$list, $viaShift]) {
            foreach ($list as $t) {
                if (isset($seen[$t->id])) {
                    continue;
                }
                $seen[$t->id] = true;
                $due = TeamProgressController::dueAt($t);
                if ($due && $due->lt($now)) {
                    $key = 'past_due';
                } elseif ($t->start_date->gt($today)) {
                    $key = $t->start_date->lte($today->copy()->addDays(7)) ? 'upcoming' : 'later';
                } elseif ($t->end_date->isSameDay($today)) {
                    $key = 'today';
                } else {
                    $key = 'upcoming';
                }
                $sections[$key][] = ['task' => $t, 'due' => $due, 'via_shift' => $viaShift];
            }
        }
        foreach ($sections as &$rows) {
            usort($rows, function ($a, $b) {
                return [TeamProgressController::priorityRank($a['task']->priority), $a['due']]
                    <=> [TeamProgressController::priorityRank($b['task']->priority), $b['due']];
            });
        }
        unset($rows);

        $completed = WeeklyTask::where('business_id', $business_id)
            ->where('completed_by', $userId)
            ->where('status', 'complete')
            ->where('completed_at', '>=', $today->copy()->subDays(7))
            ->orderByDesc('completed_at')
            ->get();
        $completedCount = $completed->count() + \DB::table('task_completion_logs')
            ->where('business_id', $business_id)
            ->where('completed_by', $userId)
            ->where('status', 'complete')
            ->whereDate('log_date', '>=', $today->copy()->subDays(7)->toDateString())
            ->count();

        $storeLabels = TaskController::STORE_LABELS;
        $firstName = auth()->user()->first_name;

        return view('tasks.my_tasks', compact('sections', 'completed', 'completedCount', 'storeLabels', 'shiftStores', 'firstName'));
    }

    /** [store key => label] for stores Sling has this person working today. */
    private function myShiftStoresToday($userId)
    {
        $out = [];
        $shifts = SlingShift::where('event_type', SlingShift::TYPE_SHIFT)
            ->where('published', 1)
            ->where('erp_user_id', $userId)
            ->whereDate('dtstart', Carbon::today()->toDateString())
            ->get();
        foreach ($shifts as $s) {
            $store = TeamProgressController::SLING_LOCATIONS[strtolower(trim((string) $s->location_name))] ?? null;
            if ($store) {
                $out[$store] = TaskController::STORE_LABELS[$store] ?? ucfirst($store);
            }
        }
        return $out;
    }
}
