<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\CustomerWant;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

class CustomerWantController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $status = $request->input('status', 'active');
        $priority = $request->input('priority');
        $location_id = $request->input('location_id');
        $q = trim($request->input('q', ''));

        $query = CustomerWant::with(['contact', 'location', 'creator'])
            ->where('business_id', $business_id);

        if (!empty($status)) {
            $query->where('status', $status);
        }
        if (!empty($priority)) {
            $query->where('priority', $priority);
        }
        if (!empty($location_id)) {
            $query->where('location_id', $location_id);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('artist', 'like', "%{$q}%")
                  ->orWhere('title', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%")
                  ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $wants = $query
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderByDesc('created_at')
            ->paginate(50)
            ->appends($request->except('page'));

        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('customer_wants.index', compact(
            'wants', 'business_locations', 'status', 'priority', 'location_id', 'q'
        ));
    }

    public function create(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id);
        return view('customer_wants.create', compact('business_locations'));
    }

    public function store(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $data = $request->validate([
            'contact_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'artist' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'format' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'priority' => 'required|in:low,normal,high',
        ]);
        $data['business_id'] = $business_id;
        $data['created_by'] = auth()->user()->id;
        $data['status'] = 'active';

        CustomerWant::create($data);

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want added.']);
    }

    public function edit($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $business_locations = BusinessLocation::forDropdown($business_id);
        return view('customer_wants.edit', compact('want', 'business_locations'));
    }

    public function update($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $data = $request->validate([
            'contact_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'artist' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'format' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'priority' => 'required|in:low,normal,high',
            'status' => 'required|in:active,fulfilled,cancelled',
        ]);
        $want->fill($data)->save();

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want updated.']);
    }

    public function fulfill($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $want->status = 'fulfilled';
        $want->fulfilled_by = auth()->user()->id;
        $want->fulfilled_at = now();
        $want->fulfilled_note = $request->input('fulfilled_note');
        $want->save();

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want marked as fulfilled.']);
    }

    public function destroy($id, Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)->findOrFail($id);
        $want->delete();

        return redirect(action('CustomerWantController@index'))
            ->with('status', ['success' => true, 'msg' => 'Want deleted.']);
    }
}
