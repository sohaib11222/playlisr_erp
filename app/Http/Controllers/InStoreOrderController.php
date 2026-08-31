<?php

namespace App\Http\Controllers;

use App\InStoreOrder;
use App\BusinessLocation;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use DB;

class InStoreOrderController extends Controller
{
    /**
     * Display a listing of in-store orders.
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $statuses = ['pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

        if (request()->ajax()) {
            $orders = InStoreOrder::where('in_store_orders.business_id', $business_id)
                ->leftJoin('business_locations', 'in_store_orders.location_id', '=', 'business_locations.id')
                ->leftJoin('users as creator_users', 'in_store_orders.created_by', '=', 'creator_users.id')
                ->select(
                    'in_store_orders.*',
                    'business_locations.name as location_name',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(creator_users.first_name,''), ' ', COALESCE(creator_users.last_name,''))), ''), creator_users.username) as created_by_name")
                );

            if (request()->has('status') && request()->status != '') {
                $orders->where('in_store_orders.status', request()->status);
            } else {
                $orders->where('in_store_orders.status', 'pending');
            }

            return DataTables::of($orders)
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">';
                    if ($row->status == 'pending') {
                        $html .= '<button type="button" class="btn btn-info btn-xs notify_customer" data-href="' . action('InStoreOrderController@notify', [$row->id]) . '" data-id="' . $row->id . '"><i class="fa fa-bell"></i> Notify</button>';
                        $html .= '<button type="button" class="btn btn-success btn-xs mark_complete" data-href="' . action('InStoreOrderController@markComplete', [$row->id]) . '"><i class="fa fa-check"></i> Complete</button>';
                        $html .= '<a href="' . action('InStoreOrderController@edit', [$row->id]) . '" class="btn btn-warning btn-xs"><i class="fa fa-edit"></i></a>';
                        $html .= '<button type="button" class="btn btn-danger btn-xs delete_order" data-href="' . action('InStoreOrderController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    }
                    $html .= '</div>';
                    return $html;
                })
                ->editColumn('status', function ($row) {
                    $labels = [
                        'pending' => '<span class="label label-warning">Pending</span>',
                        'completed' => '<span class="label label-success">Completed</span>',
                        'cancelled' => '<span class="label label-danger">Cancelled</span>',
                    ];
                    return $labels[$row->status] ?? $row->status;
                })
                ->addColumn('is_paid_label', function ($row) {
                    return $row->is_paid
                        ? '<span class="label label-success">Paid</span>'
                        : '<span class="label label-default">Unpaid</span>';
                })
                ->editColumn('price_paid', function ($row) {
                    return '$' . number_format((float) $row->price_paid, 2);
                })
                ->addColumn('notified_info', function ($row) {
                    if (!$row->notified_at) return '-';
                    $parts = [\Carbon::parse($row->notified_at)->format('n/j/y g:i A')];
                    if ($row->notify_method) $parts[] = 'via ' . strtoupper($row->notify_method);
                    return implode('<br>', $parts);
                })
                ->addColumn('created_info', function ($row) {
                    $parts = [];
                    if (!empty($row->created_by_name) && trim($row->created_by_name) !== '') {
                        $parts[] = '<strong>' . e(trim($row->created_by_name)) . '</strong>';
                    }
                    if ($row->created_at) {
                        $parts[] = '<small>' . \Carbon::parse($row->created_at)->format('n/j/y g:i A') . '</small>';
                    }
                    return $parts ? implode('<br>', $parts) : '-';
                })
                ->rawColumns(['action', 'status', 'is_paid_label', 'notified_info', 'created_info'])
                ->make(true);
        }

        return view('in_store_orders.index', compact('statuses'));
    }

    /**
     * Show the form for creating a new in-store order.
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id);

        return view('in_store_orders.create', compact('locations'));
    }

    /**
     * Store a newly created in-store order.
     */
    public function store(Request $request)
    {
        try {
            $business_id = request()->session()->get('user.business_id');

            $request->validate([
                'customer_name' => 'required|string|max:255',
                'customer_phone' => 'nullable|string|max:40',
                'customer_email' => 'nullable|email|max:255',
                'location_id' => 'nullable|exists:business_locations,id',
                'item_name' => 'required|string|max:255',
                'price_paid' => 'nullable|numeric|min:0',
                'is_paid' => 'nullable',
                'notes' => 'nullable|string',
            ]);

            $order = new InStoreOrder();
            $order->business_id = $business_id;
            $order->location_id = $request->location_id;
            $order->customer_name = $request->customer_name;
            $order->customer_phone = $request->customer_phone;
            $order->customer_email = $request->customer_email;
            $order->item_name = $request->item_name;
            $order->price_paid = $request->price_paid ?: 0;
            $order->is_paid = $request->has('is_paid') ? 1 : 0;
            $order->status = 'pending';
            $order->notes = $request->notes;
            $order->created_by = auth()->user()->id;
            $order->save();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->action('InStoreOrderController@index')->with('status', $output);
    }

    /**
     * Show the form for editing the specified in-store order.
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $order = InStoreOrder::where('business_id', $business_id)->findOrFail($id);

        if ($order->status != 'pending') {
            return redirect()->action('InStoreOrderController@index')
                ->with('status', ['success' => false, 'msg' => 'Only pending orders can be edited']);
        }

        $locations = BusinessLocation::forDropdown($business_id);

        return view('in_store_orders.edit', compact('order', 'locations'));
    }

    /**
     * Update the specified in-store order.
     */
    public function update(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $order = InStoreOrder::where('business_id', $business_id)->findOrFail($id);

            if ($order->status != 'pending') {
                return redirect()->action('InStoreOrderController@index')
                    ->with('status', ['success' => false, 'msg' => 'Only pending orders can be updated']);
            }

            $request->validate([
                'customer_name' => 'required|string|max:255',
                'customer_phone' => 'nullable|string|max:40',
                'customer_email' => 'nullable|email|max:255',
                'location_id' => 'nullable|exists:business_locations,id',
                'item_name' => 'required|string|max:255',
                'price_paid' => 'nullable|numeric|min:0',
                'is_paid' => 'nullable',
                'notes' => 'nullable|string',
            ]);

            $order->location_id = $request->location_id;
            $order->customer_name = $request->customer_name;
            $order->customer_phone = $request->customer_phone;
            $order->customer_email = $request->customer_email;
            $order->item_name = $request->item_name;
            $order->price_paid = $request->price_paid ?: 0;
            $order->is_paid = $request->has('is_paid') ? 1 : 0;
            $order->notes = $request->notes;
            $order->save();

            $output = [
                'success' => true,
                'msg' => __('lang_v1.success'),
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return redirect()->action('InStoreOrderController@index')->with('status', $output);
    }

    /**
     * Remove the specified in-store order.
     */
    public function destroy($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $order = InStoreOrder::where('business_id', $business_id)->findOrFail($id);

            if ($order->status != 'pending') {
                $output = [
                    'success' => false,
                    'msg' => 'Only pending orders can be deleted',
                ];
            } else {
                $order->delete();
                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success'),
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Mark an in-store order as completed (customer picked it up / it's closed out).
     */
    public function markComplete($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $order = InStoreOrder::where('business_id', $business_id)->findOrFail($id);

            if ($order->status != 'pending') {
                $output = [
                    'success' => false,
                    'msg' => 'Only pending orders can be marked complete',
                ];
            } else {
                $order->status = 'completed';
                $order->save();
                $output = [
                    'success' => true,
                    'msg' => 'Order marked complete',
                ];
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }

        return $output;
    }

    /**
     * Notify the customer their in-store order is ready, by email and/or SMS.
     * Never throws — a failed send is reported back, not fatal.
     */
    public function notify(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $order = InStoreOrder::where('business_id', $business_id)
                ->with('location')
                ->findOrFail($id);

            $notifyMethod = strtolower((string) $request->input('notify_method', 'none'));
            $channels = [];
            if (in_array($notifyMethod, ['email', 'both'])) $channels[] = 'email';
            if (in_array($notifyMethod, ['sms', 'both']))   $channels[] = 'sms';

            if (empty($channels)) {
                return ['success' => false, 'msg' => 'Choose a notify method.'];
            }

            $storeName = optional($order->location)->name ?: 'Nivessa';
            $results = [];

            if (in_array('email', $channels)) {
                if (empty($order->customer_email)) {
                    $results['email'] = ['ok' => false, 'msg' => 'No email on file for this order.'];
                } else {
                    try {
                        \Mail::to($order->customer_email)->send(new \App\Mail\InStoreOrderReady($order, $storeName));
                        $results['email'] = ['ok' => true, 'msg' => 'Emailed ' . $order->customer_email];
                    } catch (\Throwable $e) {
                        \Log::warning('InStoreOrder email failed: ' . $e->getMessage());
                        $results['email'] = ['ok' => false, 'msg' => 'Email failed: ' . $e->getMessage()];
                    }
                }
            }

            if (in_array('sms', $channels)) {
                if (empty($order->customer_phone)) {
                    $results['sms'] = ['ok' => false, 'msg' => 'No phone on file for this order.'];
                } else {
                    $first = trim(explode(' ', trim($order->customer_name))[0] ?? '');
                    $hey = $first !== '' ? ('Hey ' . $first . ', ') : 'Hey, ';
                    $msg = $hey . "Nivessa here — your {$order->item_name} order is ready at {$storeName}. "
                         . "We'll hold it behind the counter.";
                    try {
                        $sms = app(\App\Services\OpenPhoneService::class);
                        $result = $sms->send($order->customer_phone, $msg);
                        $results['sms'] = ['ok' => (bool) ($result['success'] ?? false), 'msg' => $result['msg'] ?? ''];
                    } catch (\Throwable $e) {
                        \Log::warning('InStoreOrder sms failed: ' . $e->getMessage());
                        $results['sms'] = ['ok' => false, 'msg' => 'Text failed: ' . $e->getMessage()];
                    }
                }
            }

            $order->notified_at = now();
            $order->notify_method = $notifyMethod;
            $order->save();

            return [
                'success' => true,
                'msg' => 'Notification sent.',
                'notifications' => $results,
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            return [
                'success' => false,
                'msg' => __('messages.something_went_wrong'),
            ];
        }
    }
}
