@extends('layouts.app')
@section('title', 'Order for this Week')

@section('content')
{{-- 2026-05-20: visual reskin to match /pos/create — Sarah asked
     "make ui like pos create please". Same body-scoped class trick
     (.ica-v2) + static CSS + Inter Tight font as the POS v2 redesign. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="{{ asset('css/ica-create-layout.css?v=' . $asset_v) }}">
<script>document.body.classList.add('ica-v2');</script>
<section class="content-header">
    <h1>Order for this Week</h1>
    <p class="text-muted ica-lead">
        <strong>1.</strong> Pick a store below. <strong>2.</strong> Review the Fast sellers list (Jon's focus — items that sold &lt;90 days and are out of stock). <strong>3.</strong> Export.
        Everything else (charts, events, ABC, frozen, customer wants) lives behind the “Show all the other reorder lists” toggle once the list builds.
    </p>
</section>

<section class="content">

    @if(!empty($migrationsMissing))
    <div class="alert alert-warning">
        <strong>Database migration required.</strong> The chart-import tables don't exist yet on this server. SSH in and run
        <code>php artisan migrate</code>, then refresh this page. Fast-moving OOS + Events + Long OOS buckets work now; Street Pulse / Universal / New releases sections stay empty until the migration runs.
    </div>
    @endif

    {{-- ── Purchasing budget banner ──────────────────────────────────── --}}
    @if(!empty($purchaseBudget))
    @php
        $pb = $purchaseBudget;
        $barClass = $pb['over_budget'] ? 'progress-bar-danger' : ($pb['pct_spent'] >= 80 ? 'progress-bar-warning' : 'progress-bar-success');
        $remainColor = $pb['over_budget'] ? '#a94442' : ($pb['remaining'] < 1000 ? '#8a6d3b' : '#2c699a');
    @endphp
    <div class="ica-budget-banner">
        <div class="ica-budget-head">
            <span class="ica-budget-title">Purchasing budget — week {{ $pb['week_no'] }} of 13 <small class="text-muted">({{ \Carbon\Carbon::parse($pb['start'])->format('M j') }} – {{ \Carbon\Carbon::parse($pb['end'])->format('M j') }})</small></span>
            <span class="ica-budget-figures">
                <span class="ica-budget-spent">Spent <strong>${{ number_format($pb['spent'], 0) }}</strong></span>
                <span class="ica-budget-sep">·</span>
                <span>Budget <strong>${{ number_format($pb['budget'], 0) }}</strong></span>
                <span class="ica-budget-sep">·</span>
                <span style="color: {{ $remainColor }};">Remaining <strong>${{ number_format($pb['remaining'], 0) }}</strong></span>
            </span>
        </div>
        <div class="progress ica-budget-bar">
            <div class="progress-bar {{ $barClass }}" role="progressbar" style="width: {{ $pb['pct_spent'] }}%;">
                {{ $pb['pct_spent'] }}%
            </div>
        </div>
        @if($pb['over_budget'])
        <div class="ica-budget-warn">⚠️ Over budget this week — confirm with Jon before placing more orders.</div>
        @endif
    </div>
    @endif

    {{-- ── Pick a store (one click → builds) ──────────────────────── --}}
    <div class="row no-print">
        <div class="col-md-12">
            <div class="ica-store-picker">
                <span class="ica-store-picker-label">What store?</span>
                <button type="button" class="btn btn-lg btn-default ica-store-btn" data-preset="hollywood_all">
                    Hollywood
                </button>
                <button type="button" class="btn btn-lg btn-default ica-store-btn" data-preset="pico_all">
                    Pico
                </button>
                <span class="ica-filter-group">
                    <label class="ica-filter-label">Category</label>
                    <select id="ica_filter_category" class="ica-filter-select">
                        <option value="">All</option>
                    </select>
                </span>
                <span class="ica-filter-group">
                    <label class="ica-filter-label">Genre</label>
                    <select id="ica_filter_genre" class="ica-filter-select">
                        <option value="">All</option>
                    </select>
                </span>
                <span class="ica-filter-group">
                    <label class="ica-filter-label" title="ABC class: A = top 80% of inventory value, B = next 15%, C = bottom 5%">ABC</label>
                    <select id="ica_filter_abc" class="ica-filter-select">
                        <option value="">All</option>
                        <option value="A">A only</option>
                        <option value="B">B only</option>
                        <option value="C">C only</option>
                    </select>
                </span>
                <label class="ica-filter-check" title="Hide Record Store Day exclusives (titles with 'RSD' or 'Record Store Day' in the name)">
                    <input type="checkbox" id="ica_filter_hide_rsd"> Hide RSD titles
                </label>
                <a class="btn btn-link btn-sm pull-right" data-toggle="collapse" href="#ica_advanced_filters" role="button">
                    vinyl/CDs only? ▾
                </a>
            </div>

            <div class="collapse" id="ica_advanced_filters">
                @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ica_preset', 'Preset (template)') !!}
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
    </div>

    {{-- ── More options (chart imports + inbox pull, collapsed by default) ── --}}
    <details class="ica-more-options no-print">
        <summary>More options — chart imports, inbox auto-fetch, saved sessions</summary>
        <div class="row" id="ica_freshness_banner" style="margin-top:8px;">
            <div class="col-md-6">
                @component('components.widget', ['class' => 'box-solid', 'title' => 'Street Pulse / Luminate chart'])
                <p class="text-muted small" id="ica_sp_freshness">Not yet imported.</p>
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ica_sp_modal">
                    <i class="fa fa-upload"></i> Upload this week's chart
                </button>
                @endcomponent
            </div>
            <div class="col-md-6">
                @component('components.widget', ['class' => 'box-solid', 'title' => 'UMe / Universal chart + anniversaries'])
                <p class="text-muted small" id="ica_ut_freshness">Not yet imported.</p>
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#ica_ut_modal">
                    <i class="fa fa-upload"></i> Upload this week's chart
                </button>
                <p class="text-muted small" style="margin-top:6px;">Drag the "UMe Back-in-Stock + Active LPs and CDs" xlsx. The Top 200 + this-week deliveries feed the Universal Top bucket; the "Key Anniversaries + Birthdays" tab (Michael Jackson biopic, Drake tour, etc.) feeds the Upcoming Events bucket.</p>
                @endcomponent
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                @component('components.widget', ['class' => 'box-warning', 'title' => 'Manager picks — stock-up suggestions'])
                <p class="text-muted small">Lashyn or any manager can flag a category to stock up on. The Manager picks bucket surfaces low-stock candidates matching it.</p>
                <div id="ica_mgrpicks_list" class="ica-mgrpicks-list">
                    <p class="text-muted small">Loading current picks…</p>
                </div>
                <hr style="margin: 12px 0;">
                <div class="ica-mgrpicks-add">
                    <div class="row">
                        <div class="col-md-5">
                            <label class="small">Suggestion <small class="text-muted">(e.g. “get more sealed electronic”)</small></label>
                            <input type="text" class="form-control input-sm" id="ica_mgrpick_note" maxlength="500" placeholder="get more …">
                        </div>
                        <div class="col-md-3">
                            <label class="small">Category match <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control input-sm" id="ica_mgrpick_category" maxlength="191" placeholder="e.g. Sealed Electronic">
                        </div>
                        <div class="col-md-2">
                            <label class="small">Suggested by</label>
                            <input type="text" class="form-control input-sm" id="ica_mgrpick_by" maxlength="64" placeholder="Lashyn">
                        </div>
                        <div class="col-md-2">
                            <label class="small">&nbsp;</label><br>
                            <button type="button" class="btn btn-primary btn-sm" id="ica_mgrpick_add">
                                <i class="fa fa-plus"></i> Add pick
                            </button>
                        </div>
                    </div>
                </div>
                @endcomponent
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                @component('components.widget', ['class' => 'box-info', 'title' => 'Auto-fetch from inbox'])
                <p class="text-muted small">Auto-pulls Street Pulse + UMe emails from sarah@nivessa.com every Wednesday 08:15 PST. Trigger manually below.</p>
                <button type="button" class="btn btn-primary btn-sm" id="ica_run_import" data-dry-run="1">
                    <i class="fa fa-bolt"></i> Run test (dry-run)
                </button>
                <button type="button" class="btn btn-success btn-sm" id="ica_run_import_real" data-dry-run="0">
                    <i class="fa fa-download"></i> Run for real
                </button>
                <button type="button" class="btn btn-info btn-sm" id="ica_run_apple" style="margin-left:12px;">
                    Run Apple Music pull now
                </button>
                <pre id="ica_run_import_output" style="display:none; margin-top:12px; max-height:300px; overflow:auto; font-size:11px; background:#f9f9f9; padding:8px;"></pre>
                @endcomponent
            </div>
        </div>
    </details>

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

    {{-- Saved sessions removed 2026-05-20 — Sarah didn't recognize the
         feature, never used. Backend routes + controller still exist so
         no migration needed; just dropped from the UI. --}}
</section>

{{-- ── Street Pulse import modal (file or paste) ─────────────────── --}}
<div class="modal fade" id="ica_sp_modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Import Street Pulse / Luminate chart</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Upload the weekly chart file (.xlsx or .csv from Luminate) <strong>or</strong> paste the rows below. Re-importing replaces that week's entries.</p>
                <div class="form-group">
                    <label>Week of</label>
                    <input type="date" class="form-control" id="ica_sp_week" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label>Chart file <small class="text-muted">(.xlsx / .csv / .png / .jpg — pick multiple PNGs at once)</small></label>
                    <input type="file" class="form-control" id="ica_sp_file" accept=".xlsx,.xls,.csv,.tsv,.txt,.png,.jpg,.jpeg,.webp" multiple>
                    <p class="help-block small">
                        <strong>If you only have email screenshots (Luminate PNG),</strong> select all of them at once (Cmd-click or Shift-click in the file picker) — we'll OCR each one in your browser and append the rows to the paste box. ~30s per image.
                    </p>
                    <div id="ica_sp_ocr_status" class="text-muted small" style="display:none; margin-top:6px;"></div>
                </div>
                <div class="form-group">
                    <label>…or paste chart body</label>
                    <textarea class="form-control" id="ica_sp_body" rows="10" placeholder="1. Artist — Title — Format&#10;2. Artist — Title — Format&#10;…"></textarea>
                </div>
                <p class="text-muted small">New releases: mark with <code>*NEW*</code>, <code>(NEW)</code>, or <code>★</code>; for files we auto-flag if release date is within 60 days.</p>
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

{{-- ── Universal Top import modal (file or paste) ────────────────── --}}
<div class="modal fade" id="ica_ut_modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Import UMe / Universal chart</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Drop the weekly UMe xlsx attachment ("UMe Back-in-Stock + Active LPs and CDs"). The Top 200 + this-week deliveries tabs are pulled automatically. Paste fallback below.</p>
                <div class="form-group">
                    <label>Week of</label>
                    <input type="date" class="form-control" id="ica_ut_week" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label>UMe xlsx <small class="text-muted">(preferred)</small></label>
                    <input type="file" class="form-control" id="ica_ut_file" accept=".xlsx,.xls,.csv,.tsv,.txt">
                </div>
                <div class="form-group">
                    <label>…or paste chart body</label>
                    <textarea class="form-control" id="ica_ut_body" rows="10"></textarea>
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
.ica-help-toggle { margin-bottom: 6px; }
.ica-help-toggle a { font-size: 13px; font-weight: 500; color: #2c699a; text-decoration: none; }
.ica-help-toggle a:hover { text-decoration: underline; }
.ica-help-panel {
    background: #fffbe6;
    border: 1px solid #ffeaa7;
    border-radius: 4px;
    padding: 14px 18px;
    margin-bottom: 14px;
    font-size: 13px;
    line-height: 1.55;
}
.ica-help-panel h4 { font-size: 15px; }
.ica-help-panel ol > li { margin-bottom: 6px; }
.ica-help-panel ul { margin: 4px 0 6px 0; }
.ica-store-picker {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 14px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.ica-store-picker-label {
    font-size: 15px;
    font-weight: 600;
    margin-right: 12px;
    color: #555;
}
.ica-store-btn { font-weight: 500; }
.ica-store-btn.is-active { background: #2c699a !important; color: #fff !important; border-color: #205373 !important; }
.ica-store-divider { color: #aaa; font-weight: bold; padding: 0 8px; }
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
.ica-tag.anniversary { background: #e6dcff; color: #4a2a8e; }
.ica-tag.event { background: #d1ecf1; color: #0c5460; }
.ica-tag.frozen, .ica-tag.do_not_reorder { background: #d6e4f0; color: #2c3e50; }
.ica-tag.frozen_dupe { background: #f5c6cb; color: #721c24; font-weight: 700; }
.ica-tag.abc_A { background: #2c699a; color: #fff; font-weight: 700; }
.ica-tag.abc_B { background: #f0ad4e; color: #fff; font-weight: 700; }
.ica-tag.abc_C { background: #ddd; color: #555; }
.ica-tag.manager_pick { background: #fff2b3; color: #5a4410; font-weight: 600; }

/* Manager picks admin */
.ica-mgrpicks-list { margin-bottom: 8px; }
.ica-mgrpick-item {
    background: #fff2b3;
    border: 1px solid #f0dc7a;
    border-radius: 4px;
    padding: 8px 12px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}
.ica-mgrpick-item .ica-mgrpick-meta { color: #5a4410; }
.ica-mgrpick-item .ica-mgrpick-by { font-weight: 700; margin-right: 6px; }
.ica-mgrpick-item .ica-mgrpick-cat { color: #806717; font-size: 12px; margin-left: 6px; }
.ica-mgrpick-item .btn { margin-left: 8px; }

/* Frozen bucket warning style — make it visually clear this is a DON'T list */
.ica-bucket[data-bucket="frozen_inventory"] .ica-bucket-header { border-left-color: #c0392b; background: #fdf2f0; }
.ica-bucket[data-bucket="frozen_inventory"] .ica-row-table tbody tr { opacity: 0.85; }
.ica-bucket[data-bucket="frozen_inventory"] .ica-qty-input { background: #f5f5f5; }

/* ABC A-restock bucket — emphasize as priority */
.ica-bucket[data-bucket="abc_a_restock"] .ica-bucket-header { border-left-color: #2c699a; background: #f0f6fc; }

/* Lead intro */
.ica-lead { font-size: 14px; line-height: 1.6; }
.ica-lead strong { color: #2c699a; }

/* "More options" + "Show all the other reorder lists" disclosures */
.ica-more-options,
.ica-secondary-disclosure {
    margin: 14px 0;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 0;
}
.ica-more-options > summary,
.ica-secondary-disclosure > summary {
    cursor: pointer;
    padding: 12px 18px;
    font-size: 14px;
    font-weight: 600;
    color: #2c699a;
    list-style: revert;
    user-select: none;
}
.ica-more-options > summary:hover,
.ica-secondary-disclosure > summary:hover { background: #f7f9fc; }
.ica-more-options[open] > summary,
.ica-secondary-disclosure[open] > summary { border-bottom: 1px solid #eee; }
.ica-more-options[open] { padding: 0 14px 14px; }
.ica-secondary-buckets { padding: 12px 14px 4px; }

/* Friendly loading card (replaces "Building…" spinner) */
.ica-loading-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 22px 24px;
    margin: 14px 0;
}
.ica-loading-head { font-size: 16px; color: #2c699a; margin-bottom: 6px; }
.ica-loading-head i { margin-right: 10px; }
.ica-loading-meta { color: #888; font-size: 13px; margin-bottom: 18px; }
.ica-loading-skeleton { display: flex; flex-direction: column; gap: 8px; }
.ica-skeleton-row {
    height: 34px;
    background: linear-gradient(90deg, #f0f3f7 0%, #e6ebf2 50%, #f0f3f7 100%);
    background-size: 200% 100%;
    border-radius: 4px;
    animation: ica-skel-pulse 1.4s ease-in-out infinite;
}
@keyframes ica-skel-pulse {
    0% { background-position: 100% 0; }
    100% { background-position: -100% 0; }
}

/* Frozen-inventory insight bar (style mirrors the budget banner) */
.ica-frozen-insight {
    border-radius: 4px;
    padding: 10px 16px;
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.ica-frozen-insight-low { background: #eef5fb; border: 1px solid #c6dcec; color: #2c3e50; }
.ica-frozen-insight-med { background: #fff7e0; border: 1px solid #f0d97a; color: #5a4410; }
.ica-frozen-insight-high { background: #fdecea; border: 1px solid #f4b5af; color: #7d1f15; }
.ica-frozen-head { font-size: 14px; }
.ica-frozen-head strong { font-size: 16px; }
.ica-frozen-head span.text-muted { display: block; margin-top: 2px; }
.ica-frozen-cta a { font-weight: 600; }

/* Last-ordered hint (rendered in the reason column for fast_oos) */
.ica-last-order { color: #2c699a; font-weight: 500; }

/* Budget banner */
.ica-budget-banner {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 12px 18px;
    margin-bottom: 14px;
}
.ica-budget-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px; }
.ica-budget-title { font-size: 15px; font-weight: 600; color: #444; }
.ica-budget-figures { font-size: 13px; color: #555; }
.ica-budget-figures strong { font-size: 15px; color: #333; }
.ica-budget-sep { color: #bbb; padding: 0 6px; }
.ica-budget-bar { margin: 8px 0 0 0; height: 14px; }
.ica-budget-bar .progress-bar { font-size: 10px; line-height: 14px; font-weight: 600; }
.ica-budget-warn { color: #a94442; font-weight: 600; margin-top: 6px; font-size: 13px; }
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
    window.ICA_EVENTS_URL = "{{ action('InventoryCheckController@eventsBucket') }}";
    window.ICA_SECONDARY_URL = "{{ action('InventoryCheckController@secondaryBuckets') }}";
    window.ICA_ABC_URL = "{{ action('InventoryCheckController@abcRestockBucket') }}";
    window.ICA_FROZEN_URL = "{{ action('InventoryCheckController@frozenInventoryBucket') }}";
    window.ICA_FROZEN_UPDATE_URL = "{{ action('InventoryCheckController@frozenStockUpdate') }}";
    window.ICA_MGRPICKS_BUCKET_URL = "{{ action('InventoryCheckController@managerPicksBucket') }}";
    window.ICA_MGRPICKS_LIST_URL = "{{ action('InventoryCheckController@listManagerPicks') }}";
    window.ICA_MGRPICKS_ADD_URL = "{{ action('InventoryCheckController@addManagerPick') }}";
    window.ICA_MGRPICKS_DISMISS_URL = "{{ url('reports/inventory-check-assistant/manager-picks') }}";
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
<!-- Tesseract.js for browser-side OCR of Luminate PNG screenshots. v5
     loaded from jsDelivr (cached, ~1MB gzipped). Only kicks in when an
     image file is selected on the StreetPulse modal. -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.0/dist/tesseract.min.js"></script>
<script src="{{ asset('js/inventory_check_assistant.js?v=' . $asset_v) }}"></script>
@endsection
