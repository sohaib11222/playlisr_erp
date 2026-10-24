{{-- "Fastest selling genres" rows for one [store × date window × category]
     combo. Shared by home/index.blade.php's initial render (default view)
     and HomeController@getFastestSellingGenres (AJAX) so both paths always
     render identically. --}}
@if(count($rows))
    {{-- Headers with data-sort re-order the rows client-side (see fsgSortRows in home/index.blade.php). --}}
    <div class="fsg-row fsg-head {{ $rows->first()->buy_level !== null ? 'fsg-with-buy' : '' }}"><div></div><div class="fsg-sort" data-sort="genre">Genre</div><div></div><div class="fsg-sort" data-sort="days" style="text-align:right;">Days</div><div class="fsg-sort" data-sort="price" style="text-align:right;" title="Revenue ÷ units sold">Avg price</div><div class="fsg-sort" data-sort="ppd" style="text-align:right;" title="Σ(sale − cost) ÷ Σ(cost × days held), in cents">Profit / $1 / day</div><div class="fsg-sort" data-sort="stock" style="text-align:right;">In stock</div><div class="fsg-sort" data-sort="turn" style="text-align:right;" title="Units sold ÷ in stock, compared with the rest of its category (1.0x = average). The buy form's bonus / cut uses this.">Turnover</div><div></div>@if($rows->first()->buy_level !== null)<div title="What the Beta buy form pays for this genre at this store (last 60 days)">Buy form</div>@endif</div>
@endif
@forelse($rows as $idx => $r)
    <div class="fsg-row fsg-clickable {{ $r->buy_level !== null ? 'fsg-with-buy' : '' }}" data-genre="{{ $r->genre }}" data-category="{{ $r->category ?? '' }}" data-days="{{ $r->avg_sell_days }}" data-price="{{ $r->avg_price }}" data-ppd="{{ $r->profit_per_dollar_day }}" data-stock="{{ $r->in_stock }}" data-turn="{{ $r->turnover }}" title="See what sold">
        <div class="fsg-rank">{{ $idx + 1 }}</div>
        <div>
            <p class="fsg-label">{{ $r->genre }}@if($r->category)<span class="fsg-cat">{{ $r->category }}</span>@endif<span class="fsg-sub-num">{{ number_format($r->units) }} units · ${{ number_format($r->revenue, 0) }}</span></p>
        </div>
        <div class="fsg-bar"><div style="width:{{ $r->bar_pct }}%;"></div></div>
        <div class="fsg-days">
            <span class="fsg-days-num">{{ number_format($r->avg_sell_days, 1) }}</span><span class="fsg-days-unit">d</span>
        </div>
        <div class="fsg-price">${{ number_format($r->avg_price, 2) }}</div>
        @if(is_null($r->profit_per_dollar_day))
            <div class="fsg-ppd none" title="No purchase cost recorded">—</div>
        @else
            <div class="fsg-ppd {{ $r->profit_per_dollar_day < 0 ? 'neg' : '' }}" title="${{ number_format($r->gross_profit, 0) }} profit on ${{ number_format($r->cost, 0) }} cost">{{ number_format($r->profit_per_dollar_day * 100, 2) }}¢</div>
        @endif
        <div class="fsg-stock {{ $r->in_stock > 0 ? 'fsg-stock-link' : 'zero' }}" @if($r->in_stock > 0) title="See what's in stock" @endif>{{ number_format($r->in_stock) }}</div>
        <div class="fsg-turn {{ $r->turnover === null ? 'none' : ($r->turnover >= 1.2 ? 'up' : ($r->turnover < 0.8 ? 'down' : '')) }}">{{ $r->turnover === null ? '—' : number_format($r->turnover, 1) . 'x' }}</div>
        <div class="fsg-tag {{ $r->tag }}">{{ $r->tag_emoji ? $r->tag_emoji . ' ' : '' }}{{ $r->tag }}</div>
        @if($r->buy_level !== null)
            <div class="fsg-buy {{ $r->buy_level }}" title="{{ $r->buy_title }}">{{ $r->buy_label }}</div>
        @endif
    </div>
@empty
    <div class="fsg-empty">No genres with ≥5 sales in this window.</div>
@endforelse
