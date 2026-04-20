@extends('layouts.app')
@section('title', __('home.home'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header content-header-custom">
    <h1>{{ __('home.welcome_message', ['name' => Session::get('user.first_name')]) }}
    </h1>
</section>

{{-- ============================================================
     Nivessa employee dashboard (new) — what's selling, collections
     bought, recent sales, top-dollar items. Lives above the classic
     admin widgets below.
     ============================================================ --}}
@if(auth()->user()->can('dashboard.data'))
<section class="content no-print" style="padding-bottom: 0;">
    <style>
        .niv-card { background:#fff; border:1px solid #e6e8ec; border-radius:10px; padding:14px 16px; margin-bottom:18px; box-shadow:0 1px 3px rgba(0,0,0,.03); }
        .niv-card h3 { margin:0 0 10px 0; font-size:15px; text-transform:uppercase; letter-spacing:.6px; color:#1b6ca8; font-weight:700; border-bottom:1px solid #eef0f3; padding-bottom:8px; }
        .niv-card h3 .niv-sub { font-size:11px; color:#7b8796; text-transform:none; letter-spacing:0; font-weight:500; margin-left:8px; }
        .niv-card table { width:100%; font-size:13px; }
        .niv-card table td, .niv-card table th { padding:6px 8px; vertical-align:top; }
        .niv-card table tr + tr td { border-top:1px solid #f1f2f4; }
        .niv-chip { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2f7; color:#3c5a73; font-size:11px; font-weight:600; }
        .niv-chip.niv-chip-lp { background:#fde7f1; color:#9d174d; }
        .niv-chip.niv-chip-cd { background:#e0f2fe; color:#075985; }
        .niv-chip.niv-chip-cassette { background:#fff7ed; color:#9a3412; }
        .niv-chip.niv-chip-dvd { background:#ecfeff; color:#155e75; }
        .niv-chip.niv-chip-magazine { background:#f5f3ff; color:#5b21b6; }
        .niv-muted { color:#7b8796; font-size:12px; }
        .niv-money { font-weight:700; color:#1f7a45; }
    </style>

    {{-- ==========================================================
         Nick-style personal progress dashboard
         The first thing employees see when they log in.
         Focus: personal progress vs your own past, not ranking.
         ========================================================== --}}
    <style>
        .pp-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px 22px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.03); }
        .pp-muted { color:#6b7280; font-size:12px; }
        .pp-micro { font-size:11px; color:#6b7280; }
        .pp-green { color:#3b6d11; }
        .pp-green-bg { background:#c0dd97; color:#173404; }
        .pp-green-bg-dark { background:#639922; color:#fff; }
        .pp-gray-bg { background:#d3d1c7; color:#2c2c2a; }
        .pp-arrow-up { display:inline-block; width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-bottom:7px solid #3b6d11; margin-right:5px; vertical-align:middle; }
        .pp-arrow-down { display:inline-block; width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:7px solid #991b1b; margin-right:5px; vertical-align:middle; }
        .pp-progress { height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; }
        .pp-progress > div { height:100%; }
        .pp-bar { height:74px; display:flex; align-items:flex-end; justify-content:center; border-radius:8px; padding-bottom:6px; font-size:11px; font-weight:600; }
        .pp-rank { width:28px; height:28px; border-radius:4px; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-size:13px; font-weight:600; flex:0 0 auto; }
    </style>

    <div class="pp-card" style="padding:22px 26px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px;">
            <div>
                <div style="font-size:24px; font-weight:600; margin-bottom:2px;">What's up, {{ $me_first_name }} 🔥</div>
                <div class="pp-muted">Keep crushin' it. {{ \Carbon\Carbon::now()->format('l, F j') }}</div>
            </div>
            <div class="pp-muted" style="text-align:right;">
                <div>{{ $team_location_name ?? 'Store' }} · {{ \Carbon\Carbon::now()->format('g:ia') }}</div>
                <div style="margin-top:2px;">Shift: {{ $my_today_hrs >= 0.01 ? number_format($my_today_hrs, 1) . 'h' : '—' }}</div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1.3fr 1fr; gap:16px;">
            <div style="background:#f8fafc; border-radius:10px; padding:16px 20px;">
                <div class="pp-muted" style="margin-bottom:6px;">Today so far</div>
                <div style="font-size:34px; font-weight:600; line-height:1;">
                    @if(!is_null($my_today_rph))
                        ${{ number_format($my_today_rph, 0) }}<span style="font-size:16px; color:#6b7280; font-weight:400;">/hr</span>
                    @else
                        <span style="color:#9ca3af; font-size:18px; font-weight:500;">No shift yet — open your register to start</span>
                    @endif
                </div>
                @if(!is_null($my_vs_30d_pct))
                    <div style="margin-top:10px;">
                        @if($my_vs_30d_pct >= 0)
                            <span class="pp-arrow-up"></span>
                            <span style="font-size:12px; font-weight:500; color:#3b6d11;">+{{ number_format($my_vs_30d_pct, 0) }}% vs your 30-day avg</span>
                        @else
                            <span class="pp-arrow-down"></span>
                            <span style="font-size:12px; font-weight:500; color:#991b1b;">{{ number_format($my_vs_30d_pct, 0) }}% vs your 30-day avg</span>
                        @endif
                    </div>
                @elseif(!is_null($my_30d_rph_avg))
                    <div style="margin-top:10px;" class="pp-micro">30-day avg: ${{ number_format($my_30d_rph_avg, 0) }}/hr</div>
                @endif
            </div>

            <div style="background:#f8fafc; border-radius:10px; padding:16px 20px;">
                <div class="pp-muted" style="margin-bottom:6px;">Your best this week</div>
                <div style="font-size:22px; font-weight:600; line-height:1.2;">${{ number_format($my_7day_best_rph, 0) }}/hr</div>
                @if($my_7day_best_day)
                    <div class="pp-micro" style="margin:4px 0 0 0;">{{ $my_7day_best_day }} · ${{ number_format($my_beat_gap, 0) }} to beat it today</div>
                @endif
                <div class="pp-progress" style="margin-top:10px;">
                    @php
                        $beat_pct = $my_7day_best_rph > 0 && !is_null($my_today_rph) ? min(100, ($my_today_rph / $my_7day_best_rph) * 100) : 0;
                    @endphp
                    <div style="width:{{ $beat_pct }}%; background:#ba7517;"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- 7-day streak --}}
    <div class="pp-card">
        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px;">
            <div style="font-size:14px; font-weight:600;">Your 7-day streak</div>
            <div class="pp-muted">{{ $my_streak_above }} of 7 above your average</div>
        </div>
        <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:8px;">
            @foreach($my_7day as $e)
                @php
                    if ($e->is_today) { $cls = 'pp-green-bg-dark'; }
                    elseif (!is_null($e->rph) && $e->above_avg) { $cls = 'pp-green-bg'; }
                    else { $cls = 'pp-gray-bg'; }
                    $is_best = $my_7day_best_day === $e->day && !$e->is_today;
                @endphp
                <div style="text-align:center;">
                    <div class="pp-bar {{ $cls }}" style="height: {{ max(36, $e->bar_pct * 0.74) }}px;">
                        {{ is_null($e->rph) ? '—' : number_format($e->rph, 0) }}
                    </div>
                    <div class="pp-micro" style="margin-top:4px;">
                        {{ $e->day }}{{ $is_best ? ' ★' : '' }}{{ $e->is_today ? ' · today' : '' }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Daily goals --}}
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div class="pp-card" style="margin-bottom:0;">
            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px;">
                <div class="pp-muted">Price {{ $goal_priced_today }} items today</div>
                <div style="font-size:13px; font-weight:600;">{{ $my_priced_today }} / {{ $goal_priced_today }}</div>
            </div>
            <div class="pp-progress">
                @php $priced_pct = min(100, ($my_priced_today / max(1, $goal_priced_today)) * 100); @endphp
                <div style="width:{{ $priced_pct }}%; background:#534ab7;"></div>
            </div>
            <div class="pp-micro" style="margin-top:8px;">
                @if($my_priced_today >= $goal_priced_today)
                    🎉 Goal hit — keep going!
                @else
                    {{ $goal_priced_today - $my_priced_today }} more to hit your daily goal
                @endif
            </div>
        </div>
        <div class="pp-card" style="margin-bottom:0;">
            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px;">
                <div class="pp-muted">Rewards signups</div>
                <div style="font-size:13px; font-weight:600;">{{ $rewards_me_today }} / {{ $goal_rewards_today }}</div>
            </div>
            <div class="pp-progress">
                @php $rew_pct = min(100, ($rewards_me_today / max(1, $goal_rewards_today)) * 100); @endphp
                <div style="width:{{ $rew_pct }}%; background:#1d9e75;"></div>
            </div>
            <div class="pp-micro" style="margin-top:8px;">
                @if($rewards_me_today >= $goal_rewards_today)
                    🎯 Daily streak locked in
                @else
                    {{ $goal_rewards_today - $rewards_me_today }} more for the daily streak
                @endif
            </div>
        </div>
    </div>

    {{-- Nice sales today (your top rings) --}}
    <div class="pp-card">
        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:14px;">
            <div style="font-size:14px; font-weight:600;">Nice sales today</div>
            <div class="pp-micro">Your top rings{{ $my_today_items_total ? ', '.$my_top_today->count().' of '.$my_today_items_total : '' }}</div>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
            @forelse($my_top_today as $i => $s)
                @php $rank_bg = $i === 0 ? '#ba7517' : '#888780'; @endphp
                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:#f8fafc; border-radius:8px;">
                    <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                        <div class="pp-rank" style="background:{{ $rank_bg }};">{{ $i + 1 }}</div>
                        <span style="font-size:13px;">{{ $s->artist ? $s->artist . ' — ' : '' }}{{ $s->name }}@if($s->format) <span class="pp-micro">[{{ $s->format }}]</span>@endif</span>
                    </div>
                    <span style="font-size:14px; font-weight:600; color:#3b6d11;">${{ number_format($s->price, 0) }}</span>
                </div>
            @empty
                <div class="pp-micro" style="padding:8px 0;">Ring something up and your top sales will land here.</div>
            @endforelse
        </div>
    </div>

    {{-- Team goal today --}}
    <div class="pp-card">
        <div style="font-size:14px; font-weight:600; margin-bottom:4px;">{{ $team_location_name ?? 'Store' }} today — team</div>
        <div class="pp-muted" style="margin-bottom:14px;">Everyone's in on this one</div>
        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px;">
            <span style="font-size:13px;">${{ number_format($team_today_rev, 0) }} / ${{ number_format($team_goal, 0) }} daily goal</span>
            <span style="font-size:13px; font-weight:600; color:#534ab7;">{{ number_format($team_pct, 0) }}%</span>
        </div>
        <div class="pp-progress" style="height:12px; border-radius:6px;">
            <div style="width:{{ $team_pct }}%; background:#534ab7;"></div>
        </div>
        <div class="pp-micro" style="margin-top:10px;">
            @php $rem = max(0, $team_goal - $team_today_rev); @endphp
            @if($rem > 0)
                ${{ number_format($rem, 0) }} to go · {{ \Carbon\Carbon::now()->diffForHumans(\Carbon\Carbon::now()->endOfDay(), ['parts' => 2, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }} left in the day
            @else
                🎉 Goal smashed — nice work
            @endif
        </div>
    </div>

    {{-- ==========================================================
         Top Sellers by Store — what's hot, what's moving, what to push
         Store tabs (Hollywood / Pico / Online) × dimension tabs (Genres / Artists / Records)
         ========================================================== --}}
    @if(!empty($ts_stores))
    <style>
        .ts-module { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px 24px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.03); }
        .ts-title { font-size:16px; font-weight:600; margin:0; }
        .ts-sub { font-size:12px; color:#6b7280; margin:0 0 14px 0; }
        .ts-tab-row { display:flex; gap:4px; margin-bottom:14px; border-bottom:1px solid #e5e7eb; }
        .ts-tab { padding:8px 14px; font-size:13px; color:#6b7280; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; }
        .ts-tab.active { font-weight:600; color:#0f172a; border-bottom-color:#534ab7; }
        .ts-pill-row { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .ts-pill { padding:6px 12px; font-size:12px; color:#6b7280; border:1px solid #e5e7eb; border-radius:16px; cursor:pointer; background:#fff; }
        .ts-pill.active { background:#eeedfe; color:#3c3489; border-color:transparent; font-weight:600; }
        .ts-row { display:grid; grid-template-columns:24px 1fr 140px 80px 80px; gap:12px; align-items:center; padding:12px; background:#f8fafc; border-radius:8px; margin-bottom:8px; }
        .ts-rank { font-size:13px; font-weight:600; color:#6b7280; text-align:center; }
        .ts-label { font-size:14px; font-weight:500; margin:0; }
        .ts-sub-num { font-size:11px; color:#6b7280; margin:2px 0 0 0; }
        .ts-bar { height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; }
        .ts-bar > div { height:100%; background:#534ab7; }
        .ts-trend { display:flex; align-items:center; gap:4px; font-size:11px; font-weight:500; }
        .ts-trend-up { color:#3b6d11; }
        .ts-trend-up .arrow { width:0; height:0; border-left:4px solid transparent; border-right:4px solid transparent; border-bottom:6px solid #3b6d11; }
        .ts-trend-down { color:#991b1b; }
        .ts-trend-down .arrow { width:0; height:0; border-left:4px solid transparent; border-right:4px solid transparent; border-top:6px solid #991b1b; }
        .ts-trend-flat { color:#6b7280; }
        .ts-trend-flat .arrow { width:6px; height:2px; background:#6b7280; }
        .ts-tag { font-size:11px; color:#6b7280; }
        .ts-tag.hot { color:#9a3412; font-weight:600; }
        .ts-tag.rising { color:#065f46; font-weight:600; }
        .ts-tag.cooling { color:#991b1b; }
        .ts-insight-row { display:flex; justify-content:space-between; align-items:center; margin-top:18px; padding-top:14px; border-top:1px solid #e5e7eb; }
        .ts-insight { font-size:12px; color:#6b7280; margin:0; }
        .ts-breakdown-link { font-size:12px; color:#185fa5; text-decoration:none; }
    </style>

    <div class="ts-module">
        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:2px;">
            <div class="ts-title">What's hot right now</div>
            <div class="ts-sub" style="margin-bottom:0;">Last 30 days</div>
        </div>
        <div class="ts-sub">Know what's moving, know what to push</div>

        {{-- Store tabs --}}
        <div class="ts-tab-row" id="ts-store-tabs">
            @foreach($ts_stores as $i => $s)
                <div class="ts-tab {{ $i === 0 ? 'active' : '' }}" data-store="{{ $s['key'] }}">{{ $s['label'] }}</div>
            @endforeach
        </div>

        {{-- Dimension pills --}}
        <div class="ts-pill-row" id="ts-dim-pills">
            <div class="ts-pill active" data-dim="genres">Genres</div>
            <div class="ts-pill" data-dim="artists">Artists</div>
            <div class="ts-pill" data-dim="records">Individual records</div>
        </div>

        {{-- Rollup tables per [store × dim] — only one visible at a time --}}
        @foreach($ts_stores as $i => $s)
            @foreach(['genres', 'artists', 'records'] as $dim)
                @php $rows = $ts_data[$s['key']][$dim] ?? collect(); @endphp
                <div class="ts-body" data-store="{{ $s['key'] }}" data-dim="{{ $dim }}" style="display: {{ ($i === 0 && $dim === 'genres') ? 'block' : 'none' }};">
                    @forelse($rows as $idx => $r)
                        <div class="ts-row">
                            <div class="ts-rank">{{ $idx + 1 }}</div>
                            <div>
                                <p class="ts-label">{{ $r->label }}</p>
                                <p class="ts-sub-num">{{ number_format($r->units) }} units · ${{ number_format($r->revenue, 0) }}</p>
                            </div>
                            <div class="ts-bar"><div style="width:{{ $r->bar_pct }}%;"></div></div>
                            @php
                                if (is_null($r->trend_pct)) { $trend_cls = 'ts-trend-flat'; $trend_label = '—'; }
                                elseif ($r->trend_pct >= 5) { $trend_cls = 'ts-trend-up'; $trend_label = '+' . number_format($r->trend_pct, 0) . '%'; }
                                elseif ($r->trend_pct <= -5) { $trend_cls = 'ts-trend-down'; $trend_label = number_format($r->trend_pct, 0) . '%'; }
                                else { $trend_cls = 'ts-trend-flat'; $trend_label = ($r->trend_pct >= 0 ? '+' : '') . number_format($r->trend_pct, 0) . '%'; }
                            @endphp
                            <div class="ts-trend {{ $trend_cls }}"><span class="arrow"></span>{{ $trend_label }}</div>
                            <div class="ts-tag {{ $r->tag }}">{{ $r->tag_emoji ? $r->tag_emoji . ' ' : '' }}{{ $r->tag }}</div>
                        </div>
                    @empty
                        <div class="ts-sub-num" style="padding:16px; text-align:center;">No sales in this window yet.</div>
                    @endforelse
                </div>
            @endforeach
        @endforeach

        @if($ts_insight)
        <div class="ts-insight-row">
            <p class="ts-insight">💡 {{ $ts_insight }}</p>
            <a href="{{ action('ReportController@categorySalesReport') }}" class="ts-breakdown-link">See full breakdown →</a>
        </div>
        @endif
    </div>

    <script>
    (function () {
        var $module = $('.ts-module');
        if (!$module.length) return;
        var currentStore = $module.find('.ts-tab.active').data('store');
        var currentDim   = $module.find('.ts-pill.active').data('dim');

        function refresh() {
            $module.find('.ts-body').hide();
            $module.find('.ts-body[data-store="' + currentStore + '"][data-dim="' + currentDim + '"]').show();
        }
        $module.on('click', '.ts-tab', function () {
            currentStore = $(this).data('store');
            $module.find('.ts-tab').removeClass('active');
            $(this).addClass('active');
            refresh();
        });
        $module.on('click', '.ts-pill', function () {
            currentDim = $(this).data('dim');
            $module.find('.ts-pill').removeClass('active');
            $(this).addClass('active');
            refresh();
        });
    })();
    </script>
    @endif

    {{-- OLD metrics kept below as secondary "business-wide" dashboard.
         Can be collapsed/removed once the personal dashboard proves out. --}}

    {{-- Progress: MoM, YoY, and personal month-over-month --}}
    <div class="row">
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#fff7ed,#ffedd5);">
                <h3 style="color:#9a3412;"><i class="fa fa-chart-line"></i> Store Sales MTD</h3>
                <div style="font-size:26px; font-weight:800; color:#9a3412;">${{ number_format($sales_mtd, 0) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($mom_pct))
                        vs last month same range: ${{ number_format($sales_lm_same, 0) }}
                    @else
                        <strong style="color:{{ $mom_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $mom_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($mom_pct), 1) }}%</strong>
                        vs last month (${{ number_format($sales_lm_same, 0) }})
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);">
                <h3 style="color:#075985;"><i class="fa fa-calendar-alt"></i> Store Sales YTD</h3>
                <div style="font-size:26px; font-weight:800; color:#075985;">${{ number_format($sales_ytd, 0) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($yoy_pct))
                        vs same range last year: ${{ number_format($sales_ly_same, 0) }}
                    @else
                        <strong style="color:{{ $yoy_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $yoy_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($yoy_pct), 1) }}%</strong>
                        vs last year (${{ number_format($sales_ly_same, 0) }})
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);">
                <h3 style="color:#78350f;"><i class="fa fa-trophy"></i> You — Priced MTD</h3>
                <div style="font-size:26px; font-weight:800; color:#78350f;">{{ number_format($my_mtd_priced) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($my_priced_pct))
                        last month: {{ number_format($my_lm_priced) }}
                    @else
                        <strong style="color:{{ $my_priced_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $my_priced_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($my_priced_pct), 1) }}%</strong>
                        vs last month ({{ number_format($my_lm_priced) }})
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#faf5ff,#f3e8ff);">
                <h3 style="color:#6b21a8;"><i class="fa fa-medal"></i> You — Rung Up MTD</h3>
                <div style="font-size:26px; font-weight:800; color:#6b21a8;">{{ number_format($my_mtd_rung) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($my_rung_pct))
                        last month: {{ number_format($my_lm_rung) }}
                    @else
                        <strong style="color:{{ $my_rung_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $my_rung_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($my_rung_pct), 1) }}%</strong>
                        vs last month ({{ number_format($my_lm_rung) }})
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Top 3 leaderboard this week --}}
    <div class="row">
        <div class="col-md-12">
            <div class="niv-card">
                <h3><i class="fa fa-trophy" style="color:#f59e0b;"></i> This Week's Top 3 <span class="niv-sub">by sales revenue — race you to #1</span>
                    <a href="{{ action('ReportController@employeeLeaderboard') }}" class="btn btn-xs btn-default pull-right" style="margin-top:-3px;">Full leaderboard</a>
                </h3>
                <div class="row">
                    @php $medals = ['🥇', '🥈', '🥉']; $bgs = ['#fef9c3', '#e5e7eb', '#fed7aa']; $fgs = ['#78350f','#1f2937','#7c2d12']; @endphp
                    @forelse($leaderboard_top3 as $i => $r)
                        <div class="col-md-4">
                            <div style="background:{{ $bgs[$i] }}; border-radius:10px; padding:14px 16px; display:flex; align-items:center; gap:12px;">
                                <div style="font-size:36px;">{{ $medals[$i] }}</div>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-weight:700; color:{{ $fgs[$i] }}; font-size:15px;">{{ $r->employee }}</div>
                                    <div style="font-size:22px; font-weight:800; color:{{ $fgs[$i] }};">
                                        @if(!is_null($r->revenue_per_hour))
                                            ${{ number_format($r->revenue_per_hour, 0) }}<span style="font-size:14px; font-weight:700; opacity:.8;"> / hr</span>
                                        @else
                                            ${{ number_format($r->revenue, 0) }}
                                        @endif
                                    </div>
                                    <div class="niv-muted" style="font-size:11px;">
                                        ${{ number_format($r->revenue, 0) }} total
                                        @if($r->hours_worked > 0) · {{ number_format($r->hours_worked, 1) }}h @endif
                                        · {{ number_format($r->items_rung) }} items
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-md-12"><div class="niv-muted">No sales yet this week — be the first on the board.</div></div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Personal "today" stats + rewards accounts created today --}}
    <div class="row">
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);">
                <h3 style="color:#3730a3;"><i class="fa fa-tag"></i> You — Items Priced Today</h3>
                <div style="font-size:36px; font-weight:800; color:#3730a3; line-height:1;">{{ number_format($my_priced_today) }}</div>
                <div class="niv-muted" style="margin-top:4px;">Products you created today</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);">
                <h3 style="color:#065f46;"><i class="fa fa-cash-register"></i> You — Rung Up Today</h3>
                <div style="font-size:36px; font-weight:800; color:#065f46; line-height:1;">{{ number_format($my_pos_items_today) }}</div>
                <div class="niv-muted" style="margin-top:4px;">{{ $my_pos_tx_today }} transaction{{ $my_pos_tx_today == 1 ? '' : 's' }}, {{ $my_pos_items_today }} line items</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-id-card"></i> Rewards Accounts Created Today <span class="niv-sub">{{ $rewards_today_total }} today — by employee</span></h3>
                <table>
                    <tbody>
                    @forelse($rewards_today as $r)
                        <tr>
                            <td>{{ trim($r->employee) ?: '(unknown)' }}</td>
                            <td class="text-right niv-money">{{ (int) $r->cnt }}</td>
                        </tr>
                    @empty
                        <tr><td class="niv-muted">No new rewards accounts created yet today.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="niv-muted" style="margin-top:6px; font-size:11px;">Note: attribution is by the employee who created the account; the system doesn't currently record which store it was created at (would need a small schema change).</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-star"></i> Top Selling Categories — by store <span class="niv-sub">last 30 days, by revenue</span></h3>
                @forelse($top_categories_by_location as $loc => $cats)
                    <div style="margin-bottom:12px;">
                        <strong>{{ $loc }}</strong>
                        <table>
                            <tbody>
                            @foreach($cats as $c)
                                <tr>
                                    <td>{{ $c->category }}</td>
                                    <td class="text-right niv-muted">{{ number_format($c->qty, 0) }} sold</td>
                                    <td class="text-right niv-money">${{ number_format($c->revenue, 0) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @empty
                    <div class="niv-muted">No sales yet in the last 30 days.</div>
                @endforelse
            </div>
        </div>

        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-compact-disc"></i> What's Selling — by format <span class="niv-sub">last 30 days (LP / CD / Cassette / DVD / Magazine / …)</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Format</th>
                            <th class="text-right">Units sold</th>
                            <th class="text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($top_formats as $f)
                        @php
                            $cls = strtolower(preg_replace('/[^a-z0-9]/i','-', $f->format));
                        @endphp
                        <tr>
                            <td><span class="niv-chip niv-chip-{{ $cls }}">{{ $f->format }}</span></td>
                            <td class="text-right">{{ number_format($f->qty, 0) }}</td>
                            <td class="text-right niv-money">${{ number_format($f->revenue, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="niv-muted">No format-tagged sales yet. Once products have a format set, this populates.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-boxes"></i> Collections Bought <span class="niv-sub">what we bought — last 30 days</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Date</th>
                            <th>Seller</th>
                            <th>Store</th>
                            <th>Employee</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($recent_purchases as $p)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($p->transaction_date)->format('M j') }}</td>
                            <td>{{ $p->supplier }}</td>
                            <td>{{ $p->location_name }}</td>
                            <td class="niv-muted">{{ trim($p->employee) ?: '—' }}</td>
                            <td class="text-right niv-money">${{ number_format($p->final_total, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="niv-muted">No purchases yet in the last 30 days.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-gem"></i> Most Expensive Items Sold <span class="niv-sub">last 7 days, top 10 by unit price</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Item</th>
                            <th>Format</th>
                            <th>Store</th>
                            <th class="text-right">Unit price</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($top_expensive_items as $t)
                        <tr>
                            <td>
                                <strong>{{ $t->artist ? $t->artist . ' — ' : '' }}{{ $t->name }}</strong>
                                <div class="niv-muted">{{ \Carbon\Carbon::parse($t->transaction_date)->format('M j, h:i A') }}</div>
                            </td>
                            <td>@if($t->format)<span class="niv-chip niv-chip-{{ strtolower(preg_replace('/[^a-z0-9]/i','-', $t->format)) }}">{{ $t->format }}</span>@endif</td>
                            <td>{{ $t->location_name }}</td>
                            <td class="text-right niv-money">${{ number_format($t->unit_price_inc_tax, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="niv-muted">No sales in the last 7 days.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Active customer wants — things to keep an eye out for in new inventory --}}
    <div class="row">
        <div class="col-md-12">
            <div class="niv-card" style="border-left:4px solid #dc2626;">
                <h3 style="color:#991b1b;"><i class="fa fa-hand-point-right"></i> Active Customer Wants <span class="niv-sub">call-me-when-it-comes-in list — <a href="{{ action('CustomerWantController@index') }}">see all / add new →</a></span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Priority</th>
                            <th>Artist</th>
                            <th>Title</th>
                            <th>Format</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Store</th>
                            <th>Added</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($active_wants as $w)
                        <tr>
                            <td>
                                @if($w->priority === 'high')
                                    <span class="label label-danger">HIGH</span>
                                @elseif($w->priority === 'low')
                                    <span class="label label-default">low</span>
                                @else
                                    <span class="label label-info">normal</span>
                                @endif
                            </td>
                            <td>{{ $w->artist }}</td>
                            <td><strong>{{ $w->title }}</strong></td>
                            <td>@if($w->format)<span class="niv-chip niv-chip-{{ strtolower(preg_replace('/[^a-z0-9]/i','-', $w->format)) }}">{{ $w->format }}</span>@endif</td>
                            <td>{{ trim($w->customer) ?: '—' }}</td>
                            <td>{{ $w->phone }}</td>
                            <td>{{ $w->location_name }}</td>
                            <td class="niv-muted">{{ \Carbon\Carbon::parse($w->created_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="niv-muted">No active wants. <a href="{{ action('CustomerWantController@create') }}">Add one</a> when a customer asks for something you don't have in stock.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="niv-card">
                <h3><i class="fa fa-balance-scale"></i> Avg $ per Transaction — by Employee <span class="niv-sub">month-to-date, 3+ transactions only</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Employee</th>
                            <th class="text-right"># transactions</th>
                            <th class="text-right">Total $</th>
                            <th class="text-right">Avg $ / tx</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($avg_per_employee as $a)
                        <tr>
                            <td><strong>{{ trim($a->employee) ?: '(unknown)' }}</strong></td>
                            <td class="text-right">{{ number_format($a->tx_count) }}</td>
                            <td class="text-right">${{ number_format($a->total, 0) }}</td>
                            <td class="text-right niv-money">${{ number_format($a->avg_tx, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="niv-muted">No sales in this window yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="niv-card" style="border-color:#ef4444;">
                <h3 style="color:#991b1b;"><i class="fa fa-heart"></i> Active Customer Wants <span class="niv-sub">{{ $active_wants_count ?? 0 }} open — flag high priority</span>
                    <a href="{{ action('CustomerWantController@index') }}" class="btn btn-xs btn-default pull-right" style="margin-top:-3px;"><i class="fa fa-list"></i> See all</a>
                    <a href="{{ action('CustomerWantController@create') }}" class="btn btn-xs btn-primary pull-right" style="margin-top:-3px; margin-right:6px;"><i class="fa fa-plus"></i> Add want</a>
                </h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Priority</th>
                            <th>Artist</th>
                            <th>Title</th>
                            <th>Format</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Store</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($active_wants as $w)
                        <tr @if($w->priority==='high') style="background:#fef2f2;" @endif>
                            <td>
                                @if($w->priority==='high')<span class="label label-danger">HIGH</span>
                                @elseif($w->priority==='low')<span class="label label-default">low</span>
                                @else<span class="label label-info">normal</span>@endif
                            </td>
                            <td>{{ $w->artist }}</td>
                            <td><strong>{{ $w->title }}</strong>@if($w->notes)<div class="niv-muted"><small>{{ $w->notes }}</small></div>@endif</td>
                            <td>{{ $w->format }}</td>
                            <td>{{ trim($w->customer) ?: '—' }}</td>
                            <td>{{ $w->phone }}</td>
                            <td>{{ $w->location_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="niv-muted">No active wants. When a customer asks you to call them when something comes in, add it here.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="niv-card">
                <h3><i class="fa fa-history"></i> Last 15 Items Sold <span class="niv-sub">live, across all stores</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Time</th>
                            <th>Item</th>
                            <th>Format</th>
                            <th>Store</th>
                            <th>Cashier</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($last_sold_items as $l)
                        <tr>
                            <td class="niv-muted">{{ \Carbon\Carbon::parse($l->transaction_date)->format('M j, h:i A') }}</td>
                            <td><strong>{{ $l->artist ? $l->artist . ' — ' : '' }}{{ $l->name }}</strong></td>
                            <td>@if($l->format)<span class="niv-chip niv-chip-{{ strtolower(preg_replace('/[^a-z0-9]/i','-', $l->format)) }}">{{ $l->format }}</span>@endif</td>
                            <td>{{ $l->location_name }}</td>
                            <td class="niv-muted">{{ trim($l->employee) ?: '—' }}</td>
                            <td class="text-right">{{ number_format($l->quantity, 0) }}</td>
                            <td class="text-right niv-money">${{ number_format($l->unit_price_inc_tax, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="niv-muted">No sales yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
@endif
<!-- Main content -->
<section class="content content-custom no-print">
    <br>
    @if(auth()->user()->can('dashboard.data'))
        @if($is_admin)
        	<div class="row">
                <div class="col-md-4 col-xs-12">
                    @if(count($all_locations) > 1)
                        {!! Form::select('dashboard_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'dashboard_location']); !!}
                    @endif
                </div>
        		<div class="col-md-8 col-xs-12">
                    <div class="form-group pull-right d-flex justify-content-between">
                        <div class="input-group">
                            <button type="button" class="btn btn-primary" id="dashboard_date_filter">
                                <span>
                                    <i class="fa fa-calendar"></i> {{ __('messages.filter_by_date') }}
                                </span>
                                <i class="fa fa-caret-down"></i>
                            </button>
                        </div>
                        
                    </div>
                    <div class="input-group pull-center">
                        <a href="{{ route('products.create') }}" class="btn btn-success">
                            Add Product
                        </a>
                        <a href="{{ route('product.massCreate') }}" class="btn btn-success">
                            Bulk Add Product
                        </a>
                        <a href="{{ route('labels.show') }}" class="btn btn-success">
                            Print Labels
                        </a>
                    </div>
                </div>

        	</div>
    	   <br>
    	   <div class="row row-custom">
                <!-- /.col -->
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                   <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-aqua"><i class="ion ion-ios-cart-outline"></i></span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.total_sell') }}</span>
                          <span class="info-box-number total_sell"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                   </div>
                  <!-- /.info-box -->
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-green">
                            <i class="ion ion-ios-paper-outline"></i>
                            
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('lang_v1.net') }} @show_tooltip(__('lang_v1.net_home_tooltip'))</span>
                          <span class="info-box-number net"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-yellow">
                            <i class="ion ion-ios-paper-outline"></i>
                            <i class="fa fa-exclamation"></i>
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.invoice_due') }}</span>
                          <span class="info-box-number invoice_due"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>

                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-red text-white">
                            <i class="fas fa-exchange-alt"></i>
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('lang_v1.total_sell_return') }}</span>
                          <span class="info-box-number total_sell_return"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>
    	    <!-- /.col -->
            </div>
          	<div class="row row-custom">
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                   <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-aqua"><i class="ion ion-cash"></i></span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.total_purchase') }}</span>
                          <span class="info-box-number total_purchase"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                   </div>
                   <!-- /.info-box -->
                </div>
                <!-- /.col -->

                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                   <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-yellow">
                            <i class="fa fa-dollar"></i>
                            <i class="fa fa-exclamation"></i>
                        </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.purchase_due') }}</span>
                          <span class="info-box-number purchase_due"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                   </div>
                  <!-- /.info-box -->
                </div>
                <!-- /.col -->
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-red text-white">
                            <i class="fas fa-undo-alt"></i>
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('lang_v1.total_purchase_return') }}</span>
                          <span class="info-box-number total_purchase_return"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>

                <!-- expense -->
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-red">
                          <i class="fas fa-minus-circle"></i>
                        </span>

                        <div class="info-box-content">
                          <span class="info-box-text">
                            {{ __('lang_v1.expense') }}
                          </span>
                          <span class="info-box-number total_expense"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>
            </div>
            @if(!empty($widgets['after_sale_purchase_totals']))
                @foreach($widgets['after_sale_purchase_totals'] as $widget)
                    {!! $widget !!}
                @endforeach
            @endif
        @endif 
        <!-- end is_admin check -->
         @if(auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.view'))
            @if(!empty($all_locations))
              	<!-- sales chart start -->
              	<div class="row">
              		<div class="col-sm-12">
                        @component('components.widget', ['class' => 'box-primary', 'title' => __('home.sells_last_30_days')])
                          {!! $sells_chart_1->container() !!}
                        @endcomponent
              		</div>
              	</div>
            @endif
            @if(!empty($widgets['after_sales_last_30_days']))
                @foreach($widgets['after_sales_last_30_days'] as $widget)
                    {!! $widget !!}
                @endforeach
            @endif
            @if(!empty($all_locations))
              	<div class="row">
              		<div class="col-sm-12">
                        @component('components.widget', ['class' => 'box-primary', 'title' => __('home.sells_current_fy')])
                          {!! $sells_chart_2->container() !!}
                        @endcomponent
              		</div>
              	</div>
            @endif
        @endif
      	<!-- sales chart end -->
        @if(!empty($widgets['after_sales_current_fy']))
            @foreach($widgets['after_sales_current_fy'] as $widget)
                {!! $widget !!}
            @endforeach
        @endif
      	<!-- products less than alert quntity -->
      	<div class="row">
            @if(auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.view'))
                <div class="col-sm-6">
                    @component('components.widget', ['class' => 'box-warning'])
                      @slot('icon')
                        <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                      @endslot
                      @slot('title')
                        {{ __('lang_v1.sales_payment_dues') }} @show_tooltip(__('lang_v1.tooltip_sales_payment_dues'))
                      @endslot
                        <div class="row">
                            @if(count($all_locations) > 1)
                                <div class="col-md-6 col-sm-6 col-md-offset-6 mb-10">
                                    {!! Form::select('sales_payment_dues_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'sales_payment_dues_location']); !!}
                                </div>
                            @endif
                            <div class="col-md-12">
                                <table class="table table-bordered table-striped" id="sales_payment_dues_table" style="width: 100%;">
                                    <thead>
                                      <tr>
                                        <th>@lang( 'contact.customer' )</th>
                                        <th>@lang( 'sale.invoice_no' )</th>
                                        <th>@lang( 'home.due_amount' )</th>
                                        <th>@lang( 'messages.action' )</th>
                                      </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    @endcomponent
                </div>
            @endif
            @can('purchase.view')
                <div class="col-sm-6">
                    @component('components.widget', ['class' => 'box-warning'])
                    @slot('icon')
                    <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                    @endslot
                    @slot('title')
                    {{ __('lang_v1.purchase_payment_dues') }} @show_tooltip(__('tooltip.payment_dues'))
                    @endslot
                    <div class="row">
                        @if(count($all_locations) > 1)
                            <div class="col-md-6 col-sm-6 col-md-offset-6 mb-10">
                                {!! Form::select('purchase_payment_dues_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'purchase_payment_dues_location']); !!}
                            </div>
                        @endif
                        <div class="col-md-12">
                            <table class="table table-bordered table-striped" id="purchase_payment_dues_table" style="width: 100%;">
                                <thead>
                                  <tr>
                                    <th>@lang( 'purchase.supplier' )</th>
                                    <th>@lang( 'purchase.ref_no' )</th>
                                    <th>@lang( 'home.due_amount' )</th>
                                    <th>@lang( 'messages.action' )</th>
                                  </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                    @endcomponent
                </div>
            @endcan
        </div>
        @can('stock_report.view')
            <div class="row">
                <div class="@if((session('business.enable_product_expiry') != 1) && auth()->user()->can('stock_report.view')) col-sm-12 @else col-sm-6 @endif">
                    @component('components.widget', ['class' => 'box-warning'])
                      @slot('icon')
                        <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                      @endslot
                      @slot('title')
                        {{ __('home.product_stock_alert') }} @show_tooltip(__('tooltip.product_stock_alert'))
                      @endslot
                      <div class="row">
                            @if(count($all_locations) > 1)
                                <div class="col-md-6 col-sm-6 col-md-offset-6 mb-10">
                                    {!! Form::select('stock_alert_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'stock_alert_location']); !!}
                                </div>
                            @endif
                            <div class="col-md-12">
                                <table class="table table-bordered table-striped" id="stock_alert_table" style="width: 100%;">
                                    <thead>
                                      <tr>
                                        <th>@lang( 'sale.product' )</th>
                                        <th>@lang( 'business.location' )</th>
                                        <th>@lang( 'report.current_stock' )</th>
                                      </tr>
                                    </thead>
                                </table>
                            </div>
                      </div>
                    @endcomponent
                </div>
                @if(session('business.enable_product_expiry') == 1)
                    <div class="col-sm-6">
                        @component('components.widget', ['class' => 'box-warning'])
                          @slot('icon')
                            <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                          @endslot
                          @slot('title')
                            {{ __('home.stock_expiry_alert') }} @show_tooltip( __('tooltip.stock_expiry_alert', [ 'days' =>session('business.stock_expiry_alert_days', 30) ]) )
                          @endslot
                          <input type="hidden" id="stock_expiry_alert_days" value="{{ \Carbon::now()->addDays(session('business.stock_expiry_alert_days', 30))->format('Y-m-d') }}">
                          <table class="table table-bordered table-striped" id="stock_expiry_alert_table">
                            <thead>
                              <tr>
                                  <th>@lang('business.product')</th>
                                  <th>@lang('business.location')</th>
                                  <th>@lang('report.stock_left')</th>
                                  <th>@lang('product.expires_in')</th>
                              </tr>
                            </thead>
                          </table>
                        @endcomponent
                    </div>
                @endif
      	    </div>
        @endcan
        @if(auth()->user()->can('so.view_all') || auth()->user()->can('so.view_own'))
            <div class="row" @if(!auth()->user()->can('dashboard.data'))style="margin-top: 190px !important;"@endif>
                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-warning'])
                        @slot('icon')
                            <i class="fas fa-list-alt text-yellow fa-lg" aria-hidden="true"></i>
                        @endslot
                        @slot('title')
                            {{__('lang_v1.sales_order')}}
                        @endslot
                        <div class="row">
                        @if(count($all_locations) > 1)
                            <div class="col-md-4 col-sm-6 col-md-offset-8 mb-10">
                                {!! Form::select('so_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'so_location']); !!}
                            </div>
                        @endif
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped ajax_view" id="sales_order_table">
                                        <thead>
                                            <tr>
                                                <th>@lang('messages.action')</th>
                                                <th>@lang('messages.date')</th>
                                                <th>@lang('restaurant.order_no')</th>
                                                <th>@lang('sale.customer_name')</th>
                                                <th>@lang('lang_v1.contact_no')</th>
                                                <th>@lang('sale.location')</th>
                                                <th>@lang('sale.status')</th>
                                                <th>@lang('lang_v1.shipping_status')</th>
                                                <th>@lang('lang_v1.quantity_remaining')</th>
                                                <th>@lang('lang_v1.added_by')</th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endcomponent
                </div>
            </div>
        @endif
        @if(!empty($common_settings['enable_purchase_order']) && (auth()->user()->can('purchase_order.view_all') || auth()->user()->can('purchase_order.view_own')) )
            <div class="row" @if(!auth()->user()->can('dashboard.data'))style="margin-top: 190px !important;"@endif>
                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-warning'])
                      @slot('icon')
                          <i class="fas fa-list-alt text-yellow fa-lg" aria-hidden="true"></i>
                      @endslot
                      @slot('title')
                          @lang('lang_v1.purchase_order')
                      @endslot
                        <div class="row">
                        @if(count($all_locations) > 1)
                            <div class="col-md-4 col-sm-6 col-md-offset-8 mb-10">
                                {!! Form::select('po_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'po_location']); !!}
                            </div>
                        @endif
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped ajax_view" id="purchase_order_table" style="width: 100%;">
                                      <thead>
                                          <tr>
                                              <th>@lang('messages.action')</th>
                                              <th>@lang('messages.date')</th>
                                              <th>@lang('purchase.ref_no')</th>
                                              <th>@lang('purchase.location')</th>
                                              <th>@lang('purchase.supplier')</th>
                                              <th>@lang('sale.status')</th>
                                              <th>@lang('lang_v1.quantity_remaining')</th>
                                              <th>@lang('lang_v1.added_by')</th>
                                          </tr>
                                      </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endcomponent
                </div>
            </div>
        @endif

        @if(auth()->user()->can('access_pending_shipments_only') || auth()->user()->can('access_shipping') || auth()->user()->can('access_own_shipping') )
            @component('components.widget', ['class' => 'box-warning'])
              @slot('icon')
                  <i class="fas fa-list-alt text-yellow fa-lg" aria-hidden="true"></i>
              @endslot
              @slot('title')
                  @lang('lang_v1.pending_shipments')
              @endslot
                <div class="row">
                    @if(count($all_locations) > 1)
                        <div class="col-md-4 col-sm-6 col-md-offset-8 mb-10">
                            {!! Form::select('pending_shipments_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'pending_shipments_location']); !!}
                        </div>
                    @endif
                    <div class="col-md-12">  
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped ajax_view" id="shipments_table">
                                <thead>
                                    <tr>
                                        <th>@lang('messages.action')</th>
                                        <th>@lang('messages.date')</th>
                                        <th>@lang('sale.invoice_no')</th>
                                        <th>@lang('sale.customer_name')</th>
                                        <th>@lang('lang_v1.contact_no')</th>
                                        <th>@lang('sale.location')</th>
                                        <th>@lang('lang_v1.shipping_status')</th>
                                        @if(!empty($custom_labels['shipping']['custom_field_1']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_1']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_2']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_2']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_3']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_3']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_4']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_4']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_5']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_5']}}
                                            </th>
                                        @endif
                                        <th>@lang('sale.payment_status')</th>
                                        <th>@lang('restaurant.service_staff')</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div> 
                </div>
            @endcomponent
        @endif

        @if(auth()->user()->can('account.access') && config('constants.show_payments_recovered_today') == true)
            @component('components.widget', ['class' => 'box-warning'])
              @slot('icon')
                  <i class="fas fa-money-bill-alt text-yellow fa-lg" aria-hidden="true"></i>
              @endslot
              @slot('title')
                  @lang('lang_v1.payment_recovered_today')
              @endslot
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="cash_flow_table">
                        <thead>
                            <tr>
                                <th>@lang( 'messages.date' )</th>
                                <th>@lang( 'account.account' )</th>
                                <th>@lang( 'lang_v1.description' )</th>
                                <th>@lang( 'lang_v1.payment_method' )</th>
                                <th>@lang( 'lang_v1.payment_details' )</th>
                                <th>@lang('account.credit')</th>
                                <th>@lang( 'lang_v1.account_balance' ) @show_tooltip(__('lang_v1.account_balance_tooltip'))</th>
                                <th>@lang( 'lang_v1.total_balance' ) @show_tooltip(__('lang_v1.total_balance_tooltip'))</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total text-center">
                                <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                                <td class="footer_total_credit"></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent
        @endif

        @if(!empty($widgets['after_dashboard_reports']))
          @foreach($widgets['after_dashboard_reports'] as $widget)
            {!! $widget !!}
          @endforeach
        @endif

    @endif
   <!-- can('dashboard.data') end -->
</section>
<!-- /.content -->
<div class="modal fade payment_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>
<div class="modal fade edit_pso_status_modal" tabindex="-1" role="dialog"></div>
<div class="modal fade edit_payment_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>
@stop
@section('javascript')
    <script src="{{ asset('js/home.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
    @includeIf('sales_order.common_js')
    @includeIf('purchase_order.common_js')
    @if(!empty($all_locations))
        {!! $sells_chart_1->script() !!}
        {!! $sells_chart_2->script() !!}
    @endif
    <script type="text/javascript">
        $(document).ready( function(){
        sales_order_table = $('#sales_order_table').DataTable({
          processing: true,
          serverSide: true,
          scrollY: "75vh",
          scrollX:        true,
          scrollCollapse: true,
          aaSorting: [[1, 'desc']],
          "ajax": {
              "url": '{{action("SellController@index")}}?sale_type=sales_order',
              "data": function ( d ) {
                    d.for_dashboard_sales_order = true;

                    if ($('#so_location').length > 0) {
                        d.location_id = $('#so_location').val();
                    }
                }
          },
          columnDefs: [ {
              "targets": 7,
              "orderable": false,
              "searchable": false
          } ],
          columns: [
              { data: 'action', name: 'action'},
              { data: 'transaction_date', name: 'transaction_date'  },
              { data: 'invoice_no', name: 'invoice_no'},
              { data: 'conatct_name', name: 'conatct_name'},
              { data: 'mobile', name: 'contacts.mobile'},
              { data: 'business_location', name: 'bl.name'},
              { data: 'status', name: 'status'},
              { data: 'shipping_status', name: 'shipping_status'},
              { data: 'so_qty_remaining', name: 'so_qty_remaining', "searchable": false},
              { data: 'added_by', name: 'u.first_name'},
          ]
        });

        @if(auth()->user()->can('account.access') && config('constants.show_payments_recovered_today') == true)

            // Cash Flow Table
            cash_flow_table = $('#cash_flow_table').DataTable({
                processing: true,
                serverSide: true,
                "ajax": {
                        "url": "{{action("AccountController@cashFlow")}}",
                        "data": function ( d ) {
                            d.type = 'credit';
                            d.only_payment_recovered = true;
                        }
                    },
                "ordering": false,
                "searching": false,
                columns: [
                    {data: 'operation_date', name: 'operation_date'},
                    {data: 'account_name', name: 'account_name'},
                    {data: 'sub_type', name: 'sub_type'},
                    {data: 'method', name: 'TP.method'},
                    {data: 'payment_details', name: 'payment_details', searchable: false},
                    {data: 'credit', name: 'amount'},
                    {data: 'balance', name: 'balance'},
                    {data: 'total_balance', name: 'total_balance'},
                ],
                "fnDrawCallback": function (oSettings) {
                    __currency_convert_recursively($('#cash_flow_table'));
                },
                "footerCallback": function ( row, data, start, end, display ) {
                    var footer_total_credit = 0;

                    for (var r in data){
                        footer_total_credit += $(data[r].credit).data('orig-value') ? parseFloat($(data[r].credit).data('orig-value')) : 0;
                    }
                    $('.footer_total_credit').html(__currency_trans_from_en(footer_total_credit));
                }
            });
        @endif

        $('#so_location').change( function(){
            sales_order_table.ajax.reload();
        });
        @if(!empty($common_settings['enable_purchase_order']))
          //Purchase table
          purchase_order_table = $('#purchase_order_table').DataTable({
              processing: true,
              serverSide: true,
              aaSorting: [[1, 'desc']],
              scrollY: "75vh",
              scrollX:        true,
              scrollCollapse: true,
              ajax: {
                  url: '{{action("PurchaseOrderController@index")}}',
                  data: function(d) {
                      d.from_dashboard = true;

                        if ($('#po_location').length > 0) {
                            d.location_id = $('#po_location').val();
                        }
                  },
              },
              columns: [
                  { data: 'action', name: 'action', orderable: false, searchable: false },
                  { data: 'transaction_date', name: 'transaction_date' },
                  { data: 'ref_no', name: 'ref_no' },
                  { data: 'location_name', name: 'BS.name' },
                  { data: 'name', name: 'contacts.name' },
                  { data: 'status', name: 'transactions.status' },
                  { data: 'po_qty_remaining', name: 'po_qty_remaining', "searchable": false},
                  { data: 'added_by', name: 'u.first_name' }
              ]
            })

            $('#po_location').change( function(){
                purchase_order_table.ajax.reload();
            });
        @endif

        sell_table = $('#shipments_table').DataTable({
            processing: true,
            serverSide: true,
            aaSorting: [[1, 'desc']],
            scrollY:        "75vh",
            scrollX:        true,
            scrollCollapse: true,
            "ajax": {
                "url": '{{action("SellController@index")}}',
                "data": function ( d ) {
                    d.only_pending_shipments = true;
                    if ($('#pending_shipments_location').length > 0) {
                        d.location_id = $('#pending_shipments_location').val();
                    }
                }
            },
            columns: [
                { data: 'action', name: 'action', searchable: false, orderable: false},
                { data: 'transaction_date', name: 'transaction_date'  },
                { data: 'invoice_no', name: 'invoice_no'},
                { data: 'conatct_name', name: 'conatct_name'},
                { data: 'mobile', name: 'contacts.mobile'},
                { data: 'business_location', name: 'bl.name'},
                { data: 'shipping_status', name: 'shipping_status'},
                @if(!empty($custom_labels['shipping']['custom_field_1']))
                    { data: 'shipping_custom_field_1', name: 'shipping_custom_field_1'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_2']))
                    { data: 'shipping_custom_field_2', name: 'shipping_custom_field_2'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_3']))
                    { data: 'shipping_custom_field_3', name: 'shipping_custom_field_3'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_4']))
                    { data: 'shipping_custom_field_4', name: 'shipping_custom_field_4'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_5']))
                    { data: 'shipping_custom_field_5', name: 'shipping_custom_field_5'},
                @endif
                { data: 'payment_status', name: 'payment_status'},
                { data: 'waiter', name: 'ss.first_name', @if(empty($is_service_staff_enabled)) visible: false @endif }
            ],
            "fnDrawCallback": function (oSettings) {
                __currency_convert_recursively($('#sell_table'));
            },
            createdRow: function( row, data, dataIndex ) {
                $( row ).find('td:eq(4)').attr('class', 'clickable_td');
            }
        });

        $('#pending_shipments_location').change( function(){
            sell_table.ajax.reload();
        });
    });
    </script>
@endsection

