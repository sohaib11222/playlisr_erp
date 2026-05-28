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
        // Per-bucket helpers — one bar each for Used (35%) and New (65%).
        // 2026-05-27: split was a single bar before; Jon's Q3 cash-flow
        // plan caps used inventory at 30-40% of weekly spend.
        $usedBar = $pb['used'] ?? null;
        $newBar = $pb['new'] ?? null;
        $bandFor = function ($bucket) {
            if (empty($bucket)) return ['progress-bar-success', '#2c699a'];
            if ($bucket['over_budget']) return ['progress-bar-danger', '#a94442'];
            if ($bucket['pct_spent'] >= 80) return ['progress-bar-warning', '#8a6d3b'];
            return ['progress-bar-success', '#2c699a'];
        };
        [$usedBarClass, $usedColor] = $bandFor($usedBar);
        [$newBarClass, $newColor] = $bandFor($newBar);
        $remainColor = $pb['over_budget'] ? '#a94442' : ($pb['remaining'] < 1000 ? '#8a6d3b' : '#2c699a');
    @endphp
    <div class="ica-budget-banner" id="ica_budget_banner"
         data-week="{{ $pb['week_no'] }}"
         data-budget="{{ $pb['budget'] }}"
         data-remaining="{{ $pb['remaining'] }}">
        <div class="ica-budget-head">
            <span class="ica-budget-title">Purchasing budget — week {{ $pb['week_no'] }} of 13 <small class="text-muted">({{ \Carbon\Carbon::parse($pb['start'])->format('M j') }} – {{ \Carbon\Carbon::parse($pb['end'])->format('M j') }})</small></span>
            <span class="ica-budget-figures">
                <span class="ica-budget-spent">Spent <strong id="ica_budget_spent">${{ number_format($pb['spent'], 0) }}</strong></span>
                <span class="ica-budget-sep">·</span>
                <span>Budget <strong>${{ number_format($pb['budget'], 0) }}</strong></span>
                <span class="ica-budget-sep">·</span>
                <span id="ica_budget_remain_wrap" style="color: {{ $remainColor }};">Remaining <strong id="ica_budget_remain">${{ number_format($pb['remaining'], 0) }}</strong></span>
                <button type="button" class="btn btn-xs btn-default ica-budget-add-btn" id="ica_log_buy_btn" title="Log a purchase against this week's budget (e.g. Jon's $2k collection on Sunday)">+ Log a buy</button>
            </span>
        </div>

        {{-- ── Per-store spend split (2026-05-27 Sarah) ─────────────────── --}}
        @if(!empty($pb['per_location']))
        <div class="ica-budget-per-loc">
            <small class="text-muted ica-budget-per-loc-label">By store this week:</small>
            @foreach($pb['per_location'] as $loc)
                <span class="ica-budget-loc-chip">
                    <strong>{{ $loc['name'] }}</strong>
                    <span class="ica-budget-loc-amt">${{ number_format($loc['spent'], 0) }}</span>
                </span>
            @endforeach
            @if(!empty($pb['spent_from_manual']))
                <span class="ica-budget-loc-chip ica-budget-loc-manual" title="Manual + Log a buy entries — not yet tied to a store">
                    <strong>Manual buys</strong>
                    <span class="ica-budget-loc-amt">${{ number_format($pb['spent_from_manual'], 0) }}</span>
                </span>
            @endif
        </div>
        @endif

        {{-- ── Used / New split rows ────────────────────────────────── --}}
        @php
            $renderSplitRow = function ($label, $caption, $bucket, $barClass, $accentColor, $kindClass) {
                if (empty($bucket)) return '';
                $spent = number_format($bucket['spent'], 0);
                $budget = number_format($bucket['budget'], 0);
                $pct = $bucket['pct_spent'];
                $remaining = $bucket['remaining'];
                $over = $bucket['over_budget'];
                $remainLine = $over
                    ? '<span class="ica-bar-remaining-over">over by $' . number_format(abs($remaining), 0) . '</span>'
                    : '<span class="ica-bar-remaining">$' . number_format($remaining, 0) . ' left</span>';
                return <<<HTML
        <div class="ica-bar-row {$kindClass}">
            <div class="ica-bar-left">
                <div class="ica-bar-kind">{$label}</div>
                <div class="ica-bar-caption">{$caption}</div>
            </div>
            <div class="ica-bar-track-wrap">
                <div class="ica-bar-track">
                    <div class="ica-bar-fill {$barClass}" style="width: {$pct}%;"></div>
                    <div class="ica-bar-track-label">\${$spent} <span class="ica-bar-track-of">of</span> \${$budget}</div>
                </div>
            </div>
            <div class="ica-bar-right">
                <div class="ica-bar-pct">{$pct}%</div>
                <div class="ica-bar-remaining-wrap">{$remainLine}</div>
            </div>
        </div>
HTML;
            };
        @endphp
        {!! $renderSplitRow('USED', 'Cap 35% · keep low — slow sell-through', $usedBar, $usedBarClass, $usedColor, 'ica-bar-used') !!}
        {!! $renderSplitRow('NEW',  'Target 65% · majority of weekly spend', $newBar, $newBarClass, $newColor, 'ica-bar-new') !!}

        @if(!empty($pb['manual_entries_this_week']))
        <div class="ica-budget-manual-list">
            <small class="text-muted">Manual entries this week:</small>
            @foreach($pb['manual_entries_this_week'] as $me)
                @php $meKind = strtolower((string) ($me['kind'] ?? 'new')); @endphp
                <span class="ica-budget-manual-chip" data-entry-id="{{ $me['id'] ?? '' }}">
                    <span class="ica-budget-kind-badge ica-kind-{{ $meKind }}">{{ ucfirst($meKind) }}</span>
                    ${{ number_format((float) ($me['amount'] ?? 0), 0) }}
                    @if(!empty($me['source'])) · {{ $me['source'] }} @endif
                    @if(!empty($me['date'])) · {{ \Carbon\Carbon::parse($me['date'])->format('M j') }} @endif
                    by {{ $me['user_name'] ?? '' }}
                    <button type="button" class="ica-budget-manual-remove" data-entry-id="{{ $me['id'] ?? '' }}" title="Remove">×</button>
                </span>
            @endforeach
        </div>
        @endif
        @if($pb['over_budget'])
        <div class="ica-budget-warn">Over budget this week — confirm with Jon before placing more orders.</div>
        @elseif(!empty($usedBar) && $usedBar['over_budget'])
        <div class="ica-budget-warn">Used over its 35% cap — frozen-inventory risk. Slow used buys.</div>
        @endif
    </div>
    {{-- Inline form for + Log a buy (hidden until clicked) --}}
    <div class="ica-budget-log-form" id="ica_log_buy_form" style="display:none;">
        <div class="ica-log-row">
            <label>Amount $</label>
            <input type="number" id="ica_log_amount" class="form-control input-sm" step="0.01" min="0" placeholder="2000.00">
            <label>Kind</label>
            <span class="ica-log-kind">
                <label class="ica-log-kind-opt">
                    <input type="radio" name="ica_log_kind" value="new" checked> New
                </label>
                <label class="ica-log-kind-opt">
                    <input type="radio" name="ica_log_kind" value="used"> Used
                </label>
            </span>
            <label>Date</label>
            <input type="date" id="ica_log_date" class="form-control input-sm" value="{{ \Carbon\Carbon::now()->format('Y-m-d') }}">
            <label>Source</label>
            <input type="text" id="ica_log_source" class="form-control input-sm" placeholder="e.g. customer collection, Discogs lot">
            <label>Note</label>
            <input type="text" id="ica_log_note" class="form-control input-sm" placeholder="Jon's $2k collection Sun">
            <button type="button" class="btn btn-primary btn-sm" id="ica_log_save">Save</button>
            <button type="button" class="btn btn-link btn-sm" id="ica_log_cancel">Cancel</button>
        </div>
        <small class="text-muted">Logs against the weekly budget bar above. Doesn't create a formal purchase transaction — use /buy-from-customer for that. For ad-hoc cash buys / Jon-on-the-floor pickups. <strong>Kind</strong> decides whether it counts against the Used or New sub-budget.</small>
    </div>
    @endif

    {{-- ── Pick a store (one click → builds) ──────────────────────── --}}
    <div class="row no-print">
        <div class="col-md-12">
            {{-- 2026-05-27 Sarah: all filters on a single row. ABC defaults to "A only" so
                 the fast-moving step 1 list is already filtered to A products on landing. --}}
            <div class="ica-store-picker ica-store-picker-singlerow">
                <span class="ica-store-picker-label">What store?</span>
                <button type="button" class="btn btn-default ica-store-btn" data-preset="hollywood_all">
                    Hollywood
                </button>
                <button type="button" class="btn btn-default ica-store-btn" data-preset="pico_all">
                    Pico
                </button>
                <span class="ica-store-divider">·</span>
                <span class="ica-filter-group">
                    <label class="ica-filter-label">Category</label>
                    <select id="ica_filter_category" class="ica-filter-select form-control input-sm">
                        <option value="">All</option>
                    </select>
                </span>
                <span class="ica-filter-group">
                    <label class="ica-filter-label">Genre</label>
                    <select id="ica_filter_genre" class="ica-filter-select form-control input-sm">
                        <option value="">All</option>
                    </select>
                </span>
                <span class="ica-filter-group">
                    <label class="ica-filter-label" title="ABC class: A = top 80% of inventory value, B = next 15%, C = bottom 5%">ABC</label>
                    <select id="ica_filter_abc" class="ica-filter-select form-control input-sm">
                        <option value="">All</option>
                        <option value="A" selected>A only</option>
                        <option value="B">B only</option>
                        <option value="C">C only</option>
                    </select>
                </span>
                <label class="ica-filter-check" title="Hide Record Store Day exclusives (titles with 'RSD' or 'Record Store Day' in the name)">
                    <input type="checkbox" id="ica_filter_hide_rsd"> Hide RSD
                </label>
                <a class="btn btn-link btn-xs" data-toggle="collapse" href="#ica_advanced_filters" role="button">
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

    {{-- ── Buckets render target ─────────────────────────────────── --}}
    <div class="row">
        <div class="col-md-12" id="ica_buckets_root">
            <div class="text-center text-muted" style="padding: 40px 0;">
                <i class="fa fa-arrow-up fa-2x"></i>
                <p style="margin-top: 12px;">Pick a preset + location → click <strong>Build order list</strong>.</p>
            </div>
        </div>
    </div>

    {{-- ── Place this order footer ────────────────────────────────── --}}
    {{-- 2026-05-27 Sarah: the sticky "1068 units · order cost $21,942"
         strip was confusing. Drop the running totals; keep the buttons
         in a simple footer that only shows once a list has been built.
         The #ica_summary span is hidden but kept so JS can still write
         to it without errors. --}}
    <div class="row no-print">
        <div class="col-md-12">
            <div class="ica-order-footer" id="ica_export_strip" style="display:none;">
                <div class="ica-order-footer-title">Place this order</div>
                <span class="ica-summary" id="ica_summary" style="display:none;">—</span>
                <div class="ica-order-footer-actions">
                    <button type="button" class="btn btn-warning" id="ica_autofill_budget" title="Pre-check rows in priority order until this week's remaining budget is used up">
                        Auto-fill to budget
                    </button>
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
            </div>
        </div>
    </div>

    {{-- ── More options (chart imports + inbox pull, collapsed by default) ── --}}
    {{-- 2026-05-27 Sarah: moved below the buckets so the main flow isn't
         interrupted by chart-import / supplier / inbox plumbing. --}}
    <details class="ica-more-options no-print">
        <summary>More options — chart imports, inbox auto-fetch, supplier feeds, manager picks</summary>
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
                @component('components.widget', ['class' => 'box-info', 'title' => 'Supplier price feeds'])
                <p class="text-muted small">Each supplier (AMS, Secretly, Beggars, Redeye, VP) is a row below. Expand one and type artist + title + cost as you look prices up on the supplier's site — entries accumulate. Paste-from-portal + xlsx upload are nested options if you ever have bulk data. The cheapest match across all uploaded prices becomes the green "$X.XX via &lt;supplier&gt;" badge on chart-pick rows.</p>
                <div id="ica_supplier_grid" class="ica-supplier-grid">
                    <p class="text-muted small">Loading current feeds…</p>
                </div>
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
    padding: 12px 16px;
    margin-bottom: 14px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
/* 2026-05-27: single-row variant — never wraps, scrolls horizontally on
   narrow screens instead of stacking. Sarah wants one row regardless. */
.ica-store-picker-singlerow {
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 10px;
    white-space: nowrap;
}
.ica-store-picker-singlerow .ica-filter-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.ica-store-picker-singlerow .ica-filter-label {
    font-size: 12px;
    font-weight: 600;
    color: #666;
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.ica-store-picker-singlerow .ica-filter-select {
    width: auto;
    min-width: 100px;
    max-width: 180px;
    flex-shrink: 0;
}
.ica-store-picker-singlerow .ica-filter-check {
    flex-shrink: 0;
    font-size: 13px;
    margin: 0;
    font-weight: 500;
    color: #555;
}
.ica-store-picker-singlerow .ica-store-btn { flex-shrink: 0; }
.ica-store-picker-label {
    font-size: 14px;
    font-weight: 700;
    margin-right: 4px;
    color: #444;
    flex-shrink: 0;
}
.ica-store-btn { font-weight: 500; }
.ica-store-btn.is-active { background: #2c699a !important; color: #fff !important; border-color: #205373 !important; }
.ica-store-divider { color: #ccc; font-weight: bold; padding: 0 4px; flex-shrink: 0; }
/* "Place this order" footer (replaces the old sticky export strip 2026-05-27) */
.ica-order-footer {
    background: #f7f9fc;
    border: 1px solid #d6e0ea;
    border-radius: 4px;
    padding: 14px 18px;
    margin: 18px 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px;
}
.ica-order-footer-title {
    font-size: 14px;
    font-weight: 700;
    color: #2c699a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-right: auto;
}
.ica-order-footer-actions { display: flex; gap: 8px; flex-wrap: wrap; }
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
.ica-row-table th.ica-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.ica-row-table th.ica-sortable:hover { background: #eef3f7; }
.ica-row-table th.ica-sortable[data-sort-dir] { background: #e1ecf4; }
.ica-row-table th .ica-sort-ind { color: #2c699a; font-size: 11px; margin-left: 2px; }
.ica-row-table td.ica-updated-col,
.ica-row-table td.ica-created-col { white-space: nowrap; color: #555; }
.ica-row-table td.ica-price-col { white-space: nowrap; }
.ica-row-table a.ica-product-link { color: #2c699a; text-decoration: none; }
.ica-row-table a.ica-product-link:hover { text-decoration: underline; color: #1d4f73; }
.ica-frozen-controls { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; }
.ica-frozen-controls .ica-filter-label { font-size: 12px; color: #555; margin: 0; }
.ica-frozen-controls .ica-frozen-days-select { width: auto; display: inline-block; }
.ica-frozen-controls .ica-frozen-days-custom { width: 80px; display: inline-block; }
/* Per-bucket Category / Genre filter strip 2026-05-27 — every bucket
   gets its own scoped filters so each STEP card / section can be
   narrowed without changing the page-level filters above. */
.ica-bucket-controls {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 6px; flex-wrap: wrap;
}
.ica-bucket-controls .ica-filter-label {
    font-size: 11px; color: #666; margin: 0;
    text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600;
}
.ica-bucket-controls .ica-bucket-cat-filter,
.ica-bucket-controls .ica-bucket-gen-filter {
    width: auto; min-width: 120px; max-width: 180px;
    display: inline-block;
}

/* ABC A-restock bucket — emphasize as priority */
.ica-bucket[data-bucket="abc_a_restock"] .ica-bucket-header { border-left-color: #2c699a; background: #f0f6fc; }

/* 2026-05-27 STEP cards — big, friendly section headers for the primary
   ordering workflow (fast-OOS, listening parties, LA events, charts).
   Helps Sarah see the order of operations at a glance. */
.ica-step-card {
    border: 2px solid #2c699a;
    border-radius: 6px;
    padding: 14px 18px 10px;
    margin: 18px 0 10px;
    background: linear-gradient(180deg, #f0f6fc 0%, #fff 60%);
}
.ica-step-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.ica-step-badge {
    background: #2c699a; color: #fff; font-weight: 800;
    font-size: 13px; padding: 4px 11px; border-radius: 4px;
    letter-spacing: 0.6px; text-transform: uppercase;
    flex-shrink: 0;
}
.ica-step-title { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
.ica-step-note {
    margin-top: 8px;
    font-size: 13px;
    color: #444;
    background: #fffbe6;
    border-left: 3px solid #f0ad4e;
    padding: 7px 12px;
    border-radius: 0 3px 3px 0;
}
.ica-step-note strong { color: #8a6d3b; }
.ica-step-note .ica-step-dont { color: #a94442; font-weight: 700; }
/* 2026-05-27: when a bucket is wrapped in a step card, suppress its own
   header (label/why/count pill) so we don't show "UPCOMING EVENTS — STOCK UP"
   right under the STEP 2 badge. Keep the per-bucket Category/Genre filter +
   collapse button visible. */
.ica-step-card .ica-bucket-header > div:first-child > h3,
.ica-step-card .ica-bucket-header > div:first-child > .ica-why { display: none; }
.ica-step-card .ica-bucket { box-shadow: none; border: none; margin-top: 8px; }
.ica-step-card .ica-bucket-header { border-left: none; padding-top: 0; }
/* Don't-reorder warning around frozen (lives inside the secondary disclosure) */
.ica-dont-card {
    border: 2px solid #c0392b;
    background: #fdf2f0;
    padding: 12px 16px;
    border-radius: 6px;
    margin: 14px 0 6px;
}
.ica-dont-head { display: flex; align-items: baseline; gap: 10px; }
.ica-dont-badge { background: #c0392b; color: #fff; font-weight: 700; font-size: 12px; padding: 3px 9px; border-radius: 3px; letter-spacing: 0.5px; }
.ica-dont-title { font-size: 16px; font-weight: 700; color: #7d1f15; margin: 0; }

/* Per-event order summary above the events_upcoming bucket table.
   Each event becomes a chip showing its date, location, and the total
   suggested units across matching artists so Sarah can answer
   "did we order for the listening party? how many?" at a glance. */
.ica-event-summary { margin: 12px 0 10px; }
.ica-event-summary-head {
    font-size: 13px; font-weight: 700; color: #2c699a;
    margin: 10px 0 6px; letter-spacing: 0.3px;
    text-transform: uppercase;
}
.ica-event-chips { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 8px; }
.ica-event-chip {
    border: 1px solid #d6e0ea;
    border-radius: 4px;
    padding: 8px 12px;
    background: #fff;
}
.ica-event-chip.ica-event-listening { border-color: #b896d4; background: #f5efff; }
.ica-event-chip.ica-event-show { border-color: #cfe2dc; background: #f3faf7; }
.ica-event-chip-head { font-size: 13px; line-height: 1.4; }
.ica-event-chip-head strong { color: #222; }
.ica-event-chip-date { color: #888; font-size: 12px; margin-left: 6px; }
.ica-event-chip-qty { font-size: 12px; color: #555; margin-top: 4px; }
.ica-event-chip-qty strong { color: #2c699a; font-size: 14px; }
.ica-event-anniv {
    background: #e6dcff; color: #4a2a8e;
    font-size: 10px; padding: 1px 5px; border-radius: 2px;
    margin-left: 4px; vertical-align: middle;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.ica-event-empty {
    font-size: 13px; color: #888; font-style: italic;
    padding: 8px 12px; background: #fafafa;
    border: 1px dashed #ddd; border-radius: 4px;
}
.ica-empty-cta {
    margin: 12px 0; padding: 14px 18px;
    background: #fffbe6; border: 1px solid #ffeaa7; border-radius: 4px;
}
.ica-empty-cta p { margin: 0 0 8px; color: #555; font-size: 13px; }
.ica-fetch-supplier-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.ica-fetch-supplier-hint { margin-top: 6px; color: #888; }
.ica-fetch-supplier-hint a { color: #2c699a; }
/* UMe weekly spotlights — inline chip block inside STEP 4 (2026-05-27) */
.ica-spot-block { margin: 12px 0 10px; }
.ica-spot-chips { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px; }
.ica-spot-chip {
    border: 1px solid #d6e0ea; border-radius: 4px;
    padding: 8px 12px; background: #fff;
}
.ica-spot-chip-head { font-size: 13px; line-height: 1.4; color: #222; }
.ica-spot-chip-meta { font-size: 11px; color: #888; margin-top: 4px; display: flex; gap: 8px; flex-wrap: wrap; }
.ica-spot-have { color: #1d4f73; font-weight: 600; }
.ica-spot-miss { color: #a94442; }
/* Distributor price columns (2026-05-27 Sarah) — one per supplier so she
   can compare side-by-side and pick the cheapest. Best-price cell glows
   green. Empty cells dim. */
.ica-supplier-col {
    text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;
    color: #555; font-size: 13px; padding: 4px 8px;
}
.ica-supplier-col[data-price=""], .ica-supplier-col:empty { color: #bbb; }
.ica-supplier-best-cell {
    background: #d4edda; color: #0b3d1a; font-weight: 700;
}
.ica-row-table th.ica-sortable[title*="Latest wholesale price"] {
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;
}
/* Live diagnostic line inside each step card (2026-05-27) — surfaces the
   bucket's `why` text including supplier-feed status, so when the AMS
   column is empty Sarah can see exactly why (no feed uploaded vs no
   match for this title). */
.ica-step-why {
    margin-top: 6px; font-size: 12px; color: #666;
    padding: 6px 10px; background: #f7f9fc;
    border-radius: 3px; line-height: 1.5;
}

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
.ica-budget-bar { margin: 0; height: 14px; }
.ica-budget-bar .progress-bar { font-size: 10px; line-height: 14px; font-weight: 600; }
.ica-budget-warn { color: #a94442; font-weight: 600; margin-top: 6px; font-size: 13px; }
/* Per-store spend chips (2026-05-27 Sarah) */
.ica-budget-per-loc {
    margin-top: 10px; display: flex; flex-wrap: wrap;
    align-items: center; gap: 8px;
}
.ica-budget-per-loc-label {
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
    color: #888; font-weight: 600; margin-right: 4px;
}
.ica-budget-loc-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: #eef5fb; border: 1px solid #c6dcec;
    padding: 3px 9px; border-radius: 12px; font-size: 12px; color: #2c3e50;
}
.ica-budget-loc-chip strong { color: #1d4f73; font-weight: 700; }
.ica-budget-loc-amt { color: #555; font-weight: 600; }
.ica-budget-loc-chip.ica-budget-loc-manual {
    background: #fffbe6; border-color: #ffeaa7;
}
.ica-budget-loc-chip.ica-budget-loc-manual strong { color: #8a6d3b; }

/* Used/New split rows v2 (Sarah 2026-05-27 — readability pass) */
.ica-bar-row {
    display: grid;
    grid-template-columns: 200px 1fr 180px;
    gap: 18px;
    align-items: center;
    margin-top: 14px;
    padding: 10px 12px;
    border-radius: 6px;
    background: #fafafa;
    border: 1px solid #ececec;
}
.ica-bar-used { background: #f5faf6; border-color: #d6ead9; }
.ica-bar-new  { background: #f4f8fc; border-color: #d4e3f1; }

.ica-bar-left { min-width: 0; }
.ica-bar-kind {
    font-size: 18px; font-weight: 800; letter-spacing: 1.2px;
    color: #333; line-height: 1.1;
}
.ica-bar-used .ica-bar-kind { color: #2e7d32; }
.ica-bar-new  .ica-bar-kind { color: #1565c0; }
.ica-bar-caption {
    font-size: 11px; color: #888; margin-top: 2px; line-height: 1.3;
}

.ica-bar-track-wrap { min-width: 0; }
.ica-bar-track {
    position: relative;
    height: 28px;
    border-radius: 4px;
    background: #e8e8e8;
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
}
.ica-bar-fill {
    position: absolute; top: 0; left: 0; bottom: 0;
    transition: width 0.3s ease;
    background-color: #5cb85c;
}
.ica-bar-fill.progress-bar-success { background-color: #5cb85c; }
.ica-bar-fill.progress-bar-warning { background-color: #f0ad4e; }
.ica-bar-fill.progress-bar-danger  { background-color: #d9534f; }
.ica-bar-track-label {
    position: relative; z-index: 2;
    line-height: 28px; padding: 0 12px;
    font-size: 14px; font-weight: 600; color: #222;
    text-shadow: 0 1px 0 rgba(255,255,255,0.6);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ica-bar-track-of { color: #888; font-weight: 400; font-size: 12px; }

.ica-bar-right { text-align: right; }
.ica-bar-pct {
    font-size: 22px; font-weight: 800; color: #333; line-height: 1;
}
.ica-bar-used .ica-bar-pct { color: #2e7d32; }
.ica-bar-new  .ica-bar-pct { color: #1565c0; }
.ica-bar-row .ica-bar-fill.progress-bar-warning ~ * .ica-bar-pct,
.ica-bar-remaining-wrap { font-size: 12px; color: #666; margin-top: 4px; }
.ica-bar-remaining { color: #2c699a; font-weight: 600; }
.ica-bar-remaining-over { color: #a94442; font-weight: 700; }

/* Kind badges (still used on manual entry chips) */
.ica-budget-kind-badge {
    display: inline-block; font-size: 11px; font-weight: 700;
    padding: 2px 7px; border-radius: 3px; letter-spacing: 0.3px;
    margin-right: 4px;
}
.ica-kind-used { background: #e8f5e9; color: #2e7d32; }
.ica-kind-new  { background: #e3f2fd; color: #1565c0; }
.ica-log-kind { display: inline-flex; gap: 10px; align-items: center; padding: 0 4px; }
.ica-log-kind-opt { font-weight: 400; margin: 0; cursor: pointer; }

@media (max-width: 900px) {
    .ica-bar-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .ica-bar-right { text-align: left; display: flex; align-items: baseline; gap: 12px; }
    .ica-bar-remaining-wrap { margin-top: 0; }
}
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
    window.ICA_KNOWN_SUPPLIERS = @json($knownSuppliers ?? []);
    window.ICA_CHART_FRESHNESS = @json($chartFreshness ?? []);
    window.ICA_COPY_FORMAT = @json($copyFormat);
    window.ICA_BUCKETS_URL = "{{ action('InventoryCheckController@buckets') }}";
    window.ICA_EVENTS_URL = "{{ action('InventoryCheckController@eventsBucket') }}";
    window.ICA_SECONDARY_URL = "{{ action('InventoryCheckController@secondaryBuckets') }}";
    window.ICA_ABC_URL = "{{ action('InventoryCheckController@abcRestockBucket') }}";
    window.ICA_FROZEN_URL = "{{ action('InventoryCheckController@frozenInventoryBucket') }}";
    window.ICA_FROZEN_UPDATE_URL = "{{ action('InventoryCheckController@frozenStockUpdate') }}";
    window.ICA_PRODUCT_VIEW_URL_BASE = "{{ url('products/view') }}";
    window.ICA_MGRPICKS_BUCKET_URL = "{{ action('InventoryCheckController@managerPicksBucket') }}";
    window.ICA_UME_SPOT_URL = "{{ action('InventoryCheckController@umeSpotlightsBucket') }}";
    window.ICA_SUPPLIER_LIST_URL = "{{ action('InventoryCheckController@listSupplierFeeds') }}";
    window.ICA_SUPPLIER_UPLOAD_URL = "{{ action('InventoryCheckController@uploadSupplierFeed') }}";
    window.ICA_SUPPLIER_AUTOFETCH_URL = "{{ action('InventoryCheckController@runSupplierAutoFetch') }}";
    window.ICA_SUPPLIER_CREDS_URL = "{{ url('reports/inventory-check-assistant/supplier-credentials') }}";
    window.ICA_LOG_BUY_URL = "{{ action('InventoryCheckController@addManualBudgetEntry') }}";
    window.ICA_LOG_BUY_DELETE_BASE = "{{ url('reports/inventory-check-assistant/manual-budget-entry') }}";
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
