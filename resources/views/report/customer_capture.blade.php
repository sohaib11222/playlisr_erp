@extends('layouts.app')
@section('title', 'Customer Capture Rate')

@section('content')
<style>
    .content-wrapper, body { background: #FAF6EE !important; }
    .content-header > h1 { font-weight: 800; font-size: 26px; color: #1F1B16; letter-spacing: -.01em; }
    .content-header > h1 small { color: #8E8273; font-weight: 500; }
    .cc .box { background: #FFFFFF !important; border: 1px solid #ECE3CF !important;
        border-radius: 10px !important; box-shadow: 0 1px 2px rgba(31,27,22,.06) !important; }
    .cc label { font-size: 11px; font-weight: 600; letter-spacing: .12em;
        text-transform: uppercase; color: #8E8273; }
    .cc .form-control { border: 1px solid #DFD2B3 !important; border-radius: 8px !important;
        box-shadow: none !important; }
    .cc-table { width: 100%; }
    .cc-table thead tr { color: #8E8273; text-transform: uppercase; font-size: 10px; letter-spacing: .5px; }
    .cc-table th { border-bottom: 1px solid #ECE3CF; padding: 6px 10px; }
    .cc-table td { border-top: 1px solid #F4ECD9; padding: 9px 10px; vertical-align: middle; }
    .cc-table tfoot td { border-top: 2px solid #ECE3CF; font-weight: 800; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .cc-name { font-weight: 700; color: #1F1B16; }
    .cc-bar { position: relative; height: 8px; border-radius: 4px; background: #F0E7D4; overflow: hidden; min-width: 90px; }
    .cc-bar > span { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 4px; }
    .rate-good > span { background: #1b7a32; }
    .rate-mid  > span { background: #C9902B; }
    .rate-bad  > span { background: #b23b3b; }
    .cc-rate-num { font-weight: 800; font-size: 15px; }
    .cc-good { color: #1b7a32; } .cc-mid { color: #9a6a12; } .cc-bad { color: #b23b3b; }
    .cc-reason-row td { padding: 6px 10px; border-top: 1px solid #F4ECD9; }
    .cc-explain { background: #FFF9DB !important; border: 1px solid #E8CF68 !important;
        border-left: 4px solid #E8CF68 !important; color: #5A4410 !important; border-radius: 8px;
        padding: 10px 14px; margin-bottom: 16px; }
    .cc-chip { display:inline-block; background:#F0E7D4; color:#5A4410; border-radius:6px;
        padding:2px 8px; font-size:12px; font-weight:600; margin:0 6px 6px 0; }
</style>

<section class="content-header">
    <h1>Customer Capture Rate <small>% of in-store sales with a Nivessa account attached</small></h1>
</section>

<section class="content cc">
    <div class="cc-explain">
        <strong>How to read this:</strong> every finalized in-store register sale now either has a real customer
        account attached, or the cashier recorded that the customer declined (with a reason). <strong>Capture rate =
        attached ÷ total in-store sales.</strong> “Skipped (no record)” is older walk-in sales from before the gate —
        it trends to zero over time.
    </div>

    <div class="box">
        <div class="box-body">
            <form method="get" class="form-inline" style="margin-bottom:14px;">
                <label style="margin-right:8px;">Period</label>
                <select name="period" class="form-control" onchange="this.form.submit()">
                    @php $periods = ['today'=>'Today','yesterday'=>'Yesterday','this_week'=>'This week','last_7'=>'Last 7 days','last_30'=>'Last 30 days','this_month'=>'This month','last_month'=>'Last month']; @endphp
                    @foreach($periods as $val => $lbl)
                        <option value="{{ $val }}" {{ $period === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                    @endforeach
                </select>
                <span class="text-muted" style="margin-left:12px;">{{ $start->format('M j') }} – {{ $end->format('M j, Y') }}</span>
            </form>

            <table class="cc-table">
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th class="num">In-store sales</th>
                        <th class="num">Attached</th>
                        <th class="num">Declined</th>
                        <th class="num">Skipped (no record)</th>
                        <th style="width:180px;">Capture rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report as $r)
                        @php
                            $rate = $r['rate'];
                            $cls = $rate === null ? '' : ($rate >= 70 ? 'good' : ($rate >= 40 ? 'mid' : 'bad'));
                        @endphp
                        <tr>
                            <td class="cc-name">{{ $r['name'] }}</td>
                            <td class="num">{{ number_format($r['total']) }}</td>
                            <td class="num cc-good">{{ number_format($r['attached']) }}</td>
                            <td class="num">{{ number_format($r['declined']) }}</td>
                            <td class="num text-muted">{{ number_format($r['skipped']) }}</td>
                            <td>
                                @if($rate === null)
                                    <span class="text-muted">—</span>
                                @else
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <span class="cc-rate-num cc-{{ $cls }}">{{ $rate }}%</span>
                                        <div class="cc-bar rate-{{ $cls }}" style="flex:1;"><span style="width:{{ max(2,$rate) }}%;"></span></div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted" style="padding:18px; text-align:center;">No in-store sales in this period.</td></tr>
                    @endforelse
                </tbody>
                @if(count($report))
                <tfoot>
                    <tr>
                        <td>All cashiers</td>
                        <td class="num">{{ number_format($totals['sales']) }}</td>
                        <td class="num">{{ number_format($totals['attached']) }}</td>
                        <td class="num">{{ number_format($totals['declined']) }}</td>
                        <td class="num">{{ number_format($totals['skipped']) }}</td>
                        <td>{!! $totals['rate'] === null ? '—' : '<span class="cc-rate-num">'.$totals['rate'].'%</span>' !!}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if(!empty($reason_totals))
    <div class="box">
        <div class="box-header"><h3 class="box-title">Why customers weren’t signed up</h3></div>
        <div class="box-body">
            @foreach($reason_totals as $reason => $count)
                <span class="cc-chip">{{ $reason }} · <strong>{{ number_format($count) }}</strong></span>
            @endforeach
        </div>
    </div>
    @endif
</section>
@endsection
