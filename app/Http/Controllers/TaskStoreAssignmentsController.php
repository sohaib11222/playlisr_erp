<?php

namespace App\Http\Controllers;

use App\User;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

/**
 * Admin-only page to explicitly set each employee's store (Pico/Hollywood)
 * for Tasks/Employee Tasks visibility. Needed because most Cashier-role
 * accounts have access_all_locations = true for POS purposes, so location
 * permissions can't be used to infer which store's tasks someone should see
 * (see OpeningChecklistController::storesForUser()).
 */
class TaskStoreAssignmentsController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function guard()
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->guard();
        $business_id = $request->session()->get('user.business_id');

        $users = User::where('business_id', $business_id)
            ->user()
            ->where('is_cmmsn_agnt', 0)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'home_store']);

        $storeLabels = TaskController::STORE_LABELS;

        return view('admin.task_store_assignments', compact('users', 'storeLabels'));
    }

    public function save(Request $request)
    {
        $this->guard();
        $business_id = $request->session()->get('user.business_id');

        $assignments = $request->input('home_store', []);
        $validStores = array_keys(TaskController::STORE_LABELS);

        $users = User::where('business_id', $business_id)
            ->whereIn('id', array_keys($assignments))
            ->get();

        foreach ($users as $user) {
            $value = $assignments[$user->id] ?? '';
            $user->home_store = in_array($value, $validStores, true) ? $value : null;
            $user->save();
        }

        return redirect()->action('TaskStoreAssignmentsController@index')
            ->with('status', ['success' => true, 'msg' => 'Store assignments saved.']);
    }
}
