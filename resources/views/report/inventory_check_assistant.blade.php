@extends('layouts.app')
@section('title', 'Inventory Check Assistant')

@section('content')
<section class="content-header">
    <h1>Inventory Check Assistant</h1>
    <p class="text-muted">Unified reorder candidates, notes, and AMS-oriented export.</p>
</section>

<section class="content">
    <div class="row no-print">
        <div class="col-md-12">
            <ol class="breadcrumb ica-steps">
                <li class="active"><span class="label label-primary">1</span> Filters</li>
                <li><span class="label label-default">2</span> Review</li>
                <li><span class="label label-default">3</span> Verify</li>
                <li><span class="label label-default">4</span> Export</li>
            </ol>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_preset', 'Preset') !!}
                    {!! Form::select('ica_preset', $presetOptions, null, ['class' => 'form-control select2', 'id' => 'ica_preset', 'style' => 'width:100%']); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_location_id', __('purchase.business_location') . ':') !!}
                    {!! Form::select('ica_location_id', $business_locations, null, ['class' => 'form-control select2', 'id' => 'ica_location_id', 'style' => 'width:100%', 'placeholder' => __('messages.please_select')]); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_category_id', __('category.category') . ':') !!}
                    {!! Form::select('ica_category_id', $categories, null, ['class' => 'form-control select2', 'id' => 'ica_category_id', 'style' => 'width:100%', 'placeholder' => __('messages.all')]); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_supplier_id', __('purchase.supplier') . ':') !!}
                    {!! Form::select('ica_supplier_id', $suppliers, null, ['class' => 'form-control select2', 'id' => 'ica_supplier_id', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_sale_start', __('lang_v1.sell_date') . ' (' . __('lang_v1.from') . ')') !!}
                    {!! Form::date('ica_sale_start', \Carbon\Carbon::now()->subDays(90)->format('Y-m-d'), ['class' => 'form-control', 'id' => 'ica_sale_start']); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_sale_end', __('lang_v1.to')) !!}
                    {!! Form::date('ica_sale_end', \Carbon\Carbon::now()->format('Y-m-d'), ['class' => 'form-control', 'id' => 'ica_sale_end']); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>&nbsp;</label><br>
                    <button type="button" class="btn btn-primary" id="ica_apply">
                        <i class="fa fa-search"></i> Load candidates
                    </button>
                </div>
            </div>
            @endcomponent
        </div>
    </div>

    <div class="row no-print">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Order candidates'])
            <div class="btn-group margin-bottom" id="ica_tab_filters">
                <button type="button" class="btn btn-default active" data-filter="all">All</button>
                <button type="button" class="btn btn-default" data-filter="most_sold">Most sold</button>
                <button type="button" class="btn btn-default" data-filter="fast_seller">Fast sellers</button>
                <button type="button" class="btn btn-default" data-filter="empty_tab">Empty / low</button>
            </div>
            <p class="help-block" id="ica_meta_line"></p>
            <div class="table-responsive" id="print_area">
                <table class="table table-bordered table-striped table-condensed" id="ica_table">
                    <thead>
                        <tr>
                            <th class="no-print"><input type="checkbox" id="ica_select_all" title="Select visible"></th>
                            <th class="no-print">Verified</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Artist</th>
                            <th>Format</th>
                            <th>Location</th>
                            <th>Stock</th>
                            <th>Sold (window)</th>
                            <th>Avg sell days</th>
                            <th>Tags</th>
                            <th>Suggested qty</th>
                        </tr>
                    </thead>
                    <tbody id="ica_tbody">
                        <tr><td colspan="12" class="text-center text-muted">Load filters and click &quot;Load candidates&quot;.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="margin-top">
                <button type="button" class="btn btn-success" id="ica_export_csv"><i class="fa fa-download"></i> Export CSV (AMS-oriented)</button>
                <button type="button" class="btn btn-info" id="ica_copy_cart"><i class="fa fa-clipboard"></i> Copy for cart</button>
                <button type="button" class="btn btn-default" id="ica_print"><i class="fa fa-print"></i> Print list</button>
            </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            @component('components.widget', ['class' => 'box-solid', 'title' => 'Street Pulse notes'])
            <div class="form-group">
                {!! Form::label('ica_sp_body', 'Weekly picks / notes') !!}
                {!! Form::textarea('ica_sp_body', null, ['class' => 'form-control', 'id' => 'ica_sp_body', 'rows' => 3, 'placeholder' => 'Paste Street Pulse picks or summary…']); !!}
            </div>
            <div class="form-group">
                {!! Form::label('ica_sp_ref', 'Reference date') !!}
                {!! Form::date('ica_sp_ref', \Carbon\Carbon::now()->format('Y-m-d'), ['class' => 'form-control', 'id' => 'ica_sp_ref']); !!}
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="ica_sp_save">Save note</button>
            <hr>
            <div id="ica_notes_street" class="ica-note-list small"></div>
            @endcomponent
        </div>
        <div class="col-md-6">
            @component('components.widget', ['class' => 'box-solid', 'title' => 'Customer requests (Whatnot / floor)'])
            <div class="form-group">
                {!! Form::label('ica_cr_body', 'Request') !!}
                {!! Form::textarea('ica_cr_body', null, ['class' => 'form-control', 'id' => 'ica_cr_body', 'rows' => 3, 'placeholder' => 'Customer request or SKU to find…']); !!}
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="ica_cr_save">Save request</button>
            <hr>
            <div id="ica_notes_customer" class="ica-note-list small"></div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-default', 'title' => 'Saved sessions'])
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Session name</label>
                        <input type="text" class="form-control" id="ica_session_name" placeholder="e.g. Hollywood sealed — week of …">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Load session</label>
                        <select class="form-control" id="ica_session_select">
                            <option value="">—</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <label>&nbsp;</label><br>
                    <button type="button" class="btn btn-primary" id="ica_session_save">Save session</button>
                    <button type="button" class="btn btn-default" id="ica_session_load">Load</button>
                    <button type="button" class="btn btn-danger" id="ica_session_delete">Delete</button>
                </div>
            </div>
            <p class="text-muted small">Sessions store filters, verification checkboxes, and preset for audit / resume.</p>
            @endcomponent
        </div>
    </div>
</section>

<style>
@media print {
    .no-print, .main-header, .main-sidebar, .content-header .breadcrumb, .ica-steps { display: none !important; }
    .content-wrapper, .content { margin: 0 !important; padding: 0 !important; }
}
</style>
@endsection

@section('javascript')
<script type="text/javascript">
    window.ICA_PRESET_META = @json($presetMeta ?? []);
    window.ICA_COPY_FORMAT = @json($copyFormat);
    window.ICA_DATA_URL = "{{ action('InventoryCheckController@data') }}";
    window.ICA_EXPORT_URL = "{{ action('InventoryCheckController@export') }}";
    window.ICA_NOTES_URL = "{{ action('InventoryCheckController@listNotes') }}";
    window.ICA_NOTES_STORE = "{{ action('InventoryCheckController@storeNote') }}";
    window.ICA_SESSIONS_URL = "{{ action('InventoryCheckController@listSessions') }}";
    window.ICA_SESSIONS_STORE = "{{ action('InventoryCheckController@storeSession') }}";
    window.ICA_CSRF = "{{ csrf_token() }}";
</script>
<script src="{{ asset('js/inventory_check_assistant.js?v=' . $asset_v) }}"></script>
@endsection
