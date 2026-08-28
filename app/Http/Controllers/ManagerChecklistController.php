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
 * recurring item is expanded into dated instances with an explicit due date,
 * sorted soonest-first, with overdue items flagged. The list is viewed one
 * week at a time (see weekBounds()/nav()), defaulting to the current week.
 *
 * The item list is fixed, plain PHP (DAILY/WEEKLY/MONTHLY below) — not
 * admin-editable, matching DailyChecklistController's GROUPS. What IS in the
 * DB is the completion records (manager_checklist_completions): one row per
 * item actually checked off for a given dated instance, keyed by period_key.
 *
 * Cadence: each item is either
 *   - weekday-specific — carries a 'days' array of ISO weekday numbers
 *     (1=Monday .. 7=Sunday) it's due on. One instance is generated per
 *     matching weekday in the displayed week, using a "D:YYYY-MM-DD" period
 *     key (same encoding as a plain daily item — a Monday/Thursday-only item
 *     is really just two dated instances filtered to specific weekdays).
 *     Daily-group items default to all 7 days if 'days' is omitted.
 *   - a uniform weekly bucket (Weekly group, no 'days' key) — one instance
 *     for the displayed week, due the Sunday that ends it, "W:YYYY-MM-DD"
 *     (the Monday that starts the week).
 *   - a uniform monthly bucket (Monthly group) — one instance per calendar
 *     month, due the last day of the month, "M:YYYY-MM". Only shown when
 *     that due date falls inside the displayed week.
 *
 * Items may also carry a 'manager' key ('zakary' or 'luis') restricting them
 * to one manager only — used for the per-employee 1:1 rows, which are
 * currently only confirmed for Zakary (real Sling schedule data). Luis's
 * 1:1 blocks aren't set up in Sling yet, so he keeps the generic "Team 1:1s"
 * item until that's done.
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
 *                                    posted user id) and only their own items
 *                                    (an item scoped to the other manager via
 *                                    'manager' is rejected too).
 *   /admin/manager-checklists     — owner/admin-only summary of both managers'
 *                                    task lists (read-only) with overdue/done counts.
 */
class ManagerChecklistController extends Controller
{
    const TABLE = 'manager_checklist_completions';

    /**
     * Neither manager is a manager before this date. No task instance may
     * have a due date earlier than this — see the instance generators.
     */
    const MANAGERS_START = '2026-09-01';

    /** Monday of the week containing MANAGERS_START — first valid displayed week / weekly period_key. */
    const FIRST_WEEK_MONDAY = '2026-08-31';

    /** First valid monthly period_key (Y-m). */
    const FIRST_MONTH = '2026-09';

    /** Shared "how to run it" script for every 1:1 item — same text, personalized label per row. */
    const ONE_ON_ONE_SCRIPT = "10-15 min, one-on-one. Ask: How's the week going? Anything getting in your way - schedule, team, customers? One thing that's going well, one thing you want to get better at. Anything you're seeing on the floor I should know about (sales, product, theft). Anything you need from me?";

    /**
     * Daily duties. Each item is
     * ['label' => task name, 'how' => plain instructions, 'days' => ISO weekdays it's due on (1=Mon..7=Sun)],
     * so a first-time manager doesn't have to guess what the task means or when it's due.
     * 'days' omitted = every day.
     */
    const DAILY = [
        'sales_check' => [
            'label' => 'Sales Review',
            'how'   => 'Open <a href="/reports/lfl-sales" target="_blank" rel="noopener">Like-for-Like Sales</a> and check today\'s total so far against the same day last year. Note if you\'re behind and why.',
            'days'  => [1, 4], // Monday, Thursday
        ],
    ];

    /**
     * Weekly duties. An item with a 'days' key is due on those specific
     * weekdays (one instance per matching weekday); without it, it's a
     * uniform once-a-week item due the Sunday that ends the week. An item
     * with a 'manager' key only shows up for that one manager.
     */
    const WEEKLY = [
        // Zakary's team (Pico) — confirmed against his real Sling 1:1 Check-In
        // blocks vs. who's on register at the same time (screenshot, Aug 2026):
        //   Alec K   Mon 2:00-3:00pm overlaps Alec's register shift
        //   Willy Y  Wed same logic
        //   Andy Theiss Fri 2:00-4:00pm falls fully inside Andy's 9:45-4:00 shift
        //   Davis Bryant Sun 10:00-11:00am falls fully inside Davis's 9:45-3:00 shift
        // Saturday is ambiguous (Zak's Sat 1:1 2:00-4:00pm straddles the Davis/Alec
        // handoff) and deliberately left out — see report to Sarah.
        'team_1on1_alec' => [
            'label'   => '1:1 - Alec K',
            'how'     => self::ONE_ON_ONE_SCRIPT,
            'days'    => [1], // Monday
            'manager' => 'zakary',
        ],
        'team_1on1_willy' => [
            'label'   => '1:1 - Willy Y',
            'how'     => self::ONE_ON_ONE_SCRIPT,
            'days'    => [3], // Wednesday
            'manager' => 'zakary',
        ],
        'team_1on1_andy' => [
            'label'   => '1:1 - Andy Theiss',
            'how'     => self::ONE_ON_ONE_SCRIPT,
            'days'    => [5], // Friday
            'manager' => 'zakary',
        ],
        'team_1on1_davis' => [
            'label'   => '1:1 - Davis Bryant',
            'how'     => self::ONE_ON_ONE_SCRIPT,
            'days'    => [7], // Sunday
            'manager' => 'zakary',
        ],
        // Luis's Hollywood team isn't set up with 1:1 blocks in Sling yet, so
        // he keeps the generic weekly item (due Sunday) until that's done and
        // a real per-employee split (like Zakary's above) can be added.
        'team_1on1s' => [
            'label'   => 'Team 1:1s (each employee)',
            'how'     => self::ONE_ON_ONE_SCRIPT,
            'manager' => 'luis',
        ],
        'supplies_check' => [
            'label' => 'Supplies check',
            'how'   => 'Check bags, tape, cleaning supplies, receipt paper, and register supplies in the <a href="/admin/supplies" target="_blank" rel="noopener">Supplies</a> page. Low on anything? Tell Jon/Sarah.',
            'days'  => [1], // Monday
        ],
        'training_review' => [
            'label' => 'Training checklist review - where each hire stands',
            'how'   => 'For anyone still ramping up, check where they stand on register, buys, theft awareness, and the floor.',
            // no 'days' - stays generic weekly, due Sunday.
        ],
        'merch_walk' => [
            'label' => 'Section/merchandising standards walk',
            'how'   => 'Walk every section - genres in order, no gaps, priced right, looks clean.',
            'days'  => [3], // Wednesday
        ],
    ];

    /** Monthly duties — one instance per calendar month, due the last day. */
    const MONTHLY = [
        'sales_vs_goal' => [
            'label' => 'Sales vs. goal review',
            'how'   => 'Compare this month\'s total to goal in <a href="/reports/lfl-sales?view=monthly" target="_blank" rel="noopener">Like-for-Like Sales</a>. Note what worked and what didn\'t.',
        ],
        'shrink_loss' => [
            'label' => 'Shrink/loss review',
            'how'   => 'Look at this month\'s results in the <a href="/reports/inventory-check-assistant" target="_blank" rel="noopener">Inventory Check Assistant</a> for missing stock. Flag anything that looks like theft or a process gap.',
        ],
        'cash_close_accuracy' => [
            'label' => 'Cash close accuracy review',
            'how'   => 'Check this month\'s closes in the <a href="/reports/register-report" target="_blank" rel="noopener">Register Report</a> for accuracy - drawer counts matching, no repeated discrepancies.',
        ],
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

    /** The item definition array for a key, across all three groups, or null. */
    public static function itemByKey($key)
    {
        foreach ([self::DAILY, self::WEEKLY, self::MONTHLY] as $arr) {
            if (isset($arr[$key])) {
                return $arr[$key];
            }
        }
        return null;
    }

    /** True if this item should be shown/toggleable for the given manager key. No 'manager' key = both. */
    private static function itemAppliesToManager(array $item, $managerKey)
    {
        return !isset($item['manager']) || $item['manager'] === $managerKey;
    }

    /* ---------- week bounds ---------- */

    /** Monday (Y-m-d) of the week containing $date. */
    private static function mondayOf($date)
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
    }

    /**
     * Resolve the displayed week's Monday from the request's ?week= param
     * (any date inside the wanted week), defaulting to the current week.
     * Never earlier than FIRST_WEEK_MONDAY - neither manager existed before
     * MANAGERS_START, so there's nothing to show before that week.
     */
    private static function resolveWeekStart(Request $request, $today)
    {
        $default = max(self::mondayOf($today), self::FIRST_WEEK_MONDAY);
        $param   = $request->input('week');
        $start   = $param ? self::mondayOf($param) : $default;
        if ($start < self::FIRST_WEEK_MONDAY) {
            $start = self::FIRST_WEEK_MONDAY;
        }
        return $start;
    }

    /** Nav data (prev/next week links, label, current-week flag) for the week picker in the views. */
    private static function weekNav($weekStart, $today)
    {
        $default = max(self::mondayOf($today), self::FIRST_WEEK_MONDAY);
        $start   = Carbon::parse($weekStart);
        return [
            'week_start'  => $weekStart,
            'week_end'    => $start->copy()->addDays(6)->format('Y-m-d'),
            'prev'        => $weekStart > self::FIRST_WEEK_MONDAY ? $start->copy()->subWeek()->format('Y-m-d') : null,
            'next'        => $start->copy()->addWeek()->format('Y-m-d'),
            'this_week'   => $default,
            'is_current'  => $weekStart === $default,
            'week_label'  => $start->format('M j') . ' - ' . $start->copy()->addDays(6)->format('M j, Y'),
        ];
    }

    /* ---------- instance generation (one week at a time) ---------- */

    /** Weekday-filtered "D:" instances within [$weekStart,$weekEnd], skipping anything before MANAGERS_START. */
    private static function weekdayInstancesInRange($weekStart, $weekEnd, array $days)
    {
        $out    = [];
        $cursor = Carbon::parse($weekStart);
        $end    = Carbon::parse($weekEnd);
        while ($cursor->lte($end)) {
            $ymd = $cursor->format('Y-m-d');
            if ($ymd >= self::MANAGERS_START && in_array((int) $cursor->format('N'), $days, true)) {
                $out[] = ['period_key' => 'D:' . $ymd, 'due_date' => $ymd, 'period_note' => null];
            }
            $cursor->addDay();
        }
        return $out;
    }

    /** Monthly instances whose due date (last day of the month) falls inside [$weekStart,$weekEnd]. */
    private static function monthlyInstancesInWeek($weekStart, $weekEnd)
    {
        $out   = [];
        $start = Carbon::parse($weekStart);
        $end   = Carbon::parse($weekEnd);
        $months = array_unique([$start->format('Y-m'), $end->format('Y-m')]);
        foreach ($months as $ym) {
            if ($ym < self::FIRST_MONTH) {
                continue;
            }
            $due = Carbon::parse($ym . '-01')->endOfMonth();
            if ($due->gte($start) && $due->lte($end)) {
                $out[] = [
                    'period_key'  => 'M:' . $ym,
                    'due_date'    => $due->format('Y-m-d'),
                    'period_note' => $due->format('F Y'),
                ];
            }
        }
        return $out;
    }

    /** All instances for one item, within the displayed week only. */
    private static function instancesForItemInWeek($groupName, array $item, $weekStart, $weekEnd)
    {
        if ($groupName === 'Monthly') {
            return self::monthlyInstancesInWeek($weekStart, $weekEnd);
        }

        $days = $item['days'] ?? ($groupName === 'Daily' ? [1, 2, 3, 4, 5, 6, 7] : null);
        if ($days !== null) {
            return self::weekdayInstancesInRange($weekStart, $weekEnd, $days);
        }

        // Weekly, uniform bucket (no explicit days) - one instance for this week.
        if ($weekStart < self::FIRST_WEEK_MONDAY) {
            return [];
        }
        $due = Carbon::parse($weekStart)->addDays(6)->format('Y-m-d');
        return [[
            'period_key'  => 'W:' . $weekStart,
            'due_date'    => $due,
            'period_note' => 'week of ' . Carbon::parse($weekStart)->format('M j'),
        ]];
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
     * right period_key shape for how that item is scheduled (weekday-filtered
     * "D:" vs uniform "W:"/"M:"), a real calendar date/Monday/month, on an
     * allowed weekday when the item restricts to specific days, and not
     * earlier than the manager start date. Used to validate toggle() input
     * without trusting anything the client sends.
     */
    private static function periodKeyValidForItem($itemKey, $periodKey)
    {
        $group = self::groupFor($itemKey);
        $item  = self::itemByKey($itemKey);
        if (!$group || !$item) {
            return false;
        }

        $days = $item['days'] ?? ($group === 'Daily' ? [1, 2, 3, 4, 5, 6, 7] : null);

        if ($days !== null) {
            if (strpos($periodKey, 'D:') !== 0) {
                return false;
            }
            $d = substr($periodKey, 2);
            if (!self::isValidYmd($d) || $d < self::MANAGERS_START) {
                return false;
            }
            return in_array((int) Carbon::parse($d)->format('N'), $days, true);
        }

        if ($group === 'Weekly') {
            if (strpos($periodKey, 'W:') !== 0) {
                return false;
            }
            $mon = substr($periodKey, 2);
            if (!self::isValidYmd($mon) || (int) date('N', strtotime($mon)) !== 1) {
                return false;
            }
            return $mon >= self::FIRST_WEEK_MONDAY;
        }

        if ($group === 'Monthly') {
            if (strpos($periodKey, 'M:') !== 0) {
                return false;
            }
            $ym = substr($periodKey, 2);
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                return false;
            }
            return $ym >= self::FIRST_MONTH;
        }

        return false;
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
     * Build the flat, due-date-sorted task list for one user, for a single
     * displayed week: every applicable recurring item expanded into its
     * dated instances within [$weekStart, $weekStart+6], each with
     * done/overdue flags (overdue is judged against the real $today, not the
     * displayed week, so a still-open task from a past week you're browsing
     * back to correctly shows as overdue). Sorted by due date ascending, then
     * Daily before Weekly before Monthly, then item key.
     */
    private function buildTaskList($businessId, $userId, $managerKey, $weekStart, $today = null)
    {
        $today   = $today ?: date('Y-m-d');
        $weekEnd = Carbon::parse($weekStart)->addDays(6)->format('Y-m-d');

        $itemsFlat  = []; // itemKey => ['item'=>.., 'group'=>.., 'instances'=>..]
        $periodKeys = [];

        foreach (self::groups() as $groupName => $items) {
            foreach ($items as $itemKey => $item) {
                if (!self::itemAppliesToManager($item, $managerKey)) {
                    continue;
                }
                $instances = self::instancesForItemInWeek($groupName, $item, $weekStart, $weekEnd);
                if (empty($instances)) {
                    continue;
                }
                $itemsFlat[$itemKey] = ['item' => $item, 'group' => $groupName, 'instances' => $instances];
                foreach ($instances as $inst) {
                    $periodKeys[] = $inst['period_key'];
                }
            }
        }

        $done = $userId ? $this->completedPairs($businessId, $userId, $periodKeys) : [];

        $rows = [];
        foreach ($itemsFlat as $itemKey => $entry) {
            foreach ($entry['instances'] as $inst) {
                $isDone = isset($done[$itemKey . '|' . $inst['period_key']]);
                $rows[] = [
                    'key'         => $itemKey,
                    'label'       => $entry['item']['label'],
                    'how'         => $entry['item']['how'],
                    'freq'        => $entry['group'],
                    'period_key'  => $inst['period_key'],
                    'period_note' => $inst['period_note'],
                    'due_date'    => $inst['due_date'],
                    'done'        => $isDone,
                    'overdue'     => (!$isDone && $inst['due_date'] < $today),
                ];
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
        $today      = date('Y-m-d');

        if (!$this->ready()) {
            return view('manager_checklist.index', [
                'notReady' => true,
                'meta'     => self::MANAGER_KEYS[$key],
            ]);
        }

        $weekStart = self::resolveWeekStart($request, $today);
        $nav       = self::weekNav($weekStart, $today);

        $tasks        = $this->buildTaskList($businessId, $userId, $key, $weekStart, $today);
        $overdueCount = count(array_filter($tasks, function ($t) { return $t['overdue']; }));

        return view('manager_checklist.index', [
            'notReady'     => false,
            'meta'         => self::MANAGER_KEYS[$key],
            'tasks'        => $tasks,
            'overdueCount' => $overdueCount,
            'startDate'    => self::MANAGERS_START,
            'nav'          => $nav,
        ]);
    }

    /**
     * Toggle a single task instance (AJAX auto-save). Always writes against
     * the logged-in manager's own user_id — there is no way to pass a
     * different user id in, so a manager can never check off the other
     * manager's items (also enforced explicitly below for items restricted
     * via 'manager'). The posted period_key is validated against the item's
     * schedule and the manager start date before it's trusted for anything.
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

        $managerKey = self::currentManagerKey();
        $item       = self::itemByKey($key);
        if (!self::itemAppliesToManager($item, $managerKey)) {
            return response()->json(['ok' => false, 'msg' => 'That task is not on your checklist.'], 403);
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
        $today      = date('Y-m-d');

        if (!$this->ready()) {
            return view('manager_checklist.admin', ['notReady' => true]);
        }

        $weekStart = self::resolveWeekStart($request, $today);
        $nav       = self::weekNav($weekStart, $today);

        $managers = [];
        foreach (self::MANAGER_KEYS as $key => $meta) {
            $userRow = $this->managerUser($businessId, $key);
            $tasks   = $userRow ? $this->buildTaskList($businessId, $userRow->id, $key, $weekStart, $today) : [];

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
            'nav'       => $nav,
        ]);
    }
}
