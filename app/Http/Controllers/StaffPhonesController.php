<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;

/**
 * Staff cell numbers for the start-of-shift task text
 * (SendPendingAssignmentTexts). Stored on users.contact_number. Sling's API
 * doesn't expose phone numbers, so they're entered here once.
 */
class StaffPhonesController extends Controller
{
    private function guard()
    {
        if (!TeamProgressController::canView()) {
            abort(403, 'Unauthorized action.');
        }
    }

    public static function staff($business_id)
    {
        return User::where('business_id', $business_id)
            ->user()
            ->where('is_cmmsn_agnt', 0)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->orderBy('first_name')
            ->get();
    }

    public function index(Request $request)
    {
        $this->guard();
        $business_id = $request->session()->get('user.business_id');
        $staff = self::staff($business_id);
        $onSling = \App\SlingShift::whereNotNull('erp_user_id')
            ->where('dtstart', '>=', now()->subDays(14))
            ->distinct()->pluck('erp_user_id')->map(function ($id) { return (int) $id; })->all();
        return view('tasks.staff_phones', compact('staff', 'onSling'));
    }

    public function save(Request $request)
    {
        $this->guard();
        $business_id = $request->session()->get('user.business_id');
        $staff = self::staff($business_id)->keyBy('id');
        $phones = (array) $request->input('phones', []);
        $changed = 0;
        foreach ($phones as $id => $raw) {
            if (!isset($staff[(int) $id])) {
                continue;
            }
            $digits = preg_replace('/\D/', '', (string) $raw);
            if ($digits !== '' && strlen($digits) === 10) {
                $digits = '1' . $digits;
            }
            $clean = $digits === '' ? null : '+' . $digits;
            if ($clean !== null && strlen($digits) !== 11) {
                return redirect()->back()->withInput()->with('status', ['success' => false, 'msg' => 'Check the number for ' . $staff[(int) $id]->first_name . '. Use a 10-digit US cell number.']);
            }
            $u = $staff[(int) $id];
            if ((string) $u->contact_number !== (string) $clean) {
                $u->contact_number = $clean;
                $u->save();
                $changed++;
            }
        }
        return redirect()->back()->with('status', ['success' => true, 'msg' => $changed ? "Saved {$changed} number(s)." : 'No changes.']);
    }
}
