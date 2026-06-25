<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
     * The daily tasks, grouped. Keys are stable ids (used in storage); edit the
     * labels freely, but don't rename a key once it's in use or old records lose
     * the mapping.
     */
    const GROUPS = [
        'Start of day' => [
            'sling_week' => 'Check Sling first — make sure we\'re covered and good for the whole week.',
        ],
        'Phones, messages & email — keep these going while you work (answer calls as they come in)' => [
            'phone_texts' => 'Check the store phone and texts — start after 9am, not before (watch for the overcharges).',
            'whatsapp'    => 'Check WhatsApp and reply.',
            'hello_email' => 'Reply to hello@nivessa.com.',
            'discogs'     => 'Check Discogs orders and messages.',
        ],
        'Sales & accounting' => [
            'sales_feed'  => 'Daily sales feed: make sure every transaction is in BOTH the ERP and Clover, and DM anyone whose sale is missing.',
            'accounting'  => 'Do the day\'s accounting transactions.',
        ],
        'Events & social' => [
            'events_cal'    => 'Update the events calendar.',
            'events_ready'  => 'Make sure we\'re ready for the upcoming events.',
            'events_social' => 'Post the events on social media.',
        ],
        'People & operations' => [
            'scheduling'  => 'Set up the employee scheduling.',
            'onboarding'  => 'Handle any onboarding / offboarding for new or departing employees.',
            'purchasing'  => 'Help with purchasing.',
            'cameras'     => 'Check the cameras — staff are working, not on their phones.',
        ],
    ];

    /** Optional helper links shown next to a task. */
    const LINKS = [
        'sales_feed' => '/pos/recent-feed',
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

    /** Fatteen + Jon only — this is Fatteen's personal list; Jon oversees it. */
    public static function canAccess()
    {
        return EmployeeChecklistController::isFatteen() || self::isJon();
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

        // Recent history (all users, most recent first) so admins can review.
        $recent = $all;
        usort($recent, function ($a, $b) {
            return strcmp(($b['date'] ?? '') . ($b['updated_at'] ?? ''), ($a['date'] ?? '') . ($a['updated_at'] ?? ''));
        });
        $recent = array_slice($recent, 0, 30);

        return view('daily_checklist.index', [
            'groups'     => self::GROUPS,
            'links'      => self::LINKS,
            'checked'    => $checked,
            'totalItems' => count(self::allKeys()),
            'today'      => $date,
            'recent'     => $recent,
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
}
