@extends('layouts.app')
@section('title', 'Weekly Reorder')

@section('content')
<style>
    .ro-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .ro-bar .form-group { margin:0; }
    .ro-muted { color:#888; font-size:12px; }
    .ro-flag-wrong { color:#c62828; font-weight:bold; }
    .ro-flag-low { color:#ef6c00; font-weight:bold; }
    table.ro-cat td { vertical-align:middle !important; }
    tr.ro-detail td { background:#fafafa; }
    tr.ro-detail table { margin:4px 0 8px; font-size:12px; }
</style>

<section class="content-header">
    <h1>Weekly Reorder <small>{{ $storeName }}, everything else</small></h1>
</section>

<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            <form method="GET" action="{{ action('ReorderController@index') }}" class="ro-bar">
                <div class="form-group">
                    <label>Store</label>
                    <select name="location_id" class="form-control" onchange="this.form.submit()">
                        @foreach($locations as $id => $name)
                            <option value="{{ $id }}" @if($id == $locationId) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Format</label>
                    <select name="format" class="form-control" onchange="this.form.submit()">
                        <option value="vinyl">Sealed vinyl</option>
                        <option value="cd">Sealed CDs</option>
                        <option value="cassette">Sealed cassettes</option>
                        <option value="other" selected>Everything else (apparel, toys, books...)</option>
                    </select>
                </div>
            </form>
            <p style="margin:10px 0 0;">Everything that isn't sealed music, last 90 days. Click a row to see its top sellers.
                <span class="ro-flag-wrong">Stock looks wrong</span> means the ERP shows more than 2 years of sales on hand, so spot check those shelves.
                <span class="ro-flag-low">Running low</span> means under 4 weeks left at this pace.</p>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-condensed ro-cat">
                <thead>
                    <tr>
                        <th>Area</th>
                        <th class="text-right">Sold, 90 days</th>
                        <th class="text-right">Sales, 90 days</th>
                        <th class="text-right">ERP stock</th>
                        <th class="text-right">Weeks of stock</th>
                        <th>Check</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($areas as $i => $a)
                    <tr style="cursor:pointer;" onclick="var d=document.getElementById('ro-d{{ $i }}'); d.style.display = d.style.display === 'none' ? '' : 'none';">
                        <td><b>{{ $a['name'] }}</b> <i class="fa fa-caret-down ro-muted"></i></td>
                        <td class="text-right">{{ number_format($a['sold']) }}</td>
                        <td class="text-right">${{ number_format($a['revenue']) }}</td>
                        <td class="text-right">{{ number_format($a['on_hand']) }}</td>
                        <td class="text-right">{{ $a['weeks'] === null ? 'no sales' : number_format($a['weeks']) }}</td>
                        <td class="ro-flag-{{ $a['flag'][0] }}">{{ $a['flag'][1] }}</td>
                    </tr>
                    <tr class="ro-detail" id="ro-d{{ $i }}" style="display:none;">
                        <td colspan="6">
                            @if(empty($a['top']))
                                <span class="ro-muted">Nothing sold in 90 days.</span>
                            @else
                                <table class="table table-condensed">
                                    <tr><th>Top sellers</th><th class="text-right">Sold</th><th class="text-right">ERP stock</th><th>Last sold</th><th>Last bought from</th><th></th></tr>
                                    @foreach($a['top'] as $t)
                                        <tr>
                                            <td>{{ $t['name'] }} <span class="ro-muted">{{ $t['sku'] }}</span></td>
                                            <td class="text-right">{{ $t['sold'] }}</td>
                                            <td class="text-right">{{ $t['stock'] }}</td>
                                            <td>{{ $t['last'] ? \Carbon\Carbon::parse($t['last'])->format('M j') : '' }}</td>
                                            <td>@if($t['supplier'] || $t['purchase_id'])<a href="{{ url('/purchases/' . $t['purchase_id']) }}" target="_blank">{{ $t['supplier'] ?: 'no supplier' }}</a>, ${{ number_format($t['supplier_cost'], 2) }} x {{ $t['supplier_qty'] + 0 }} on {{ \Carbon\Carbon::parse($t['supplier_date'])->format('M j') }}@else<span class="ro-muted">never bought in the ERP</span>@endif</td>
                                            <td>@if($t['restock'])<span class="ro-flag-low">Out: restock or count it</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </table>
                                @if($a['used'])<span class="ro-muted">Used stock comes from buying collections, not distributors.</span>@endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">Nothing found for this store.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
