@extends('layouts.app')
@section('title', 'LFL Data Coverage')

@section('content')
<section class="content-header">
    <h1>Like-for-Like — Data Coverage</h1>
    <p class="text-muted" style="max-width:900px;">
        Finalized in-store sells (Whatnot excluded), grouped by <strong>store &times; month &times; source</strong>,
        last 36 months since {{ $since }}. The LFL report counts imports too, so a prior-year
        <code>n/a</code> means a store has <strong>$0</strong> for that month. Each cell shows
        <span style="color:#1a7f37;">live</span> + <span style="color:#b58900;">import</span> revenue;
        hover a cell for the import_source breakdown. Read-only — nothing is changed.
    </p>
</section>

<section class="content">

<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Totals by source</h3></div>
    <div class="box-body">
        <table class="table table-condensed" style="max-width:640px;">
            <thead><tr><th>import_source</th><th style="text-align:right;">tx</th><th style="text-align:right;">revenue</th></tr></thead>
            <tbody>
            @foreach ($srcTotals as $src => $t)
                <tr>
                    <td><code>{{ $src }}</code></td>
                    <td style="text-align:right;">{{ number_format($t['cnt']) }}</td>
                    <td style="text-align:right;">${{ number_format($t['revenue']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Revenue by store &amp; month</h3></div>
    <div class="box-body table-responsive">
        <table class="table table-condensed table-bordered">
            <thead>
                <tr>
                    <th>Month</th>
                    @foreach ($locations as $name)
                        <th style="text-align:right;">{{ $name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @foreach ($months as $ym => $byLoc)
                <tr>
                    <td><strong>{{ $ym }}</strong></td>
                    @foreach ($locations as $locId => $name)
                        @php
                            $c = $byLoc[$locId] ?? null;
                            $live = $c['live'] ?? 0;
                            $imp  = $c['import'] ?? 0;
                            $tot  = $live + $imp;
                            $srcs = $c['srcs'] ?? [];
                            $title = '';
                            foreach ($srcs as $s => $n) { $title .= $s . ': ' . $n . " tx\n"; }
                        @endphp
                        <td style="text-align:right; {{ $tot == 0 ? 'background:#fdecea;' : '' }}" title="{{ trim($title) }}">
                            @if ($tot == 0)
                                <span class="text-muted">$0</span>
                            @else
                                <strong>${{ number_format($tot) }}</strong>
                                <div style="font-size:11px;">
                                    @if ($live > 0)<span style="color:#1a7f37;">live ${{ number_format($live) }}</span>@endif
                                    @if ($live > 0 && $imp > 0) · @endif
                                    @if ($imp > 0)<span style="color:#b58900;">imp ${{ number_format($imp) }}</span>@endif
                                </div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

</section>
@endsection
