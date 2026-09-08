<?php

namespace App\Http\Controllers;

use App\Communication;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use DB;

class CommunicationController extends Controller
{
    /** Shared by index() (initial page render) and stats() (polled for
     * live updates) so the two never drift out of sync. */
    private function computeCounts(int $business_id): array
    {
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

        return [$counts, $topic_counts];
    }

    /**
     * Polled from the front end every ~20s so new webhook-logged inquiries
     * and replies show up without a manual page reload — the stat cards
     * and topic counts are otherwise only computed on initial page load.
     */
    public function stats()
    {
        $business_id = request()->session()->get('user.business_id');
        [$counts, $topic_counts] = $this->computeCounts($business_id);

        return response()->json([
            'counts' => $counts,
            'topic_counts' => $topic_counts,
        ]);
    }

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
        $statuses = ['pending' => 'Pending', 'overdue' => 'Unresolved 1hr+', 'unreplied' => 'Not Replied To', 'replied' => 'Replied', 'resolved' => 'Resolved'];

        if (request()->ajax()) {
            // Bind the cutoff from PHP (app timezone, America/Los_Angeles)
            // rather than using MySQL's NOW() — the DB session's timezone
            // doesn't necessarily match the app's, and comparing against it
            // directly was flagging every pending row as overdue instantly.
            $overdue_cutoff = now()->subHour()->toDateTimeString();

            $rows = Communication::where('communications.business_id', $business_id)
                ->leftJoin('users as assignee_users', 'communications.assigned_to', '=', 'assignee_users.id')
                ->leftJoin('users as creator_users', 'communications.created_by', '=', 'creator_users.id')
                ->select(
                    'communications.*',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(assignee_users.first_name,''), ' ', COALESCE(assignee_users.last_name,''))), ''), assignee_users.username) as assignee_name"),
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(creator_users.first_name,''), ' ', COALESCE(creator_users.last_name,''))), ''), creator_users.username) as created_by_name")
                )
                ->selectRaw("(communications.status = 'pending' AND communications.created_at <= ?) as is_overdue", [$overdue_cutoff]);

            if (request()->has('status') && request()->status != '') {
                if (request()->status == 'overdue') {
                    $rows->where('communications.status', 'pending')
                        ->where('communications.created_at', '<=', now()->subHour());
                } elseif (request()->status == 'unreplied') {
                    // has_real_reply excludes Quo's canned missed-call
                    // auto-text — that's not a staff reply, so those
                    // inquiries still count as unhandled here.
                    $rows->where('communications.status', 'pending')
                        ->where('communications.has_real_reply', 0);
                } elseif (request()->status == 'replied') {
                    $rows->where('communications.has_real_reply', 1);
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

            if (filter_var(request()->input('today'), FILTER_VALIDATE_BOOLEAN)) {
                $rows->whereDate('communications.created_at', now()->toDateString());
            }

            return DataTables::of($rows)
                ->editColumn('channel', function ($row) {
                    $text = Communication::CHANNELS[$row->channel] ?? $row->channel;
                    return e($text);
                })
                ->editColumn('topic', function ($row) {
                    $class = $row->topic === 'unhappy_customer' ? 'label-danger' : 'label-default';
                    $text = Communication::TOPICS[$row->topic] ?? $row->topic;
                    return '<span class="label ' . $class . '">' . e($text) . '</span>';
                })
                ->editColumn('status', function ($row) {
                    if ($row->status == 'resolved') {
                        return '<span class="label label-success">Resolved</span>';
                    }
                    return $row->is_overdue
                        ? '<span class="label label-danger">Overdue</span>'
                        : '<span class="label label-default">Pending</span>';
                })
                ->addColumn('customer_info', function ($row) {
                    $parts = [];
                    if (!empty($row->customer_name)) $parts[] = '<strong>' . e($row->customer_name) . '</strong>';
                    if (!empty($row->contact_info)) $parts[] = '<small>' . e($row->contact_info) . '</small>';
                    return $parts ? implode('<br>', $parts) : '-';
                })
                ->addColumn('message_excerpt', function ($row) {
                    if (empty($row->message)) {
                        return '-';
                    }
                    // A grouped conversation thread stores each message as
                    // its own "[m/d h:ma] ..." entry (same convention as
                    // resolution_notes, including the hidden dedupe marker)
                    // — show the latest one, not whichever happened to be
                    // first, so staff see what the customer is actually
                    // asking about right now.
                    $message = preg_replace('/<!--.*?-->/', '', $row->message);
                    $entries = Communication::parseReplyEntries($message);
                    if (count($entries) > 1) {
                        usort($entries, function ($a, $b) {
                            if ($a['time'] && $b['time']) return $a['time'] <=> $b['time'];
                            return 0;
                        });
                        $last = end($entries);
                        $text = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $last['text']));
                        $preview = strlen($text) > 90 ? substr($text, 0, 90) . '…' : $text;
                        return e($preview) . ' <span class="text-muted" style="font-size:11px;white-space:nowrap;">(' . count($entries) . ' messages)</span>';
                    }
                    $text = trim(preg_replace('/^\[\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{2}[ap]m\]\s*/i', '', trim($message)));
                    return e(strlen($text) > 90 ? substr($text, 0, 90) . '…' : $text);
                })
                ->addColumn('reply_status', function ($row) {
                    if (empty($row->resolution_notes)) {
                        return '<span class="label label-default">No reply</span>';
                    }
                    // Strip the <!--quo-reply-...--> dedupe markers the
                    // webhook appends — for matching retried deliveries,
                    // not for display.
                    $notes = preg_replace('/<!--.*?-->/', '', $row->resolution_notes);
                    $entries = Communication::parseReplyEntries($notes);
                    if (empty($entries)) {
                        return '<span class="label label-default">No reply</span>';
                    }
                    // Entries aren't guaranteed to be stored oldest-first (a
                    // backfill import can attach replies out of chronological
                    // order) — sort by parsed time so "latest" is genuinely
                    // the latest, not whichever happened to be appended last.
                    usort($entries, function ($a, $b) {
                        if ($a['time'] && $b['time']) return $a['time'] <=> $b['time'];
                        return 0;
                    });
                    $realEntries = array_values(array_filter($entries, function ($e) {
                        return !Communication::isAutoReplyText($e['text']);
                    }));
                    if (empty($realEntries)) {
                        // Only a canned auto-response (e.g. Quo's missed-call
                        // auto-text) — never count that as a staff reply, or
                        // the inquiry falsely looks handled.
                        $last = end($entries);
                        $preview = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $last['text']));
                        $preview = strlen($preview) > 70 ? substr($preview, 0, 70) . '…' : $preview;
                        return '<span class="label label-default" title="' . e($last['text']) . '"><i class="fa fa-bolt"></i> Auto-reply only</span>'
                            . ($preview !== '' ? '<div class="reply-preview">' . e($preview) . '</div>' : '');
                    }
                    $lastReal = end($realEntries);
                    $preview = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $lastReal['text']));
                    $preview = strlen($preview) > 70 ? substr($preview, 0, 70) . '…' : $preview;
                    return '<span class="label label-success" title="' . e($lastReal['text']) . '"><i class="fa fa-reply"></i> Replied</span>'
                        . ($preview !== '' ? '<div class="reply-preview">' . e($preview) . '</div>' : '');
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
                    $html .= '<button type="button" class="btn btn-default btn-xs view_thread" data-id="' . $row->id . '" title="View conversation"><i class="fa fa-comments"></i></button>';
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
                ->rawColumns(['channel', 'topic', 'status', 'customer_info', 'message_excerpt', 'reply_status', 'assigned_info', 'created_info', 'action'])
                ->make(true);
        }

        [$counts, $topic_counts] = $this->computeCounts($business_id);

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
                'resolution_notes' => 'nullable|string',
                'occurred_at' => 'nullable|date',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $c = new Communication();
            $c->business_id = $business_id;
            $c->channel = $request->channel;
            $c->topic = $request->topic;
            $c->customer_name = $request->customer_name;
            $c->contact_info = $request->contact_info;
            $c->message = $request->message;
            if ($request->filled('resolution_notes')) {
                $c->resolution_notes = $request->resolution_notes;
            }
            $c->is_priority = (filter_var($request->input('is_priority'), FILTER_VALIDATE_BOOLEAN) || $request->topic === 'unhappy_customer') ? 1 : 0;
            $c->assigned_to = $request->assigned_to ?: null;
            $c->status = 'pending';
            $c->created_by = auth()->user()->id;
            // Backdating support for logging something that happened
            // earlier (a call from this morning, an email from yesterday)
            // rather than defaulting to "right now".
            if ($request->filled('occurred_at')) {
                try {
                    $c->created_at = \Carbon::parse($request->occurred_at);
                } catch (\Throwable $e) {
                }
            }
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

        // Strip the <!--quo-reply-...--> dedupe markers the webhook appends —
        // they're only for matching retried deliveries, not for editing.
        if (!empty($c->resolution_notes)) {
            $c->resolution_notes = trim(preg_replace('/<!--.*?-->/', '', $c->resolution_notes));
        }

        return [
            'success' => true,
            'data' => $c,
        ];
    }

    /**
     * Full conversation view for one inquiry — every inbound message
     * (from the "message" log) and every staff reply (from
     * "resolution_notes") merged into one chronological thread, like
     * Quo's own conversation view.
     */
    public function thread($id)
    {
        $business_id = request()->session()->get('user.business_id');
        $c = Communication::where('business_id', $business_id)->findOrFail($id);

        $stripPrefix = function ($text) {
            $text = preg_replace('/<!--.*?-->/', '', $text);
            return trim(preg_replace('/^\[[^\]]+\]\s*/', '', $text));
        };

        $inbound = array_map(function ($e) use ($stripPrefix) {
            return ['who' => 'customer', 'text' => $stripPrefix($e['text']), 'time' => $e['time']];
        }, Communication::parseReplyEntries($c->message));

        $notes = preg_replace('/<!--.*?-->/', '', (string) $c->resolution_notes);
        $outbound = array_map(function ($e) use ($stripPrefix) {
            return ['who' => 'staff', 'text' => $stripPrefix($e['text']), 'time' => $e['time']];
        }, Communication::parseReplyEntries($notes));

        $entries = array_merge($inbound, $outbound);
        usort($entries, function ($a, $b) {
            if ($a['time'] && $b['time']) {
                return $a['time'] <=> $b['time'];
            }
            return 0;
        });

        foreach ($entries as &$e) {
            $e['time_label'] = $e['time'] ? $e['time']->format('n/j g:ia') : '';
            unset($e['time']);
        }

        return response()->json([
            'success' => true,
            'channel' => $c->channel,
            'channel_label' => Communication::CHANNELS[$c->channel] ?? $c->channel,
            'contact_info' => $c->contact_info,
            'can_reply' => in_array($c->channel, ['phone_1', 'phone_2'], true),
            'entries' => $entries,
        ]);
    }

    /**
     * Send an actual SMS reply through Quo from whichever line (Pico or
     * Hollywood) the customer texted, and log it the same way a live
     * message.delivered webhook would.
     */
    public function sendReply(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');
        $c = Communication::where('business_id', $business_id)->findOrFail($id);

        $request->validate(['message' => 'required|string|max:1000']);

        if (!in_array($c->channel, ['phone_1', 'phone_2'], true)) {
            return response()->json(['success' => false, 'msg' => 'Direct reply from here only works for the Quo phone lines right now.']);
        }
        if (empty($c->contact_info)) {
            return response()->json(['success' => false, 'msg' => 'No phone number on this inquiry to reply to.']);
        }

        $fromNumber = array_search($c->channel, Communication::QUO_NUMBERS, true);
        if (!$fromNumber) {
            return response()->json(['success' => false, 'msg' => 'Could not determine which Quo line to send from.']);
        }

        $svc = new \App\Services\OpenPhoneService();
        $result = $svc->sendFrom($fromNumber, $c->contact_info, $request->message);
        if (!$result['success']) {
            return response()->json(['success' => false, 'msg' => $result['msg']]);
        }

        $stamp = now()->format('n/j g:ia');
        $c->resolution_notes = trim(($c->resolution_notes ? $c->resolution_notes . "\n" : '') . "[$stamp] " . $request->message);
        $c->save();

        Communication::notifyStaff($business_id, new \App\Notifications\CommunicationRepliedNotification($c));

        return response()->json(['success' => true, 'msg' => 'Sent']);
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
                'resolution_notes' => 'nullable|string',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $c->channel = $request->channel;
            $c->topic = $request->topic;
            $c->customer_name = $request->customer_name;
            $c->contact_info = $request->contact_info;
            $c->message = $request->message;
            if ($request->has('resolution_notes')) {
                $c->resolution_notes = $request->resolution_notes !== '' ? $request->resolution_notes : null;
            }
            $c->is_priority = (filter_var($request->input('is_priority'), FILTER_VALIDATE_BOOLEAN) || $request->topic === 'unhappy_customer') ? 1 : 0;
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

            Communication::notifyStaff($business_id, new \App\Notifications\CommunicationResolvedNotification($c));

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
