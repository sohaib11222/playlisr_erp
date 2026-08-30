{{-- "What's hot right now" rollup rows for one [store × dim × range] combo.
     Shared by home/index.blade.php's initial render (default range, every
     combo precomputed) and HomeController@getTopSellersRange (AJAX, one
     combo per call) so both paths always render identically. --}}
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
    @if($store['filter'] === '__placeholder__')
        <div class="ts-sub-num" style="padding:16px; text-align:center; line-height:1.5;">
            <strong>{{ $store['label'] }} isn't wired into the ERP yet.</strong><br>
            Sales from this channel aren't tagged on transactions, so there's nothing to roll up. Once an <code>is_{{ $store['key'] }}</code> flag (or <code>source='{{ $store['key'] }}'</code>) is set during import, this tab will fill in automatically.
        </div>
    @else
        <div class="ts-sub-num" style="padding:16px; text-align:center;">No sales in this window yet.</div>
    @endif
@endforelse
