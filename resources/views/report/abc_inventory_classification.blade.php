@extends('layouts.app')
@section('title', 'ABC Inventory Classification')

@section('content')
{{-- Match the POS Create (pos-v2) look: Inter Tight + cream tokens, scoped
     under body.abc-v2 so it doesn't bleed into other reports. --}}
<script>document.body.classList.add('abc-v2');</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
body.abc-v2 {
    --bg:#FAF6EE; --surface:#FFFFFF; --surface-2:#F7F1E3;
    --ink:#1F1B16; --ink-2:#5A5045; --ink-3:#8E8273;
    --line:#ECE3CF; --line-2:#DFD2B3;
    --accent:#FFF2B3; --accent-deep:#E8CF68; --accent-soft:#FFF9DB; --accent-text:#5A4410;
    --success:#2F6B3E; --danger:#8A3A2E; --cr:#7A1F1F;
    --radius:12px; --radius-sm:8px;
    --shadow-sm:0 1px 2px rgba(31,27,22,.06); --shadow-md:0 4px 14px rgba(31,27,22,.08);
    font-family:"Inter Tight",system-ui,sans-serif; color:var(--ink);
    -webkit-font-smoothing:antialiased;
}
body.abc-v2 .content-wrapper { background:var(--bg) !important; }
body.abc-v2 section.content, body.abc-v2 .box, body.abc-v2 .form-control,
body.abc-v2 .btn, body.abc-v2 select, body.abc-v2 input, body.abc-v2 table {
    font-family:"Inter Tight",system-ui,sans-serif;
}

/* Cards */
body.abc-v2 .abc-card {
    background:var(--surface); border:1px solid var(--line);
    border-radius:var(--radius); box-shadow:var(--shadow-sm);
    padding:18px 20px; margin-bottom:18px;
}
body.abc-v2 .abc-h1 { font-size:24px; font-weight:800; margin:0 0 4px; letter-spacing:-.01em; }
body.abc-v2 .abc-sub { color:var(--ink-2); font-size:14px; margin:0 0 18px; }
body.abc-v2 .abc-card h3 {
    font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
    color:var(--ink-3); margin:0 0 14px;
}

/* Banners */
body.abc-v2 .abc-banner {
    border-radius:var(--radius); padding:14px 18px; margin-bottom:16px;
    font-size:14px; line-height:1.5; border:1px solid transparent;
}
body.abc-v2 .abc-banner.info    { background:var(--accent-soft); border-color:var(--accent-deep); color:var(--accent-text); }
body.abc-v2 .abc-banner.warn    { background:#FBEFE9; border-color:#E7C3B5; color:var(--danger); }
body.abc-v2 .abc-banner.howto   { background:#EAF4ED; border-color:#BFE0C9; color:var(--success); }
body.abc-v2 .abc-banner a { color:inherit; text-decoration:underline; font-weight:700; }

/* Filters */
body.abc-v2 .abc-filters { display:flex; flex-wrap:wrap; gap:14px; }
body.abc-v2 .abc-field { flex:1 1 160px; min-width:150px; }
body.abc-v2 .abc-field label {
    display:block; font-size:12px; font-weight:700; color:var(--ink-2);
    text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px;
}
body.abc-v2 .abc-field .form-control {
    height:42px; border:1px solid var(--line-2); border-radius:var(--radius-sm);
    background:var(--surface); color:var(--ink); font-size:15px; box-shadow:none;
}
body.abc-v2 .abc-field .form-control:focus { border-color:var(--accent-deep); box-shadow:0 0 0 3px rgba(232,207,104,.25); }

/* Table */
body.abc-v2 table.dataTable { border-collapse:separate !important; border-spacing:0; width:100% !important; }
body.abc-v2 table.dataTable thead th {
    background:var(--surface-2); color:var(--ink-2); font-size:12px; font-weight:700;
    text-transform:uppercase; letter-spacing:.03em; border-bottom:2px solid var(--line-2) !important;
    padding:12px 14px; white-space:nowrap;
}
body.abc-v2 table.dataTable tbody td {
    padding:11px 14px; font-size:14.5px; color:var(--ink);
    border-top:1px solid var(--line) !important; vertical-align:middle;
}
body.abc-v2 table.dataTable tbody tr:nth-child(even) td { background:#FCFAF4; }
body.abc-v2 table.dataTable tbody tr:hover td { background:var(--accent-soft); }
body.abc-v2 td.num, body.abc-v2 th.num { text-align:right; font-variant-numeric:tabular-nums; }

/* ABC class badges */
body.abc-v2 .abc-badge {
    display:inline-block; min-width:26px; text-align:center;
    font-weight:800; font-size:13px; border-radius:999px; padding:2px 10px;
}
body.abc-v2 .abc-badge.A { background:#E4F1E8; color:var(--success); }
body.abc-v2 .abc-badge.B { background:var(--accent); color:var(--accent-text); }
body.abc-v2 .abc-badge.C { background:#F6DfD8; color:var(--cr); }
body.abc-v2 .abc-md { font-weight:800; color:var(--cr); font-variant-numeric:tabular-nums; }
body.abc-v2 .abc-strike { color:var(--ink-3); text-decoration:line-through; font-size:13px; }

/* Export buttons + search */
body.abc-v2 .dt-buttons .btn {
    background:var(--surface); border:1px solid var(--line-2); border-radius:var(--radius-sm);
    color:var(--ink); font-weight:600; padding:7px 14px; margin-right:6px;
}
body.abc-v2 .dt-buttons .btn:hover { background:var(--accent); border-color:var(--accent-deep); }
body.abc-v2 .dataTables_filter input {
    border:1px solid var(--line-2); border-radius:var(--radius-sm); height:38px; padding:0 12px;
}
body.abc-v2 .dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background:var(--accent) !important; border-color:var(--accent-deep) !important; color:var(--accent-text) !important;
    border-radius:var(--radius-sm);
}
</style>

<section class="content">
    <div class="abc-card">
        <h1 class="abc-h1">Markdown &amp; ABC Report</h1>
        <p class="abc-sub">Pick a store and genre, then set <strong>ABC Class = C</strong> to see the slow movers to clear. Only items with stock on hand are listed.</p>

        @if(!empty($imported_meta))
        <div class="abc-banner info">
            <strong>ABC source: imported.</strong>
            {{ $imported_meta['source_file'] ?? '' }}@if(!empty($imported_meta['period_label'])) · {{ $imported_meta['period_label'] }}@endif
            · {{ number_format($imported_meta['stats']['matched'] ?? 0) }}/{{ number_format($imported_meta['stats']['rows'] ?? 0) }} rows matched ·
            <a href="{{ url('/admin/abc-import') }}">Manage import</a>
        </div>
        @else
        <div class="abc-banner warn">
            <strong>ABC source: live (inventory value).</strong>
            No imported file active — classes are computed live from on-hand value.
            <a href="{{ url('/admin/abc-import') }}">Upload sales-based ABC</a>
        </div>
        @endif

        <div class="abc-banner howto">
            <strong>How to read it:</strong> <span class="abc-badge C">C</span> = slow movers → mark down. Suggested price is
            <strong>30% off</strong> the current sticker. <span class="abc-badge A">A</span> best sellers and
            <span class="abc-badge B">B</span> steady sellers keep full price.
        </div>
    </div>

    <div class="abc-card">
        <h3>Filters</h3>
        <div class="abc-filters">
            <div class="abc-field">
                <label for="abc_location_id">Store</label>
                {!! Form::select('abc_location_id', $business_locations, null, ['class' => 'form-control', 'id' => 'abc_location_id']) !!}
            </div>
            <div class="abc-field">
                <label for="abc_format">Genre / Format</label>
                {!! Form::select('abc_format', ['' => 'All Genres'] + $formats, null, ['class' => 'form-control', 'id' => 'abc_format']) !!}
            </div>
            <div class="abc-field">
                <label for="abc_class">ABC Class</label>
                {!! Form::select('abc_class', ['' => 'All', 'A' => 'A', 'B' => 'B', 'C' => 'C — markdown'], null, ['class' => 'form-control', 'id' => 'abc_class']) !!}
            </div>
        </div>
    </div>

    <div class="abc-card">
        <div class="table-responsive">
            <table class="table" id="abc_inventory_table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Genre / Format</th>
                        <th>Class</th>
                        <th class="num">Qty On Hand</th>
                        <th class="num">Current Price</th>
                        <th class="num">Markdown (30% off, C)</th>
                        <th class="num">Qty Sold</th>
                        <th class="num">Inventory Value</th>
                        <th class="num">Cumulative %</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
$(document).ready(function () {
    var table = $('#abc_inventory_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@abcInventoryClassification") }}',
            data: function (d) {
                d.location_id = $('#abc_location_id').val();
                d.format = $('#abc_format').val();
                d.class = $('#abc_class').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        order: [[8, 'desc']],
        columnDefs: [{ targets: [4, 5, 6, 7, 8, 9], className: 'num' }],
        columns: [
            { data: 'product', name: 'product' },
            { data: 'sku', name: 'sku' },
            { data: 'format', name: 'format' },
            {
                data: 'abc_class',
                name: 'abc_class',
                render: function (data) {
                    if (!data) { return ''; }
                    return '<span class="abc-badge ' + data + '">' + data + '</span>';
                }
            },
            { data: 'qty_on_hand', name: 'qty_on_hand' },
            {
                data: 'current_price',
                name: 'current_price',
                render: function (data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'markdown_price',
                name: 'markdown_price',
                render: function (data, type, row) {
                    if (data === null || data === undefined) { return ''; }
                    return '<span class="abc-strike">' + __currency_trans_from_en(row.current_price || 0, true) + '</span> '
                         + '<span class="abc-md">' + __currency_trans_from_en(data, true) + '</span>';
                }
            },
            { data: 'qty_sold', name: 'qty_sold' },
            {
                data: 'inventory_value',
                name: 'inventory_value',
                render: function (data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'cumulative_value_pct',
                name: 'cumulative_value_pct',
                render: function (data) { return (parseFloat(data || 0)).toFixed(2) + '%'; }
            }
        ]
    });

    $('#abc_location_id, #abc_format, #abc_class').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
