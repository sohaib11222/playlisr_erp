<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    const RATINGS = ['great' => 'Great', 'good' => 'Good', 'needs_work' => 'Needs work'];

    // Text questions, in form order. Key => [label, hint].
    const QUESTIONS = [
        'highlight' => ['Highlight', 'Best thing they did or what went well this week'],
        'lowlight'  => ['Lowlight', 'What did not go well, or what they are struggling with'],
        'erp'       => ['ERP updates they asked for', 'Anything in the ERP or POS they want fixed or added'],
        'ideas'     => ['Good ideas we should implement', 'Ideas from them worth trying'],
        'notes'     => ['Anything else for Jon', 'Optional'],
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

    /** Active staff logins for the employee dropdown. */
    private function employees($businessId)
    {
        return DB::table('users')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->where(function ($q) {
                $q->whereNull('user_type')->orWhere('user_type', 'user');
            })
            ->orderBy('first_name')
            ->select('id', 'first_name', 'last_name')
            ->get();
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

        $filterEmployee = (int) $request->input('employee_id');
        if ($filterEmployee) {
            $rows = array_filter($rows, function ($r) use ($filterEmployee) {
                return (int) ($r['employee_id'] ?? 0) === $filterEmployee;
            });
        }

        return view('manager_checkin.index', [
            'employees'      => $this->employees($businessId),
            'rows'           => array_values($rows),
            'isAdmin'        => $isAdmin,
            'filterEmployee' => $filterEmployee,
            'ratings'        => self::RATINGS,
            'questions'      => self::QUESTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $this->guard();

        $businessId = $request->session()->get('user.business_id') ?: auth()->user()->business_id;
        $employeeId = (int) $request->input('employee_id');
        $employee   = $this->employees($businessId)->first(function ($u) use ($employeeId) {
            return (int) $u->id === $employeeId;
        });
        $rating     = (string) $request->input('rating');
        $date       = (string) $request->input('date');

        if (!$employee || !isset(self::RATINGS[$rating]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return redirect()->action('ManagerCheckinController@index')
                ->withInput()
                ->with('status', ['success' => 0, 'msg' => 'Pick the employee, date and how they are doing.']);
        }

        $me    = auth()->user();
        $entry = [
            'id'            => uniqid('ci_'),
            'business_id'   => (int) $businessId,
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

        return redirect()->action('ManagerCheckinController@index')
            ->with('status', ['success' => 1, 'msg' => 'Saved check-in for ' . $entry['employee_name'] . '.']);
    }
}
