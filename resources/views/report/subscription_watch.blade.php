@extends('layouts.app')
@section('title', 'Subscription Watch')

@section('content')
@php
    $money = function ($v) { return $v === null ? '' : '$' . number_format($v, 2); };
    $md = function ($ymd) { return $ymd ? date('m/d/y', strtotime($ymd)) : ''; };
    $mon = function ($ym) { return date('M Y', strtotime($ym . '-01')); };
    $cardNames = function ($list) use ($cards) {
        return implode(', ', array_map(function ($c) use ($cards) {
            return $cards[$c]['label'] ?? $c;
        }, $list));
    };
    $owner = function ($list) use ($cards) {
        $o = array_unique(array_map(function ($c) use ($cards) { return $cards[$c]['owner'] ?? 'Personal'; }, $list));
        return implode(' + ', $o);
    };
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
    $missing = [];
    foreach ($cards as $k => $c) {
        if (empty($cov[$k][$month])) { $missing[] = $c['label']; }
    }
@endphp
<style>
    .sw-wrap { max-width:1100px; }
    .sw-card { background:#fff; border:1px solid #ddd; border-radius:8px; padding:14px 16px; margin-bottom:16px; }
    .sw-card h3 { margin:0 0 10px; font-size:18px; font-weight:600; }
    .sw-big { font-size:26px; font-weight:700; color:#222; }
    .sw-row { display:flex; flex-wrap:wrap; gap:16px; }
    .sw-row > div { flex:1; min-width:160px; }
    .sw-label { font-size:13px; color:#333; }
    table.sw { width:100%; font-size:14px; }
    table.sw th { background:#f4f4f4; font-size:13px; }
    table.sw td, table.sw th { padding:6px 8px !important; vertical-align:middle !important; }
    .sw-up { color:#c62828; font-weight:600; }
    .sw-new { color:#1565c0; font-weight:600; }
    .sw-pill { display:inline-block; border-radius:10px; padding:1px 8px; font-size:12px; background:#e3f2fd; color:#0d47a1; }
    .sw-pill.biz { background:#e8f5e9; color:#1b5e20; }
    .sw-x { background:none; border:none; color:#c62828; font-size:12px; text-decoration:underline; padding:0; }
    .sw-warn { background:#fff8e1; border:1px solid #ffe082; border-radius:6px; padding:8px 12px; margin-bottom:12px; color:#333; }
    .sw-hist { font-size:12px; color:#333; }
</style>

<section class="content-header">
    <h1>Subscription Watch <small style="color:#333;">new subscriptions and price jumps, every card</small></h1>
</section>

<section class="content sw-wrap">
    <form method="get" class="form-inline" style="margin-bottom:12px;">
        <a class="btn btn-default btn-sm" href="?month={{ $prevMonth }}">&larr; {{ $mon($prevMonth) }}</a>
        <select name="month" class="form-control input-sm" onchange="this.form.submit()">
            @php $opts = array_unique(array_merge([$month], $months)); rsort($opts); @endphp
            @foreach ($opts as $m)
                <option value="{{ $m }}" {{ $m === $month ? 'selected' : '' }}>{{ $mon($m) }}</option>
            @endforeach
        </select>
        <a class="btn btn-default btn-sm" href="?month={{ $nextMonth }}">{{ $mon($nextMonth) }} &rarr;</a>
    </form>

    @if ($missing)
        <div class="sw-warn">No charges loaded yet for {{ $mon($month) }} on: {{ implode(', ', $missing) }}. Upload a CSV below, or the monthly bot fills these in when you are signed in to that bank in Chrome.</div>
    @endif

    <div class="sw-card">
        <div class="sw-row">
            <div><div class="sw-label">New subscriptions</div><div class="sw-big">{{ count($r['new']) }}</div></div>
            <div><div class="sw-label">Went up</div><div class="sw-big">{{ count($r['jumped']) }}</div>
                <div class="sw-label">+{{ $money(array_sum(array_column($r['jumped'], 'diff'))) }} a month</div></div>
            <div><div class="sw-label">All subscriptions in {{ $mon($month) }}</div><div class="sw-big">{{ $money($r['total']) }}</div>
                <div class="sw-label">{{ count($r['active']) }} charges</div></div>
        </div>
    </div>

    <div class="sw-card">
        <h3>New this month</h3>
        @if (!$r['new'])
            <p>No new subscriptions in {{ $mon($month) }}.</p>
        @else
        <table class="table table-bordered sw">
            <tr><th>Merchant</th><th>Card</th><th>Started</th><th>Amount</th><th></th></tr>
            @foreach ($r['new'] as $x)
                <tr>
                    <td class="sw-new">{{ $x['desc'] }}</td>
                    <td>{{ $cardNames($x['cards']) }} <span class="sw-pill {{ $owner($x['cards']) === 'Business' ? 'biz' : '' }}">{{ $owner($x['cards']) }}</span></td>
                    <td>{{ $mon($x['first']) }}</td>
                    <td>{{ $money($x['this']) }}</td>
                    <td>@include('report.partials.subscription_watch_ignore', ['k' => $x['key']])</td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>

    <div class="sw-card">
        <h3>Went up</h3>
        @if (!$r['jumped'])
            <p>Nothing went up in {{ $mon($month) }}.</p>
        @else
        <table class="table table-bordered sw">
            <tr><th>Merchant</th><th>Card</th><th>Usual</th><th>{{ $mon($month) }}</th><th>Up by</th><th></th></tr>
            @foreach ($r['jumped'] as $x)
                <tr>
                    <td>{{ $x['desc'] }}</td>
                    <td>{{ $cardNames($x['cards']) }} <span class="sw-pill {{ $owner($x['cards']) === 'Business' ? 'biz' : '' }}">{{ $owner($x['cards']) }}</span></td>
                    <td>{{ $money($x['before']) }}</td>
                    <td>{{ $money($x['this']) }}</td>
                    <td class="sw-up">+{{ $money($x['diff']) }}{{ $x['pct'] !== null ? ' (' . $x['pct'] . '%)' : '' }}</td>
                    <td>@include('report.partials.subscription_watch_ignore', ['k' => $x['key']])</td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>

    @if ($r['stopped'])
    <div class="sw-card">
        <h3>Did not charge this month</h3>
        <p class="sw-label">Charged last month but not in {{ $mon($month) }}. Canceled, billed yearly, or moved to another card.</p>
        <table class="table table-bordered sw">
            <tr><th>Merchant</th><th>Card</th><th>Last month</th><th>Last charge</th></tr>
            @foreach ($r['stopped'] as $x)
                <tr><td>{{ $x['desc'] }}</td><td>{{ $cardNames($x['cards']) }}</td><td>{{ $money($x['last']) }}</td><td>{{ $md($x['last_date']) }}</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    <div class="sw-card">
        <h3>Every subscription charged in {{ $mon($month) }}</h3>
        <table class="table table-bordered sw">
            <tr><th>Merchant</th><th>Card</th><th>{{ $mon($month) }}</th><th>Last 6 months</th><th></th></tr>
            @foreach ($r['active'] as $x)
                @php $h = array_slice(array_filter($x['history'], function ($v, $ym) use ($month) { return $ym <= $month; }, ARRAY_FILTER_USE_BOTH), -6, 6, true); @endphp
                <tr>
                    <td>{{ $x['desc'] }}</td>
                    <td>{{ $cardNames($x['cards']) }} <span class="sw-pill {{ $owner($x['cards']) === 'Business' ? 'biz' : '' }}">{{ $owner($x['cards']) }}</span></td>
                    <td>{{ $money($x['this']) }}</td>
                    <td class="sw-hist">@foreach ($h as $ym => $v){{ date('m/y', strtotime($ym . '-01')) }} {{ $money($v) }}@if (!$loop->last), @endif @endforeach</td>
                    <td>@include('report.partials.subscription_watch_ignore', ['k' => $x['key']])</td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="sw-card">
        <h3>Cards loaded</h3>
        <table class="table table-bordered sw">
            <tr><th>Card</th><th>Type</th><th>Months loaded</th><th>Latest</th></tr>
            @foreach ($cards as $k => $c)
                @php $ms = array_keys($cov[$k] ?? []); @endphp
                <tr>
                    <td>{{ $c['label'] }}</td>
                    <td><span class="sw-pill {{ $c['owner'] === 'Business' ? 'biz' : '' }}">{{ $c['owner'] }}</span></td>
                    <td>{{ $ms ? $mon($ms[0]) . ' to ' . $mon(end($ms)) : 'None yet' }}</td>
                    <td>{{ $ms ? $mon(end($ms)) : '' }}</td>
                </tr>
            @endforeach
        </table>

        <h3 style="margin-top:16px;">Upload a CSV</h3>
        <p class="sw-label">Download the card's activity as CSV from Chase, Citi or Barclays and upload it here. Re-uploading the same dates replaces them, so nothing doubles.</p>
        <form method="post" action="{{ url('/reports/subscriptions/import') }}" enctype="multipart/form-data" class="form-inline">
            @csrf
            <select name="card" class="form-control input-sm" required>
                @foreach ($cards as $k => $c)<option value="{{ $k }}">{{ $c['label'] }}</option>@endforeach
            </select>
            <input type="file" name="csv" accept=".csv,text/csv" class="form-control input-sm" required>
            <button class="btn btn-primary btn-sm">Upload</button>
        </form>
        <details style="margin-top:10px;">
            <summary>Add a new card</summary>
            <form method="post" action="{{ url('/reports/subscriptions/import') }}" enctype="multipart/form-data" class="form-inline" style="margin-top:8px;">
                @csrf
                <input name="card" class="form-control input-sm" placeholder="Last 4" maxlength="4" required style="width:80px;">
                <input name="label" class="form-control input-sm" placeholder="Name, e.g. Chase Ink 4321 (Nivessa One)" required style="width:300px;">
                <select name="owner" class="form-control input-sm"><option>Business</option><option>Personal</option></select>
                <input type="file" name="csv" accept=".csv,text/csv" class="form-control input-sm" required>
                <button class="btn btn-primary btn-sm">Add and upload</button>
            </form>
        </details>

        @if ($imports)
            <h3 style="margin-top:16px;">Recent imports</h3>
            <table class="table table-bordered sw">
                <tr><th>When</th><th>Card</th><th>Dates</th><th>Charges</th><th>From</th></tr>
                @foreach ($imports as $i)
                    <tr><td>{{ $md($i['at']) }}</td><td>{{ $cards[$i['card']]['label'] ?? $i['card'] }}</td><td>{{ $md($i['from']) }} to {{ $md($i['to']) }}</td><td>{{ $i['rows'] }}</td><td>{{ $i['source'] }}</td></tr>
                @endforeach
            </table>
        @endif

        @if ($ignored)
            <details style="margin-top:10px;">
                <summary>Marked not a subscription ({{ count($ignored) }})</summary>
                @foreach ($ignored as $k)
                    <div>{{ $k }} @include('report.partials.subscription_watch_ignore', ['k' => $k, 'undo' => true])</div>
                @endforeach
            </details>
        @endif
    </div>
</section>
@endsection
