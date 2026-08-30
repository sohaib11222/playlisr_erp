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
                'sign_offer'   => 'Have them sign the offer letter and handbook acknowledgment.',
                'quickbooks'   => 'Make sure they\'re set up in QuickBooks with direct deposit.',
                'emergency'    => 'Get an emergency contact on file.',
            ],
            'Clover (register access)' => [
                'clover_add'   => 'Add the employee in Clover and assign a unique 4-digit POS login code.',
            ],
            'ERP (playlist.nivessa.com)' => [
                'erp_create'   => 'Create their ERP user (status Active, login enabled) with the correct role.',
            ],
            'Slack' => [
                'slack_invite' => 'Invite them to the Nivessa workspace by email.',
                'slack_chan'   => 'Add them to the core channels (#shift-notes and their team channels).',
            ],
            'Sling' => [
                'sling_add'    => 'Add the employee in Sling (name, email, phone).',
            ],
            'Other' => [
                'keys'         => 'Confirm Jon gave them the store keys / alarm code.',
                'training'     => 'Set them up with a 2-hour training.',
            ],
        ],
        'offboarding' => [
            'Paperwork / HR' => [
                'final_pay'    => 'Process the final paycheck per CA law (due on the last day if you terminate; within 72 hours if they quit without notice).',
            ],
            'Clover (register access)' => [
                'clover_deact' => 'Deactivate the employee in Clover (this revokes their POS code).',
            ],
            'ERP (playlist.nivessa.com)' => [
                'erp_disable'  => 'Make their login inactive — do NOT delete the user (that keeps their sales/listing history intact).',
            ],
            'Slack' => [
                'slack_deact'  => 'Deactivate their account in Workspace Admin > Members.',
            ],
            'Sling' => [
                'sling_off'    => 'Deactivate the employee in Sling and make all their future shifts available.',
            ],
            'Other' => [
                'keys'         => 'Confirm Jon collected the keys.',
                'email_pw'     => 'Change the orders@ email password.',
                'discogs_pw'   => 'Change the Discogs password.',
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

        // Editing a past run? Load it so the form pre-fills with that
        // employee's name and whatever was already checked.
        $editId = trim((string) $request->input('edit', ''));
        $editing = null;
        if ($editId !== '') {
            foreach ($forType as $r) {
                if ((string) ($r['id'] ?? '') === $editId) {
                    $editing = $r;
                    break;
                }
            }
        }

        return view('employee_checklist.index', [
            'groups'      => self::groupsFor($type),
            'totalItems'  => count($allKeys),
            'recent'      => $recent,
            'itemLabels'  => self::allItems($type),
            'editing'     => $editing,
            'type'        => $type,
            'typeOptions' => self::TYPE_LABELS,
            'baseUrl'     => url('/employee-checklist'),
            'intro'       => self::INTROS[$type] ?? '',
        ]);
    }

    /**
     * One-click "Compile & Send Offer" for the standard Sales Cashier hire —
     * the role Sarah hires most often. Fills the fixed cashier offer-letter
     * template (resources/views/pdf/cashier_offer_letter) with just the
     * candidate's name/start date, compiles it to a PDF with mpdf (same
     * library TransactionUtil uses for receipts), and emails it via
     * CashierOfferLetter (see app/Mail/CashierOfferLetter.php).
     * Covers the checklist's "sign_offer" step.
     */
    public function sendOffer(Request $request)
    {
        $this->guard();

        $request->validate([
            'full_name'  => 'required|string|max:120',
            'email'      => 'required|email|max:190',
            'start_date' => 'required|string|max:60',
        ]);

        $fullName = trim($request->input('full_name'));
        $email = trim($request->input('email'));
        $startDate = trim($request->input('start_date'));
        $firstName = trim(explode(' ', $fullName)[0]);

        $body = view('pdf.cashier_offer_letter', [
            'firstName' => $firstName,
            'fullName'  => $fullName,
            'startDate' => $startDate,
        ])->render();

        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => public_path('uploads/temp'),
            'mode'    => 'utf-8',
            'format'  => 'A4',
        ]);
        $mpdf->WriteHTML($body);
        $pdfBinary = $mpdf->Output('', 'S');

        $filename = 'Offer Letter - ' . $fullName . '.pdf';

        try {
            \Mail::to($email)->send(new \App\Mail\CashierOfferLetter($firstName, $pdfBinary, $filename));
        } catch (\Throwable $e) {
            \Log::warning('Cashier offer letter email failed: ' . $e->getMessage());
            return redirect()->action('EmployeeChecklistController@index', ['type' => 'onboarding'])
                ->with('status', ['success' => 0, 'msg' => 'Could not send the offer email: ' . $e->getMessage()]);
        }

        return redirect()->action('EmployeeChecklistController@index', ['type' => 'onboarding'])
            ->with('status', ['success' => 1, 'msg' => 'Offer letter compiled and emailed to ' . $email . ' (' . $fullName . ').']);
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
        $userName = auth()->user()->first_name . ' ' . auth()->user()->last_name;
        $note = mb_substr(trim((string) $request->input('note', '')), 0, 1000);

        $all = self::readAll();

        // Editing an existing run? Update it in place so Fatteen can come back
        // and tick off what was skipped instead of creating a duplicate entry.
        $editId = trim((string) $request->input('edit_id', ''));
        if ($editId !== '') {
            foreach ($all as $i => $r) {
                if ((string) ($r['id'] ?? '') === $editId && ($r['type'] ?? '') === $type) {
                    $all[$i]['employee_name'] = $employee;
                    $all[$i]['checked']       = $checked;
                    $all[$i]['missed']        = $missed;
                    $all[$i]['checked_count'] = count($checked);
                    $all[$i]['total']         = count($allKeys);
                    $all[$i]['note']          = $note;
                    $all[$i]['updated_by']    = $userName;
                    $all[$i]['updated_at']    = date('Y-m-d H:i');
                    self::writeAll($all);

                    $left = count($missed);
                    $msg = $left === 0
                        ? 'Updated ' . $employee . '\'s checklist — all steps done now. Nice work!'
                        : 'Updated ' . $employee . '\'s checklist. ' . $left . ' item(s) still left undone.';
                    return redirect()->action('EmployeeChecklistController@index', ['type' => $type])
                        ->with('status', ['success' => 1, 'msg' => $msg]);
                }
            }
            // edit_id no longer found — fall through and log as a new run.
        }

        $all[] = [
            'id'            => round(microtime(true) * 1000),
            'date'          => date('Y-m-d'),
            'type'          => $type,
            'type_label'    => self::TYPE_LABELS[$type] ?? '',
            'employee_name' => $employee,
            'user_id'       => auth()->id(),
            'user_name'     => $userName,
            'checked'       => $checked,
            'missed'        => $missed,
            'checked_count' => count($checked),
            'total'         => count($allKeys),
            'note'          => $note,
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
