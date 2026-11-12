<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Manager Check-ins — a short form the store managers (Luis at Hollywood,
 * Zakary at Pico) fill out after each weekly 1:1 with an employee, so Jon gets
 * a report back on every person: overall rating, highlight, lowlight, ERP
 * updates they asked for, and good ideas worth implementing.
 *
 * Access:
 *   - Managers (matched by first name, same rule as ManagerChecklistController)
 *     can submit and see their own past check-ins.
 *   - Admins (Jon, Sarah) can submit too and see everyone's, filterable by
 *     employee or manager.
 *
 * No migration: storage/app/manager_checkins.json, newest first.
 */
class ManagerCheckinController extends Controller
{
    const STORE_PATH = 'manager_checkins.json';

    // Jon's running notes for his own weekly check-in with each manager
    // (Sarah 2026-11-12): fixes from the check-ins, things to follow up on.
    // One text per store, keyed "<business_id>:<store>". Admins edit, the
    // store's manager reads it at the top of this page.
    const NOTES_PATH = 'manager_jon_notes.json';

    // Updates for one employee that the manager should pass on at that
    // person's next check-in (Sarah 2026-11-12). Admins add them; the manager
    // ticks each one off in the check-in form, which records when and in
    // which check-in it was shared.
    const UPDATES_PATH = 'manager_checkin_updates.json';

    // Jon's own weekly 1:1 with each manager (Sarah 2026-11-12): Zak Mondays
    // 11am, Luis Mondays 12pm. Jon's notes above are the agenda; each 1:1 he
    // logs here (date + what was talked about / decided), newest first.
    const ONE_ON_ONES_PATH = 'manager_one_on_ones.json';
    const ONE_ON_ONE_TIME = ['pico' => 'Mondays 11am', 'hw' => 'Mondays 12pm'];

    const STORES = ['hw' => 'Hollywood', 'pico' => 'Pico'];

    // Not floor staff, never in the employee dropdown (Sarah 2026-09-25): gone
    // (Abby, Clark), freelancers/contractors (Chris, Fahrul, Insha, Viper,
    // "Freelancer"), HR (Fatteen = "Nerdy Solutions"), owners (Sarah, Jon).
    // Matched against lowercase first name or any word of the full name.
    const EXCLUDE = ['abby', 'chris', 'clark', 'fahrul', 'fatteen', 'insha', 'nerdy', 'viper', 'freelancer', 'sarah', 'jon', 'jonathan'];

    // Managers only ever show under their own store, even if Sling has them
    // covering a shift at the other one.
    const HOME_STORE = ['luis' => 'hw', 'zakary' => 'pico'];

    // About their week, not a grade on the person. Key 'needs_work' kept so
    // older entries still read.
    const RATINGS = ['great' => 'Great', 'good' => 'Good', 'needs_work' => 'Rough week'];

    // Text questions, in form order. Key => [label, hint]. Framed around what
    // the employee is contributing and what they need, not a performance review.
    const QUESTIONS = [
        'projects'  => ['Important projects they are working on', 'What they are working on and a quick summary of progress'],
        'highlight' => ['Highlights', 'Wins from their week, big or small'],
        'ideas'     => ['Good ideas we should implement', 'Anything they would change or try in the store'],
        'erp'       => ['ERP updates they asked for', 'What would make their job easier in the ERP or POS'],
        'lowlight'  => ['Lowlights or blockers', 'Anything getting in their way or frustrating them'],
        'manager_notes' => ['Comments from manager to Jon', 'Your own take for Jon, optional'],
    ];

    private function isAdmin()
    {
        $u = auth()->user();
        return $u && app(BusinessUtil::class)->is_admin($u);
    }

    private function guard()
    {
        if (!$this->isAdmin() && !ManagerChecklistController::currentManagerKey()) {
            abort(403, 'Manager check-ins are for managers and admins only.');
        }
    }

    private static function load()
    {
        if (!Storage::exists(self::STORE_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::STORE_PATH), true);
        return is_array($data) ? $data : [];
    }

    private static function save(array $items)
    {
        Storage::put(self::STORE_PATH, json_encode(array_values($items), JSON_PRETTY_PRINT));
    }

    private static function loadNotes()
    {
        if (!Storage::exists(self::NOTES_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::NOTES_PATH), true);
        return is_array($data) ? $data : [];
    }

    private static function loadUpdates()
    {
        if (!Storage::exists(self::UPDATES_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::UPDATES_PATH), true);
        return is_array($data) ? $data : [];
    }

    private static function saveUpdates(array $items)
    {
        Storage::put(self::UPDATES_PATH, json_encode(array_values($items), JSON_PRETTY_PRINT));
    }

    /** Admins add an update for one employee. */
    public function addUpdate(Request $request)
    {
        if (!$this->isAdmin()) {
            abort(403, 'Only Jon and Sarah can add updates.');
        }
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $store      = $this->currentStore($request);
        $employeeId = (int) $request->input('employee_id');
        $employee   = $this->employees($businessId, $store)->first(function ($u) use ($employeeId) {
            return (int) $u->id === $employeeId;
        });
        $text = mb_substr(trim((string) $request->input('text')), 0, 1000);
        if (!$employee || $text === '') {
            return redirect()->action('ManagerCheckinController@index', ['store' => $store])
                ->with('status', ['success' => 0, 'msg' => 'Pick the employee and type the update.']);
        }
        $me    = auth()->user();
        $items = self::loadUpdates();
        $items[] = [
            'id'            => uniqid('up_'),
            'business_id'   => (int) $businessId,
            'store'         => $store,
            'employee_id'   => $employeeId,
            'employee_name' => trim($employee->first_name . ' ' . $employee->last_name),
            'text'          => $text,
            'created_at'    => date('Y-m-d H:i:s'),
            'created_by'    => trim($me->first_name . ' ' . $me->last_name),
            'shared_at'     => null,
            'shared_by'     => null,
            'checkin_id'    => null,
        ];
        self::saveUpdates($items);

        return redirect()->to(url('/manager-checkins') . '?store=' . $store . '#ci-updates')
            ->with('status', ['success' => 1, 'msg' => 'Added an update for ' . trim($employee->first_name . ' ' . $employee->last_name) . '.']);
    }

    private static function loadOneOnOnes()
    {
        if (!Storage::exists(self::ONE_ON_ONES_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::ONE_ON_ONES_PATH), true);
        return is_array($data) ? $data : [];
    }

    /** Admins log one of Jon's 1:1s with a store's manager. */
    public function saveOneOnOne(Request $request)
    {
        if (!$this->isAdmin()) {
            abort(403, 'Only Jon and Sarah can log 1:1s.');
        }
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $store      = $this->currentStore($request);
        $date       = (string) $request->input('date');
        $text       = mb_substr(trim((string) $request->input('text')), 0, 5000);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $text === '') {
            return redirect()->to(url('/manager-checkins') . '?store=' . $store . '#ci-1on1')
                ->with('status', ['success' => 0, 'msg' => 'Pick the date and write what you talked about.']);
        }
        $me    = auth()->user();
        $items = self::loadOneOnOnes();
        array_unshift($items, [
            'id'          => uniqid('oo_'),
            'business_id' => (int) $businessId,
            'store'       => $store,
            'date'        => $date,
            'text'        => $text,
            'created_by'  => trim($me->first_name . ' ' . $me->last_name),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        Storage::put(self::ONE_ON_ONES_PATH, json_encode(array_values($items), JSON_PRETTY_PRINT));

        return redirect()->to(url('/manager-checkins') . '?store=' . $store . '#ci-1on1')
            ->with('status', ['success' => 1, 'msg' => 'Saved the 1:1 from ' . date('m/d/y', strtotime($date)) . '.']);
    }

    /** Admins delete a check-in (a test or a mistake); its shared updates go back to "To share". */
    public function deleteCheckin(Request $request)
    {
        if (!$this->isAdmin()) {
            abort(403);
        }
        $store = $this->currentStore($request);
        $id    = (string) $request->input('id');
        self::save(array_filter(self::load(), function ($r) use ($id) {
            return ($r['id'] ?? '') !== $id;
        }));
        $updates = self::loadUpdates();
        foreach ($updates as &$u) {
            if (($u['checkin_id'] ?? '') === $id) {
                $u['shared_at'] = null;
                $u['shared_by'] = null;
                $u['checkin_id'] = null;
            }
        }
        unset($u);
        self::saveUpdates($updates);

        return redirect()->to(url('/manager-checkins') . '?store=' . $store)
            ->with('status', ['success' => 1, 'msg' => 'Deleted the check-in.']);
    }

    /** Admins remove an update (added by mistake or no longer needed). */
    public function deleteUpdate(Request $request)
    {
        if (!$this->isAdmin()) {
            abort(403);
        }
        $store = $this->currentStore($request);
        $id    = (string) $request->input('id');
        $items = array_filter(self::loadUpdates(), function ($u) use ($id) {
            return ($u['id'] ?? '') !== $id;
        });
        self::saveUpdates($items);

        return redirect()->to(url('/manager-checkins') . '?store=' . $store . '#ci-updates')
            ->with('status', ['success' => 1, 'msg' => 'Removed the update.']);
    }

    /** Manager's home store (hw / pico) or null for admins and everyone else. */
    private static function managerHomeStore()
    {
        $key = ManagerChecklistController::currentManagerKey();
        return $key ? (self::HOME_STORE[$key] ?? null) : null;
    }

    /** Admins save Jon's notes for one store's manager. */
    public function saveNotes(Request $request)
    {
        if (!$this->isAdmin()) {
            abort(403, 'Only Jon and Sarah can edit these notes.');
        }
        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $store      = $this->currentStore($request);
        $me         = auth()->user();

        $notes = self::loadNotes();
        $notes[$businessId . ':' . $store] = [
            'text'       => mb_substr(trim((string) $request->input('notes')), 0, 20000),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => trim($me->first_name . ' ' . $me->last_name),
        ];
        Storage::put(self::NOTES_PATH, json_encode($notes, JSON_PRETTY_PRINT));

        return redirect()->action('ManagerCheckinController@index', ['store' => $store])
            ->with('status', ['success' => 1, 'msg' => 'Saved notes for ' . self::STORES[$store] . '.']);
    }

    /** Store picked by the toggle; defaults to the manager's own store (Zakary = Pico, everyone else = Hollywood). */
    private function currentStore(Request $request)
    {
        $store = (string) $request->input('store');
        if (isset(self::STORES[$store])) {
            return $store;
        }
        return ManagerChecklistController::currentManagerKey() === 'zakary' ? 'pico' : 'hw';
    }

    /**
     * Active staff logins for the employee dropdown, limited to people with a
     * Sling shift at that store in the last 30 days or the synced weeks ahead
     * (sling_shifts.location_name). Falls back to everyone if Sling isn't synced.
     */
    private function employees($businessId, $store = null)
    {
        $ids = null;
        if ($store && Schema::hasTable('sling_shifts')) {
            $ids = DB::table('sling_shifts')
                ->where('event_type', 'shift')
                ->whereNotNull('erp_user_id')
                ->where('location_name', 'like', $store === 'pico' ? '%pico%' : '%hollywood%')
                ->whereDate('dtstart', '>=', date('Y-m-d', strtotime('-30 days')))
                ->distinct()
                ->pluck('erp_user_id')
                ->all();
            if (empty($ids)) {
                $ids = null;
            }
        }

        return DB::table('users')
            ->when($ids !== null, function ($q) use ($ids) {
                return $q->whereIn('id', $ids);
            })
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->where(function ($q) {
                $q->whereNull('user_type')->orWhere('user_type', 'user');
            })
            ->orderBy('first_name')
            ->select('id', 'first_name', 'last_name')
            ->get()
            ->filter(function ($u) use ($store) {
                $home = self::HOME_STORE[strtolower(trim((string) $u->first_name))] ?? null;
                if ($store && $home && $home !== $store) {
                    return false;
                }
                $words = preg_split('/[^a-z]+/', strtolower(trim($u->first_name . ' ' . $u->last_name)));
                return !array_intersect($words, self::EXCLUDE);
            })
            ->values();
    }

    /**
     * Open one-off tasks, projects, and things finished in the last 14 days
     * for each employee, keyed by user id, so the form can show them when the
     * manager picks someone. Repeating chores (daily/weekly repeats) are left
     * out: this is about their projects, not routine duties.
     */
    private function workFor($businessId, array $userIds)
    {
        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = ['tasks' => [], 'projects' => [], 'done' => []];
        }
        if (empty($userIds)) {
            return $out;
        }
        $since = date('Y-m-d H:i:s', strtotime('-14 days'));

        if (Schema::hasTable('weekly_tasks') && Schema::hasTable('task_assignees')) {
            $rows = DB::table('weekly_tasks as t')
                ->join('task_assignees as a', 'a.task_id', '=', 't.id')
                ->where('t.business_id', $businessId)
                ->whereIn('a.user_id', $userIds)
                ->where('t.repeat_daily', 0)
                ->where('t.repeat_weekly', 0)
                ->whereNull('t.repeat_of')
                ->where(function ($q) use ($since) {
                    $q->where('t.status', '!=', 'complete')->orWhere('t.completed_at', '>=', $since);
                })
                ->orderBy('t.created_at', 'desc')
                ->select('a.user_id', 't.id', 't.title', 't.status')
                ->get();
            foreach ($rows as $r) {
                $item = ['title' => $r->title, 'status' => $r->status, 'url' => url('/tasks/' . $r->id . '/edit')];
                $out[$r->user_id][$r->status === 'complete' ? 'done' : 'tasks'][] = $item;
            }
        }

        if (Schema::hasTable('projects')) {
            foreach (['project_assignees', 'project_contributors'] as $pivot) {
                if (!Schema::hasTable($pivot)) {
                    continue;
                }
                $rows = DB::table('projects as p')
                    ->join($pivot . ' as a', 'a.project_id', '=', 'p.id')
                    ->where('p.business_id', $businessId)
                    ->whereIn('a.user_id', $userIds)
                    ->where(function ($q) use ($since) {
                        $q->where('p.status', '!=', 'complete')->orWhere('p.completed_at', '>=', $since);
                    })
                    ->select('a.user_id', 'p.id', 'p.title', 'p.status')
                    ->get();
                foreach ($rows as $r) {
                    $item = ['title' => $r->title, 'status' => $r->status, 'url' => url('/tasks/projects/' . $r->id . '/edit'), 'project' => true];
                    $bucket = $r->status === 'complete' ? 'done' : 'projects';
                    if (!in_array($item, $out[$r->user_id][$bucket], true)) {
                        $out[$r->user_id][$bucket][] = $item;
                    }
                }
            }
        }
        return $out;
    }

    /** ERP asks go to Sarah (she builds all ERP fixes); falls back to the employee. */
    private function erpOwnerId($businessId)
    {
        return DB::table('users')
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', ['sarah'])
            ->where('status', 'active')
            ->value('id');
    }

    public function index(Request $request)
    {
        $this->guard();

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $isAdmin    = $this->isAdmin();

        $rows = array_filter(self::load(), function ($r) use ($businessId, $isAdmin) {
            if ((int) ($r['business_id'] ?? 0) !== (int) $businessId) {
                return false;
            }
            return $isAdmin || (int) ($r['manager_id'] ?? 0) === (int) auth()->id();
        });

        $store = $this->currentStore($request);
        $rows  = array_filter($rows, function ($r) use ($store) {
            return ($r['store'] ?? $store) === $store;
        });

        $filterEmployee = (int) $request->input('employee_id');
        if ($filterEmployee) {
            $rows = array_filter($rows, function ($r) use ($filterEmployee) {
                return (int) ($r['employee_id'] ?? 0) === $filterEmployee;
            });
        }

        $employees = $this->employees($businessId, $store);

        // Per-employee updates for this store: open ones by employee id (shown
        // in the form when that person is picked) and the full list for the card.
        $updates = array_values(array_filter(self::loadUpdates(), function ($u) use ($businessId, $store) {
            return (int) ($u['business_id'] ?? 0) === (int) $businessId && ($u['store'] ?? '') === $store;
        }));
        $openUpdates = [];
        foreach ($updates as $u) {
            if (empty($u['shared_at'])) {
                $openUpdates[$u['employee_id']][] = ['id' => $u['id'], 'text' => $u['text']];
            }
        }
        $sharedByCheckin = [];
        foreach ($updates as $u) {
            if (!empty($u['checkin_id'])) {
                $sharedByCheckin[$u['checkin_id']][] = $u['text'];
            }
        }

        // A manager only sees the notes for his own store.
        $showNotes = $isAdmin || self::managerHomeStore() === $store;
        $jonNotes  = $showNotes ? (self::loadNotes()[$businessId . ':' . $store] ?? null) : null;
        $oneOnOnes = $showNotes ? array_values(array_filter(self::loadOneOnOnes(), function ($o) use ($businessId, $store) {
            return (int) ($o['business_id'] ?? 0) === (int) $businessId && ($o['store'] ?? '') === $store;
        })) : [];
        usort($oneOnOnes, function ($a, $b) { return strcmp($b['date'], $a['date']); });

        return view('manager_checkin.index', [
            'employees'      => $employees,
            'work'           => $this->workFor($businessId, $employees->pluck('id')->all()),
            'erpOwnerId'     => $this->erpOwnerId($businessId),
            'rows'           => array_values($rows),
            'isAdmin'        => $isAdmin,
            'store'          => $store,
            'stores'         => self::STORES,
            'filterEmployee' => $filterEmployee,
            'ratings'        => self::RATINGS,
            'questions'      => self::QUESTIONS,
            'showNotes'      => $showNotes,
            'jonNotes'       => $jonNotes,
            'notesManager'   => $store === 'pico' ? 'Zak' : 'Luis',
            'updates'        => $updates,
            'oneOnOnes'      => $oneOnOnes,
            'oneOnOneTime'   => self::ONE_ON_ONE_TIME[$store] ?? '',
            'openUpdates'    => $openUpdates,
            'sharedByCheckin' => $sharedByCheckin,
        ]);
    }

    public function store(Request $request)
    {
        $this->guard();

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $store      = $this->currentStore($request);
        $employeeId = (int) $request->input('employee_id');
        $employee   = $this->employees($businessId, $store)->first(function ($u) use ($employeeId) {
            return (int) $u->id === $employeeId;
        });
        $rating     = (string) $request->input('rating');
        $date       = (string) $request->input('date');

        if (!$employee || !isset(self::RATINGS[$rating]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return redirect()->action('ManagerCheckinController@index', ['store' => $store])
                ->withInput()
                ->with('status', ['success' => 0, 'msg' => 'Pick the employee, date and how they are doing.']);
        }

        $me    = auth()->user();
        $entry = [
            'id'            => uniqid('ci_'),
            'business_id'   => (int) $businessId,
            'store'         => $store,
            'date'          => $date,
            'employee_id'   => $employeeId,
            'employee_name' => trim($employee->first_name . ' ' . $employee->last_name),
            'manager_id'    => (int) $me->id,
            'manager_name'  => trim($me->first_name . ' ' . $me->last_name),
            'rating'        => $rating,
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        foreach (array_keys(self::QUESTIONS) as $key) {
            $entry[$key] = mb_substr(trim((string) $request->input($key)), 0, 2000);
        }

        $items = self::load();
        array_unshift($items, $entry);
        self::save($items);

        // Updates the manager ticked as shared with this person.
        $told = array_map('strval', (array) $request->input('told', []));
        if ($told) {
            $updates = self::loadUpdates();
            foreach ($updates as &$u) {
                if (in_array($u['id'] ?? '', $told, true) && (int) ($u['employee_id'] ?? 0) === $employeeId && empty($u['shared_at'])) {
                    $u['shared_at']  = date('Y-m-d H:i:s');
                    $u['shared_by']  = $entry['manager_name'];
                    $u['checkin_id'] = $entry['id'];
                }
            }
            unset($u);
            self::saveUpdates($updates);
        }

        return redirect()->action('ManagerCheckinController@index', ['store' => $store])
            ->with('status', ['success' => 1, 'msg' => 'Saved check-in for ' . $entry['employee_name'] . '.']);
    }
}
