{{-- "Fastest selling genres" rows for one [store × date window × category]
     combo. Shared by home/index.blade.php's initial render (default view)
     and HomeController@getFastestSellingGenres (AJAX) so both paths always
     render identically. --}}
@if(count($rows))
    {{-- Headers with data-sort re-order the rows client-side (see fsgSortRows in home/index.blade.php). --}}
    <div class="fsg-row fsg-head"><div></div><div class="fsg-sort" data-sort="genre">Genre</div><div></div><div class="fsg-sort" data-sort="days" style="text-align:right;">Days</div><div class="fsg-sort" data-sort="ppd" style="text-align:right;" title="Σ(sale − cost) ÷ Σ(cost × days held), in cents">Profit / $1 / day</div><div class="fsg-sort" data-sort="stock" style="text-align:right;">In stock</div><div></div></div>
@endif
@forelse($rows as $idx => $r)
    <div class="fsg-row fsg-clickable" data-genre="{{ $r->genre }}" data-category="{{ $r->category ?? '' }}" data-days="{{ $r->avg_sell_days }}" data-ppd="{{ $r->profit_per_dollar_day }}" data-stock="{{ $r->in_stock }}" title="See what sold">
        <div class="fsg-rank">{{ $idx + 1 }}</div>
        <div>
            <p class="fsg-label">{{ $r->genre }}@if($r->category)<span class="fsg-cat">{{ $r->category }}</span>@endif<span class="fsg-sub-num">{{ number_format($r->units) }} units · ${{ number_format($r->revenue, 0) }}</span></p>
        </div>
        <div class="fsg-bar"><div style="width:{{ $r->bar_pct }}%;"></div></div>
        <div class="fsg-days">
            <span class="fsg-days-num">{{ number_format($r->avg_sell_days, 1) }}</span><span class="fsg-days-unit">d</span>
        </div>
        @if(is_null($r->profit_per_dollar_day))
            <div class="fsg-ppd none" title="No purchase cost recorded">—</div>
        @else
            <div class="fsg-ppd {{ $r->profit_per_dollar_day < 0 ? 'neg' : '' }}" title="${{ number_format($r->gross_profit, 0) }} profit on ${{ number_format($r->cost, 0) }} cost">{{ number_format($r->profit_per_dollar_day * 100, 2) }}¢</div>
        @endif
        <div class="fsg-stock {{ $r->in_stock > 0 ? 'fsg-stock-link' : 'zero' }}" @if($r->in_stock > 0) title="See what's in stock" @endif>{{ number_format($r->in_stock) }}</div>
        <div class="fsg-tag {{ $r->tag }}">{{ $r->tag_emoji ? $r->tag_emoji . ' ' : '' }}{{ $r->tag }}</div>
    </div>
@empty
    <div class="fsg-empty">No genres with ≥5 sales in this window.</div>
@endforelse
