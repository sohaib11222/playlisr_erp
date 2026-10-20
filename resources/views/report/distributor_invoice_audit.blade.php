@extends('layouts.app')
@section('title', 'Distributor Invoice Audit')

@section('content')
@php
    $fmtDate = function ($d) { return $d ? date('m/d/y', strtotime($d)) : ''; };
    $flagLabel = ['gap' => 'NOT IN ERP', 'missing_lines' => 'Lines missing', 'total_differs' => 'Total differs', 'ok' => 'OK'];
    $flagStyle = [
        'gap' => 'background:#c0392b;color:#fff;',
        'missing_lines' => 'background:#e67e22;color:#fff;',
        'total_differs' => 'background:#f6e7b0;color:#222;',
        'ok' => 'background:#dff0d8;color:#222;',
    ];
@endphp
<section class="content-header">
    <h1>Distributor Invoice Audit</h1>
    <p style="max-width:950px;">
        Every distributor invoice from the last {{ $days }} days against the ERP purchase it landed on. A purchase line is
        what lets the store receive an item, so anything flagged here can't be received until it's fixed.
        Import invoices at <a href="/admin/ams-invoice-import">Import AMS Invoice</a> and
        <a href="/admin/alliance-invoice-import">Import Alliance Invoice</a>.
        Show: <a href="?days=30">30 days</a> | <a href="?days=60">60 days</a> | <a href="?days=90">90 days</a> (default 60)
    </p>
</section>

<section class="content">
@if (session('status'))
    <div class="alert alert-{{ session('status')['success'] ? 'success' : 'danger' }}">{{ session('status')['msg'] }}</div>
@endif

<div class="box box-solid">
    <div class="box-body">
        <table class="table table-condensed table-bordered" style="max-width:720px;font-size:13px;margin:0;">
            <thead><tr><th>Distributor</th><th style="text-align:right;">Invoices</th><th style="text-align:right;">Not in ERP</th><th style="text-align:right;">Flagged</th><th style="text-align:right;">Purchases with no invoice</th></tr></thead>
            <tbody>
            @foreach ($summary as $dist => $s)
                <tr>
                    <td>{{ strtoupper($dist) }}</td>
                    <td style="text-align:right;">{{ $s['invoices'] ?? 0 }}</td>
                    <td style="text-align:right;{{ !empty($s['gaps']) ? 'color:#c0392b;font-weight:700;' : '' }}">{{ $s['gaps'] ?? 0 }}</td>
                    <td style="text-align:right;">{{ $s['flagged'] ?? 0 }}</td>
                    <td style="text-align:right;">{{ $s['adhoc'] ?? 0 }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="box box-solid">
    <div class="box-header with-border"><h3 class="box-title">Automatic intake</h3></div>
    <div class="box-body">
        <p style="max-width:950px;">At 7am, 10am, 1pm, 4pm and 7pm the ERP checks the AMS portal and the invoice mailbox for new invoices and logs each one as an Ordered purchase, creating any titles we don't carry yet. Anything it can't read is posted to Slack and listed below; upload those by hand at
            <a href="/admin/ams-invoice-import">Import AMS Invoice</a> or <a href="/admin/alliance-invoice-import">Import Alliance Invoice</a>.</p>
        <table class="table table-condensed table-bordered" style="max-width:720px;font-size:13px;">
            <tr><td>AMS login</td><td>{!! $intake['ams_main'] ? 'Set (account ' . e($intake['ams_main']) . ')' : '<strong style="color:#c0392b;">Not set</strong>' !!}</td></tr>
            <tr><td>Second AMS login</td><td>{!! $intake['ams2'] ? 'Set (account ' . e($intake['ams2']) . ')' : '<strong style="color:#c0392b;">Not set</strong> (Pico 131507)' !!}</td></tr>
            <tr><td>Alliance e-invoice mailbox</td><td>{!! $intake['mailbox'] ? e($intake['mailbox']) . ' (e-invoices from hdadmin@aent.com need to reach this inbox)' : '<strong style="color:#c0392b;">Not set</strong>' !!}</td></tr>
            <tr><td>Slack alerts</td><td>{!! $intake['slack'] ? 'Set' : '<strong style="color:#c0392b;">Not set</strong>' !!}</td></tr>
            <tr><td>Last check</td><td>{{ $lastRun ? date('m/d/y g:ia', strtotime($lastRun)) : 'Not yet' }}</td></tr>
        </table>
        <form method="POST" action="/reports/distributor-invoice-audit/run-intake" style="display:inline;">
            {{ csrf_field() }}
            <button class="btn btn-primary">Check for new invoices now</button>
        </form>

        @if (count($intakeLog))
            <table class="table table-condensed table-bordered" style="font-size:12px;margin-top:12px;">
                <thead><tr><th>When</th><th>Distributor</th><th>Invoice</th><th>Result</th><th>Details</th></tr></thead>
                <tbody>
                @foreach ($intakeLog as $l)
                    <tr>
                        <td>{{ date('m/d/y g:ia', strtotime($l['at'])) }}</td>
                        <td>{{ strtoupper($l['distributor'] ?? '') }}</td>
                        <td>{{ $l['invoice'] ?? '' }}</td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-weight:600;{{ in_array($l['status'], ['failed', 'error'], true) ? 'background:#c0392b;color:#fff;' : ($l['status'] === 'imported' ? 'background:#dff0d8;color:#222;' : 'background:#d9ecf7;color:#222;') }}">{{ $l['status'] }}</span></td>
                        <td>
                            @if (!empty($l['transaction_id']))
                                <a href="/purchases/{{ $l['transaction_id'] }}/edit" target="_blank">#{{ $l['transaction_id'] }}</a>
                            @endif
                            {{ $l['msg'] ?? '' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if ($isAdmin)
            <form method="POST" action="/reports/distributor-invoice-audit/intake-settings" style="margin-top:14px;max-width:720px;" autocomplete="off">
                {{ csrf_field() }}
                <h4 style="margin-top:0;">Intake setup (admin)</h4>
                <p>Blank fields keep what's already saved. Saved encrypted on the server.</p>
                <div class="form-group"><label>Slack incoming webhook for invoice alerts</label>
                    <input type="text" name="slack_webhook" class="form-control" placeholder="https://hooks.slack.com/services/..."></div>
                <div class="row">
                    <div class="col-sm-4 form-group"><label>Second AMS account #</label><input type="text" name="ams2_account" class="form-control" placeholder="131507"></div>
                    <div class="col-sm-4 form-group"><label>Its AMS username</label><input type="text" name="ams2_user" class="form-control"></div>
                    <div class="col-sm-4 form-group"><label>Its AMS password</label><input type="password" name="ams2_pass" class="form-control" autocomplete="new-password"></div>
                </div>
                <div class="row">
                    <div class="col-sm-6 form-group"><label>Different invoice mailbox (optional)</label><input type="text" name="mail_user" class="form-control" placeholder="invoices@nivessa.com"></div>
                    <div class="col-sm-6 form-group"><label>Its Google app password</label><input type="password" name="mail_pass" class="form-control" autocomplete="new-password"></div>
                </div>
                <label style="font-weight:normal;"><input type="checkbox" name="test_slack" value="1"> post a test message to Slack</label><br>
                <button class="btn btn-default">Save setup</button>
            </form>
        @endif
    </div>
</div>

<div class="box box-solid">
    <div class="box-header with-border"><h3 class="box-title">Invoices</h3></div>
    <div class="box-body table-responsive">
        <table class="table table-condensed table-bordered" style="font-size:12px;">
            <thead>
                <tr><th>Status</th><th>Distributor</th><th>Invoice</th><th>Date</th><th>Store</th>
                    <th style="text-align:right;">Invoice total</th><th>ERP purchase(s)</th><th style="text-align:right;">ERP total</th><th>Details</th></tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;font-weight:600;{{ $flagStyle[$r['flag']] }}">{{ $flagLabel[$r['flag']] }}</span></td>
                    <td>{{ strtoupper($r['distributor']) }}</td>
                    <td>{{ $r['invoice'] }}</td>
                    <td>{{ $fmtDate($r['date']) }}</td>
                    <td>{{ ucfirst((string) $r['store']) }}</td>
                    <td style="text-align:right;">{{ $r['total'] !== null ? '$' . number_format($r['total'], 2) : '' }}</td>
                    <td>
                        @foreach ($r['tx'] as $t)
                            <a href="/purchases/{{ $t->id }}/edit" target="_blank">#{{ $t->id }}</a> {{ $t->status }}<br>
                        @endforeach
                    </td>
                    <td style="text-align:right;">{{ count($r['tx']) ? '$' . number_format($r['erp_total'], 2) : '' }}</td>
                    <td>
                        @if ($r['flag'] === 'gap')
                            No purchase in the ERP. Import the PDF.
                        @endif
                        @foreach ($r['missing'] as $m)
                            {{ $m['upc'] }} {{ $m['desc'] }}: invoice {{ (int) $m['need'] }}, ERP {{ (int) $m['got'] }}<br>
                        @endforeach
                        {{ $r['note'] }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="9">No invoices in this window.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="box box-solid">
    <div class="box-header with-border"><h3 class="box-title">Distributor purchases with no invoice behind them</h3></div>
    <div class="box-body table-responsive">
        <p>Entered by hand (usually at the store when the box arrived). The invoice for these was never imported or registered, so lines and costs aren't checked.</p>
        <table class="table table-condensed table-bordered" style="font-size:12px;">
            <thead><tr><th>Distributor</th><th>Purchase</th><th>Ref</th><th>Date</th><th>Store</th><th>Status</th><th>Entered by</th><th style="text-align:right;">Lines</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Total</th></tr></thead>
            <tbody>
            @forelse ($adhoc as $a)
                <tr>
                    <td>{{ strtoupper($a['distributor']) }}</td>
                    <td><a href="/purchases/{{ $a['tx']->id }}/edit" target="_blank">#{{ $a['tx']->id }}</a></td>
                    <td>{{ $a['tx']->ref_no }}</td>
                    <td>{{ $fmtDate($a['tx']->transaction_date) }}</td>
                    <td>{{ ucfirst($a['store']) }}</td>
                    <td>{{ $a['tx']->status }}</td>
                    <td>{{ $a['tx']->by_name }}</td>
                    <td style="text-align:right;">{{ $a['lines'] }}</td>
                    <td style="text-align:right;">{{ (int) $a['qty'] }}</td>
                    <td style="text-align:right;">${{ number_format((float) $a['tx']->final_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="10">None.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="box box-solid">
    <div class="box-header with-border"><h3 class="box-title">Register invoices</h3></div>
    <div class="box-body">
        <p>Add invoices you know were sent (from the distributor's emails or portal) so a missing import shows up above. One per line:
            <code>distributor,invoice,date,store,total</code>, for example <code>alliance,PLS98028358,10/17/26,hollywood,101.83</code>.</p>
        <form method="POST" action="/reports/distributor-invoice-audit/register">
            {{ csrf_field() }}
            <textarea name="paste" class="form-control" rows="4" style="font-family:monospace;max-width:720px;"></textarea>
            <button class="btn btn-primary" style="margin-top:8px;">Register</button>
        </form>
    </div>
</div>
</section>
@endsection
