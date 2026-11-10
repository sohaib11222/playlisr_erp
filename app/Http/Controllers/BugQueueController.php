<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bug queue (Sarah 2026-11-09).
 *
 *  - Anyone logged in reports a bug from the "Report a bug" button on every
 *    page (layouts/partials/bug-report-button) and follows its status on
 *    /bug-fixes, so nobody has to ask Sarah one by one.
 *  - The ERP bugs routine (claude.ai cloud routine) pulls new reports from
 *    /bug-queue/api/pending, fixes each on its own branch, opens a PR and
 *    reports back here with a plain-English summary.
 *  - Admins (Sarah, Jon) approve on /bug-fixes, which merges the PR on
 *    GitHub; the merge to main deploys it. "Send back" returns it to the
 *    routine with a note; "Won't fix" closes the PR.
 *
 * Routine auth: the routine sends its GitHub token as a Bearer token. We
 * accept it only if GitHub says that token can push to the ERP repo (the
 * same bar as pushing code, which deploys anyway). The last good token is
 * kept in storage/app/bug-queue/settings.json and used for merging, so no
 * extra secret has to be set up anywhere.
 */
class BugQueueController extends Controller
{
    const REPO = 'sohaib11222/playlisr_erp';
    const SETTINGS = 'bug-queue/settings.json';

    const STATUS_LABELS = [
        'new' => 'Waiting for a fix',
        'rework' => 'Being reworked',
        'needs_info' => 'Question for Sarah and Jon',
        'fix_ready' => 'Fix ready, waiting for approval',
        'merged' => 'Fixed and live',
        'wont_fix' => "Won't fix",
    ];

    // ---------------------------------------------------------------- helpers

    private function isAdmin(): bool
    {
        $u = auth()->user();
        try {
            return $u && ($u->can('superadmin') || $u->hasAnyPermission('Admin#' . $u->business_id)
                || $u->hasRole('Admin#' . $u->business_id));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            abort(403, 'Only Sarah and Jon can approve fixes.');
        }
    }

    private static function settings(): array
    {
        try {
            if (Storage::exists(self::SETTINGS)) {
                $s = json_decode(Storage::get(self::SETTINGS), true);
                return is_array($s) ? $s : [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    private static function saveSettings(array $s): void
    {
        Storage::put(self::SETTINGS, json_encode($s, JSON_PRETTY_PRINT));
    }

    /** Call the GitHub REST API. Returns [http_code, decoded_body]. */
    private static function github(string $method, string $path, ?string $token, array $body = null): array
    {
        $ch = curl_init('https://api.github.com' . $path);
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: nivessa-erp-bug-queue',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($headers, $body !== null ? ['Content-Type: application/json'] : []),
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode((string) $raw, true)];
    }

    /** Routine auth: Bearer GitHub token that can push to the ERP repo. */
    private function routineAuthorized(Request $request): bool
    {
        $token = (string) $request->bearerToken();
        if (strlen($token) < 20) {
            return false;
        }
        $hash = hash('sha256', $token);
        $s = self::settings();
        if (($s['token_hash'] ?? '') === $hash && ($s['token_checked_at'] ?? 0) > time() - 3600) {
            return true;
        }
        [$code, $repo] = self::github('GET', '/repos/' . self::REPO, $token);
        if ($code !== 200 || empty($repo['permissions']['push'])) {
            return false;
        }
        $s['token'] = encrypt($token);
        $s['token_hash'] = $hash;
        $s['token_checked_at'] = time();
        self::saveSettings($s);
        return true;
    }

    private static function storedToken(): ?string
    {
        $s = self::settings();
        try {
            return !empty($s['token']) ? decrypt($s['token']) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** "Fix now" requests: [id => ['by' => name, 'at' => 'Y-m-d H:i:s']] kept in settings.json. */
    private static function fixNowList(): array
    {
        $s = self::settings();
        return isset($s['fix_now']) && is_array($s['fix_now']) ? $s['fix_now'] : [];
    }

    private static function clearFixNow(int $id): void
    {
        $s = self::settings();
        if (isset($s['fix_now'][$id]) || isset($s['fix_now'][(string) $id])) {
            unset($s['fix_now'][$id], $s['fix_now'][(string) $id]);
            self::saveSettings($s);
        }
    }

    private function userName(): string
    {
        $u = auth()->user();
        return $u ? trim($u->first_name . ' ' . $u->last_name) : 'Someone';
    }


    /** Current staff for the tag picker: [[id, name], ...], Sarah and Jon first. */
    public static function taggableUsers(int $business_id): array
    {
        if ($business_id <= 0) {
            return [];
        }
        return \Cache::remember('bug_queue_taggable_' . $business_id, 600, function () use ($business_id) {
            $rows = DB::table('users')->where('business_id', $business_id)->whereNull('deleted_at')
                ->where('status', 'active')->where('allow_login', 1)->where('user_type', 'user')
                ->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
            $out = [];
            foreach ($rows as $u) {
                $name = trim($u->first_name . ' ' . $u->last_name);
                if ($name === '') {
                    continue;
                }
                $first = strtolower(trim($u->first_name));
                $rank = in_array($first, ['sarah'], true) ? 0 : (in_array($first, ['jon', 'jonathan'], true) ? 1 : 2);
                $out[] = ['id' => (int) $u->id, 'name' => $name, 'rank' => $rank];
            }
            usort($out, function ($a, $b) {
                return [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']];
            });
            return array_map(function ($u) {
                return [$u['id'], $u['name']];
            }, $out);
        });
    }

    private static function idList(?string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $csv))));
    }

    private static function userNames(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(function ($u) {
                return [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)];
            })->all();
    }

    /** Slack DM each user (best effort, never throws). */
    private static function notify(int $business_id, array $user_ids, string $text): void
    {
        try {
            $ids = \App\Services\TimeClockService::slackIds($business_id);
            foreach (array_unique($user_ids) as $uid) {
                if (!empty($ids[$uid])) {
                    $r = \App\Services\TimeClockService::slackDm($ids[$uid], $text);
                    if (!$r['ok']) {
                        \Log::info('bug queue DM to user ' . $uid . ' failed: ' . $r['error']);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::info('bug queue notify failed: ' . $e->getMessage());
        }
    }

    private static function short(string $text, int $n = 160): string
    {
        $t = preg_replace('/\s+/', ' ', trim($text));
        return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 3) . '...' : $t;
    }

    /** Server errors logged in the 15 minutes before a report (for the fixer). */
    private static function nearbyErrors(string $created_at): array
    {
        try {
            $t = \Carbon\Carbon::parse($created_at);
            $from = $t->copy()->subMinutes(15)->format('Y-m-d H:i:s');
            $to = $t->copy()->addMinutes(2)->format('Y-m-d H:i:s');
            $files = [storage_path('logs/laravel-' . $t->format('Y-m-d') . '.log'), storage_path('logs/laravel.log')];
            $out = [];
            foreach ($files as $f) {
                if (!is_file($f)) {
                    continue;
                }
                $size = filesize($f);
                $fh = fopen($f, 'r');
                fseek($fh, max(0, $size - 4 * 1024 * 1024));
                while (($line = fgets($fh)) !== false) {
                    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|EMERGENCY|ALERT)/', $line, $m)
                        && $m[1] >= $from && $m[1] <= $to) {
                        $out[] = mb_substr(trim($line), 0, 600);
                    }
                }
                fclose($fh);
                if ($out) {
                    break;
                }
            }
            return array_slice(array_values(array_unique($out)), -8);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------ staff pages

    public function index(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $is_admin = $this->isAdmin();
        $tab = (string) $request->input('tab', $is_admin ? 'review' : 'open');

        $q = DB::table('erp_bug_reports')->where(function ($q) use ($business_id) {
            $q->where('business_id', $business_id)->orWhereNull('business_id');
        });
        $counts = (clone $q)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all();

        $tabs = [
            'review' => ['Ready to approve', ['fix_ready']],
            'open' => ['Waiting for a fix', ['new', 'rework', 'needs_info']],
            'done' => ['Fixed', ['merged']],
            'wont_fix' => ["Won't fix", ['wont_fix']],
            'mine' => ['My reports', null],
            'tagged' => ['Tagged me', null],
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'open';
        }
        $list = clone $q;
        if ($tab === 'mine') {
            $list->where('reported_by', auth()->id());
        } elseif ($tab === 'tagged') {
            $list->where('tagged_user_ids', 'like', '%,' . (int) auth()->id() . ',%');
        } else {
            $list->whereIn('status', $tabs[$tab][1]);
        }
        $reports = $list->orderByRaw($tab === 'done' ? 'merged_at desc' : 'created_at desc')->limit(200)->get();

        $all_tagged = [];
        foreach ($reports as $r) {
            $all_tagged = array_merge($all_tagged, self::idList($r->tagged_user_ids));
        }
        $tag_names = self::userNames(array_unique($all_tagged));

        $tab_counts = [];
        foreach ($tabs as $k => $t) {
            $tab_counts[$k] = $t[1] === null ? null : array_sum(array_intersect_key($counts, array_flip($t[1])));
        }

        $settings = self::settings();
        $routine_seen = !empty($settings['last_poll_at']) ? $settings['last_poll_at'] : null;

        return view('bug_queue.index', [
            'reports' => $reports,
            'tab' => $tab,
            'tabs' => $tabs,
            'tab_counts' => $tab_counts,
            'is_admin' => $is_admin,
            'labels' => self::STATUS_LABELS,
            'routine_seen' => $routine_seen,
            'have_token' => self::storedToken() !== null,
            'tag_names' => $tag_names,
            'fix_now' => self::fixNowList(),
        ]);
    }

    /** "Report a bug" modal on every page. */
    public function report(Request $request)
    {
        $request->validate([
            'description' => 'required|string|max:5000',
            'screenshot' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp,bmp,heic,heif|max:20480',
        ]);
        $business_id_for_tags = (int) $request->session()->get('user.business_id');
        $allowed = array_column(self::taggableUsers($business_id_for_tags), 0);
        $tagged = array_values(array_intersect(array_map('intval', (array) $request->input('tagged', [])), $allowed));
        $context = mb_substr((string) $request->input('context'), 0, 6000);
        $business_id = (int) $request->session()->get('user.business_id');
        $location = null;
        try {
            $loc_id = auth()->user()->location_id ?? null;
            if ($loc_id) {
                $location = DB::table('business_locations')->where('id', $loc_id)->value('name');
            }
        } catch (\Throwable $e) {
        }

        $id = DB::table('erp_bug_reports')->insertGetId([
            'business_id' => $business_id ?: null,
            'reported_by' => auth()->id(),
            'reporter_name' => $this->userName(),
            'tagged_user_ids' => $tagged ? ',' . implode(',', $tagged) . ',' : null,
            'client_context' => $context !== '' ? $context : null,
            'source' => 'erp',
            'kind' => $request->input('kind') === 'request' ? 'request' : 'bug',
            'page_url' => substr((string) $request->input('page_url'), 0, 1000),
            'location_name' => $location,
            'description' => trim((string) $request->input('description')),
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($request->hasFile('screenshot')) {
            $path = $request->file('screenshot')->storeAs('bug-queue/screenshots', $id . '.' . $request->file('screenshot')->extension());
            DB::table('erp_bug_reports')->where('id', $id)->update(['screenshot_path' => $path]);
        }

        if ($tagged) {
            $kind = $request->input('kind') === 'request' ? 'a fix request' : 'a bug';
            self::notify($business_id, $tagged, $this->userName() . ' tagged you on ' . $kind . ' (#' . $id . '): "'
                . self::short((string) $request->input('description')) . '" ' . url('/bug-fixes?tab=tagged#bug-' . $id));
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'id' => $id, 'url' => url('/bug-fixes?tab=mine')]);
        }
        return redirect('/bug-fixes?tab=mine')->with('status', ['success' => true, 'msg' => 'Thanks, #' . $id . ' is in the queue.']);
    }

    public function screenshot(Request $request, $id)
    {
        $path = DB::table('erp_bug_reports')->where('id', (int) $id)->value('screenshot_path');
        if (!$path || !Storage::exists($path)) {
            abort(404);
        }
        return response(Storage::get($path))->header('Content-Type', Storage::mimeType($path));
    }

    /** Reporter answers the routine's question. */
    public function answer(Request $request, $id)
    {
        $this->requireAdmin();
        $request->validate(['answer' => 'required|string|max:5000']);
        $row = DB::table('erp_bug_reports')->where('id', (int) $id)->first();
        if (!$row || $row->status !== 'needs_info') {
            return back()->with('status', ['success' => false, 'msg' => 'That bug is not waiting on an answer.']);
        }
        $answer = trim(($row->answer ? $row->answer . "\n\n" : '') . $this->userName() . ': ' . $request->input('answer'));
        DB::table('erp_bug_reports')->where('id', $row->id)->update([
            'answer' => $answer, 'status' => 'new', 'updated_at' => now(),
        ]);
        // Answered by Sarah/Jon: send it straight to the Fix now lane.
        $s = self::settings();
        $s['fix_now'] = isset($s['fix_now']) && is_array($s['fix_now']) ? $s['fix_now'] : [];
        $s['fix_now'][(string) $row->id] = ['by' => $this->userName(), 'at' => now()->toDateTimeString()];
        self::saveSettings($s);
        return back()->with('status', ['success' => true, 'msg' => 'Thanks, Claude picks #' . $row->id . ' back up within about 10 minutes.']);
    }

    // --------------------------------------------------------- admin actions

    public function approve(Request $request, $id)
    {
        $this->requireAdmin();
        $row = DB::table('erp_bug_reports')->where('id', (int) $id)->first();
        if (!$row || $row->status !== 'fix_ready' || !$row->pr_number) {
            return back()->with('status', ['success' => false, 'msg' => 'Bug #' . (int) $id . ' has no fix ready to approve.']);
        }
        $token = self::storedToken();
        if (!$token) {
            return back()->with('status', ['success' => false, 'msg' => 'The fixer has not checked in yet, so the ERP cannot merge. Try again after its next run.']);
        }

        [$code, $res] = self::github('PUT', '/repos/' . self::REPO . '/pulls/' . (int) $row->pr_number . '/merge', $token, [
            'merge_method' => 'squash',
            'commit_title' => 'Bug #' . $row->id . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string) $row->fix_summary), 0, 60)
                . ' (#' . (int) $row->pr_number . ')',
            'commit_message' => 'Approved by ' . $this->userName() . ' on /bug-fixes.',
        ]);

        if ($code === 200 && !empty($res['merged'])) {
            DB::table('erp_bug_reports')->where('id', $row->id)->update([
                'status' => 'merged',
                'decided_by' => auth()->id(),
                'decided_by_name' => $this->userName(),
                'decided_at' => now(),
                'merged_at' => now(),
                'updated_at' => now(),
            ]);
            if ($row->branch) {
                self::github('DELETE', '/repos/' . self::REPO . '/git/refs/heads/' . $row->branch, $token);
            }
            $who = array_merge($row->reported_by ? [(int) $row->reported_by] : [], self::idList($row->tagged_user_ids));
            $who = array_diff($who, [(int) auth()->id()]);
            if ($who) {
                self::notify((int) $row->business_id, $who, 'Fixed: #' . $row->id . ' "' . self::short((string) $row->description, 100)
                    . '" goes live in a few minutes. ' . self::short((string) $row->fix_summary, 300) . ' ' . url('/bug-fixes?tab=done#bug-' . $row->id));
            }
            return back()->with('status', ['success' => true, 'msg' => 'Bug #' . $row->id . ' approved. It goes live in a few minutes.']);
        }

        // Already merged on GitHub (someone merged by hand)?
        [$pc, $pr] = self::github('GET', '/repos/' . self::REPO . '/pulls/' . (int) $row->pr_number, $token);
        if ($pc === 200 && !empty($pr['merged'])) {
            DB::table('erp_bug_reports')->where('id', $row->id)->update([
                'status' => 'merged', 'decided_by' => auth()->id(), 'decided_by_name' => $this->userName(),
                'decided_at' => now(), 'merged_at' => now(), 'updated_at' => now(),
            ]);
            return back()->with('status', ['success' => true, 'msg' => 'Bug #' . $row->id . ' was already merged. Marked as fixed.']);
        }

        $why = $res['message'] ?? ('GitHub error ' . $code);
        if ($code === 405 || $code === 409) {
            // Conflicts with newer code: send it back to the routine to redo.
            DB::table('erp_bug_reports')->where('id', $row->id)->update([
                'status' => 'rework',
                'review_note' => 'Could not merge (' . $why . '). Please redo the fix on top of the latest main.',
                'updated_at' => now(),
            ]);
            return back()->with('status', ['success' => false, 'msg' => 'Bug #' . $row->id . ' clashes with newer code, so it went back to the fixer to redo.']);
        }
        return back()->with('status', ['success' => false, 'msg' => 'Could not merge bug #' . $row->id . ': ' . $why]);
    }

    public function sendBack(Request $request, $id)
    {
        $this->requireAdmin();
        $request->validate(['note' => 'required|string|max:3000']);
        $row = DB::table('erp_bug_reports')->where('id', (int) $id)->first();
        if (!$row) {
            abort(404);
        }
        DB::table('erp_bug_reports')->where('id', $row->id)->update([
            'status' => 'rework',
            'review_note' => $this->userName() . ': ' . trim((string) $request->input('note')),
            'decided_by' => auth()->id(),
            'decided_by_name' => $this->userName(),
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
        return back()->with('status', ['success' => true, 'msg' => 'Bug #' . $row->id . ' sent back with your note.']);
    }

    public function wontFix(Request $request, $id)
    {
        $this->requireAdmin();
        $row = DB::table('erp_bug_reports')->where('id', (int) $id)->first();
        if (!$row) {
            abort(404);
        }
        $token = self::storedToken();
        if ($token && $row->pr_number) {
            self::github('PATCH', '/repos/' . self::REPO . '/pulls/' . (int) $row->pr_number, $token, ['state' => 'closed']);
            if ($row->branch) {
                self::github('DELETE', '/repos/' . self::REPO . '/git/refs/heads/' . $row->branch, $token);
            }
        }
        self::clearFixNow((int) $row->id);
        DB::table('erp_bug_reports')->where('id', $row->id)->update([
            'status' => 'wont_fix',
            'review_note' => trim((string) $request->input('note')) !== '' ? $this->userName() . ': ' . trim((string) $request->input('note')) : $row->review_note,
            'decided_by' => auth()->id(),
            'decided_by_name' => $this->userName(),
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
        return back()->with('status', ['success' => true, 'msg' => 'Bug #' . $row->id . " marked won't fix."]);
    }

    /** "Fix now": ask the fixer to work this one right away (picked up within ~10 minutes). */
    public function fixNow(Request $request, $id)
    {
        $this->requireAdmin();
        $row = DB::table('erp_bug_reports')->where('id', (int) $id)->first();
        if (!$row || !in_array($row->status, ['new', 'rework'], true)) {
            return back()->with('status', ['success' => false, 'msg' => 'Bug #' . (int) $id . ' is not waiting for a fix.']);
        }
        $s = self::settings();
        $s['fix_now'] = isset($s['fix_now']) && is_array($s['fix_now']) ? $s['fix_now'] : [];
        $s['fix_now'][(string) $row->id] = ['by' => $this->userName(), 'at' => now()->toDateTimeString()];
        self::saveSettings($s);
        return back()->with('status', ['success' => true, 'msg' => 'Sent #' . $row->id . ' to Claude. It starts within about 10 minutes and shows up under Ready to approve when done.']);
    }

    /** Put a closed report back in the queue. */
    public function reopen(Request $request, $id)
    {
        $this->requireAdmin();
        DB::table('erp_bug_reports')->where('id', (int) $id)->update(['status' => 'new', 'updated_at' => now()]);
        return back()->with('status', ['success' => true, 'msg' => 'Bug #' . (int) $id . ' is back in the queue.']);
    }

    // ------------------------------------------------------- routine API

    /** GET /bug-queue/api/pending — reports for the routine to work on. */
    public function apiPending(Request $request)
    {
        if (!$this->routineAuthorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $s = self::settings();
        $s['last_poll_at'] = now()->toDateTimeString();
        self::saveSettings($s);

        $fix_now = self::fixNowList();
        $rows = DB::table('erp_bug_reports')->whereIn('status', ['new', 'rework'])->orderBy('created_at')->limit(50)->get();
        $rows = $rows->sortBy(function ($r) use ($fix_now) {
            return isset($fix_now[(string) $r->id]) ? 0 : 1;
        })->values();
        return response()->json(['reports' => $rows->map(function ($r) use ($fix_now) {
            return [
                'id' => $r->id,
                'fix_now' => isset($fix_now[(string) $r->id]),
                'status' => $r->status,
                'source' => $r->source,
                'kind' => $r->kind,
                'slack_ts' => $r->slack_ts,
                'reporter' => $r->reporter_name,
                'tagged' => array_values(self::userNames(self::idList($r->tagged_user_ids))),
                'browser_context' => $r->client_context ? json_decode($r->client_context, true) : null,
                'server_errors_near_report_time' => self::nearbyErrors((string) $r->created_at),
                'store' => $r->location_name,
                'page_url' => $r->page_url,
                'description' => $r->description,
                'screenshot_url' => $r->screenshot_path ? url('/bug-queue/api/' . $r->id . '/screenshot') : null,
                'earlier_question' => $r->question,
                'reporter_answer' => $r->answer,
                'review_note' => $r->review_note,
                'pr_number' => $r->pr_number,
                'branch' => $r->branch,
                'created_at' => $r->created_at,
            ];
        })->values()]);
    }

    /**
     * GET /bug-queue/api/fix-now — cheap check for the every-10-minutes fixer:
     * ids someone pressed "Fix now" on that still wait for a fix.
     */
    public function apiFixNow(Request $request)
    {
        if (!$this->routineAuthorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $fix_now = self::fixNowList();
        $ids = array_map('intval', array_keys($fix_now));
        $live = $ids ? DB::table('erp_bug_reports')->whereIn('id', $ids)->whereIn('status', ['new', 'rework'])->pluck('id')->map(function ($i) {
            return (int) $i;
        })->all() : [];
        $stale = array_diff($ids, $live);
        if ($stale) {
            $s = self::settings();
            foreach ($stale as $i) {
                unset($s['fix_now'][(string) $i], $s['fix_now'][$i]);
            }
            self::saveSettings($s);
        }
        sort($live);
        return response()->json(['ids' => array_values($live)]);
    }

    public function apiScreenshot(Request $request, $id)
    {
        if (!$this->routineAuthorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $path = DB::table('erp_bug_reports')->where('id', (int) $id)->value('screenshot_path');
        if (!$path || !Storage::exists($path)) {
            abort(404);
        }
        return response(Storage::get($path))->header('Content-Type', Storage::mimeType($path));
    }


    /**
     * POST /bug-queue/api/digest — end of the daily fixer run: one Slack DM
     * to Sarah and Jon with how many fixes wait for approval (none if zero).
     */
    public function apiDigest(Request $request)
    {
        if (!$this->routineAuthorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $n = DB::table('erp_bug_reports')->where('status', 'fix_ready')->count();
        $open = DB::table('erp_bug_reports')->whereIn('status', ['new', 'rework', 'needs_info'])->count();
        if ($n === 0) {
            return response()->json(['ok' => true, 'sent' => false, 'ready' => 0]);
        }
        $business_id = (int) DB::table('business')->orderBy('id')->value('id');
        $approvers = [];
        foreach (self::taggableUsers($business_id) as $u) {
            $first = strtolower(strtok($u[1], ' '));
            if (in_array($first, ['sarah', 'jon', 'jonathan'], true)) {
                $approvers[] = $u[0];
            }
        }
        self::notify($business_id, $approvers, $n . ' bug ' . ($n === 1 ? 'fix is' : 'fixes are') . ' ready to approve'
            . ($open ? ' (' . $open . ' still open)' : '') . ': ' . url('/bug-fixes?tab=review'));
        return response()->json(['ok' => true, 'sent' => true, 'ready' => $n, 'approvers' => count($approvers)]);
    }

    /** POST /bug-queue/api/report — routine imports a #erp-bugs Slack post. */
    public function apiReport(Request $request)
    {
        if (!$this->routineAuthorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $desc = trim((string) $request->input('description'));
        if ($desc === '') {
            return response()->json(['error' => 'description required'], 422);
        }
        $ts = (string) $request->input('slack_ts');
        if ($ts !== '') {
            $existing = DB::table('erp_bug_reports')->where('slack_ts', $ts)->value('id');
            if ($existing) {
                return response()->json(['id' => $existing, 'duplicate' => true]);
            }
        }
        $business_id = DB::table('business')->orderBy('id')->value('id');
        $id = DB::table('erp_bug_reports')->insertGetId([
            'business_id' => $business_id,
            'reporter_name' => mb_substr((string) $request->input('reporter', 'Slack'), 0, 191),
            'source' => 'slack',
            'kind' => $request->input('kind') === 'request' ? 'request' : 'bug',
            'slack_ts' => $ts !== '' ? $ts : null,
            'page_url' => mb_substr((string) $request->input('page_url'), 0, 1000) ?: null,
            'description' => $desc,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'duplicate' => false]);
    }

    /**
     * POST /bug-queue/api/{id}/update — routine reports back.
     *   status=fix_ready + pr_number, pr_url, branch, fix_summary, test_steps
     *   status=needs_info + question
     *   status=merged (it was already fixed by an earlier PR) + fix_summary
     *   status=wont_fix + fix_summary (why: not a bug, duplicate of #N, ...)
     */
    public function apiUpdate(Request $request, $id)
    {
        if (!$this->routineAuthorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $row = DB::table('erp_bug_reports')->where('id', (int) $id)->first();
        if (!$row) {
            return response()->json(['error' => 'not found'], 404);
        }
        $status = (string) $request->input('status');
        if (!in_array($status, ['fix_ready', 'needs_info', 'merged', 'wont_fix'], true)) {
            return response()->json(['error' => 'status must be fix_ready, needs_info, merged or wont_fix'], 422);
        }
        $upd = ['status' => $status, 'updated_at' => now()];
        foreach (['fix_summary', 'test_steps', 'pr_url', 'branch', 'question'] as $f) {
            if ($request->has($f)) {
                $upd[$f] = (string) $request->input($f);
            }
        }
        if ($request->has('pr_number')) {
            $upd['pr_number'] = (int) $request->input('pr_number') ?: null;
        }
        if ($status === 'fix_ready') {
            if (empty($upd['pr_number']) && empty($row->pr_number)) {
                return response()->json(['error' => 'pr_number required for fix_ready'], 422);
            }
            $upd['review_note'] = $row->review_note; // keep history visible
        }
        if ($status === 'merged') {
            $upd['merged_at'] = now();
        }
        DB::table('erp_bug_reports')->where('id', $row->id)->update($upd);
        self::clearFixNow((int) $row->id);
        // Sarah 11/10/26: the fixer asks Sarah and Jon, never the employee who reported it.
        if ($status === 'needs_info' && !empty($upd['question'])) {
            $sarah = [];
            foreach (self::taggableUsers((int) ($row->business_id ?: DB::table('business')->orderBy('id')->value('id'))) as $u) {
                if (in_array(strtolower(strtok($u[1], ' ')), ['sarah', 'jon', 'jonathan'], true)) {
                    $sarah[] = $u[0];
                }
            }
            self::notify((int) $row->business_id, $sarah, 'Question about #' . $row->id . ' (from ' . ($row->reporter_name ?: 'staff') . '): '
                . self::short($upd['question'], 400) . ' Answer here: ' . url('/bug-fixes?tab=open#bug-' . $row->id));
        }
        return response()->json(['ok' => true, 'id' => $row->id, 'status' => $status, 'review_url' => url('/bug-fixes?tab=review')]);
    }
}
