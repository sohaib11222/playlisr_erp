<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;

/**
 * Job applications from the careers page, ported from the website's
 * /admin/applications (Sarah, 2026-10-15). Same status tabs, role filter,
 * sorting, rubric scorecard, bulk archive and ?find=<name> deep link.
 */
class ApplicationsController extends Controller
{
    const STATUSES = ['new', 'accepted', 'hold', 'maybe', 'rejected', 'archived'];

    // Applications under this AI screen score are what "Archive under 70%" sweeps.
    const ARCHIVE_AI_THRESHOLD = 70;

    // Keep in sync with the website's careers apply form.
    const ROLE_OPTIONS = [
        'Cashier (Hollywood)',
        'Floor Sales Lead (Hollywood, Fri/Sat/Sun Afternoons)',
        'Inventory Specialist (Hollywood)',
        'Store Manager (Pico or Hollywood)',
        'Visual Merchandiser (Pico or Hollywood)',
        'Live Show Host (Pico or Hollywood)',
        'N.Y.O.R. (Name Your Own Role)',
    ];

    // Store Lead rubric: 14 criteria, 0 to 3 each, out of 42.
    const SCORE_SECTIONS = [
        'Music & Product Knowledge' => [
            'mq1' => 'Personal relationship with music & formats',
            'mq2' => 'Vinyl pressing knowledge (jazz scenario)',
            'mq3' => 'Best record store in LA, operator view',
        ],
        'Commercial Awareness & Customer Engagement' => [
            'ca1' => 'Approaching a browsing customer',
            'ca2' => 'Using sales data as Store Lead',
            'ca3' => 'Helped a customer find the unexpected',
        ],
        'People & Leadership' => [
            'pl1' => 'Held a colleague accountable',
            'pl2' => 'Team knows expectations without you there',
            'pl3' => 'Helped someone develop',
        ],
        'Values & Behaviours' => [
            'vb1' => 'Handled a difficult customer',
            'vb2' => 'Got it done without clear instructions',
            'vb3' => 'Noticed an improvement and acted',
            'vb4' => 'How colleagues would describe you',
        ],
        'Practical Activity' => [
            'act' => 'Floor Walk or Data Review',
        ],
    ];

    const SORTS = ['name', 'role', 'location', 'aiScore', 'score', 'createdAt', 'status'];

    protected function guard()
    {
        if (!auth()->user()->can('product.create') && !auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->guard();

        // Status and role stick per user, like the website's localStorage, so
        // the page reopens on the role being hired for. Default tab is New.
        if ($request->has('status')) {
            $status = (string) $request->query('status');
            session(['wa_inbox_app_status' => $status]);
        } else {
            $status = (string) session('wa_inbox_app_status', 'new');
        }
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            $status = 'new';
        }
        if ($request->has('role')) {
            $role = (string) $request->query('role');
            session(['wa_inbox_app_role' => $role]);
        } else {
            $role = (string) session('wa_inbox_app_role', '');
        }

        $sortBy = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'createdAt';
        $sortDir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) $request->query('page', 1));
        $limit = 50;

        $query = http_build_query(array_filter([
            'page' => $page,
            'limit' => $limit,
            'status' => $status,
            'role' => $role,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ], function ($v) { return $v !== '' && $v !== null; }));
        $resp = WebsiteBridge::admin('GET', '/job-applications/admin/all?' . $query);

        $applications = $resp['data'] ?? [];
        $totalCount = (int) ($resp['totalCount'] ?? 0);
        $counts = array_merge(array_fill_keys(self::STATUSES, 0), $resp['counts'] ?? []);

        // ?find=<name|email|id> opens that applicant whatever tab is saved.
        $find = trim((string) $request->query('find', ''));
        $findHit = null;
        $findMissed = false;
        if ($find !== '') {
            $findHit = $this->findApplication($find);
            $findMissed = $findHit === null;
        }

        return view('website_admin.inbox.applications', [
            'bridgeError' => empty($resp['success']) ? ($resp['message'] ?? 'Could not load applications.') : null,
            'applications' => $applications,
            'totalCount' => $totalCount,
            'totalPages' => (int) ceil($totalCount / $limit),
            'counts' => $counts,
            'status' => $status,
            'role' => $role,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'page' => $page,
            'find' => $find,
            'findHit' => $findHit,
            'findMissed' => $findMissed,
            'roleOptions' => self::ROLE_OPTIONS,
            'scoreSections' => self::SCORE_SECTIONS,
            'maxScore' => count(self::scoreKeys()) * 3,
            'threshold' => self::ARCHIVE_AI_THRESHOLD,
        ]);
    }

    protected function findApplication(string $q)
    {
        $needle = mb_strtolower($q);
        foreach (self::STATUSES as $st) {
            $r = WebsiteBridge::admin('GET', '/job-applications/admin/all?page=1&limit=1000&status=' . $st, null, 30);
            foreach (($r['data'] ?? []) as $a) {
                $name = mb_strtolower((string) ($a['name'] ?? ''));
                $email = mb_strtolower((string) ($a['email'] ?? ''));
                if (($a['_id'] ?? '') === $q || ($needle !== '' && strpos($name, $needle) !== false) || $email === $needle) {
                    return $a;
                }
            }
        }
        return null;
    }

    public function status(Request $request, $id)
    {
        $this->guard();
        $status = (string) $request->input('status');
        if (!in_array($status, self::STATUSES, true)) {
            return back()->with('error', 'Unknown status.');
        }
        $body = ['status' => $status];
        $note = $request->input('note');
        // Reject on the website files the applicant under Archived with the
        // private reason as the note. Nothing is sent to the applicant.
        if ($request->input('reject')) {
            $body['status'] = 'archived';
            $reason = trim((string) $request->input('reason', ''));
            $note = $reason !== '' ? 'Rejected: ' . $reason : 'Rejected';
        }
        if ($note !== null && $note !== '') {
            $body['note'] = $note;
        }
        $resp = WebsiteBridge::admin('PATCH', '/job-applications/admin/status/' . rawurlencode($id), $body);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Something went wrong.');
        }
        $msg = $request->input('reject') ? 'Application rejected and moved to Archived.' : 'Application marked ' . $status . '.';
        return back()->with('status', $msg);
    }

    public function interview(Request $request, $id)
    {
        $this->guard();
        $scheduled = (bool) $request->input('scheduled');
        $resp = WebsiteBridge::admin('PATCH', '/job-applications/admin/interview-scheduled/' . rawurlencode($id), ['scheduled' => $scheduled]);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Something went wrong.');
        }
        return back()->with('status', $scheduled ? 'Interview marked as scheduled.' : 'Interview scheduled mark cleared.');
    }

    public function score(Request $request, $id)
    {
        $this->guard();
        $card = [];
        $total = 0;
        foreach (self::scoreKeys() as $k) {
            $v = max(0, min(3, (int) $request->input('card.' . $k, 0)));
            $card[$k] = $v;
            $total += $v;
        }
        $resp = WebsiteBridge::admin('PATCH', '/job-applications/admin/score/' . rawurlencode($id), ['scorecard' => $card]);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Something went wrong.');
        }
        $max = count(self::scoreKeys()) * 3;
        return back()->with('status', $total ? "Scored {$total}/{$max}." : 'Score cleared.');
    }

    public function destroy(Request $request, $id)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('DELETE', '/job-applications/admin/' . rawurlencode($id));
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Something went wrong.');
        }
        return back()->with('status', 'Application deleted.');
    }

    /** Preview count for the bulk archive (JSON, changes nothing). */
    public function archivePreview(Request $request)
    {
        $this->guard();
        $resp = WebsiteBridge::admin('POST', '/job-applications/admin/archive-low-ai', ['threshold' => self::ARCHIVE_AI_THRESHOLD, 'preview' => true]);
        if (empty($resp['success'])) {
            return response()->json(['success' => false, 'message' => $resp['message'] ?? 'Could not check applications.']);
        }
        return response()->json(['success' => true, 'count' => (int) ($resp['count'] ?? 0)]);
    }

    public function archiveLow(Request $request)
    {
        $this->guard();
        $t = self::ARCHIVE_AI_THRESHOLD;
        $resp = WebsiteBridge::admin('POST', '/job-applications/admin/archive-low-ai', ['threshold' => $t, 'preview' => false], 60);
        if (empty($resp['success'])) {
            return back()->with('error', $resp['message'] ?? 'Something went wrong.');
        }
        $n = (int) ($resp['archived'] ?? 0);
        return back()->with('status', $n ? "Archived {$n} application" . ($n === 1 ? '' : 's') . " under {$t}%." : 'Nothing to archive.');
    }

    public static function scoreKeys(): array
    {
        $keys = [];
        foreach (self::SCORE_SECTIONS as $items) {
            $keys = array_merge($keys, array_keys($items));
        }
        return $keys;
    }

    /** Applicants type "www.x.com" or "N/A"; normalize to a real external link or null. */
    public static function externalUrl($raw)
    {
        $v = trim((string) $raw);
        if ($v === '' || preg_match('/^(n\/?a|none|-)$/i', $v)) {
            return null;
        }
        return preg_match('/^https?:\/\//i', $v) ? $v : 'https://' . $v;
    }

    public static function band($total)
    {
        if (!$total) {
            return null;
        }
        if ($total >= 32) {
            return ['Offer', 'green'];
        }
        if ($total >= 25) {
            return ['Talent pool / Hold', 'amber'];
        }
        return ['Regret', 'red'];
    }
}
