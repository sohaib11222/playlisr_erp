<?php

namespace App\Http\Controllers;

use App\Communication;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use DB;

class CommunicationController extends Controller
{
    /**
     * Display the Communications Hub — every inbound customer message
     * logged across phone (2 Quo lines), Instagram, WhatsApp, Facebook,
     * TikTok. Manual log for now — no live API/webhook wired to those
     * platforms yet, staff enter what came in.
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $channels = Communication::CHANNELS;
        $topics = Communication::TOPICS;
        $statuses = ['pending' => 'Pending', 'overdue' => 'Unresolved 1hr+', 'resolved' => 'Resolved'];

        if (request()->ajax()) {
            $rows = Communication::where('communications.business_id', $business_id)
                ->leftJoin('users as assignee_users', 'communications.assigned_to', '=', 'assignee_users.id')
                ->leftJoin('users as creator_users', 'communications.created_by', '=', 'creator_users.id')
                ->select(
                    'communications.*',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(assignee_users.first_name,''), ' ', COALESCE(assignee_users.last_name,''))), ''), assignee_users.username) as assignee_name"),
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(creator_users.first_name,''), ' ', COALESCE(creator_users.last_name,''))), ''), creator_users.username) as created_by_name"),
                    DB::raw("(communications.status = 'pending' AND communications.created_at <= (NOW() - INTERVAL 1 HOUR)) as is_overdue"),
                    DB::raw("(communications.is_priority = 1 OR (communications.status = 'pending' AND communications.created_at <= (NOW() - INTERVAL 1 HOUR))) as priority_sort")
                );

            if (request()->has('status') && request()->status != '') {
                if (request()->status == 'overdue') {
                    $rows->where('communications.status', 'pending')
                        ->where('communications.created_at', '<=', now()->subHour());
                } else {
                    $rows->where('communications.status', request()->status);
                }
            } else {
                $rows->where('communications.status', 'pending');
            }

            if (request()->has('topic') && request()->topic != '') {
                $rows->where('communications.topic', request()->topic);
            }

            if (request()->has('channel') && request()->channel != '') {
                $rows->where('communications.channel', request()->channel);
            }

            return DataTables::of($rows)
                ->addColumn('priority_flag', function ($row) {
                    if ($row->is_overdue) {
                        return '<span class="label label-danger" title="Unresolved 1hr+"><i class="fa fa-clock-o"></i></span>';
                    }
                    return $row->is_priority
                        ? '<span class="label label-danger" title="Priority"><i class="fa fa-exclamation-circle"></i></span>'
                        : '';
                })
                ->editColumn('channel', function ($row) {
                    return e(Communication::CHANNELS[$row->channel] ?? $row->channel);
                })
                ->editColumn('topic', function ($row) {
                    $classes = [
                        'unhappy_customer' => 'label-danger',
                        'shipping' => 'label-info',
                        'stock' => 'label-primary',
                        'events' => 'label-success',
                        'careers' => 'label-default',
                        'partnerships' => 'label-warning',
                        'general' => 'label-default',
                    ];
                    $class = $classes[$row->topic] ?? 'label-default';
                    $text = Communication::TOPICS[$row->topic] ?? $row->topic;
                    return '<span class="label ' . $class . '">' . e($text) . '</span>';
                })
                ->editColumn('status', function ($row) {
                    if ($row->status == 'resolved') {
                        return '<span class="label label-success">Resolved</span>';
                    }
                    return $row->is_overdue
                        ? '<span class="label label-danger">Unresolved &ndash; High Priority</span>'
                        : '<span class="label label-warning">Pending</span>';
                })
                ->addColumn('customer_info', function ($row) {
                    $parts = [];
                    if (!empty($row->customer_name)) $parts[] = '<strong>' . e($row->customer_name) . '</strong>';
                    if (!empty($row->contact_info)) $parts[] = '<small>' . e($row->contact_info) . '</small>';
                    return $parts ? implode('<br>', $parts) : '-';
                })
                ->addColumn('message_excerpt', function ($row) {
                    if (empty($row->message)) return '-';
                    $text = trim($row->message);
                    return e(strlen($text) > 90 ? substr($text, 0, 90) . '…' : $text);
                })
                ->addColumn('assigned_info', function ($row) {
                    return !empty($row->assignee_name) ? e(trim($row->assignee_name)) : '<span class="text-muted">Unassigned</span>';
                })
                ->addColumn('created_info', function ($row) {
                    $parts = [];
                    if (!empty($row->created_by_name)) $parts[] = '<strong>' . e(trim($row->created_by_name)) . '</strong>';
                    if ($row->created_at) $parts[] = '<small>' . \Carbon::parse($row->created_at)->format('n/j/y g:i A') . '</small>';
                    return $parts ? implode('<br>', $parts) : '-';
                })
                ->addColumn('action', function ($row) {
                    $html = '<div class="btn-group">';
                    $html .= '<button type="button" class="btn btn-default btn-xs edit_comm" data-id="' . $row->id . '"><i class="fa fa-edit"></i></button>';
                    if ($row->status == 'pending') {
                        $html .= '<button type="button" class="btn btn-success btn-xs mark_resolved" data-href="' . action('CommunicationController@markResolved', [$row->id]) . '"><i class="fa fa-check"></i> Resolve</button>';
                    } else {
                        $html .= '<button type="button" class="btn btn-warning btn-xs mark_pending" data-href="' . action('CommunicationController@markPending', [$row->id]) . '"><i class="fa fa-undo"></i> Reopen</button>';
                    }
                    $html .= '<button type="button" class="btn btn-danger btn-xs delete_comm" data-href="' . action('CommunicationController@destroy', [$row->id]) . '"><i class="fa fa-trash"></i></button>';
                    $html .= '</div>';
                    return $html;
                })
                ->rawColumns(['priority_flag', 'topic', 'status', 'customer_info', 'message_excerpt', 'assigned_info', 'created_info', 'action'])
                ->make(true);
        }

        $counts = [
            'pending' => Communication::where('business_id', $business_id)->where('status', 'pending')->count(),
            'overdue' => Communication::where('business_id', $business_id)->where('status', 'pending')->where('created_at', '<=', now()->subHour())->count(),
            'resolved' => Communication::where('business_id', $business_id)->where('status', 'resolved')->count(),
        ];

        $topic_counts = Communication::where('business_id', $business_id)
            ->where('status', 'pending')
            ->select('topic', DB::raw('count(*) as c'))
            ->groupBy('topic')
            ->pluck('c', 'topic');

        $users = DB::table('users')
            ->where('business_id', $business_id)
            ->where('status', 'active')
            ->select('id', DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))), ''), username) as name"))
            ->orderBy('name')
            ->get();

        return view('communications.index', compact('channels', 'topics', 'statuses', 'counts', 'topic_counts', 'users'));
    }

    /**
     * Log a new inbound communication.
     */
    public function store(Request $request)
    {
        try {
            $business_id = request()->session()->get('user.business_id');

            $request->validate([
                'channel' => 'required|in:' . implode(',', array_keys(Communication::CHANNELS)),
                'topic' => 'required|in:' . implode(',', array_keys(Communication::TOPICS)),
                'customer_name' => 'nullable|string|max:255',
                'contact_info' => 'nullable|string|max:255',
                'message' => 'nullable|string',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $c = new Communication();
            $c->business_id = $business_id;
            $c->channel = $request->channel;
            $c->topic = $request->topic;
            $c->customer_name = $request->customer_name;
            $c->contact_info = $request->contact_info;
            $c->message = $request->message;
            $c->is_priority = ($request->has('is_priority') || $request->topic === 'unhappy_customer') ? 1 : 0;
            $c->assigned_to = $request->assigned_to ?: null;
            $c->status = 'pending';
            $c->created_by = auth()->user()->id;
            $c->save();

            $output = ['success' => true, 'msg' => __('lang_v1.success'), 'id' => $c->id];
        } catch (\Illuminate\Validation\ValidationException $e) {
            $output = ['success' => false, 'msg' => implode(' ', $e->validator->errors()->all())];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    /**
     * Fetch a single communication (for the edit modal).
     */
    public function edit($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $c = Communication::where('business_id', $business_id)->findOrFail($id);

        return [
            'success' => true,
            'data' => $c,
        ];
    }

    /**
     * Update an existing communication's details.
     */
    public function update(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $c = Communication::where('business_id', $business_id)->findOrFail($id);

            $request->validate([
                'channel' => 'required|in:' . implode(',', array_keys(Communication::CHANNELS)),
                'topic' => 'required|in:' . implode(',', array_keys(Communication::TOPICS)),
                'customer_name' => 'nullable|string|max:255',
                'contact_info' => 'nullable|string|max:255',
                'message' => 'nullable|string',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $c->channel = $request->channel;
            $c->topic = $request->topic;
            $c->customer_name = $request->customer_name;
            $c->contact_info = $request->contact_info;
            $c->message = $request->message;
            $c->is_priority = ($request->has('is_priority') || $request->topic === 'unhappy_customer') ? 1 : 0;
            $c->assigned_to = $request->assigned_to ?: null;
            $c->save();

            $output = ['success' => true, 'msg' => __('lang_v1.success')];
        } catch (\Illuminate\Validation\ValidationException $e) {
            $output = ['success' => false, 'msg' => implode(' ', $e->validator->errors()->all())];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    /**
     * Remove a logged communication.
     */
    public function destroy($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $c = Communication::where('business_id', $business_id)->findOrFail($id);
            $c->delete();

            $output = ['success' => true, 'msg' => __('lang_v1.success')];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    /**
     * Mark a communication resolved, optionally with resolution notes.
     */
    public function markResolved(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $c = Communication::where('business_id', $business_id)->findOrFail($id);

            $c->status = 'resolved';
            $c->resolved_by = auth()->user()->id;
            $c->resolved_at = now();
            if ($request->filled('resolution_notes')) {
                $c->resolution_notes = $request->resolution_notes;
            }
            $c->save();

            $output = ['success' => true, 'msg' => 'Marked resolved'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    /**
     * Reopen a resolved communication.
     */
    public function markPending($id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $c = Communication::where('business_id', $business_id)->findOrFail($id);

            $c->status = 'pending';
            $c->resolved_by = null;
            $c->resolved_at = null;
            $c->save();

            $output = ['success' => true, 'msg' => 'Reopened'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }

    /**
     * Assign a communication to a staff member.
     */
    public function assign(Request $request, $id)
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $c = Communication::where('business_id', $business_id)->findOrFail($id);

            $c->assigned_to = $request->assigned_to ?: null;
            $c->save();

            $output = ['success' => true, 'msg' => 'Assigned'];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = ['success' => false, 'msg' => __('messages.something_went_wrong')];
        }

        return $output;
    }
}
