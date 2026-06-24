<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Onboarding / Offboarding checklists for new hires and departures. Built for
 * Fatteen (HR) but also open to any Admin. Two checklists in one page, switched
 * by a tab: "onboarding" (new employee) and "offboarding" (employee quits).
 *
 * Each run is logged so there's a record of who was on/offboarded, by whom, and
 * what got skipped. The reviewer enters the employee's name, works the list,
 * checks each step, and submits.
 *
 * Access is limited to Admins and Fatteen (see canAccess) — it's HR-sensitive
 * (passports, agreements, account revocation), not for the sales floor.
 *
 * No migration: storage/app/employee_checklist.json. Renders its own view at
 * employee_checklist.index, styled to match /pos/create.
 */
class EmployeeChecklistController extends Controller
{
    const STORE_PATH = 'employee_checklist.json';

    const TYPE_LABELS = [
        'onboarding'  => 'Onboarding',
        'offboarding' => 'Offboarding',
    ];

    const LISTS = [
        'onboarding' => [
            'Paperwork / HR (do these first)' => [
                'id_i9'        => 'Collect ID for work eligibility — passport (or driver\'s license + Social Security card) for the I-9.',
                'sign_agree'   => 'Have them sign the employment agreement / offer letter.',
                'sign_handbook' => 'Have them sign the employee handbook acknowledgment.',
                'payroll'      => 'Collect direct-deposit and tax (W-4) info for payroll.',
                'emergency'    => 'Get an emergency contact on file.',
            ],
            'Clover (register access)' => [
                'clover_add'   => 'Add the employee in Clover and assign a unique 4-digit POS login code.',
                'clover_role'  => 'Set their role (Cashier — no refund/discount rights unless they\'re a manager).',
                'clover_test'  => 'Confirm they can clock in and ring a test sale.',
            ],
            'ERP (playlist.nivessa.com)' => [
                'erp_create'   => 'Create their ERP user (status Active, login enabled) with the correct role.',
                'erp_login'    => 'Confirm they can log in and reach their daily screens (POS, listing, etc.).',
            ],
            'Slack' => [
                'slack_invite' => 'Invite them to the Nivessa workspace by email.',
                'slack_chan'   => 'Add them to the core channels (#shift-notes and their team channels).',
                'slack_confirm' => 'Confirm they accepted and can post.',
            ],
            'Sling' => [
                'sling_add'    => 'Add the employee in Sling (name, email, phone).',
                'sling_assign' => 'Assign them to the right location (Nivessa / Pico / Hollywood) and position.',
                'sling_shifts' => 'Add them to upcoming shifts and confirm they got the invite.',
            ],
            'Other' => [
                'keys'         => 'Give store keys / alarm code if they need them.',
                'logins'       => 'Set up store email or any vendor logins they need.',
                'walkthrough'  => 'Quick floor walkthrough and intro to the team.',
            ],
        ],
        'offboarding' => [
            'Paperwork / HR' => [
                'last_day'     => 'Confirm last day and reason in writing.',
                'final_pay'    => 'Process the final paycheck per CA law (due on the last day if you terminate; within 72 hours if they quit without notice).',
                'exit_doc'     => 'Have them sign any exit / final acknowledgment doc.',
            ],
            'Clover (register access)' => [
                'clover_deact' => 'Deactivate the employee in Clover (this revokes their POS code).',
                'clover_check' => 'Confirm their code no longer logs into any register.',
            ],
            'ERP (playlist.nivessa.com)' => [
                'erp_disable'  => 'Disable login (do NOT delete the user — that keeps their sales/listing history intact).',
                'erp_reassign' => 'Reassign any open work.',
            ],
            'Slack' => [
                'slack_deact'  => 'Deactivate their account in Workspace Admin > Members.',
                'slack_remove' => 'Remove them from private channels.',
            ],
            'Sling' => [
                'sling_remove' => 'Remove them from all future shifts.',
                'sling_deact'  => 'Deactivate / delete the employee in Sling.',
            ],
            'Other' => [
                'keys'         => 'Collect keys and change the alarm code.',
                'revoke'       => 'Revoke their store email and any vendor logins.',
                'property'     => 'Collect any company property (uniform, equipment).',
            ],
        ],
    ];

    const INTROS = [
        'onboarding'  => 'Run this when a new employee starts. Work top to bottom — paperwork first, then set up each system (Clover, ERP, Slack, Sling). Enter their name, check off what\'s done, and submit to log it.',
        'offboarding' => 'Run this when an employee leaves. Revoke access everywhere (Clover, ERP, Slack, Sling) and close out paperwork. Enter their name, check off what\'s done, and submit to log it.',
    ];

    /* ---------- access ---------- */

    /** Is the current user Fatteen? His ERP account is "Nerdy Solutions". */
    public static function isFatteen()
    {
        $u = auth()->user();
        if (!$u) {
            return false;
        }
        $name = strtolower(trim($u->first_name . ' ' . $u->last_name));
        return strpos($name, 'fatteen') !== false || strpos($name, 'nerdy') !== false;
    }

    /** Admins and Fatteen only — HR-sensitive. */
    public static function canAccess()
    {
        $u = auth()->user();
        if (!$u) {
            return false;
        }
        return $u->hasRole('Admin#' . session('business.id')) || self::isFatteen();
    }

    private function guard()
    {
        if (!self::canAccess()) {
            abort(403, 'Unauthorized action.');
        }
    }

    /* ---------- storage helpers ---------- */

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

    public static function groupsFor($type)
    {
        return self::LISTS[$type] ?? self::LISTS['onboarding'];
    }

    public static function allItems($type)
    {
        $flat = [];
        foreach (self::groupsFor($type) as $items) {
            foreach ($items as $key => $label) {
                $flat[$key] = $label;
            }
        }
        return $flat;
    }

    private function resolveType($requested)
    {
        $requested = strtolower(trim((string) $requested));
        return isset(self::LISTS[$requested]) ? $requested : 'onboarding';
    }

    /* ---------- page ---------- */

    public function index(Request $request)
    {
        $this->guard();

        $type = $this->resolveType($request->input('type'));
        $allKeys = array_keys(self::allItems($type));

        $all = self::readAll();
        $forType = array_values(array_filter($all, function ($r) use ($type) {
            return ($r['type'] ?? 'onboarding') === $type;
        }));
        usort($forType, function ($a, $b) {
            return strcmp($b['completed_at'] ?? '', $a['completed_at'] ?? '');
        });
        $recent = array_slice($forType, 0, 25);

        return view('employee_checklist.index', [
            'groups'      => self::groupsFor($type),
            'totalItems'  => count($allKeys),
            'recent'      => $recent,
            'type'        => $type,
            'typeOptions' => self::TYPE_LABELS,
            'baseUrl'     => url('/employee-checklist'),
            'intro'       => self::INTROS[$type] ?? '',
        ]);
    }

    public function complete(Request $request)
    {
        $this->guard();

        $type = $this->resolveType($request->input('type'));
        $allKeys = array_keys(self::allItems($type));

        $employee = mb_substr(trim((string) $request->input('employee_name', '')), 0, 120);
        if ($employee === '') {
            return redirect()->action('EmployeeChecklistController@index', ['type' => $type])
                ->with('status', ['success' => 0, 'msg' => 'Please enter the employee\'s name before submitting.']);
        }

        $submitted = (array) $request->input('items', []);
        $checked = array_values(array_filter($allKeys, function ($k) use ($submitted) {
            return in_array($k, $submitted, true);
        }));

        // Guard against a stale page: don't log a bogus 0/total.
        if (empty($checked)) {
            return redirect()->action('EmployeeChecklistController@index', ['type' => $type])
                ->with('status', ['success' => 0, 'msg' => 'Nothing was recorded. The page may have been open too long, or no items were checked. Please reload, check what you finished, and submit again.']);
        }

        $missed = array_values(array_diff($allKeys, $checked));

        $all = self::readAll();
        $all[] = [
            'id'            => round(microtime(true) * 1000),
            'date'          => date('Y-m-d'),
            'type'          => $type,
            'type_label'    => self::TYPE_LABELS[$type] ?? '',
            'employee_name' => $employee,
            'user_id'       => auth()->id(),
            'user_name'     => auth()->user()->first_name . ' ' . auth()->user()->last_name,
            'checked'       => $checked,
            'missed'        => $missed,
            'checked_count' => count($checked),
            'total'         => count($allKeys),
            'note'          => mb_substr(trim((string) $request->input('note', '')), 0, 1000),
            'completed_at'  => date('Y-m-d H:i'),
        ];
        self::writeAll($all);

        $label = self::TYPE_LABELS[$type] ?? ucfirst($type);
        $msg = count($missed) === 0
            ? $label . ' for ' . $employee . ' logged — all steps done. Nice work!'
            : $label . ' for ' . $employee . ' logged. ' . count($missed) . ' item(s) still need doing — please finish them.';

        return redirect()->action('EmployeeChecklistController@index', ['type' => $type])
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
