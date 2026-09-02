@extends('layouts.app')

@section('title', 'Communications Hub')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .comm-wrap { max-width: 1320px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .comm-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
body.pos-v2 .comm-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .comm-head .sub { color: #6b6253; margin: 0; font-size: 14px; max-width: 60ch; }
body.pos-v2 .comm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 18px; }
body.pos-v2 .stat-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 12px; padding: 13px 15px; }
body.pos-v2 .stat-card .n { font-size: 22px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
body.pos-v2 .stat-card .l { font-size: 11.5px; color: #8a8070; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; margin-top: 5px; }
body.pos-v2 .stat-card.stat-pending .n { color: #b98f00; }
body.pos-v2 .stat-card.stat-resolved .n { color: #3d8b4c; }
body.pos-v2 .stat-card.stat-topic { cursor: pointer; }
body.pos-v2 .stat-card.stat-topic:hover { background: var(--pos-accent-soft); }
body.pos-v2 .stat-card.stat-topic.is-priority .n { color: #b4432f; }
body.pos-v2 .comm-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .comm-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
body.pos-v2 .comm-toolbar .filter-label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .comm-toolbar select {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 8px 11px; font-size: 14px;
  font-family: inherit; background: #fff; box-shadow: none; height: auto; color: var(--pos-ink); min-width: 170px; }
body.pos-v2 .comm-toolbar select:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 #comm_table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 #comm_table thead th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
  color: #8a8070; font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--pos-line); background: transparent; }
body.pos-v2 #comm_table tbody td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; color: var(--pos-ink); }
body.pos-v2 #comm_table tbody tr:hover { background: var(--pos-accent-soft); }
body.pos-v2 #comm_table .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
body.pos-v2 #comm_table .btn-group { display: inline-flex; gap: 5px; }
body.pos-v2 #comm_table .btn-xs { border-radius: 8px; font-family: inherit; font-weight: 600; }
body.pos-v2 .dataTables_wrapper .dataTables_filter input,
body.pos-v2 .dataTables_wrapper .dataTables_length select {
  border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 6px 9px; font-family: inherit; background: #fff; }
body.pos-v2 .dataTables_wrapper .dataTables_filter input:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .dataTables_wrapper .dataTables_info,
body.pos-v2 .dataTables_wrapper .dataTables_length,
body.pos-v2 .dataTables_wrapper .dataTables_filter { color: #8a8070; font-size: 13px; }
body.pos-v2 .dataTables_wrapper .dataTables_paginate .paginate_button.current {
  background: var(--pos-accent) !important; border: 1px solid var(--pos-accent-deep) !important; color: var(--pos-accent-text) !important; border-radius: 8px; }
body.pos-v2 .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 8px; }
body.pos-v2 #comm_modal .modal-body label { font-weight: 600; font-size: 13px; margin-top: 10px; }
body.pos-v2 #comm_modal .checkbox-row { margin-top: 14px; }
</style>

<div class="comm-wrap">
    <div class="comm-head">
        <div>
            <h1>Communications Hub</h1>
            <p class="sub">Every inbound customer message in one place &mdash; both Quo phone lines, Instagram, WhatsApp, Facebook, TikTok. Log it, tag the topic, mark it resolved. Unhappy customers always sort to the top.</p>
        </div>
        <button type="button" class="btn-accent" id="add_comm_btn"><i class="fa fa-plus"></i> Log Inquiry</button>
    </div>

    <div class="comm-stats">
        <div class="stat-card stat-pending"><div class="n">{{ $counts['pending'] }}</div><div class="l">Pending</div></div>
        <div class="stat-card stat-resolved"><div class="n">{{ $counts['resolved'] }}</div><div class="l">Resolved</div></div>
        @foreach($topics as $key => $label)
            <div class="stat-card stat-topic {{ $key == 'unhappy_customer' ? 'is-priority' : '' }}" data-topic="{{ $key }}">
                <div class="n">{{ $topic_counts[$key] ?? 0 }}</div>
                <div class="l">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    <div class="comm-card">
        <div class="comm-toolbar">
            <span class="filter-label">Status:</span>
            <select id="status_filter">
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" {{ $key == 'pending' ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
                <option value="">All</option>
            </select>

            <span class="filter-label">Topic:</span>
            <select id="topic_filter">
                <option value="">All Topics</option>
                @foreach($topics as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>

            <span class="filter-label">Channel:</span>
            <select id="channel_filter">
                <option value="">All Channels</option>
                @foreach($channels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover" id="comm_table" style="width:100%">
                <thead>
                    <tr>
                        <th></th>
                        <th>Channel</th>
                        <th>Topic</th>
                        <th>Customer</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Assigned</th>
                        <th>Logged</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="comm_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="comm_form">
                <input type="hidden" id="comm_id" value="">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="comm_modal_title">Log Inquiry</h4>
                </div>
                <div class="modal-body">
                    <label>Channel</label>
                    <select class="form-control" id="comm_channel" required>
                        @foreach($channels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <label>Topic</label>
                    <select class="form-control" id="comm_topic" required>
                        @foreach($topics as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <label>Customer name</label>
                    <input type="text" class="form-control" id="comm_customer_name">

                    <label>Contact info (phone / handle / email)</label>
                    <input type="text" class="form-control" id="comm_contact_info">

                    <label>What did they say / ask?</label>
                    <textarea class="form-control" id="comm_message" rows="3"></textarea>

                    <label>Assign to</label>
                    <select class="form-control" id="comm_assigned_to">
                        <option value="">Unassigned</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>

                    <div class="checkbox checkbox-row">
                        <label><input type="checkbox" id="comm_is_priority"> Flag as priority</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="resolve_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="resolve_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Mark Resolved</h4>
                </div>
                <div class="modal-body">
                    <label>Resolution notes (optional)</label>
                    <textarea class="form-control" id="resolution_notes" rows="3" placeholder="What was the fix / answer?"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark Resolved</button>
                </div>
            </form>
        </div>
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var TOPICS = @json($topics);

        var comm_table = $('#comm_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ action("CommunicationController@index") }}',
                data: function(d) {
                    d.status = $('#status_filter').val();
                    d.topic = $('#topic_filter').val();
                    d.channel = $('#channel_filter').val();
                }
            },
            columns: [
                { data: 'priority_flag', name: 'is_priority', orderable: false, searchable: false },
                { data: 'channel', name: 'channel' },
                { data: 'topic', name: 'topic' },
                { data: 'customer_info', name: 'customer_name' },
                { data: 'message_excerpt', name: 'message' },
                { data: 'status', name: 'status' },
                { data: 'assigned_info', name: 'assignee_name' },
                { data: 'created_info', name: 'created_info' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[0, 'desc'], [7, 'desc']],
        });

        function reload() { comm_table.ajax.reload(null, false); }

        $('#status_filter, #topic_filter, #channel_filter').on('change', reload);

        $('.stat-card.stat-topic').on('click', function() {
            $('#topic_filter').val($(this).data('topic'));
            $('#status_filter').val('');
            reload();
        });

        function resetForm() {
            $('#comm_id').val('');
            $('#comm_channel').val('phone_1');
            $('#comm_topic').val('general');
            $('#comm_customer_name').val('');
            $('#comm_contact_info').val('');
            $('#comm_message').val('');
            $('#comm_assigned_to').val('');
            $('#comm_is_priority').prop('checked', false);
        }

        $('#add_comm_btn').on('click', function() {
            resetForm();
            $('#comm_modal_title').text('Log Inquiry');
            $('#comm_modal').modal('show');
        });

        $('#comm_topic').on('change', function() {
            if ($(this).val() === 'unhappy_customer') {
                $('#comm_is_priority').prop('checked', true);
            }
        });

        $(document).on('click', '.edit_comm', function() {
            var id = $(this).data('id');
            $.get('{{ url("communications") }}/' + id + '/edit', function(result) {
                if (!result.success) { toastr.error('Could not load that inquiry.'); return; }
                var row = result.data;
                $('#comm_id').val(row.id);
                $('#comm_channel').val(row.channel);
                $('#comm_topic').val(row.topic);
                $('#comm_customer_name').val(row.customer_name);
                $('#comm_contact_info').val(row.contact_info);
                $('#comm_message').val(row.message);
                $('#comm_assigned_to').val(row.assigned_to || '');
                $('#comm_is_priority').prop('checked', !!row.is_priority);
                $('#comm_modal_title').text('Edit Inquiry');
                $('#comm_modal').modal('show');
            });
        });

        $('#comm_form').on('submit', function(e) {
            e.preventDefault();
            var id = $('#comm_id').val();
            var url = id ? '{{ url("communications") }}/' + id : '{{ action("CommunicationController@store") }}';
            var payload = {
                _token: $('meta[name="csrf-token"]').attr('content'),
                channel: $('#comm_channel').val(),
                topic: $('#comm_topic').val(),
                customer_name: $('#comm_customer_name').val(),
                contact_info: $('#comm_contact_info').val(),
                message: $('#comm_message').val(),
                assigned_to: $('#comm_assigned_to').val(),
                is_priority: $('#comm_is_priority').is(':checked') ? 1 : 0,
            };
            if (id) payload._method = 'PUT';

            $.ajax({
                method: 'POST',
                url: url,
                data: payload,
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        $('#comm_modal').modal('hide');
                        reload();
                    } else {
                        toastr.error(result.msg);
                    }
                },
                error: function() { toastr.error('Something went wrong.'); }
            });
        });

        var resolve_href = null;
        $(document).on('click', '.mark_resolved', function() {
            resolve_href = $(this).attr('data-href');
            $('#resolution_notes').val('');
            $('#resolve_modal').modal('show');
        });

        $('#resolve_form').on('submit', function(e) {
            e.preventDefault();
            if (!resolve_href) return;
            $.ajax({
                method: 'POST',
                url: resolve_href,
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    resolution_notes: $('#resolution_notes').val(),
                },
                dataType: 'json',
                success: function(result) {
                    $('#resolve_modal').modal('hide');
                    if (result.success) { toastr.success(result.msg); reload(); }
                    else { toastr.error(result.msg); }
                }
            });
        });

        $(document).on('click', '.mark_pending', function() {
            $.ajax({
                method: 'POST',
                url: $(this).attr('data-href'),
                data: { _token: $('meta[name="csrf-token"]').attr('content') },
                dataType: 'json',
                success: function(result) {
                    if (result.success) { toastr.success(result.msg); reload(); }
                    else { toastr.error(result.msg); }
                }
            });
        });

        $(document).on('click', '.delete_comm', function() {
            var url = $(this).attr('data-href');
            swal({
                title: LANG.sure,
                icon: "warning",
                buttons: true,
                dangerMode: true,
            }).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        method: 'DELETE',
                        url: url,
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) { toastr.success(result.msg); reload(); }
                            else { toastr.error(result.msg); }
                        }
                    });
                }
            });
        });
    });
</script>
@stop
