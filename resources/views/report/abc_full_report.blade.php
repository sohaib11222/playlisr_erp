@extends('layouts.app')
@section('title', 'Full ABC Report')

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
    --shadow-sm:0 1px 2px rgba(31,27,22,.06);
    font-family:"Inter Tight",system-ui,sans-serif; color:var(--ink);
    -webkit-font-smoothing:antialiased;
}
body.abc-v2 .content-wrapper { background:var(--bg) !important; }
body.abc-v2 section.content, body.abc-v2 .box, body.abc-v2 .form-control,
body.abc-v2 .btn, body.abc-v2 select, body.abc-v2 input, body.abc-v2 table {
    font-family:"Inter Tight",system-ui,sans-serif;
}

body.abc-v2 .abc-card {
    background:var(--surface); border:1px solid var(--line);
    border-radius:var(--radius); box-shadow:var(--shadow-sm);
    padding:18px 20px; margin-bottom:18px;
}
body.abc-v2 .abc-h1 { font-size:24px; font-weight:800; margin:0 0 4px; letter-spacing:-.01em; }
body.abc-v2 .abc-sub { color:var(--ink-2); font-size:15px; margin:0; line-height:1.5; }
body.abc-v2 .abc-meta { color:var(--ink-3); font-size:13px; margin-top:8px; }

body.abc-v2 .abc-filters { display:flex; flex-wrap:wrap; gap:14px; }
body.abc-v2 .abc-field { flex:1 1 200px; min-width:160px; }
body.abc-v2 .abc-field label {
    display:block; font-size:12px; font-weight:700; color:var(--ink-2);
    text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px;
}
body.abc-v2 .abc-field .form-control {
    height:44px; border:1px solid var(--line-2); border-radius:var(--radius-sm);
    background:var(--surface); color:var(--ink); font-size:16px; box-shadow:none;
}
body.abc-v2 .abc-field .form-control:focus { border-color:var(--accent-deep); box-shadow:0 0 0 3px rgba(232,207,104,.25); }

body.abc-v2 table.dataTable { border-collapse:separate !important; border-spacing:0; width:100% !important; }
body.abc-v2 table.dataTable thead th {
    background:var(--surface-2); color:var(--ink-2); font-size:12px; font-weight:700;
    text-transform:uppercase; letter-spacing:.03em; border-bottom:2px solid var(--line-2) !important;
    padding:12px 14px; white-space:nowrap;
}
body.abc-v2 table.dataTable tbody td {
    padding:12px 14px; font-size:15px; color:var(--ink);
    border-top:1px solid var(--line) !important; vertical-align:middle;
}
body.abc-v2 table.dataTable tbody tr:nth-child(even) td { background:#FCFAF4; }
body.abc-v2 table.dataTable tbody tr:hover td { background:var(--accent-soft); }

body.abc-v2 .class-tag {
    display:inline-block; font-weight:800; font-size:13px; border-radius:999px;
    padding:3px 12px; min-width:34px; text-align:center; border:1px solid transparent;
}
body.abc-v2 .class-A { background:#E5F0E8; border-color:var(--success); color:var(--success); }
body.abc-v2 .class-B { background:var(--accent-soft); border-color:var(--accent-deep); color:var(--accent-text); }
body.abc-v2 .class-C { background:#F6E3DF; border-color:var(--cr); color:var(--cr); }
body.abc-v2 .combo-tag {
    display:inline-block; font-weight:800; font-size:13px; border-radius:999px;
    padding:3px 12px; min-width:42px; text-align:center;
    background:#EDEAF6; border:1px solid #5B4B9A; color:#5B4B9A;
}
body.abc-v2 .yn-yes { color:var(--success); font-weight:700; }
body.abc-v2 .yn-no { color:var(--ink-3); }
body.abc-v2 .muted { color:var(--ink-3); }

body.abc-v2 .dt-buttons .btn {
    background:var(--surface); border:1px solid var(--line-2); border-radius:var(--radius-sm);
    color:var(--ink); font-weight:600; padding:8px 16px; margin-right:6px;
}
body.abc-v2 .dt-buttons .btn:hover { background:var(--accent); border-color:var(--accent-deep); }
body.abc-v2 .dataTables_filter input { border:1px solid var(--line-2); border-radius:var(--radius-sm); height:40px; padding:0 12px; }
body.abc-v2 .dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background:var(--accent) !important; border-color:var(--accent-deep) !important; color:var(--accent-text) !important; border-radius:var(--radius-sm);
}
</style>

<section class="content">
    <div class="abc-card">
        <h1 class="abc-h1">Full ABC Report — every row from the analyzer</h1>
        <p class="abc-sub">This is the complete classification list straight from the uploaded report, including <strong>Manual / no-SKU items that aren't in the ERP catalog</strong>. Use the <strong>Scope</strong> filter — pick <strong>Manual reorder picks</strong> to see no-SKU steady sellers (A/B + X) worth restocking.</p>
        @if(!empty($imported_meta))
            <p class="abc-meta">
                Source: {{ $imported_meta['source_file'] ?? '—' }}
                @if(!empty($imported_meta['period_label'])) · {{ $imported_meta['period_label'] }} @endif
                · uploaded {{ !empty($imported_meta['uploaded_at']) ? \Carbon\Carbon::parse($imported_meta['uploaded_at'])->format('M j, Y') : '—' }}
                @if(!empty($imported_meta['stats']['rows'])) · {{ number_format($imported_meta['stats']['rows']) }} rows @endif
            </p>
        @endif
    </div>

    @include('partials.abc_xyz_legend')

    @if(!$has_rows)
        <div class="abc-card">
            <p class="abc-sub">No report data yet. Import an ABC classification CSV from the <a href="{{ action('AbcImportController@index') }}">ABC Import</a> page first.</p>
        </div>
    @else
    <div class="abc-card">
        <div class="abc-filters">
            <div class="abc-field">
                <label for="full_search">Search</label>
                <input type="text" id="full_search" class="form-control" placeholder="Product, SKU, format…" autocomplete="off">
            </div>
            <div class="abc-field">
                <label for="full_location">Store</label>
                {!! Form::select('full_location', $business_locations, null, ['class' => 'form-control', 'id' => 'full_location']) !!}
            </div>
            <div class="abc-field">
                <label for="full_scope">Scope</label>
                {!! Form::select('full_scope', [
                    '' => 'All rows',
                    'reorder_manual' => 'Manual reorder picks (no-SKU, A/B + X)',
                    'manual' => 'Manual / no-SKU only',
                    'matched' => 'In ERP only',
                    'unmatched' => 'Not in ERP only',
                ], '', ['class' => 'form-control', 'id' => 'full_scope']) !!}
            </div>
            <div class="abc-field">
                <label for="full_class">ABC Class</label>
                {!! Form::select('full_class', ['' => 'All Classes', 'A' => 'A — best sellers', 'B' => 'B — steady', 'C' => 'C — slow movers'], '', ['class' => 'form-control', 'id' => 'full_class']) !!}
            </div>
            <div class="abc-field">
                <label for="full_xyz">XYZ</label>
                {!! Form::select('full_xyz', ['' => 'All XYZ', 'X' => 'X — steady', 'Y' => 'Y — variable', 'Z' => 'Z — sporadic'], '', ['class' => 'form-control', 'id' => 'full_xyz']) !!}
            </div>
            <div class="abc-field">
                <label for="full_combo">ABC-XYZ</label>
                {!! Form::select('full_combo', ['' => 'All'] + array_combine(
                    ['AX','AY','AZ','BX','BY','BZ','CX','CY','CZ'],
                    ['AX','AY','AZ','BX','BY','BZ','CX','CY','CZ']
                ), '', ['class' => 'form-control', 'id' => 'full_combo']) !!}
            </div>
        </div>
    </div>

    <div class="abc-card">
        <div class="table-responsive">
            <table class="table" id="abc_full_table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>ABC-XYZ</th>
                        <th>Class</th>
                        <th>XYZ</th>
                        <th>Product</th>
                        <th>Artist</th>
                        <th>SKU</th>
                        <th>Format</th>
                        <th>In ERP</th>
                        <th>Manual</th>
                        @foreach($months as $i => $m)
                        <th>{{ $month_labels[$i] }} $</th>
                        @endforeach
                        @foreach($months as $i => $m)
                        <th>{{ $month_labels[$i] }} Qty</th>
                        @endforeach
                        @if(!empty($months))
                        <th>Total $</th>
                        <th>Total Qty</th>
                        <th>Share %</th>
                        <th>Cum %</th>
                        <th>CV</th>
                        @endif
                    </tr>
                </thead>
            </table>
        </div>
    </div>
    @endif
</section>
@endsection

@section('javascript')
@if($has_rows)
<script type="text/javascript">
$(document).ready(function () {
    // Deep-link: /reports/abc-full-report?class=A pre-selects the ABC class
    // (the opening-checklist endcaps link uses ?class=A to show A-products).
    var qsClass = (new URLSearchParams(window.location.search)).get('class');
    if (qsClass) { $('#full_class').val(qsClass.toUpperCase()); }

    // Monthly $ /qty /totals columns only exist on auto-calc data (empty
    // array for CSV imports) — the header row above already matches this.
    var months = @json($months);
    var money = function (v) { return v === null || v === undefined || v === '' ? '<span class="muted">—</span>' : '$' + Number(v).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    var extraColumns = [];
    months.forEach(function (m) {
        extraColumns.push({ data: 'monthly_revenue.' + m, defaultContent: '', render: function (data) { return money(data); } });
    });
    months.forEach(function (m) {
        extraColumns.push({ data: 'monthly_qty.' + m, defaultContent: '', render: function (data) { return (data === null || data === undefined || data === '') ? '<span class="muted">—</span>' : data; } });
    });
    if (months.length) {
        extraColumns.push({ data: 'total_revenue', defaultContent: '', render: function (data) { return money(data); } });
        extraColumns.push({ data: 'total_qty', defaultContent: '' });
        extraColumns.push({ data: 'share_pct', defaultContent: '', render: function (data) { return (data === null || data === undefined || data === '') ? '—' : data + '%'; } });
        extraColumns.push({ data: 'cum_pct', defaultContent: '', render: function (data) { return (data === null || data === undefined || data === '') ? '—' : data + '%'; } });
        extraColumns.push({ data: 'cv', defaultContent: '', render: function (data) { return (data === null || data === undefined || data === '') ? '<span class="muted">—</span>' : data; } });
    }

    var table = $('#abc_full_table').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 50,
        ajax: {
            url: '{{ action("ReportController@abcFullReport") }}',
            data: function (d) {
                d.scope = $('#full_scope').val();
                d.class = $('#full_class').val();
                d.xyz = $('#full_xyz').val();
                d.abc_xyz = $('#full_combo').val();
                d.location_id = $('#full_location').val();
            }
        },
        dom: 'Blrtip',
        lengthMenu: [[25, 50, 100, 500, -1], [25, 50, 100, 500, 'All']],
        buttons: [
            {
                // Full export: hits the server so it returns EVERY filtered row,
                // not just the page the table currently shows. Carries the live
                // scope/class/xyz/combo filters + search box.
                text: '<i class="fa fa-download"></i> Export all (CSV)',
                className: 'btn',
                action: function () {
                    var params = new URLSearchParams({
                        export: 'csv',
                        scope: $('#full_scope').val() || '',
                        'class': $('#full_class').val() || '',
                        xyz: $('#full_xyz').val() || '',
                        abc_xyz: $('#full_combo').val() || '',
                        location_id: $('#full_location').val() || '',
                        search_term: $('#full_search').val() || ''
                    });
                    window.location = '{{ action("ReportController@abcFullReport") }}?' + params.toString();
                }
            },
            'print'
        ],
        ordering: false,
        columns: [
            { data: 'abc_xyz', name: 'abc_xyz', render: function (data) { return data ? '<span class="combo-tag">' + data + '</span>' : '<span class="muted">—</span>'; } },
            { data: 'class', name: 'class', render: function (data) { return data ? '<span class="class-tag class-' + data + '">' + data + '</span>' : '<span class="muted">—</span>'; } },
            { data: 'xyz', name: 'xyz', render: function (data) { return data || '<span class="muted">—</span>'; } },
            { data: 'product', name: 'product' },
            { data: 'artist', name: 'artist', render: function (data) { return data ? data : '<span class="muted">—</span>'; } },
            { data: 'sku', name: 'sku', render: function (data) { return data ? data : '<span class="muted">—</span>'; } },
            { data: 'format', name: 'format', render: function (data) { return data || '<span class="muted">—</span>'; } },
            { data: 'in_erp', name: 'in_erp', render: function (data) { return parseInt(data || 0, 10) ? '<span class="yn-yes">Yes</span>' : '<span class="yn-no">No</span>'; } },
            { data: 'manual', name: 'manual', render: function (data) { return parseInt(data || 0, 10) ? '<span class="yn-yes">Yes</span>' : '<span class="yn-no">No</span>'; } }
        ].concat(extraColumns)
    });

    $('#full_scope, #full_class, #full_xyz, #full_combo, #full_location').change(function () {
        table.ajax.reload();
    });

    var searchTimer = null;
    $('#full_search').on('keyup', function () {
        var term = this.value;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { table.search(term).draw(); }, 350);
    });
});
</script>
@endif
@endsection
