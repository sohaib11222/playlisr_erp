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
     * For every repeating task (root, i.e. not itself a generated instance)
     * that has started, make sure it has an up-to-date instance: daily
     * repeats get a fresh row once "today" doesn't have one yet; weekly
     * repeats get a fresh row once 7+ days have passed since the last one.
     * Lazy: runs on whoever hits /tasks or closes a register first, rather
     * than a cron. Idempotent — safe to call from multiple places/requests.
     *
     * A generated instance keeps its own status/attribution, same as any
     * other task, so history — who did what, which day/week — stays intact
     * instead of being silently reset. This only ever adds new rows, never
     * rewrites old ones.
     */
    private static function rolloverRepeatingTasks($business_id)
    {
        self::rolloverRepeats($business_id, 'daily', 'repeat_daily');
        self::rolloverRepeats($business_id, 'weekly', 'repeat_weekly');
    }

    private static function rolloverRepeats($business_id, $taskType, $repeatColumn)
    {
        $today = \Carbon\Carbon::today();

        $roots = WeeklyTask::with('assignees')
            ->where('business_id', $business_id)
            ->where('task_type', $taskType)
            ->where($repeatColumn, true)
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

            if ($taskType === 'daily') {
                if ($lastStart->isSameDay($today)) {
                    continue;
                }
                $newStart = $today->toDateString();
            } else {
                if ($lastStart->copy()->addDays(7)->gt($today)) {
                    continue;
                }
                $newStart = $today->toDateString();
            }

            try {
                $instance = WeeklyTask::create([
                    'business_id' => $business_id,
                    'title' => $root->title,
                    'description' => $root->description,
                    'start_date' => $newStart,
                    'end_date' => self::computeEndDate($taskType, $newStart),
                    'task_type' => $taskType,
                    'store' => $root->store,
                    'priority' => $root->priority,
                    'status' => 'not_started',
                    'created_by' => $root->created_by,
                    'repeat_daily' => $taskType === 'daily',
                    'repeat_weekly' => $taskType === 'weekly',
                    'repeat_of' => $root->id,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Unique (repeat_of, start_date) constraint — an overlapping
                // request (two /tasks loads, or a register close racing a
                // page load) already created this instance a moment ago.
                // That row is the one that counts; nothing to do here.
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
     */
    public static function dueTodayForStore($business_id, $store)
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
            $data['repeat_daily'] = $data['task_type'] === 'daily' && !empty($data['repeat_daily']);
            $data['repeat_weekly'] = $data['task_type'] === 'weekly' && !empty($data['repeat_weekly']);
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

        // Deleting a repeating root takes the whole series with it — every
        // instance it generated goes too (delete children first: repeat_of
        // is a restrict-on-delete FK, so the root can't go while instances
        // still point at it). Deleting a single generated instance (or a
        // non-repeating task) only ever removes that one row.
        if ($task->repeat_of === null) {
            WeeklyTask::where('repeat_of', $task->id)->delete();
        }

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
