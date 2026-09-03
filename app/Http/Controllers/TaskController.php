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
    private function computeEndDate(string $taskType, string $startDate)
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

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

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
            'store' => 'nullable|in:' . implode(',', array_keys($this->availableStores())),
            'priority' => 'required|in:' . implode(',', array_keys(self::PRIORITY_LABELS)),
            'assignees' => 'nullable|array',
            'assignees.*' => 'integer',
        ]);
        $assignees = $data['assignees'] ?? [];
        unset($data['assignees']);

        $data['business_id'] = $business_id;
        $data['created_by'] = auth()->id();
        $data['status'] = 'not_started';
        $data['end_date'] = $this->computeEndDate($data['task_type'], $data['start_date']);

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
            'status' => 'required|in:not_started,in_progress,complete',
            'store' => 'nullable|in:' . implode(',', array_keys($this->availableStores())),
            'priority' => 'required|in:' . implode(',', array_keys(self::PRIORITY_LABELS)),
            'assignees' => 'nullable|array',
            'assignees.*' => 'integer',
        ]);
        $assignees = $data['assignees'] ?? [];
        unset($data['assignees']);

        $data['end_date'] = $this->computeEndDate($data['task_type'], $data['start_date']);

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

        return redirect(action('TaskController@index'))
            ->with('status', ['success' => true, 'msg' => 'Status updated.']);
    }

    public function destroy($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $task = WeeklyTask::where('business_id', $business_id)->findOrFail($id);
        $task->delete();

        return redirect(action('TaskController@index'))
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
