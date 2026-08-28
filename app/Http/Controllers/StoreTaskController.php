<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\User;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Employee Tasks — an Asana-style board managers use to hand out work that
 * changes day to day (organize a section, do a supply check, cover a
 * miscellaneous shift) plus recurring weekly routine tasks, instead of
 * relying on the opening/closing checklists to catch everything (per Sarah:
 * those are too long and the presentation/tidy items on them don't get done).
 *
 * Two kinds of task:
 *   - 'once'   — a one-off, due on an explicit date (usually today). A
 *                manager adds these as the day's needs come up.
 *   - 'weekly' — a recurring routine task, due each week on a chosen weekday
 *                (or the Sunday that ends the week if no weekday is set),
 *                same convention as ManagerChecklistController's uniform
 *                weekly bucket. Completion is tracked per week, so it resets.
 *
 * A task can be assigned to one employee (assigned_to_user_id) or left
 * unassigned — a shared to-do anyone on shift at that store can take.
 *
 * Access:
 *   - Admin/owner: sees + edits both stores, switches with ?store=.
 *   - Zakary (Pico) / Luis (Hollywood): sees + edits their own store only
 *     (reuses ManagerChecklistController::currentManagerKey()).
 *   - Everyone else: read-only view of their store's board, scoped to tasks
 *     assigned to them or unassigned — can check things off but can't
 *     create, edit, reassign, or delete.
 */
class StoreTaskController extends Controller
{
    const TASKS_TABLE       = 'employee_tasks';
    const COMPLETIONS_TABLE = 'employee_task_completions';

    const STORE_LABELS = [
        'pico'      => 'Pico',
        'hollywood' => 'Hollywood',
    ];

    const MANAGER_STORE = [
        'zakary' => 'pico',
        'luis'   => 'hollywood',
    ];

    private function businessUtil()
    {
        return app(BusinessUtil::class);
    }

    private function ready()
    {
        return Schema::hasTable(self::TASKS_TABLE) && Schema::hasTable(self::COMPLETIONS_TABLE);
    }

    /* ---------- role / store resolution ---------- */

    private function isAdmin()
    {
        $u = auth()->user();
        return $u && $this->businessUtil()->is_admin($u);
    }

    /** 'zakary' / 'luis' for the logged-in user, or null. */
    private function managerKey()
    {
        return ManagerChecklistController::currentManagerKey();
    }

    /** Can the current user create/edit/delete/assign tasks for $store? */
    private function canManage($store)
    {
        if ($this->isAdmin()) {
            return true;
        }
        $mk = $this->managerKey();
        return $mk && (self::MANAGER_STORE[$mk] ?? null) === $store;
    }

    /** Stores the current user can view a board for, admin/owner = both. */
    private function storesForUser()
    {
        if ($this->isAdmin()) {
            return self::STORE_LABELS;
        }
        return OpeningChecklistController::storesForUser();
    }

    private function resolveStore(Request $request)
    {
        $available = $this->storesForUser();
        $requested = strtolower(trim((string) $request->input('store', '')));
        if (isset($available[$requested])) {
            return $requested;
        }
        $mk = $this->managerKey();
        if ($mk && isset(self::MANAGER_STORE[$mk]) && isset($available[self::MANAGER_STORE[$mk]])) {
            return self::MANAGER_STORE[$mk];
        }
        return array_key_first($available) ?: OpeningChecklistController::defaultStoreForUser();
    }

    /** Active, login-enabled employees permitted at $store, for the assignee dropdown. */
    private function employeesForStore($businessId, $store)
    {
        $locationId = null;
        foreach (BusinessLocation::where('business_id', $businessId)->get() as $loc) {
            $needle = $store === 'pico' ? 'pico' : 'holly';
            if (stripos($loc->name, $needle) !== false) {
                $locationId = $loc->id;
                break;
            }
        }

        $users = User::where('business_id', $businessId)
            ->user()
            ->where('is_cmmsn_agnt', 0)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->get();

        if ($locationId === null) {
            return $users->map(function ($u) {
                return ['id' => $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)];
            })->values();
        }

        return $users->filter(function ($u) use ($locationId) {
            $perm = $u->permitted_locations();
            return $perm === 'all' || in_array($locationId, $perm, true);
        })->map(function ($u) {
            return ['id' => $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)];
        })->values();
    }

    /* ---------- period_key helpers (mirrors ManagerChecklistController) ---------- */

    private static function mondayOf($date)
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
    }

    private static function isValidYmd($s)
    {
        $d = \DateTime::createFromFormat('Y-m-d', (string) $s);
        return $d && $d->format('Y-m-d') === $s;
    }

    /** The period_key + due date a task instance is due for "today". */
    private static function currentInstance(array $task, $today)
    {
        if ($task['recurrence'] === 'once') {
            $due = $task['due_date'] ?: $today;
            return ['period_key' => 'D:' . $due, 'due_date' => $due];
        }

        $weekStart = self::mondayOf($today);
        if ($task['weekday']) {
            $due = Carbon::parse($weekStart)->addDays(((int) $task['weekday']) - 1)->format('Y-m-d');
        } else {
            $due = Carbon::parse($weekStart)->addDays(6)->format('Y-m-d');
        }
        return ['period_key' => 'W:' . $weekStart, 'due_date' => $due];
    }

    /* ---------- board data ---------- */

    private function buildBoard($businessId, $store, $today)
    {
        $tasks = DB::table(self::TASKS_TABLE)
            ->where('business_id', $businessId)
            ->where('store', $store)
            ->where('active', 1)
            ->orderBy('recurrence')
            ->orderBy('id')
            ->get();

        $periodKeys = [];
        $rows = [];
        foreach ($tasks as $t) {
            $arr = (array) $t;
            $inst = self::currentInstance($arr, $today);
            $rows[] = array_merge($arr, $inst);
            $periodKeys[] = $inst['period_key'] . '|' . $t->id;
        }

        $taskIds = array_map(function ($t) { return $t->id; }, $rows);
        $completions = [];
        if (!empty($taskIds)) {
            $rowsC = DB::table(self::COMPLETIONS_TABLE)
                ->whereIn('task_id', $taskIds)
                ->get();
            foreach ($rowsC as $c) {
                $completions[$c->task_id . '|' . $c->period_key] = $c;
            }
        }

        $assigneeIds = array_values(array_unique(array_filter(array_map(function ($t) {
            return $t['assigned_to_user_id'];
        }, $rows))));
        $assigneeNames = [];
        if (!empty($assigneeIds)) {
            foreach (User::whereIn('id', $assigneeIds)->select('id', 'first_name', 'last_name')->get() as $u) {
                $assigneeNames[$u->id] = trim($u->first_name . ' ' . $u->last_name);
            }
        }

        $out = [];
        foreach ($rows as $t) {
            $comp = $completions[$t['id'] . '|' . $t['period_key']] ?? null;
            $out[] = [
                'id'         => $t['id'],
                'title'      => $t['title'],
                'notes'      => $t['notes'],
                'recurrence' => $t['recurrence'],
                'weekday'    => $t['weekday'],
                'assigned_to_user_id' => $t['assigned_to_user_id'],
                'assignee_name'       => $t['assigned_to_user_id'] ? ($assigneeNames[$t['assigned_to_user_id']] ?? 'Former employee') : null,
                'period_key' => $t['period_key'],
                'due_date'   => $t['due_date'],
                'done'       => (bool) $comp,
                'done_by'    => $comp && $comp->completed_by_user_id ? ($assigneeNames[$comp->completed_by_user_id] ?? null) : null,
                'overdue'    => (!$comp && $t['due_date'] < $today),
            ];
        }

        usort($out, function ($a, $b) {
            if ($a['done'] !== $b['done']) {
                return $a['done'] ? 1 : -1;
            }
            return strcmp($a['due_date'], $b['due_date']);
        });

        return $out;
    }

    /* ---------- pages ---------- */

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $store      = $this->resolveStore($request);
        $today      = date('Y-m-d');
        $canManage  = $this->canManage($store);
        $userId     = auth()->id();

        if (!$this->ready()) {
            return view('store_tasks.index', ['notReady' => true, 'store' => $store, 'storeLabel' => self::STORE_LABELS[$store] ?? $store]);
        }

        $tasks = $this->buildBoard($businessId, $store, $today);

        if (!$canManage) {
            $tasks = array_values(array_filter($tasks, function ($t) use ($userId) {
                return $t['assigned_to_user_id'] === null || $t['assigned_to_user_id'] === $userId;
            }));
        }

        return view('store_tasks.index', [
            'notReady'   => false,
            'store'      => $store,
            'storeLabel' => self::STORE_LABELS[$store] ?? $store,
            'stores'     => $this->storesForUser(),
            'canManage'  => $canManage,
            'tasks'      => $tasks,
            'employees'  => $canManage ? $this->employeesForStore($businessId, $store) : [],
            'userId'     => $userId,
        ]);
    }

    /* ---------- mutations ---------- */

    public function store(Request $request)
    {
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $store      = strtolower(trim((string) $request->input('store', '')));
        if (!isset(self::STORE_LABELS[$store]) || !$this->canManage($store)) {
            abort(403);
        }
        if (!$this->ready()) {
            return response()->json(['ok' => false, 'msg' => 'Employee Tasks table not migrated yet.'], 503);
        }

        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            return response()->json(['ok' => false, 'msg' => 'Task needs a title.'], 422);
        }

        $recurrence = $request->input('recurrence') === 'weekly' ? 'weekly' : 'once';
        $assignee   = $request->input('assigned_to_user_id');
        $assignee   = ($assignee !== null && $assignee !== '') ? (int) $assignee : null;

        $weekday = null;
        $dueDate = null;
        if ($recurrence === 'weekly') {
            $wd = (int) $request->input('weekday', 0);
            $weekday = ($wd >= 1 && $wd <= 7) ? $wd : null;
        } else {
            $dueDate = self::isValidYmd($request->input('due_date', '')) ? $request->input('due_date') : date('Y-m-d');
        }

        $id = DB::table(self::TASKS_TABLE)->insertGetId([
            'business_id'          => $businessId,
            'store'                => $store,
            'title'                => mb_substr($title, 0, 200),
            'notes'                => mb_substr(trim((string) $request->input('notes', '')), 0, 2000) ?: null,
            'assigned_to_user_id'  => $assignee,
            'created_by_user_id'   => auth()->id(),
            'recurrence'           => $recurrence,
            'weekday'              => $weekday,
            'due_date'             => $dueDate,
            'active'               => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function loadManageableTask($id, $businessId)
    {
        $task = DB::table(self::TASKS_TABLE)->where('id', $id)->where('business_id', $businessId)->first();
        if (!$task || !$this->canManage($task->store)) {
            return null;
        }
        return $task;
    }

    public function update(Request $request)
    {
        if (!$this->ready()) {
            return response()->json(['ok' => false, 'msg' => 'Not migrated yet.'], 503);
        }
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $task = $this->loadManageableTask((int) $request->input('id'), $businessId);
        if (!$task) {
            abort(403);
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        if ($request->has('title')) {
            $title = trim((string) $request->input('title', ''));
            if ($title === '') {
                return response()->json(['ok' => false, 'msg' => 'Task needs a title.'], 422);
            }
            $updates['title'] = mb_substr($title, 0, 200);
        }
        if ($request->has('notes')) {
            $updates['notes'] = mb_substr(trim((string) $request->input('notes', '')), 0, 2000) ?: null;
        }
        if ($request->has('assigned_to_user_id')) {
            $assignee = $request->input('assigned_to_user_id');
            $updates['assigned_to_user_id'] = ($assignee !== null && $assignee !== '') ? (int) $assignee : null;
        }

        DB::table(self::TASKS_TABLE)->where('id', $task->id)->update($updates);
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        if (!$this->ready()) {
            return response()->json(['ok' => false, 'msg' => 'Not migrated yet.'], 503);
        }
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $task = $this->loadManageableTask((int) $request->input('id'), $businessId);
        if (!$task) {
            abort(403);
        }

        // Archive, don't hard-delete — keeps completion history intact.
        DB::table(self::TASKS_TABLE)->where('id', $task->id)->update([
            'active' => 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return response()->json(['ok' => true]);
    }

    /**
     * Toggle one task instance done/not-done. Anyone who can see the task
     * (assigned to them, or unassigned) can check it off; managers/admin can
     * toggle anything on their store's board.
     */
    public function toggle(Request $request)
    {
        if (!$this->ready()) {
            return response()->json(['ok' => false, 'msg' => 'Not migrated yet.'], 503);
        }
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $taskId     = (int) $request->input('id');
        $periodKey  = (string) $request->input('period_key', '');

        $task = DB::table(self::TASKS_TABLE)->where('id', $taskId)->where('business_id', $businessId)->first();
        if (!$task) {
            return response()->json(['ok' => false, 'msg' => 'Unknown task.'], 404);
        }

        $userId = auth()->id();
        $allowed = $this->canManage($task->store)
            || $task->assigned_to_user_id === null
            || (int) $task->assigned_to_user_id === (int) $userId;
        if (!$allowed) {
            return response()->json(['ok' => false, 'msg' => 'Not your task.'], 403);
        }

        // Validate period_key matches what this task is currently due for -
        // don't trust an arbitrary client-supplied key.
        $today = date('Y-m-d');
        $inst  = self::currentInstance((array) $task, $today);
        if ($periodKey !== $inst['period_key']) {
            return response()->json(['ok' => false, 'msg' => 'Stale task instance - reload the page.'], 422);
        }

        $on = filter_var($request->input('checked'), FILTER_VALIDATE_BOOLEAN);

        if ($on) {
            DB::table(self::COMPLETIONS_TABLE)->updateOrInsert(
                ['task_id' => $taskId, 'period_key' => $periodKey],
                [
                    'business_id'          => $businessId,
                    'completed_by_user_id' => $userId,
                    'completed_at'         => date('Y-m-d H:i:s'),
                    'updated_at'           => date('Y-m-d H:i:s'),
                    'created_at'           => date('Y-m-d H:i:s'),
                ]
            );
        } else {
            DB::table(self::COMPLETIONS_TABLE)
                ->where('task_id', $taskId)
                ->where('period_key', $periodKey)
                ->delete();
        }

        return response()->json(['ok' => true]);
    }
}
