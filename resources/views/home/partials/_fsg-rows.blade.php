{{-- "Fastest selling genres" rows for one [store × date window × category]
     combo. Shared by home/index.blade.php's initial render (default view)
     and HomeController@getFastestSellingGenres (AJAX) so both paths always
     render identically. --}}
@if(count($rows))
    <div class="fsg-row fsg-head"><div></div><div>Genre</div><div></div><div style="text-align:right;">Days</div><div style="text-align:right;">In stock</div><div></div></div>
@endif
@forelse($rows as $idx => $r)
    <div class="fsg-row">
        <div class="fsg-rank">{{ $idx + 1 }}</div>
        <div>
            <p class="fsg-label">{{ $r->genre }}@if($r->category)<span class="fsg-cat">{{ $r->category }}</span>@endif<span class="fsg-sub-num">{{ number_format($r->units) }} units · ${{ number_format($r->revenue, 0) }}</span></p>
        </div>
        <div class="fsg-bar"><div style="width:{{ $r->bar_pct }}%;"></div></div>
        <div class="fsg-days">
            <span class="fsg-days-num">{{ number_format($r->avg_sell_days, 1) }}</span><span class="fsg-days-unit">d</span>
        </div>
        <div class="fsg-stock {{ $r->in_stock > 0 ? '' : 'zero' }}">{{ number_format($r->in_stock) }}</div>
        <div class="fsg-tag {{ $r->tag }}">{{ $r->tag_emoji ? $r->tag_emoji . ' ' : '' }}{{ $r->tag }}</div>
    </div>
@empty
    <div class="fsg-empty">No genres with ≥5 sales in this window.</div>
@endforelse
