@extends('layouts.app')
@section('title', 'Dead Stock Report')

@section('content')
{{-- Match the POS Create (pos-v2) look: Inter Tight + cream tokens, scoped
     under body.ds-v2 so it doesn't bleed into other reports. --}}
<script>document.body.classList.add('ds-v2');</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
body.ds-v2 {
    --bg:#FAF6EE; --surface:#FFFFFF; --surface-2:#F7F1E3;
    --ink:#1F1B16; --ink-2:#5A5045; --ink-3:#8E8273;
    --line:#ECE3CF; --line-2:#DFD2B3;
    --accent:#FFF2B3; --accent-deep:#E8CF68; --accent-soft:#FFF9DB; --accent-text:#5A4410;
    --danger:#8A3A2E; --danger-soft:#F6E3DF;
    --radius:12px; --radius-sm:8px;
    --shadow-sm:0 1px 2px rgba(31,27,22,.06);
    font-family:"Inter Tight",system-ui,sans-serif; color:var(--ink);
    -webkit-font-smoothing:antialiased;
}
body.ds-v2 .content-wrapper { background:var(--bg) !important; }
body.ds-v2 section.content, body.ds-v2 .form-control, body.ds-v2 .btn,
body.ds-v2 select, body.ds-v2 table { font-family:"Inter Tight",system-ui,sans-serif; }

body.ds-v2 .ds-card {
    background:var(--surface); border:1px solid var(--line);
    border-radius:var(--radius); box-shadow:var(--shadow-sm);
    padding:18px 20px; margin-bottom:18px;
}
body.ds-v2 .ds-h1 { font-size:24px; font-weight:800; margin:0 0 4px; letter-spacing:-.01em; }
body.ds-v2 .ds-sub { color:var(--ink-2); font-size:15px; margin:0; line-height:1.5; }

body.ds-v2 .ds-stats { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:18px; }
body.ds-v2 .ds-stat {
    flex:1 1 200px; background:var(--surface); border:1px solid var(--line);
    border-radius:var(--radius); box-shadow:var(--shadow-sm); padding:16px 20px;
}
body.ds-v2 .ds-stat .lbl { font-size:12px; font-weight:700; color:var(--ink-2); text-transform:uppercase; letter-spacing:.04em; }
body.ds-v2 .ds-stat .val { font-size:28px; font-weight:800; margin-top:4px; font-variant-numeric:tabular-nums; }

body.ds-v2 .ds-filters { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
body.ds-v2 .ds-field { flex:1 1 200px; min-width:170px; }
body.ds-v2 .ds-field label {
    display:block; font-size:12px; font-weight:700; color:var(--ink-2);
    text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px;
}
body.ds-v2 .ds-field .form-control {
    height:44px; border:1px solid var(--line-2); border-radius:var(--radius-sm);
    background:var(--surface); color:var(--ink); font-size:16px; box-shadow:none;
}
body.ds-v2 .ds-btn {
    height:44px; padding:0 20px; border-radius:var(--radius-sm); font-weight:700; font-size:15px;
    border:1px solid var(--accent-deep); background:var(--accent); color:var(--accent-text);
}
body.ds-v2 .ds-btn:hover { background:var(--accent-deep); color:var(--ink); }
body.ds-v2 .ds-btn.ghost { background:var(--surface); border-color:var(--line-2); color:var(--ink); line-height:42px; display:inline-block; }

body.ds-v2 .ds-table-wrap { overflow-x:auto; }
body.ds-v2 table.ds-table { width:100%; border-collapse:separate; border-spacing:0; }
body.ds-v2 table.ds-table thead th {
    background:var(--surface-2); color:var(--ink-2); font-size:12px; font-weight:700;
    text-transform:uppercase; letter-spacing:.03em; border-bottom:2px solid var(--line-2);
    padding:12px 14px; white-space:nowrap; position:sticky; top:0;
}
body.ds-v2 table.ds-table thead th a { color:inherit; text-decoration:none; }
body.ds-v2 table.ds-table thead th a:hover { color:var(--ink); }
body.ds-v2 table.ds-table thead th.active a { color:var(--ink); }
body.ds-v2 table.ds-table thead th .fa { opacity:.35; margin-left:3px; }
body.ds-v2 table.ds-table thead th.active .fa { opacity:1; }
body.ds-v2 table.ds-table tbody td {
    padding:12px 14px; font-size:15px; color:var(--ink);
    border-top:1px solid var(--line); vertical-align:middle;
}
body.ds-v2 table.ds-table tbody tr:nth-child(even) td { background:#FCFAF4; }
body.ds-v2 table.ds-table tbody tr:hover td { background:var(--accent-soft); }
body.ds-v2 .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
body.ds-v2 .item-title { font-weight:700; }
body.ds-v2 .item-artist { color:var(--ink-2); font-size:14px; }
body.ds-v2 .small-muted { color:var(--ink-3); font-size:13px; }
body.ds-v2 .sku { font-family:ui-monospace,Menlo,monospace; font-size:13px; color:var(--ink-2); }
body.ds-v2 .pill {
    display:inline-block; font-weight:700; font-size:13px; border-radius:999px; padding:3px 12px; white-space:nowrap;
}
body.ds-v2 .pill-never { background:var(--danger-soft); border:1px solid var(--danger); color:var(--danger); }
body.ds-v2 .pill-store { background:var(--accent-soft); border:1px solid var(--accent-deep); color:var(--accent-text); text-transform:capitalize; }
body.ds-v2 .value { font-weight:800; }

body.ds-v2 .ds-pager { text-align:center; margin-top:14px; }
body.ds-v2 .ds-pager .pagination > li > a, body.ds-v2 .ds-pager .pagination > li > span {
    color:var(--ink); border-color:var(--line-2);
}
body.ds-v2 .ds-pager .pagination > .active > span {
    background:var(--accent); border-color:var(--accent-deep); color:var(--accent-text);
}
</style>

@php
    $other = request()->except(['sort', 'dir', 'page']);
    $colHead = function ($col, $label, $class = '') use ($sort, $dir, $other) {
        $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $url = action('ReportController@deadStockReport') . '?' . http_build_query(array_merge($other, ['sort' => $col, 'dir' => $newDir]));
        $icon = $sort !== $col ? 'fa-sort' : ($dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
        return '<th class="' . $class . ($sort === $col ? ' active' : '') . '"><a href="' . e($url) . '">' . e($label) . '<i class="fa ' . $icon . '"></i></a></th>';
    };
@endphp

<section class="content">
    <div class="ds-card">
        <h1 class="ds-h1">Dead Stock</h1>
        <p class="ds-sub">Items in stock that haven't sold in the last <strong>{{ $days }} days</strong>, or have never sold. Biggest dollar value first. Click a column name to sort.</p>
    </div>

    <div class="ds-stats">
        <div class="ds-stat">
            <div class="lbl">Items</div>
            <div class="val">{{ number_format($totals->total_variations ?? 0) }}</div>
        </div>
        <div class="ds-stat">
            <div class="lbl">Units on hand</div>
            <div class="val">{{ number_format($totals->total_qty ?? 0) }}</div>
        </div>
        <div class="ds-stat">
            <div class="lbl">Sell value sitting</div>
            <div class="val">${{ number_format($totals->total_value ?? 0) }}</div>
        </div>
    </div>

    <div class="ds-card">
        <form method="GET" action="{{ action('ReportController@deadStockReport') }}" class="ds-filters">
            <div class="ds-field">
                <label for="ds_days">Not sold in</label>
                <select name="days" id="ds_days" class="form-control">
                    @foreach([30, 60, 90, 180, 365, 730] as $d)
                        <option value="{{ $d }}" @if((int)$days === $d) selected @endif>{{ $d }} days</option>
                    @endforeach
                </select>
            </div>
            <div class="ds-field">
                <label for="ds_location">Store</label>
                <select name="location_id" id="ds_location" class="form-control">
                    <option value="">All stores</option>
                    @foreach($business_locations as $id => $name)
                        <option value="{{ $id }}" @if((string)$location_id === (string)$id) selected @endif>{{ ucfirst($name) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit" class="ds-btn">Apply</button>
                <a href="{{ action('ReportController@deadStockReport') }}" class="ds-btn ghost">Reset</a>
            </div>
        </form>
    </div>

    <div class="ds-card" style="padding:0;">
        <div class="ds-table-wrap">
            <table class="ds-table">
                <thead>
                    <tr>
                        {!! $colHead('title', 'Item') !!}
                        {!! $colHead('format', 'Format') !!}
                        <th>Store</th>
                        {!! $colHead('qty', 'Qty', 'num') !!}
                        {!! $colHead('price', 'Price', 'num') !!}
                        {!! $colHead('days_on_hand', 'In stock since') !!}
                        {!! $colHead('last_sold', 'Last sold') !!}
                        {!! $colHead('tied_up_value', 'Value', 'num') !!}
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    @php
                        $acq = $r->date_acquired ?: $r->product_created_at;
                        $showArtist = $r->artist && stripos($r->name, $r->artist) === false;
                    @endphp
                    <tr>
                        <td>
                            <div class="item-title">{{ $r->name }}</div>
                            @if($showArtist)<div class="item-artist">{{ $r->artist }}</div>@endif
                            <div class="sku">{{ $r->sub_sku }}</div>
                        </td>
                        <td>{{ $r->format ?: '' }}</td>
                        <td>
                            @if(isset($business_locations[$r->location_id]))
                                <span class="pill pill-store">{{ $business_locations[$r->location_id] }}</span>
                            @endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format($r->qty_available, 2), '0'), '.') }}</td>
                        <td class="num">${{ number_format($r->selling_price, 2) }}</td>
                        <td>
                            @if($acq)
                                {{ \Carbon\Carbon::parse($acq)->format('M j, Y') }} @if(!$r->date_acquired)<span class="small-muted" title="No purchase record found - showing when the product was added">*</span>@endif
                                <div class="small-muted">{{ number_format($r->days_on_hand) }} days</div>
                            @endif
                        </td>
                        <td>
                            @if($r->last_sold)
                                {{ \Carbon\Carbon::parse($r->last_sold)->format('M j, Y') }}
                                <div class="small-muted">{{ number_format($r->days_since_sold) }} days ago</div>
                            @else
                                <span class="pill pill-never">Never sold</span>
                            @endif
                        </td>
                        <td class="num value">${{ number_format($r->tied_up_value, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="8" style="text-align:center;" class="small-muted">No dead stock in this window. Everything on hand has sold recently.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="ds-pager">{{ $rows->links() }}</div>

    <p class="small-muted">* No purchase record found, so this shows when the product was added to the ERP instead.</p>
</section>
@stop
