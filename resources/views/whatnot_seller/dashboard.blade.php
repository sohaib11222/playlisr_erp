@extends('layouts.app')
@section('title', 'Whatnot Seller')

@section('content')
<style>
    .wn-kpi { background: #fff; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.08); color: #222; }
    .wn-kpi b { display: block; font-size: 24px; }
    .wn-flag { display: inline-block; background: #fdecea; color: #a12622; border-radius: 10px; padding: 1px 8px; font-size: 12px; margin: 1px 2px 1px 0; }
    .wn-golden { display: inline-block; background: #d7ebfb; color: #0b4a7a; border-radius: 10px; padding: 1px 8px; font-size: 12px; }
    .wn-table td, .wn-table th { color: #222; }
    .wn-low { color: #a12622; font-weight: 600; }
    .wn-gap { color: #a12622; font-weight: 600; }
    .wn-up { color: #2b6a2b; font-weight: 600; }
</style>
<section class="content-header">
    <h1>Whatnot Seller <small style="color:#333;">what {{ $s['seller_name'] }} sold, for how much, and his pay</small></h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-body">
            <p style="margin:0 0 6px;"><strong>{{ $s['seller_name'] }}'s private link</strong> (no ERP login, he only sees Whatnot items he can sell and his own sales):</p>
            <div class="input-group">
                <input type="text" class="form-control" id="wn-link" value="{{ $link }}" readonly>
                <span class="input-group-btn"><button class="btn btn-default" type="button" onclick="var i=document.getElementById('wn-link');i.select();document.execCommand('copy');this.textContent='Copied';">Copy</button></span>
            </div>
        </div>
    </div>

    <form method="get" class="form-inline" style="margin-bottom:12px;">
        <label>From</label> <input type="date" name="start_date" value="{{ $from }}" class="form-control">
        <label>To</label> <input type="date" name="end_date" value="{{ $to }}" class="form-control">
        <button class="btn btn-primary">Show</button>
    </form>

    <div class="row">
        <div class="col-sm-3"><div class="wn-kpi">Sold on Whatnot<b>${{ number_format($total, 2) }}</b>{{ $count }} items, {{ \Carbon\Carbon::parse($from)->format('m/d/y') }} to {{ \Carbon\Carbon::parse($to)->format('m/d/y') }}</div></div>
        <div class="col-sm-3"><div class="wn-kpi">Sold under sticker<b class="{{ $under > 0 ? 'wn-low' : '' }}">${{ number_format($under, 2) }}</b>@if($premium > 0)<span class="wn-up">+${{ number_format($premium, 2) }} over sticker</span>@else average {{ $avg_pct === null ? 'n/a' : $avg_pct . '%' }} of sticker @endif</div></div>
        <div class="col-sm-3"><div class="wn-kpi">Flagged sales<b class="{{ $flagged ? 'wn-low' : '' }}">{{ $flagged }}</b>under sticker, too new in store, or under cost</div></div>
        <div class="col-sm-3"><div class="wn-kpi">{{ $s['seller_name'] }}'s pay for these dates<b>${{ number_format($pay['total'], 2) }}</b>{{ (int) $pay['items'] }} items x ${{ number_format($s['per_item'], 2) }} + {{ $pay['hours'] }} hrs x ${{ number_format($s['hourly_rate'], 2) }}</div></div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="box box-default">
                <div class="box-header"><h3 class="box-title">Did every sale get marked?</h3></div>
                <div class="box-body">
                    <p style="color:#222;">Compares what's in the ERP to the monthly Whatnot statement (typed in at <a href="{{ url('/whatnot/import-statement') }}">Import monthly statement</a>). A gap means records were sold on Whatnot but never marked, so they're still showing on the website.</p>
                    <table class="table table-condensed wn-table">
                        <tr><th>Month</th><th class="text-right">Marked in ERP</th><th class="text-right">Whatnot statement</th><th class="text-right">Not marked</th></tr>
                        @forelse($month_rows as $m)
                            @php $gap = $m['statement'] === null ? null : round($m['statement'] - $m['erp'], 2); @endphp
                            <tr>
                                <td>{{ $m['label'] }}</td>
                                <td class="text-right">${{ number_format($m['erp'], 2) }}</td>
                                <td class="text-right">{{ $m['statement'] === null ? 'not imported' : '$' . number_format($m['statement'], 2) }}</td>
                                <td class="text-right {{ $gap !== null && $gap > 1 ? 'wn-gap' : '' }}">{{ $gap === null ? '' : '$' . number_format($gap, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">No Whatnot sales in this range.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="box box-default">
                <div class="box-header"><h3 class="box-title">Rules and pay</h3></div>
                <div class="box-body">
                    <form method="post" action="{{ url('/reports/whatnot-seller/settings') }}">
                        @csrf
                        <div class="row">
                            <div class="col-sm-4 form-group"><label>Seller name</label><input class="form-control" name="seller_name" value="{{ $s['seller_name'] }}"></div>
                            <div class="col-sm-4 form-group"><label>Per item sold ($)</label><input class="form-control" type="number" step="0.01" name="per_item" value="{{ $s['per_item'] }}"></div>
                            <div class="col-sm-4 form-group"><label>Per hour ($)</label><input class="form-control" type="number" step="0.01" name="hourly_rate" value="{{ $s['hourly_rate'] }}"></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4 form-group"><label>Lowest % of sticker</label><input class="form-control" type="number" name="min_pct_of_sticker" value="{{ $s['min_pct_of_sticker'] }}"></div>
                            <div class="col-sm-4 form-group"><label>Days in store first</label><input class="form-control" type="number" name="min_days_in_store" value="{{ $s['min_days_in_store'] }}"></div>
                            <div class="col-sm-4 form-group"><label>Credit sales to user</label>
                                <select class="form-control" name="user_id"><option value="">None</option>
                                    @foreach($users as $u)<option value="{{ $u->id }}" {{ (int) $s['user_id'] === (int) $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach
                                </select></div>
                        </div>
                        <button class="btn btn-primary">Save</button>
                        <label style="margin-left:14px; font-weight:normal;"><input type="checkbox" name="new_link" value="1"> Make a new link (the old one stops working)</label>
                    </form>
                    <p style="color:#222; margin:10px 0 0;">He sees these rules on his page. Anything under the lowest % or newer than the days-in-store needs a written reason before he can save it.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header"><h3 class="box-title">Show hours {{ $s['seller_name'] }} logged</h3></div>
        <div class="box-body">
            <table class="table table-condensed wn-table">
                @forelse($shows as $h)
                    <tr><td>{{ \Carbon\Carbon::parse($h['date'])->format('m/d/y') }}</td><td>{{ $h['hours'] }} hrs</td><td>{{ $h['note'] }}</td><td>logged {{ \Carbon\Carbon::parse($h['logged_at'])->format('m/d/y g:ia') }}</td>
                        <td class="text-right"><form method="post" action="{{ url('/reports/whatnot-seller/hours/delete') }}" style="display:inline;">@csrf<input type="hidden" name="id" value="{{ $h['id'] }}"><button class="btn btn-xs btn-default">Remove</button></form></td></tr>
                @empty
                    <tr><td>No show hours logged in these dates. He logs them on his page.</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header"><h3 class="box-title">Every Whatnot item sold</h3> <span style="color:#222;">({{ $by_golden }} marked by {{ $s['seller_name'] }} himself, the rest rung up by staff on the POS)</span></div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed wn-table" id="wn-lines">
                <thead><tr><th>Date</th><th>Item</th><th>Store</th><th class="text-right">Sold</th><th class="text-right">Sticker</th><th class="text-right">% of sticker</th><th class="text-right">Cost</th><th class="text-right">Days in store</th><th>Marked by</th><th>Flags / reason</th></tr></thead>
                <tbody>
                @forelse($lines as $l)
                    <tr>
                        <td>{{ $l['date'] }}</td>
                        <td><a href="{{ url('/sells/' . $l['transaction_id']) }}" target="_blank">{{ $l['title'] }}</a></td>
                        <td>{{ $l['store'] }}</td>
                        <td class="text-right">${{ number_format($l['sold'] * $l['qty'], 2) }}</td>
                        <td class="text-right">{{ $l['sticker'] === null ? '' : '$' . number_format($l['sticker'], 2) }}</td>
                        <td class="text-right {{ $l['pct'] !== null && $l['pct'] < 100 ? 'wn-low' : ($l['pct'] !== null && $l['pct'] > 100 ? 'wn-up' : '') }}">{{ $l['pct'] === null ? '' : $l['pct'] . '%' }}</td>
                        <td class="text-right">{{ $l['cost'] ? '$' . number_format($l['cost'], 2) : '' }}</td>
                        <td class="text-right">{{ $l['days_in_store'] }}</td>
                        <td>@if($l['by_golden'])<span class="wn-golden">{{ $l['entered_by'] }}</span>@else{{ $l['entered_by'] }}@endif</td>
                        <td>@if($l['sticker'] && $l['sold'] > $l['sticker'])<span class="wn-up">+${{ number_format(($l['sold'] - $l['sticker']) * $l['qty'], 2) }} over sticker</span> @endif @foreach($l['flags'] as $f)<span class="wn-flag">{{ $f }}</span>@endforeach @if($l['note'] !== '') <div>{{ $l['note'] }}</div> @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10">No Whatnot sales in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
