<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Utils\FatteenGoalsUtil;
use Illuminate\Support\Facades\Storage;

/**
 * Fatteen's daily checklist. A fixed set of recurring tasks he works through
 * every day; the list resets each morning. He ticks each item and it auto-saves
 * (per checkbox, no submit needed) so progress isn't lost if the page reloads.
 *
 * Each day is one record per user, keyed by date + user_id, so Sarah/admins can
 * glance at the recent history and see how much got done each day.
 *
 * Built for Fatteen, also open to Admins (so Sarah can see it). Reuses
 * EmployeeChecklistController::isFatteen() for the identity check.
 *
 * No migration: storage/app/daily_checklist.json. View at daily_checklist.index,
 * styled to match /pos/create.
 */
class DailyChecklistController extends Controller
{
    const STORE_PATH = 'daily_checklist.json';

    /**
     * Recurring tasks on a longer-than-daily cadence. Unlike the daily list,
     * these only surface when they're DUE for the current period and vanish once
     * ticked, staying done until the next period begins — so a quarterly task
     * doesn't nag every day. Completion is tracked per period (see
     * daily_checklist_periodic.json), not per day.
     *
     * Keys are stable ids (used in storage). Edit labels freely; don't rename a
     * key once it's in use or old completion records lose their mapping.
     */
    const QUARTERLY = [
        'pci_compliance' => 'Run the quarterly PCI compliance test (SAQ + external network scan) and file the passing result.',
    ];

    const PERIODIC_STORE = 'daily_checklist_periodic.json';

    /**
     * The daily tasks, grouped. Keys are stable ids (used in storage); edit the
     * labels freely, but don't rename a key once it's in use or old records lose
     * the mapping.
     */
    const GROUPS = [
        'Start of day' => [
            'sling_week' => 'Check Sling first — make sure we\'re covered and good for the whole week.',
            'discogs'    => 'Check Discogs orders and messages.',
            'ams_orders' => 'Add purchases from AMS orders.',
        ],
        'Phones, messages & email' => [
            'phone_texts' => 'Check Quo texts and calls — start at 9:30 AM.',
            'whatsapp'    => 'Check WhatsApp and reply — start at 9:30 AM.',
            'hello_email' => 'Reply to the hello@nivessa.com emails.',
        ],
        'Sales & accounting' => [
            'sales_feed'    => 'Daily sales feed: make sure every transaction is in both the ERP and Clover. If it\'s not, DM the cashier to fix it.',
            'accounting'    => 'Review recent accounting transactions in QuickBooks and categorize them.',
        ],
        'Events & social' => [
            'events_cal'    => 'Update the events calendar with any new events.',
            'events_ready'  => 'Make sure we\'re ready for upcoming events — go through the checklists in advance, for both stores.',
            'events_social' => 'Post any upcoming events on social media at the time Carrie specifies.',
        ],
        'People & operations' => [
            'onboarding'  => 'Handle any onboarding / offboarding for new or departing employees.',
            'purchasing'  => 'Help with purchasing — ask Jon what the priority is to purchase.',
            'cameras'     => 'Check the cameras — staff are working, not on their phones.',
        ],
        'Customers & growth' => [
            'seller_leads'  => 'Follow up with every seller lead until they book a time to bring the collection in. Log good buys on the Goals page.',
            'venue_leads'   => 'Reply to every venue booking inquiry and follow up until it is booked.',
            'collab_leads'  => 'Follow up on partnership and event leads (brands, listening parties, private events).',
            'review_ask'    => 'Text the Google review link to customers who had a good call or visit.',
        ],
        'End of day' => [
            'close_loops'   => 'Nothing left waiting on a reply. If someone has not answered, call them, try someone else, or propose a fix.',
        ],
        'Other' => [
            'payroll'     => 'Run payroll — every other Thursday.',
        ],
    ];

    /** Optional helper links shown next to a task (an "Open ->" jump to the ERP screen). */
    const LINKS = [
        // ERP screens
        'sales_feed'     => '/pos/recent-feed',
        'events_cal'     => '/events',
        'events_ready'   => '/events',
        'ams_orders'     => '/purchases/create',
        'onboarding'     => '/employee-checklist',
        'seller_leads'   => '/buy-from-customer',
        'venue_leads'    => 'https://nivessa.com/venues',
        // External tools
        'sling_week'     => 'https://app.getsling.com',
        'discogs'        => 'https://www.discogs.com/sell/orders',
        'phone_texts'    => 'https://my.quo.com/login',
        'whatsapp'       => 'https://web.whatsapp.com',
        'hello_email'    => 'https://mail.google.com',
        'accounting'     => 'https://qbo.intuit.com',
        'events_social'  => 'https://www.instagram.com',
        'payroll'        => 'https://qbo.intuit.com',
    ];

    /* ---------- access ---------- */

    /** Is the current user Jon? His ERP account is "Jonathan Hedvat". */
    public static function isJon()
    {
        $u = auth()->user();
        if (!$u) {
            return false;
        }
        return strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    /** Fatteen + Jon/Sarah only — this is Fatteen's personal list; the owners oversee it. */
    public static function canAccess()
    {
        return EmployeeChecklistController::isFatteen() || self::isJon() || FatteenGoalsUtil::isOwner();
    }

    private function guard()
    {
        if (!self::canAccess()) {
            abort(403, 'Unauthorized action.');
        }
    }

    /* ---------- task helpers ---------- */

    public static function allKeys()
    {
        $keys = [];
        foreach (self::GROUPS as $items) {
            foreach ($items as $key => $label) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function labelFor($key)
    {
        foreach (self::GROUPS as $items) {
            if (isset($items[$key])) {
                return $items[$key];
            }
        }
        return $key;
    }

    /* ---------- period (quarterly) helpers ---------- */

    /** Current quarter id, e.g. "2026-Q3". */
    public static function currentQuarter($date = null)
    {
        $ts = $date ? strtotime($date) : time();
        $year = (int) date('Y', $ts);
        $quarter = (int) ceil(((int) date('n', $ts)) / 3);
        return $year . '-Q' . $quarter;
    }

    /** Human label for a quarter id, e.g. "Q3 2026". */
    public static function quarterLabel($period)
    {
        $parts = explode('-Q', $period);
        return isset($parts[1]) ? 'Q' . $parts[1] . ' ' . $parts[0] : $period;
    }

    /* ---------- storage ---------- */

    public static function readAll()
    {
        if (!Storage::exists(self::STORE_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::STORE_PATH), true);
        return is_array($data) ? $data : [];
    }

    private static function writeAll(array $items)
    {
        Storage::put(self::STORE_PATH, json_encode(array_values($items), JSON_PRETTY_PRINT));
    }

    public static function readPeriodic()
    {
        if (!Storage::exists(self::PERIODIC_STORE)) {
            return [];
        }
        $data = json_decode(Storage::get(self::PERIODIC_STORE), true);
        return is_array($data) ? $data : [];
    }

    private static function writePeriodic(array $items)
    {
        Storage::put(self::PERIODIC_STORE, json_encode(array_values($items), JSON_PRETTY_PRINT));
    }

    /** The completion record for a period+key, or null if not yet done. */
    private static function periodicRecord(array $recs, $period, $key)
    {
        foreach ($recs as $r) {
            if (($r['period'] ?? '') === $period && ($r['key'] ?? '') === $key) {
                return $r;
            }
        }
        return null;
    }

    /** Find today's record index for the current user, or null. */
    private static function todayIndex(array $all, $date, $userId)
    {
        foreach ($all as $i => $r) {
            if (($r['date'] ?? '') === $date && (string) ($r['user_id'] ?? '') === (string) $userId) {
                return $i;
            }
        }
        return null;
    }

    /* ---------- pages ---------- */

    public function index(Request $request)
    {
        $this->guard();

        $date = date('Y-m-d');
        $userId = auth()->id();

        $all = self::readAll();
        $idx = self::todayIndex($all, $date, $userId);
        $checked = $idx !== null ? ($all[$idx]['checked'] ?? []) : [];
        $wrapup = $idx !== null ? (string) ($all[$idx]['wrapup'] ?? '') : '';

        // Recent history (all users, most recent first) so admins can review.
        $recent = $all;
        usort($recent, function ($a, $b) {
            return strcmp(($b['date'] ?? '') . ($b['updated_at'] ?? ''), ($a['date'] ?? '') . ($a['updated_at'] ?? ''));
        });
        $recent = array_slice($recent, 0, 30);

        // Quarterly tasks: show what's still due this quarter, plus a note for
        // anything already ticked off, so it's clear the cadence is handled.
        $period = self::currentQuarter($date);
        $periodicRecs = self::readPeriodic();
        $quarterlyDue = [];
        $quarterlyDone = [];
        foreach (self::QUARTERLY as $key => $label) {
            $rec = self::periodicRecord($periodicRecs, $period, $key);
            if ($rec) {
                $quarterlyDone[$key] = $rec;
            } else {
                $quarterlyDue[$key] = $label;
            }
        }

        return view('daily_checklist.index', [
            'wrapup'        => $wrapup,
            'groups'        => self::GROUPS,
            'links'         => self::LINKS,
            'checked'       => $checked,
            'totalItems'    => count(self::allKeys()),
            'today'         => $date,
            'recent'        => $recent,
            'quarterlyDue'  => $quarterlyDue,
            'quarterlyDone' => $quarterlyDone,
            'quarterLabel'  => self::quarterLabel($period),
        ]);
    }

    /**
     * Toggle a single task for today (AJAX auto-save). Idempotent: send the
     * desired state, we store it. Creates today's record on first tick.
     */
    public function toggle(Request $request)
    {
        $this->guard();

        $key = (string) $request->input('key', '');
        if (!in_array($key, self::allKeys(), true)) {
            return response()->json(['ok' => false, 'msg' => 'Unknown task.'], 422);
        }
        $on = filter_var($request->input('checked'), FILTER_VALIDATE_BOOLEAN);

        $date = date('Y-m-d');
        $userId = auth()->id();
        $userName = auth()->user()->first_name . ' ' . auth()->user()->last_name;

        $all = self::readAll();
        $idx = self::todayIndex($all, $date, $userId);

        if ($idx === null) {
            $all[] = [
                'date'       => $date,
                'user_id'    => $userId,
                'user_name'  => $userName,
                'checked'    => [],
                'total'      => count(self::allKeys()),
                'created_at' => date('Y-m-d H:i'),
                'updated_at' => date('Y-m-d H:i'),
            ];
            $idx = count($all) - 1;
        }

        $checked = array_values(array_filter((array) ($all[$idx]['checked'] ?? []), function ($k) {
            return in_array($k, self::allKeys(), true);
        }));

        if ($on && !in_array($key, $checked, true)) {
            $checked[] = $key;
        } elseif (!$on) {
            $checked = array_values(array_filter($checked, function ($k) use ($key) {
                return $k !== $key;
            }));
        }

        $all[$idx]['checked']    = $checked;
        $all[$idx]['user_name']  = $userName;
        $all[$idx]['total']      = count(self::allKeys());
        $all[$idx]['updated_at'] = date('Y-m-d H:i');
        self::writeAll($all);

        return response()->json([
            'ok'      => true,
            'checked' => count($checked),
            'total'   => count(self::allKeys()),
        ]);
    }

    /** Fatteen's goals dashboard: monthly goals, bonus, wins log and review. */
    public function goalsPage(Request $request)
    {
        $this->guard();
        $month = (string) $request->input('month', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $business_id = (int) $request->session()->get('user.business_id');
        $goals = null;
        try {
            FatteenGoalsUtil::fillRecon($business_id, $request->session());
            $goals = FatteenGoalsUtil::summary($business_id, $month);
        } catch (\Throwable $e) {
            \Log::warning('fatteen goals: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        return view('daily_checklist.goals', [
            'goals'     => $goals,
            'goalMonth' => $month,
            'isOwner'   => FatteenGoalsUtil::isOwner(),
            'winTypes'  => FatteenGoalsUtil::WIN_TYPES,
        ]);
    }

    /** Save today's end-of-day wrap-up note (done / still open / waiting on). */
    public function saveWrapup(Request $request)
    {
        $this->guard();
        $text = mb_substr(trim((string) $request->input('wrapup', '')), 0, 4000);
        $date = date('Y-m-d');
        $userId = auth()->id();
        $all = self::readAll();
        $idx = self::todayIndex($all, $date, $userId);
        if ($idx === null) {
            $all[] = [
                'date'       => $date,
                'user_id'    => $userId,
                'user_name'  => auth()->user()->first_name . ' ' . auth()->user()->last_name,
                'checked'    => [],
                'total'      => count(self::allKeys()),
                'created_at' => date('Y-m-d H:i'),
            ];
            $idx = count($all) - 1;
        }
        $all[$idx]['wrapup'] = $text;
        $all[$idx]['updated_at'] = date('Y-m-d H:i');
        self::writeAll($all);
        return response()->json(['ok' => true]);
    }

    /**
     * Add a goals entry. Fatteen logs wins (collection buy, venue booking,
     * collab, problem solved); the owner logs chases.
     */
    public function logGoal(Request $request)
    {
        $this->guard();
        $type = (string) $request->input('type', '');
        $isOwner = FatteenGoalsUtil::isOwner();
        if (!array_key_exists($type, FatteenGoalsUtil::WIN_TYPES) && !($type === 'chase' && $isOwner)) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Pick what you are logging.']);
        }
        $desc = mb_substr(trim((string) $request->input('desc', '')), 0, 500);
        if ($desc === '') {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Add a short description.']);
        }
        $d = FatteenGoalsUtil::read();
        $d['entries'][] = [
            'id'       => uniqid('g', true),
            'month'    => date('Y-m'),
            'type'     => $type,
            'desc'     => $desc,
            'phone'    => mb_substr(trim((string) $request->input('phone', '')), 0, 40),
            'by'       => auth()->user()->first_name,
            'at'       => date('Y-m-d H:i'),
            'approved' => $type === 'chase' ? true : null,
        ];
        FatteenGoalsUtil::write($d);
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Logged.']);
    }

    /** Owner: approve, reject or delete an entry. */
    public function reviewGoal(Request $request)
    {
        $this->guard();
        if (!FatteenGoalsUtil::isOwner()) {
            abort(403, 'Unauthorized action.');
        }
        $id = (string) $request->input('id', '');
        $action = (string) $request->input('action', '');
        $d = FatteenGoalsUtil::read();
        foreach ($d['entries'] as $i => $e) {
            if (($e['id'] ?? '') !== $id) {
                continue;
            }
            if ($action === 'delete') {
                array_splice($d['entries'], $i, 1);
            } else {
                $d['entries'][$i]['approved'] = $action === 'approve';
                $d['entries'][$i]['reviewed_by'] = auth()->user()->first_name;
            }
            break;
        }
        FatteenGoalsUtil::write($d);
        return redirect()->back();
    }

    /** Owner: enter the month's numbers that live outside the ERP. */
    public function saveGoalMetrics(Request $request)
    {
        $this->guard();
        if (!FatteenGoalsUtil::isOwner()) {
            abort(403, 'Unauthorized action.');
        }
        $month = (string) $request->input('month', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return redirect()->back();
        }
        $d = FatteenGoalsUtil::read();
        $m = $d['metrics'][$month] ?? [];
        foreach (['phones_pct', 'backlog', 'reviews_hw_start', 'reviews_pico_start', 'reviews_hw_end', 'reviews_pico_end'] as $k) {
            $v = trim((string) $request->input($k, ''));
            if ($v === '') {
                unset($m[$k]);
            } elseif (is_numeric($v)) {
                $m[$k] = $v + 0;
            }
        }
        $d['metrics'][$month] = $m;
        FatteenGoalsUtil::write($d);
        return redirect()->back()->with('status', ['success' => 1, 'msg' => 'Numbers saved.']);
    }

    /**
     * Mark a quarterly task done/undone for the current quarter (AJAX auto-save).
     * Once done it won't reappear until the next quarter begins.
     */
    public function togglePeriodic(Request $request)
    {
        $this->guard();

        $key = (string) $request->input('key', '');
        if (!array_key_exists($key, self::QUARTERLY)) {
            return response()->json(['ok' => false, 'msg' => 'Unknown task.'], 422);
        }
        $on = filter_var($request->input('checked'), FILTER_VALIDATE_BOOLEAN);

        $period = self::currentQuarter();
        $userName = auth()->user()->first_name . ' ' . auth()->user()->last_name;

        // Drop any existing record for this period+key, then re-add if ticking on.
        $recs = array_values(array_filter(self::readPeriodic(), function ($r) use ($period, $key) {
            return !(($r['period'] ?? '') === $period && ($r['key'] ?? '') === $key);
        }));
        if ($on) {
            $recs[] = [
                'period'    => $period,
                'key'       => $key,
                'label'     => self::QUARTERLY[$key],
                'user_id'   => auth()->id(),
                'user_name' => $userName,
                'done_at'   => date('Y-m-d H:i'),
            ];
        }
        self::writePeriodic($recs);

        return response()->json(['ok' => true, 'period' => self::quarterLabel($period)]);
    }
}
