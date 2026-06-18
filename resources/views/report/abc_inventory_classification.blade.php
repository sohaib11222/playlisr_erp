@extends('layouts.app')
@section('title', 'Markdown List')

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

body.abc-v2 .abc-filters { display:flex; flex-wrap:wrap; gap:14px; }
body.abc-v2 .abc-field { flex:1 1 220px; min-width:180px; }
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
body.abc-v2 td.num, body.abc-v2 th.num { text-align:right; font-variant-numeric:tabular-nums; }

body.abc-v2 .genre-tag {
    display:inline-block; background:var(--accent-soft); border:1px solid var(--accent-deep);
    color:var(--accent-text); font-weight:700; font-size:13px; border-radius:999px; padding:3px 12px;
}
body.abc-v2 .was { color:var(--ink-3); text-decoration:line-through; font-size:13px; }
body.abc-v2 .now { font-weight:800; color:var(--cr); font-variant-numeric:tabular-nums; font-size:16px; }

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
        <h1 class="abc-h1">Markdown List — clear the slow movers</h1>
        <p class="abc-sub">Every product below is a slow mover that should be marked down <strong>30% off</strong> to move it out faster. Sorted by genre. Pick a store or genre to narrow the list.</p>
    </div>

    <div class="abc-card">
        <div class="abc-filters">
            <div class="abc-field">
                <label for="abc_location_id">Store</label>
                {!! Form::select('abc_location_id', $business_locations, null, ['class' => 'form-control', 'id' => 'abc_location_id']) !!}
            </div>
            <div class="abc-field">
                <label for="abc_format">Genre</label>
                {!! Form::select('abc_format', ['' => 'All Genres'] + $formats, null, ['class' => 'form-control', 'id' => 'abc_format']) !!}
            </div>
        </div>
    </div>

    <div class="abc-card">
        <div class="table-responsive">
            <table class="table" id="abc_markdown_table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Genre</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="num">In Stock</th>
                        <th class="num">Current Price</th>
                        <th class="num">Markdown Price (30% off)</th>
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
    var table = $('#abc_markdown_table').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 50,
        ajax: {
            url: '{{ action("ReportController@abcInventoryClassification") }}',
            data: function (d) {
                d.location_id = $('#abc_location_id').val();
                d.format = $('#abc_format').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        ordering: false,
        columnDefs: [{ targets: [3, 4, 5], className: 'num' }],
        columns: [
            { data: 'format', name: 'format', render: function (data) { return '<span class="genre-tag">' + data + '</span>'; } },
            { data: 'product', name: 'product' },
            { data: 'sku', name: 'sku' },
            { data: 'qty_on_hand', name: 'qty_on_hand', render: function (data) { return parseInt(data || 0, 10); } },
            { data: 'current_price', name: 'current_price', render: function (data) { return '<span class="was">' + __currency_trans_from_en(data || 0, true) + '</span>'; } },
            { data: 'markdown_price', name: 'markdown_price', render: function (data) { return '<span class="now">' + __currency_trans_from_en(data || 0, true) + '</span>'; } }
        ]
    });

    $('#abc_location_id, #abc_format').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
