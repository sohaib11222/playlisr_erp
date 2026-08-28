<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manager Checklist — recurring duties for the two store managers (Zakary at
 * Pico, Luis at Hollywood), so Sarah/Jon can see at a glance whether each one
 * kept up with the basics.
 *
 * The item list is fixed, plain PHP (DAILY/WEEKLY/MONTHLY below) — not
 * admin-editable, matching DailyChecklistController's GROUPS. What IS in the
 * DB is the completion records (manager_checklist_completions): one row per
 * item actually checked off in a given period, keyed by period_key so
 * "did they do it this week vs last week" is a real query, not just current
 * state. See migration 2026_08_28_090000_create_manager_checklist_completions_table.
 *
 * Access:
 *   /manager-checklist            — a manager's own page. Only Zakary or Luis
 *                                    (matched by first name — see currentManagerKey())
 *                                    can open it, and each can only ever toggle
 *                                    their own rows (the toggle endpoint always
 *                                    writes against auth()->id(), never a
 *                                    posted user id).
 *   /admin/manager-checklists     — owner/admin-only summary of both managers,
 *                                    current period + recent daily history.
 */
class ManagerChecklistController extends Controller
{
    const TABLE = 'manager_checklist_completions';

    /** Daily duties — reset every day. */
    const DAILY = [
        'sales_check'  => 'Sales check - where do we stand today',
        'open_close'   => 'Open/close checklist done',
        'new_arrivals' => 'New arrivals out',
        'floor_walk'   => 'Floor/theft walk',
    ];

    /** Weekly duties — reset every Monday. */
    const WEEKLY = [
        'team_1on1s'      => 'Team 1:1s (each employee)',
        'supplies_check'  => 'Supplies check',
        'inventory_check' => 'Inventory check',
        'training_review' => 'Training checklist review - where each hire stands',
        'merch_walk'      => 'Section/merchandising standards walk',
    ];

    /** Monthly duties — reset every calendar month. */
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

    public static function labelFor($key)
    {
        foreach ([self::DAILY, self::WEEKLY, self::MONTHLY] as $group) {
            if (isset($group[$key])) {
                return $group[$key];
            }
        }
        return $key;
    }

    /** Which fixed group ('Daily'/'Weekly'/'Monthly') owns this item key. */
    public static function groupFor($key)
    {
        if (isset(self::DAILY[$key])) { return 'Daily'; }
        if (isset(self::WEEKLY[$key])) { return 'Weekly'; }
        if (isset(self::MONTHLY[$key])) { return 'Monthly'; }
        return null;
    }

    /* ---------- period keys ---------- */

    /** "D:2026-08-28" */
    public static function dailyPeriodKey($date = null)
    {
        $ts = $date ? strtotime($date) : time();
        return 'D:' . date('Y-m-d', $ts);
    }

    /** "W:2026-08-24" — always the Monday that starts the week. */
    public static function weeklyPeriodKey($date = null)
    {
        $ts = $date ? strtotime($date) : time();
        $dow = (int) date('N', $ts); // 1 (Mon) .. 7 (Sun)
        $monday = $ts - (($dow - 1) * 86400);
        return 'W:' . date('Y-m-d', $monday);
    }

    /** "M:2026-08" */
    public static function monthlyPeriodKey($date = null)
    {
        $ts = $date ? strtotime($date) : time();
        return 'M:' . date('Y-m', $ts);
    }

    /** Period key for whichever group an item key belongs to, or null. */
    public static function periodKeyFor($itemKey, $date = null)
    {
        $group = self::groupFor($itemKey);
        if ($group === 'Daily') { return self::dailyPeriodKey($date); }
        if ($group === 'Weekly') { return self::weeklyPeriodKey($date); }
        if ($group === 'Monthly') { return self::monthlyPeriodKey($date); }
        return null;
    }

    /** Human label for a period key, e.g. "Aug 28" / "week of Aug 24" / "August". */
    public static function periodLabel($periodKey)
    {
        if (strpos($periodKey, 'D:') === 0) {
            return \Carbon\Carbon::parse(substr($periodKey, 2))->format('D, M j');
        }
        if (strpos($periodKey, 'W:') === 0) {
            return 'week of ' . \Carbon\Carbon::parse(substr($periodKey, 2))->format('M j');
        }
        if (strpos($periodKey, 'M:') === 0) {
            return \Carbon\Carbon::parse(substr($periodKey, 2) . '-01')->format('F Y');
        }
        return $periodKey;
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

    /** Completed item keys for one user + one period. */
    private function completedKeys($businessId, $userId, $periodKey)
    {
        if (!$this->ready()) {
            return [];
        }
        return DB::table(self::TABLE)
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->where('period_key', $periodKey)
            ->pluck('item_key')
            ->all();
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

        $today = date('Y-m-d');
        $checked = [
            'Daily'   => $this->completedKeys($businessId, $userId, self::dailyPeriodKey($today)),
            'Weekly'  => $this->completedKeys($businessId, $userId, self::weeklyPeriodKey($today)),
            'Monthly' => $this->completedKeys($businessId, $userId, self::monthlyPeriodKey($today)),
        ];

        return view('manager_checklist.index', [
            'notReady'  => false,
            'meta'      => self::MANAGER_KEYS[$key],
            'groups'    => self::groups(),
            'checked'   => $checked,
            'periods'   => [
                'Daily'   => self::periodLabel(self::dailyPeriodKey($today)),
                'Weekly'  => self::periodLabel(self::weeklyPeriodKey($today)),
                'Monthly' => self::periodLabel(self::monthlyPeriodKey($today)),
            ],
        ]);
    }

    /**
     * Toggle a single item for the current period (AJAX auto-save). Always
     * writes against the logged-in manager's own user_id — there is no way
     * to pass a different user id in, so a manager can never check off the
     * other manager's items.
     */
    public function toggle(Request $request)
    {
        $this->guardManager();

        if (!$this->ready()) {
            return response()->json(['ok' => false, 'msg' => 'Manager Checklist table not migrated yet.'], 503);
        }

        $key = (string) $request->input('key', '');
        if (!in_array($key, self::allKeys(), true)) {
            return response()->json(['ok' => false, 'msg' => 'Unknown item.'], 422);
        }
        $on = filter_var($request->input('checked'), FILTER_VALIDATE_BOOLEAN);

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $userId     = auth()->id();
        $periodKey  = self::periodKeyFor($key);

        if ($on) {
            DB::table(self::TABLE)->updateOrInsert(
                ['user_id' => $userId, 'item_key' => $key, 'period_key' => $periodKey],
                [
                    'business_id'           => $businessId,
                    'completed_at'          => date('Y-m-d H:i:s'),
                    'completed_by_user_id'  => $userId,
                    'updated_at'            => date('Y-m-d H:i:s'),
                    'created_at'            => date('Y-m-d H:i:s'),
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

        $today = date('Y-m-d');
        $periodKeys = [
            'Daily'   => self::dailyPeriodKey($today),
            'Weekly'  => self::weeklyPeriodKey($today),
            'Monthly' => self::monthlyPeriodKey($today),
        ];

        $managers = [];
        foreach (self::MANAGER_KEYS as $key => $meta) {
            $userRow = $this->managerUser($businessId, $key);
            $managers[$key] = [
                'key'      => $key,
                'label'    => $meta['label'],
                'store'    => $meta['store'],
                'user_id'  => $userRow->id ?? null,
                'found'    => (bool) $userRow,
                'checked'  => $userRow ? [
                    'Daily'   => $this->completedKeys($businessId, $userRow->id, $periodKeys['Daily']),
                    'Weekly'  => $this->completedKeys($businessId, $userRow->id, $periodKeys['Weekly']),
                    'Monthly' => $this->completedKeys($businessId, $userRow->id, $periodKeys['Monthly']),
                ] : ['Daily' => [], 'Weekly' => [], 'Monthly' => []],
            ];
        }

        // Last 7 days of daily history, per manager: date => count done / total.
        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $pk = self::dailyPeriodKey($d);
            $row = ['date' => $d, 'label' => \Carbon\Carbon::parse($d)->format('D, M j')];
            foreach ($managers as $key => $m) {
                $done = $m['user_id'] ? count($this->completedKeys($businessId, $m['user_id'], $pk)) : 0;
                $row[$key] = $done;
            }
            $history[] = $row;
        }

        return view('manager_checklist.admin', [
            'notReady'    => false,
            'managers'    => $managers,
            'groups'      => self::groups(),
            'periods'     => [
                'Daily'   => self::periodLabel($periodKeys['Daily']),
                'Weekly'  => self::periodLabel($periodKeys['Weekly']),
                'Monthly' => self::periodLabel($periodKeys['Monthly']),
            ],
            'dailyTotal'  => count(self::DAILY),
            'history'     => $history,
        ]);
    }
}
