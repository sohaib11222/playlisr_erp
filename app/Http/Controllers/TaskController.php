<?php

namespace App\Http\Controllers;

use App\User;
use App\WeeklyTask;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    // Same store keys/labels as StoreTaskController (employee_tasks board).
    const STORE_LABELS = [
        'pico'      => 'Pico',
        'hollywood' => 'Hollywood',
    ];

    const PRIORITY_LABELS = [
        'high'   => 'High',
        'medium' => 'Medium',
        'low'    => 'Low',
    ];

    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    /** end_date for a task of $taskType, starting $startDate. */
    private static function computeEndDate(string $taskType, string $startDate)
    {
        $start = \Carbon\Carbon::parse($startDate);
        return $taskType === 'daily' ? $start->toDateString() : $start->addDays(7)->toDateString();
    }

    private function isAdmin()
    {
        return $this->businessUtil->is_admin(auth()->user());
    }

    /**
     * Stores the current user is allowed to see/manage. Admins get both, so
     * they can toggle between them; everyone else is locked to whichever
     * store(s) their location permissions cover (see
     * OpeningChecklistController::storesForUser), same convention as the
     * employee_tasks board.
     */
    private function availableStores()
    {
        if ($this->isAdmin()) {
            return self::STORE_LABELS;
        }
        return OpeningChecklistController::storesForUser();
    }

    /**
     * The store to filter the list by. Admins can pick via ?store= (or see
     * both, unfiltered); everyone else is pinned to their own store
     * regardless of the query string, so a Hollywood login only ever sees
     * Hollywood tasks and a Pico login only ever sees Pico tasks.
     */
    private function resolveStore(Request $request, array $availableStores)
    {
        $requested = $request->input('store');

        if ($this->isAdmin()) {
            return (!empty($requested) && isset(self::STORE_LABELS[$requested])) ? $requested : null;
        }

        if (!empty($requested) && isset($availableStores[$requested])) {
            return $requested;
        }

        return array_key_first($availableStores) ?: OpeningChecklistController::defaultStoreForUser();
    }

    /** Active, login-enabled employees for the assignee picker: id => full name. */
    private function assignableUsers($business_id)
    {
        return User::where('business_id', $business_id)
            ->user()
            ->where('is_cmmsn_agnt', 0)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(function ($u) {
                return [$u->id => trim($u->first_name . ' ' . $u->last_name)];
            })
            ->all();
    }

    /** Sync $task's assignees to the ids in $requestedIds, dropping anything not in $assignableUsers. */
    private function syncAssignees(WeeklyTask $task, array $requestedIds, array $assignableUsers)
    {
        $validIds = array_values(array_intersect(array_map('intval', $requestedIds), array_keys($assignableUsers)));
        $task->assignees()->sync($validIds);
    }

    /**
     * Daily repeats reset in place (see resetRepeatingDailyTasks); weekly
     * repeats still get a fresh row once 7+ days have passed since the last
     * one (a week apart reads fine as separate rows — daily ones sitting a
     * day apart is what read as duplicates to Jon/Zak/the team). Lazy: runs
     * on whoever hits /tasks or closes a register first, rather than a
     * cron. Idempotent — safe to call from multiple places/requests.
     */
    private static function rolloverRepeatingTasks($business_id)
    {
        self::resetRepeatingDailyTasks($business_id);
        self::rolloverRepeats($business_id);
    }

    /**
     * A repeat_daily root never grows new rows. Once a day has passed since
     * its last reset, whatever it was left at (in_progress/complete) gets
     * logged to task_completion_logs — a quiet history trail, not shown on
     * the list — and the same row resets to not_started, dated today, ready
     * to be worked again. No new row, so the list can never show "the same
     * task twice."
     */
    private static function resetRepeatingDailyTasks($business_id)
    {
        $today = \Carbon\Carbon::today();

        $roots = WeeklyTask::where('business_id', $business_id)
            ->where('task_type', 'daily')
            ->where('repeat_daily', true)
            ->whereNull('repeat_of')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->get();

        foreach ($roots as $task) {
            $lastReset = $task->last_reset_date ? \Carbon\Carbon::parse($task->last_reset_date) : null;
            if ($lastReset && $lastReset->isSameDay($today)) {
                continue;
            }

            if ($task->status !== 'not_started') {
                \DB::table('task_completion_logs')->insert([
                    'weekly_task_id' => $task->id,
                    'business_id' => $business_id,
                    'title' => $task->title,
                    'store' => $task->store,
                    'priority' => $task->priority,
                    'log_date' => ($lastReset ?: $task->start_date)->toDateString(),
                    'status' => $task->status,
                    'started_by' => $task->started_by,
                    'completed_by' => $task->completed_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $task->status = 'not_started';
            $task->started_by = null;
            $task->started_at = null;
            $task->completed_by = null;
            $task->completed_at = null;
            $task->start_date = $today->toDateString();
            $task->end_date = $today->toDateString();
            $task->last_reset_date = $today->toDateString();
            $task->save();
        }
    }

    /** Weekly repeats still generate a fresh row once 7+ days have passed since the last one. */
    private static function rolloverRepeats($business_id)
    {
        $today = \Carbon\Carbon::today();

        $roots = WeeklyTask::with('assignees')
            ->where('business_id', $business_id)
            ->where('task_type', 'weekly')
            ->where('repeat_weekly', true)
            ->whereNull('repeat_of')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->get();

        foreach ($roots as $root) {
            $lastStart = WeeklyTask::where('business_id', $business_id)
                ->where(function ($q) use ($root) {
                    $q->where('id', $root->id)->orWhere('repeat_of', $root->id);
                })
                ->max('start_date');
            $lastStart = \Carbon\Carbon::parse($lastStart);

            if ($lastStart->copy()->addDays(7)->gt($today)) {
                continue;
            }
            $newStart = $today->toDateString();

            try {
                $instance = WeeklyTask::create([
                    'business_id' => $business_id,
                    'title' => $root->title,
                    'description' => $root->description,
                    'start_date' => $newStart,
                    'end_date' => self::computeEndDate('weekly', $newStart),
                    'task_type' => 'weekly',
                    'store' => $root->store,
                    'priority' => $root->priority,
                    'status' => 'not_started',
                    'created_by' => $root->created_by,
                    'repeat_daily' => false,
                    'repeat_weekly' => true,
                    'repeat_of' => $root->id,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Unique (repeat_of, start_date) constraint — an overlapping
                // request already created this instance a moment ago.
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    continue;
                }
                throw $e;
            }
            $instance->assignees()->sync($root->assignees->pluck('id')->all());
        }
    }

    /**
     * Daily tasks due today (not yet complete) for a store, same scoping as
     * the store filter on the list page: that store's tasks plus any
     * company-wide (store = null) task. Used to drive the "Tasks due today"
     * bubble shown right after a cashier closes out their register — the
     * moment they're most likely to actually look at it before leaving.
     *
     * When $userId is given, also scoped to that person: unassigned tasks
     * (everyone's problem) plus tasks specifically assigned to them —
     * never someone else's assigned task. Without $userId, every open
     * daily task for the store is returned (used by the /tasks list page,
     * which has its own "Assigned to" column instead of a person filter).
     */
    public static function dueTodayForStore($business_id, $store, $userId = null)
    {
        self::rolloverRepeatingTasks($business_id);

        $query = WeeklyTask::where('business_id', $business_id)
            ->where('task_type', 'daily')
            ->whereDate('start_date', \Carbon\Carbon::today())
            ->where('status', '!=', 'complete');

        if (!empty($store)) {
            $query->where(function ($q) use ($store) {
                $q->where('store', $store)->orWhereNull('store');
            });
        }

        if (!empty($userId)) {
            $query->where(function ($q) use ($userId) {
                $q->whereDoesntHave('assignees')
                    ->orWhereHas('assignees', function ($aq) use ($userId) {
                        $aq->where('users.id', $userId);
                    });
            });
        }

        return $query->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")->get();
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        self::rolloverRepeatingTasks($business_id);

        // Daily and weekly tasks share one list now (no more separate
        // tabs) — 'type' is just an optional filter, not the thing that
        // decides what's on screen.
        $type = $request->input('type');
        if (!in_array($type, ['daily', 'weekly'], true)) {
            $type = null;
        }
        // Used to default to hiding completed tasks (avoided a repeating
        // task's finished-yesterday row sitting next to its not-started-
        // today row, which read as a duplicate). Daily repeats now reset in
        // place instead of generating a new row, so that pairing can't
        // happen anymore — and hiding completed work was actively unwanted
        // ("keeping it up would be better, that way we can all see what we
        // did today"). Back to showing everything by default.
        $status = $request->input('status');
        $priority = $request->input('priority');
        $storeLabels = $this->availableStores();
        $store = $this->resolveStore($request, $storeLabels);

        $query = WeeklyTask::with(['creator', 'startedBy', 'completedBy', 'assignees'])
            ->where('business_id', $business_id);

        if (!empty($type)) {
            $query->where('task_type', $type);
        }
        if (!empty($status)) {
            $query->where('status', $status);
        }
        if (!empty($priority)) {
            $query->where('priority', $priority);
        }
        if (!empty($store)) {
            // A store-specific view includes that store's tasks plus any
            // company-wide (store = null) task, but not the other store's.
            $query->where(function ($q) use ($store) {
                $q->where('store', $store)->orWhereNull('store');
            });
        }

        $tasks = $query->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderByDesc('start_date')
            ->paginate(50)->appends($request->except('page'));
        $priorityLabels = self::PRIORITY_LABELS;
        $canToggleStore = $this->isAdmin();

        return view('tasks.index', compact('tasks', 'type', 'status', 'priority', 'store', 'storeLabels', 'priorityLabels', 'canToggleStore'));
    }

    /**
     * Standalone "End Shift" page: the same due-today status check the
     * register-close bubble shows, but for anyone — not just employees who
     * close a cash register (warehouse/stock-only staff never see the POS
     * screen at all, so they'd otherwise never get prompted).
     */
    public function endShift(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $storeLabels = $this->availableStores();
        $store = $this->resolveStore($request, $storeLabels);

        $dueTasks = self::dueTodayForStore($business_id, $store, auth()->id())
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'status' => $t->status,
                ];
            })->values()->all();

        return view('tasks.end_shift', compact('dueTasks', 'storeLabels', 'store'));
    }

    public function create(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $storeLabels = $this->availableStores();
        $priorityLabels = self::PRIORITY_LABELS;
        $assignableUsers = $this->assignableUsers($business_id);
        $type = $request->input('type', 'weekly');
        if (!in_array($type, ['daily', 'weekly'])) {
            $type = 'weekly';
        }
        return view('tasks.create', compact('storeLabels', 'priorityLabels', 'assignableUsers', 'type'));
    }

    public function store(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'task_type' => 'required|in:daily,weekly',
            'repeat_daily' => 'nullable|boolean',
            'repeat_weekly' => 'nullable|boolean',
            'store' => 'nullable|in:' . implode(',', array_keys($this->availableStores())),
            'priority' => 'required|in:' . implode(',', array_keys(self::PRIORITY_LABELS)),
            'assignees' => 'nullable|array',
            'assignees.*' => 'integer',
        ]);
        $assignees = $data['assignees'] ?? [];
        unset($data['assignees']);

        // A daily task can only repeat daily, a weekly task only weekly — a
        // stray checkbox value for the other type is silently dropped rather
        // than validated against, since there's nothing wrong with the
        // request, just nothing to do with it.
        $data['repeat_daily'] = $data['task_type'] === 'daily' && !empty($data['repeat_daily']);
        $data['repeat_weekly'] = $data['task_type'] === 'weekly' && !empty($data['repeat_weekly']);

        $data['business_id'] = $business_id;
        $data['created_by'] = auth()->id();
        $data['status'] = 'not_started';
        $data['end_date'] = self::computeEndDate($data['task_type'], $data['start_date']);
        // A fresh repeat_daily task shouldn't reset itself the moment
        // someone next loads /tasks — it's already at its starting state.
        $data['last_reset_date'] = $data['repeat_daily'] ? $data['start_date'] : null;

        $task = WeeklyTask::create($data);
        $this->syncAssignees($task, $assignees, $this->assignableUsers($business_id));

        return redirect(action('TaskController@index'))
            ->with('status', ['success' => true, 'msg' => 'Task added.']);
    }

    public function edit($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $task = WeeklyTask::with('assignees')->where('business_id', $business_id)->findOrFail($id);
        $storeLabels = $this->availableStores();
        $priorityLabels = self::PRIORITY_LABELS;
        $assignableUsers = $this->assignableUsers($business_id);
        return view('tasks.edit', compact('task', 'storeLabels', 'priorityLabels', 'assignableUsers'));
    }

    public function update($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $task = WeeklyTask::where('business_id', $business_id)->findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'task_type' => 'required|in:daily,weekly',
            'repeat_daily' => 'nullable|boolean',
            'repeat_weekly' => 'nullable|boolean',
            'status' => 'required|in:not_started,in_progress,complete',
            'store' => 'nullable|in:' . implode(',', array_keys($this->availableStores())),
            'priority' => 'required|in:' . implode(',', array_keys(self::PRIORITY_LABELS)),
            'assignees' => 'nullable|array',
            'assignees.*' => 'integer',
        ]);
        $assignees = $data['assignees'] ?? [];
        unset($data['assignees']);

        // Whether a task repeats is only editable on the root task — a
        // generated instance (repeat_of set) keeps whatever the root says,
        // so one day's/week's row can't quietly break the rest of the series.
        if ($task->repeat_of !== null) {
            unset($data['repeat_daily'], $data['repeat_weekly']);
        } else {
            $wasRepeatDaily = (bool) $task->repeat_daily;
            $data['repeat_daily'] = $data['task_type'] === 'daily' && !empty($data['repeat_daily']);
            $data['repeat_weekly'] = $data['task_type'] === 'weekly' && !empty($data['repeat_weekly']);
            if ($data['repeat_daily'] && !$wasRepeatDaily) {
                // Just turned on — don't reset it the moment someone next
                // loads /tasks; it's already at its starting state.
                $data['last_reset_date'] = $data['start_date'];
            }
        }

        $data['end_date'] = self::computeEndDate($data['task_type'], $data['start_date']);

        $this->applyStatusTransition($task, $data['status']);
        unset($data['status']);
        $task->fill($data)->save();
        $this->syncAssignees($task, $assignees, $this->assignableUsers($business_id));

        return redirect(action('TaskController@index'))
            ->with('status', ['success' => true, 'msg' => 'Task updated.']);
    }

    /** Quick status change from the dropdown on the list page. */
    public function updateStatus($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $task = WeeklyTask::where('business_id', $business_id)->findOrFail($id);

        $newStatus = $request->validate([
            'status' => 'required|in:not_started,in_progress,complete',
        ])['status'];

        $this->applyStatusTransition($task, $newStatus);
        $task->save();

        // The "Tasks due today" bubble (shown on the POS screen right after
        // register close) updates status inline via AJAX so a cashier isn't
        // bounced off the POS to /tasks mid-shift. The plain form on the
        // /tasks list page still gets the normal redirect.
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $task->status]);
        }

        return redirect(action('TaskController@index'))
            ->with('status', ['success' => true, 'msg' => 'Status updated.']);
    }

    public function destroy($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $task = WeeklyTask::where('business_id', $business_id)->findOrFail($id);

        // Delete only ever removes the one row clicked — it never takes
        // other days/instances with it. If this is a repeating root with
        // generated instances still pointing at it (repeat_of is a
        // restrict-on-delete FK, so the root can't go while they do),
        // detach them first: they survive as independent, standalone tasks
        // with their own history/status intact, no longer auto-generating
        // further copies since the root that was generating them is gone.
        WeeklyTask::where('repeat_of', $task->id)->update([
            'repeat_of' => null,
            'repeat_daily' => false,
            'repeat_weekly' => false,
        ]);

        $task->delete();

        // back() instead of a bare index redirect — keeps whatever
        // type/store/status/page filter you were looking at, so the list
        // you land on actually reflects what you just deleted instead of
        // resetting to an unfiltered page 1.
        return redirect()->back()
            ->with('status', ['success' => true, 'msg' => 'Task deleted.']);
    }

    /**
     * Sets status plus who-started/who-completed attribution based on the
     * transition being made. Moving into a state stamps the acting user;
     * moving back out of "complete" or "in_progress" clears that stamp so
     * the board never shows stale attribution for a state the task isn't in.
     */
    private function applyStatusTransition(WeeklyTask $task, string $newStatus)
    {
        $oldStatus = $task->status;

        if ($newStatus === 'in_progress' && $oldStatus !== 'in_progress') {
            $task->started_by = auth()->id();
            $task->started_at = now();
        }
        if ($newStatus === 'complete' && $oldStatus !== 'complete') {
            $task->completed_by = auth()->id();
            $task->completed_at = now();
        }
        if ($newStatus !== 'complete' && $oldStatus === 'complete') {
            $task->completed_by = null;
            $task->completed_at = null;
        }
        if ($newStatus === 'not_started' && $oldStatus !== 'not_started') {
            $task->started_by = null;
            $task->started_at = null;
        }

        $task->status = $newStatus;
    }
}
