<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Manager Checklist — recurring duties for the two store managers (Zakary at
 * Pico, Luis at Hollywood), shown as a flat, Asana-style task list: every
 * recurring item is expanded into dated instances (one per day / week /
 * month) with an explicit due date, sorted soonest-first, with overdue
 * items flagged.
 *
 * The item list is fixed, plain PHP (DAILY/WEEKLY/MONTHLY below) — not
 * admin-editable, matching DailyChecklistController's GROUPS. What IS in the
 * DB is the completion records (manager_checklist_completions): one row per
 * item actually checked off for a given dated instance, keyed by period_key.
 *
 * period_key already encodes the exact instance:
 *   "D:2026-09-01" — that calendar day (due date = the date itself)
 *   "W:2026-08-31" — the Monday that starts that week (due date = the Sunday, +6 days)
 *   "M:2026-09"    — that calendar month (due date = the last day of the month)
 * So due dates are derived from period_key rather than needing a schema
 * change — see dueDateForPeriodKey() / the *Instances() generators below.
 *
 * Neither manager is a manager before MANAGERS_START (2026-09-01) — no task
 * instance may have a due date earlier than that, enforced both in the
 * generators (they skip anything earlier) and in toggle() (rejects any
 * period_key resolving to an earlier due date).
 *
 * Access:
 *   /manager-checklist            — a manager's own page. Only Zakary or Luis
 *                                    (matched by first name — see currentManagerKey())
 *                                    can open it, and each can only ever toggle
 *                                    their own rows (the toggle endpoint always
 *                                    writes against auth()->id(), never a
 *                                    posted user id).
 *   /admin/manager-checklists     — owner/admin-only summary of both managers'
 *                                    task lists (read-only) with overdue/done counts.
 */
class ManagerChecklistController extends Controller
{
    const TABLE = 'manager_checklist_completions';

    /**
     * Neither manager is a manager before this date. No task instance may
     * have a due date earlier than this — see the *Instances() generators.
     */
    const MANAGERS_START = '2026-09-01';

    /** Monday of the week containing MANAGERS_START — first valid weekly period_key. */
    const FIRST_WEEK_MONDAY = '2026-08-31';

    /** First valid monthly period_key (Y-m). */
    const FIRST_MONTH = '2026-09';

    /* How far back/forward from "today" to expand instances. Bounded window
     * so the list doesn't grow forever — overdue items stay visible for a
     * while, upcoming items give a preview, and MANAGERS_START/FIRST_WEEK_MONDAY/
     * FIRST_MONTH clip anything earlier than the manager start date. */
    const DAILY_LOOKBACK_DAYS   = 7;
    const DAILY_LOOKAHEAD_DAYS  = 7;
    const WEEKLY_LOOKBACK_WEEKS  = 4;
    const WEEKLY_LOOKAHEAD_WEEKS = 4;
    const MONTHLY_LOOKBACK_MONTHS  = 2;
    const MONTHLY_LOOKAHEAD_MONTHS = 2;

    /** Daily duties — one instance per calendar day. */
    const DAILY = [
        'sales_check'  => 'Sales check - where do we stand today',
        'open_close'   => 'Open/close checklist done',
        'new_arrivals' => 'New arrivals out',
        'floor_walk'   => 'Floor/theft walk',
    ];

    /** Weekly duties — one instance per week, due the Sunday that ends it. */
    const WEEKLY = [
        'team_1on1s'      => 'Team 1:1s (each employee)',
        'supplies_check'  => 'Supplies check',
        'inventory_check' => 'Inventory check',
        'training_review' => 'Training checklist review - where each hire stands',
        'merch_walk'      => 'Section/merchandising standards walk',
    ];

    /** Monthly duties — one instance per calendar month, due the last day. */
    const MONTHLY = [
        'sales_vs_goal'       => 'Sales vs. goal review',
        'shrink_loss'         => 'Shrink/loss review',
        'cash_close_accuracy' => 'Cash close accuracy review',
    ];

    /**
     * The two managers, matched by first name only (per Sarah: don't guess a
     * full last name for Luis — his ERP account is some "Luis ..."; matching
     * on first name is enough since there's only one Luis and one Zakary on
     * staff). "store" is just a display label for the admin summary.
     */
    const MANAGER_KEYS = [
        'zakary' => ['label' => 'Zakary Heimlich', 'store' => 'Pico'],
        'luis'   => ['label' => 'Luis', 'store' => 'Hollywood'],
    ];

    private function businessUtil()
    {
        return app(BusinessUtil::class);
    }

    /* ---------- groups ---------- */

    public static function groups()
    {
        return [
            'Daily'   => self::DAILY,
            'Weekly'  => self::WEEKLY,
            'Monthly' => self::MONTHLY,
        ];
    }

    public static function allKeys()
    {
        return array_merge(array_keys(self::DAILY), array_keys(self::WEEKLY), array_keys(self::MONTHLY));
    }

    /** Which fixed group ('Daily'/'Weekly'/'Monthly') owns this item key. */
    public static function groupFor($key)
    {
        if (isset(self::DAILY[$key])) { return 'Daily'; }
        if (isset(self::WEEKLY[$key])) { return 'Weekly'; }
        if (isset(self::MONTHLY[$key])) { return 'Monthly'; }
        return null;
    }

    /* ---------- instance generation (the flat, dated task list) ---------- */

    /** Daily instances in the display window, each ['period_key','due_date','period_note']. */
    private static function dailyInstances($today)
    {
        $out  = [];
        $base = Carbon::parse($today);
        for ($i = -self::DAILY_LOOKBACK_DAYS; $i <= self::DAILY_LOOKAHEAD_DAYS; $i++) {
            $d = $base->copy()->addDays($i)->format('Y-m-d');
            if ($d < self::MANAGERS_START) {
                continue;
            }
            $out[] = ['period_key' => 'D:' . $d, 'due_date' => $d, 'period_note' => null];
        }
        return $out;
    }

    /** Weekly instances in the display window. Monday-start weeks, due the Sunday. */
    private static function weeklyInstances($today)
    {
        $out           = [];
        $currentMonday = Carbon::parse($today)->startOfWeek(Carbon::MONDAY);
        for ($i = -self::WEEKLY_LOOKBACK_WEEKS; $i <= self::WEEKLY_LOOKAHEAD_WEEKS; $i++) {
            $mon    = $currentMonday->copy()->addWeeks($i);
            $monStr = $mon->format('Y-m-d');
            if ($monStr < self::FIRST_WEEK_MONDAY) {
                continue;
            }
            $due    = $mon->copy()->addDays(6)->format('Y-m-d');
            $out[]  = [
                'period_key'  => 'W:' . $monStr,
                'due_date'    => $due,
                'period_note' => 'week of ' . $mon->format('M j'),
            ];
        }
        return $out;
    }

    /** Monthly instances in the display window. Due the last day of the month. */
    private static function monthlyInstances($today)
    {
        $out          = [];
        $currentMonth = Carbon::parse($today)->startOfMonth();
        for ($i = -self::MONTHLY_LOOKBACK_MONTHS; $i <= self::MONTHLY_LOOKAHEAD_MONTHS; $i++) {
            $m  = $currentMonth->copy()->addMonths($i);
            $ym = $m->format('Y-m');
            if ($ym < self::FIRST_MONTH) {
                continue;
            }
            $due   = $m->copy()->endOfMonth()->format('Y-m-d');
            $out[] = [
                'period_key'  => 'M:' . $ym,
                'due_date'    => $due,
                'period_note' => $m->format('F Y'),
            ];
        }
        return $out;
    }

    private static function instancesFor($group, $today)
    {
        if ($group === 'Daily') { return self::dailyInstances($today); }
        if ($group === 'Weekly') { return self::weeklyInstances($today); }
        if ($group === 'Monthly') { return self::monthlyInstances($today); }
        return [];
    }

    /** Due date (Y-m-d) a given period_key resolves to, or null if malformed. */
    private static function dueDateForPeriodKey($periodKey)
    {
        if (strpos($periodKey, 'D:') === 0) {
            $d = substr($periodKey, 2);
            return self::isValidYmd($d) ? $d : null;
        }
        if (strpos($periodKey, 'W:') === 0) {
            $mon = substr($periodKey, 2);
            if (!self::isValidYmd($mon)) {
                return null;
            }
            return Carbon::parse($mon)->addDays(6)->format('Y-m-d');
        }
        if (strpos($periodKey, 'M:') === 0) {
            $ym = substr($periodKey, 2);
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                return null;
            }
            return Carbon::parse($ym . '-01')->endOfMonth()->format('Y-m-d');
        }
        return null;
    }

    private static function isValidYmd($s)
    {
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }

    /**
     * True if $periodKey is a well-formed, in-bounds instance for $itemKey —
     * right frequency prefix, real calendar date/Monday/month, and not
     * earlier than the manager start date. Used to validate toggle() input
     * without trusting anything the client sends.
     */
    private static function periodKeyValidForItem($itemKey, $periodKey)
    {
        $group = self::groupFor($itemKey);
        if (!$group) {
            return false;
        }
        $prefix = ['Daily' => 'D:', 'Weekly' => 'W:', 'Monthly' => 'M:'][$group];
        if (strpos($periodKey, $prefix) !== 0) {
            return false;
        }

        if ($group === 'Weekly') {
            $mon = substr($periodKey, 2);
            if (!self::isValidYmd($mon) || (int) date('N', strtotime($mon)) !== 1) {
                return false;
            }
            return $mon >= self::FIRST_WEEK_MONDAY;
        }

        if ($group === 'Monthly') {
            $ym = substr($periodKey, 2);
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                return false;
            }
            return $ym >= self::FIRST_MONTH;
        }

        // Daily
        $d = substr($periodKey, 2);
        if (!self::isValidYmd($d)) {
            return false;
        }
        return $d >= self::MANAGERS_START;
    }

    /* ---------- access ---------- */

    /** 'zakary' / 'luis' for the logged-in user, or null if they're neither. */
    public static function currentManagerKey()
    {
        $u = auth()->user();
        if (!$u) {
            return null;
        }
        $first = strtolower(trim((string) $u->first_name));
        return isset(self::MANAGER_KEYS[$first]) ? $first : null;
    }

    private function guardManager()
    {
        if (!self::currentManagerKey()) {
            abort(403, 'This checklist is for Zakary and Luis only.');
        }
    }

    private function guardAdmin()
    {
        $u = auth()->user();
        if (!$u || !$this->businessUtil()->is_admin($u)) {
            abort(403, 'Manager Checklist summary is owner/admin-only.');
        }
    }

    /* ---------- storage ---------- */

    /** True once the migration has actually been run (see class doc). */
    private function ready()
    {
        return Schema::hasTable(self::TABLE);
    }

    /** Set of "item_key|period_key" pairs already completed by this user, for the given period_keys. */
    private function completedPairs($businessId, $userId, array $periodKeys)
    {
        if (!$this->ready() || empty($periodKeys)) {
            return [];
        }
        $rows = DB::table(self::TABLE)
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereIn('period_key', array_values(array_unique($periodKeys)))
            ->select('item_key', 'period_key')
            ->get();

        $set = [];
        foreach ($rows as $r) {
            $set[$r->item_key . '|' . $r->period_key] = true;
        }
        return $set;
    }

    /** Look up a manager's real user row from `users` by first name (no guessed ids). */
    private function managerUser($businessId, $key)
    {
        return DB::table('users')
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$key])
            ->select('id', 'first_name', 'last_name')
            ->first();
    }

    /**
     * Build the flat, due-date-sorted task list for one user: every recurring
     * item expanded into its dated instances within the display window, each
     * with done/overdue flags. Sorted by due date ascending, then Daily
     * before Weekly before Monthly, then item key.
     */
    private function buildTaskList($businessId, $userId, $today = null)
    {
        $today = $today ?: date('Y-m-d');

        $instancesByGroup = [];
        $periodKeys       = [];
        foreach (array_keys(self::groups()) as $groupName) {
            $instancesByGroup[$groupName] = self::instancesFor($groupName, $today);
            foreach ($instancesByGroup[$groupName] as $inst) {
                $periodKeys[] = $inst['period_key'];
            }
        }

        $done = $userId ? $this->completedPairs($businessId, $userId, $periodKeys) : [];

        $rows = [];
        foreach (self::groups() as $groupName => $items) {
            foreach ($instancesByGroup[$groupName] as $inst) {
                foreach ($items as $itemKey => $label) {
                    $isDone = isset($done[$itemKey . '|' . $inst['period_key']]);
                    $rows[] = [
                        'key'         => $itemKey,
                        'label'       => $label,
                        'freq'        => $groupName,
                        'period_key'  => $inst['period_key'],
                        'period_note' => $inst['period_note'],
                        'due_date'    => $inst['due_date'],
                        'done'        => $isDone,
                        'overdue'     => (!$isDone && $inst['due_date'] < $today),
                    ];
                }
            }
        }

        $freqOrder = ['Daily' => 0, 'Weekly' => 1, 'Monthly' => 2];
        usort($rows, function ($a, $b) use ($freqOrder) {
            $cmp = strcmp($a['due_date'], $b['due_date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $freqOrder[$a['freq']] <=> $freqOrder[$b['freq']];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['key'], $b['key']);
        });

        return $rows;
    }

    /* ---------- manager-facing page ---------- */

    public function index(Request $request)
    {
        $this->guardManager();

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $userId     = auth()->id();
        $key        = self::currentManagerKey();

        if (!$this->ready()) {
            return view('manager_checklist.index', [
                'notReady' => true,
                'meta'     => self::MANAGER_KEYS[$key],
            ]);
        }

        $tasks        = $this->buildTaskList($businessId, $userId);
        $overdueCount = count(array_filter($tasks, function ($t) { return $t['overdue']; }));

        return view('manager_checklist.index', [
            'notReady'     => false,
            'meta'         => self::MANAGER_KEYS[$key],
            'tasks'        => $tasks,
            'overdueCount' => $overdueCount,
            'startDate'    => self::MANAGERS_START,
        ]);
    }

    /**
     * Toggle a single task instance (AJAX auto-save). Always writes against
     * the logged-in manager's own user_id — there is no way to pass a
     * different user id in, so a manager can never check off the other
     * manager's items. The posted period_key is validated against the item's
     * frequency and the manager start date before it's trusted for anything.
     */
    public function toggle(Request $request)
    {
        $this->guardManager();

        if (!$this->ready()) {
            return response()->json(['ok' => false, 'msg' => 'Manager Checklist table not migrated yet.'], 503);
        }

        $key       = (string) $request->input('key', '');
        $periodKey = (string) $request->input('period_key', '');

        if (!in_array($key, self::allKeys(), true)) {
            return response()->json(['ok' => false, 'msg' => 'Unknown item.'], 422);
        }
        if (!self::periodKeyValidForItem($key, $periodKey)) {
            return response()->json(['ok' => false, 'msg' => 'Unknown or out-of-range task instance.'], 422);
        }

        $on = filter_var($request->input('checked'), FILTER_VALIDATE_BOOLEAN);

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $userId     = auth()->id();

        if ($on) {
            DB::table(self::TABLE)->updateOrInsert(
                ['user_id' => $userId, 'item_key' => $key, 'period_key' => $periodKey],
                [
                    'business_id'          => $businessId,
                    'completed_at'         => date('Y-m-d H:i:s'),
                    'completed_by_user_id' => $userId,
                    'updated_at'           => date('Y-m-d H:i:s'),
                    'created_at'           => date('Y-m-d H:i:s'),
                ]
            );
        } else {
            DB::table(self::TABLE)
                ->where('user_id', $userId)
                ->where('item_key', $key)
                ->where('period_key', $periodKey)
                ->delete();
        }

        return response()->json(['ok' => true]);
    }

    /* ---------- admin summary ---------- */

    public function adminSummary(Request $request)
    {
        $this->guardAdmin();

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;

        if (!$this->ready()) {
            return view('manager_checklist.admin', ['notReady' => true]);
        }

        $managers = [];
        foreach (self::MANAGER_KEYS as $key => $meta) {
            $userRow = $this->managerUser($businessId, $key);
            $tasks   = $userRow ? $this->buildTaskList($businessId, $userRow->id) : [];

            $managers[$key] = [
                'key'           => $key,
                'label'         => $meta['label'],
                'store'         => $meta['store'],
                'user_id'       => $userRow->id ?? null,
                'found'         => (bool) $userRow,
                'tasks'         => $tasks,
                'overdueCount'  => count(array_filter($tasks, function ($t) { return $t['overdue']; })),
                'doneCount'     => count(array_filter($tasks, function ($t) { return $t['done']; })),
                'totalCount'    => count($tasks),
            ];
        }

        return view('manager_checklist.admin', [
            'notReady'  => false,
            'managers'  => $managers,
            'startDate' => self::MANAGERS_START,
        ]);
    }
}
