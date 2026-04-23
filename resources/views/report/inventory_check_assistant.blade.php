@extends('layouts.app')
@section('title', 'Order for this Week')

@section('content')
<section class="content-header">
    <h1>Order for this Week <small class="text-muted">— Inventory Check Assistant</small></h1>
    <p class="text-muted">One scroll. Seven buckets. Export for AMS.</p>
</section>

<section class="content">

    @if(!empty($migrationsMissing))
    <div class="alert alert-warning">
        <strong>Database migration required.</strong> The chart-import tables don't exist yet on this server. SSH in and run
        <code>php artisan migrate</code>, then refresh this page. Fast-moving OOS + Events + Long OOS buckets work now; Street Pulse / Universal / New releases sections stay empty until the migration runs.
    </div>
    @endif

    {{-- ── Top filter bar ─────────────────────────────────────────── --}}
    <div class="row no-print">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ica_preset', 'Preset') !!}
                    {!! Form::select('ica_preset', $presetOptions, 'hollywood_all', ['class' => 'form-control select2', 'id' => 'ica_preset', 'style' => 'width:100%']); !!}
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
                    {!! Form::label('ica_category_id', __('category.category') . ' (filter):') !!}
                    {!! Form::select('ica_category_id', $categories, null, ['class' => 'form-control select2', 'id' => 'ica_category_id', 'style' => 'width:100%', 'placeholder' => __('messages.all')]); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>&nbsp;</label><br>
                    <button type="button" class="btn btn-primary btn-lg" id="ica_apply">
                        <i class="fa fa-magic"></i> Build order list
                    </button>
                </div>
            </div>
            @endcomponent
        </div>
    </div>

    {{-- ── Chart freshness banner ─────────────────────────────────── --}}
    <div class="row no-print" id="ica_freshness_banner">
        <div class="col-md-6">
            @component('components.widget', ['class' => 'box-solid', 'title' => '📬 Street Pulse chart'])
            <p class="text-muted small" id="ica_sp_freshness">Loading…</p>
            <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ica_sp_modal">
                <i class="fa fa-paste"></i> Paste this week's chart
            </button>
            @endcomponent
        </div>
        <div class="col-md-6">
            @component('components.widget', ['class' => 'box-solid', 'title' => '🌍 Universal Top chart'])
            <p class="text-muted small" id="ica_ut_freshness">Loading…</p>
            <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ica_ut_modal">
                <i class="fa fa-paste"></i> Paste this week's chart
            </button>
            @endcomponent
        </div>
    </div>

    {{-- ── Pull from inbox (auto-fetch runner) ───────────────────── --}}
    <div class="row no-print">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-info', 'title' => '📥 Auto-fetch from inbox'])
            <p class="text-muted small">Pulls last 7 days of Street Pulse + UMe Universal emails from sarah@nivessa.com via IMAP → parses attachments &amp; body → populates the two charts above. Runs every Wednesday 08:15 PST automatically; button below triggers it on demand.</p>
            <button type="button" class="btn btn-primary btn-sm" id="ica_run_import" data-dry-run="1">
                <i class="fa fa-bolt"></i> Run test (dry-run)
            </button>
            <button type="button" class="btn btn-success btn-sm" id="ica_run_import_real" data-dry-run="0">
                <i class="fa fa-download"></i> Run for real
            </button>
            <button type="button" class="btn btn-info btn-sm" id="ica_run_apple" style="margin-left:12px;">
                🍎 Run Apple Music pull now
            </button>
            <pre id="ica_run_import_output" style="display:none; margin-top:12px; max-height:300px; overflow:auto; font-size:11px; background:#f9f9f9; padding:8px;"></pre>
            @endcomponent
        </div>
    </div>

    {{-- ── Export strip (sticky) ──────────────────────────────────── --}}
    <div class="row no-print">
        <div class="col-md-12">
            <div class="ica-export-strip" id="ica_export_strip" style="display:none;">
                <span class="ica-summary" id="ica_summary">—</span>
                <div class="pull-right">
                    <button type="button" class="btn btn-success" id="ica_export_csv">
                        <i class="fa fa-download"></i> Export for AMS
                    </button>
                    <button type="button" class="btn btn-info" id="ica_copy_cart">
                        <i class="fa fa-clipboard"></i> Copy for cart
                    </button>
                    <button type="button" class="btn btn-default" id="ica_print">
                        <i class="fa fa-print"></i> Print
                    </button>
                </div>
                <div class="clearfix"></div>
            </div>
        </div>
    </div>

    {{-- ── Buckets render target ─────────────────────────────────── --}}
    <div class="row">
        <div class="col-md-12" id="ica_buckets_root">
            <div class="text-center text-muted" style="padding: 40px 0;">
                <i class="fa fa-arrow-up fa-2x"></i>
                <p style="margin-top: 12px;">Pick a preset + location → click <strong>Build order list</strong>.</p>
            </div>
        </div>
    </div>

    {{-- ── Saved sessions (unchanged, tucked at bottom) ─────────── --}}
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-default', 'title' => 'Saved sessions'])
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Session name</label>
                        <input type="text" class="form-control" id="ica_session_name" placeholder="e.g. Hollywood — week of …">
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
                    <button type="button" class="btn btn-primary btn-sm" id="ica_session_save">Save</button>
                    <button type="button" class="btn btn-default btn-sm" id="ica_session_load">Load</button>
                    <button type="button" class="btn btn-danger btn-sm" id="ica_session_delete">Delete</button>
                </div>
            </div>
            @endcomponent
        </div>
    </div>
</section>

{{-- ── Street Pulse paste modal ──────────────────────────────────── --}}
<div class="modal fade" id="ica_sp_modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Paste Street Pulse chart</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Forward the weekly email, open it, copy the chart body, paste below. Supports huge lists. Re-pasting replaces that week's entries.</p>
                <div class="form-group">
                    <label>Week of</label>
                    <input type="date" class="form-control" id="ica_sp_week" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label>Chart body</label>
                    <textarea class="form-control" id="ica_sp_body" rows="14" placeholder="1. Artist — Title — Format&#10;2. Artist — Title — Format&#10;…"></textarea>
                </div>
                <p class="text-muted small">New releases: mark a line with <code>*NEW*</code>, <code>(NEW)</code>, or <code>★</code>.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="ica_sp_import">
                    <i class="fa fa-upload"></i> Import
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ── Universal Top paste modal ────────────────────────────────── --}}
<div class="modal fade" id="ica_ut_modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Paste Universal Top chart</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Weekly Universal Music email to sarah@nivessa.com — paste the chart body here.</p>
                <div class="form-group">
                    <label>Week of</label>
                    <input type="date" class="form-control" id="ica_ut_week" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label>Chart body</label>
                    <textarea class="form-control" id="ica_ut_body" rows="14"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="ica_ut_import">
                    <i class="fa fa-upload"></i> Import
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.ica-export-strip {
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px 15px;
    margin-bottom: 15px;
    position: sticky;
    top: 0;
    z-index: 20;
}
.ica-summary { font-weight: bold; font-size: 15px; line-height: 34px; }
.ica-bucket { margin-bottom: 24px; }
.ica-bucket-header {
    background: #fff;
    border-left: 4px solid #3c8dbc;
    padding: 10px 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.ica-bucket-header h3 { margin: 0; font-size: 18px; }
.ica-bucket-header .ica-why { color: #888; font-size: 12px; display: block; margin-top: 2px; }
.ica-bucket-count {
    background: #3c8dbc;
    color: white;
    padding: 2px 10px;
    border-radius: 10px;
    font-weight: bold;
    font-size: 13px;
}
.ica-bucket-count.zero { background: #ccc; }
.ica-bucket.ica-collapsed .ica-bucket-body { display: none; }
.ica-bucket-empty { padding: 20px; text-align: center; color: #999; font-style: italic; }
.ica-tag {
    display: inline-block;
    background: #eee;
    color: #333;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 11px;
    margin-right: 3px;
}
.ica-tag.top_artist { background: #fff3cd; color: #856404; }
.ica-tag.new_release { background: #d4edda; color: #155724; }
.ica-tag.priority_high { background: #f8d7da; color: #721c24; }
.ica-row-table { margin-bottom: 0; }
.ica-row-table td { vertical-align: middle !important; }
.ica-qty-input { width: 60px; }
@media print {
    .no-print, .main-header, .main-sidebar, .content-header p, .ica-bucket-header button { display: none !important; }
    .content-wrapper, .content { margin: 0 !important; padding: 0 !important; }
    .ica-export-strip { display: none !important; }
}
</style>
@endsection

@section('javascript')
<script type="text/javascript">
    window.ICA_PRESET_META = @json($presetMeta ?? []);
    window.ICA_CHART_FRESHNESS = @json($chartFreshness ?? []);
    window.ICA_COPY_FORMAT = @json($copyFormat);
    window.ICA_BUCKETS_URL = "{{ action('InventoryCheckController@buckets') }}";
    window.ICA_EXPORT_URL = "{{ action('InventoryCheckController@export') }}";
    window.ICA_CHART_IMPORT_URL = "{{ url('reports/inventory-check-assistant/chart-import') }}";
    window.ICA_CHART_LATEST_URL = "{{ url('reports/inventory-check-assistant/chart-latest') }}";
    window.ICA_CUSTOMER_WANT_FULFILL_URL = "{{ url('reports/inventory-check-assistant/customer-want') }}";
    window.ICA_RUN_EMAIL_IMPORT_URL = "{{ url('reports/inventory-check-assistant/run-email-import') }}";
    window.ICA_RUN_APPLE_URL = "{{ url('reports/inventory-check-assistant/run-apple-music') }}";
    window.ICA_SESSIONS_URL = "{{ action('InventoryCheckController@listSessions') }}";
    window.ICA_SESSIONS_STORE = "{{ action('InventoryCheckController@storeSession') }}";
    window.ICA_CSRF = "{{ csrf_token() }}";
</script>
<script src="{{ asset('js/inventory_check_assistant.js?v=' . $asset_v) }}"></script>
@endsection
