{{-- After a purchase is saved (Jon 10/23): two separate alerts. First the
     floor reminder (the items are on nivessa.com now), then, only when a new
     product looks like one we already have, "did you just want to change the
     price?". Data comes from App\Services\ListingCheck via session flash.
     Self-contained: plain JS, no Bootstrap modal, so it can't break the page. --}}
@php($lc = session('listing_check'))
@if(!empty($lc))
<style>
    .lc-overlay { position: fixed; inset: 0; background: rgba(31,27,22,.45); z-index: 100000; display: flex; align-items: flex-start; justify-content: center; padding: 60px 16px; overflow-y: auto; font-family: 'Inter Tight', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .lc-card { position: relative; background: #FFFFFF; color: #1F1B16; border: 1px solid #DFD2B3; border-radius: 12px; box-shadow: 0 10px 40px rgba(31,27,22,.25); max-width: 560px; width: 100%; padding: 22px 24px; }
    .lc-card h3 { margin: 0 32px 10px 0; font-size: 22px; font-weight: 800; color: #1F1B16; }
    .lc-card p { margin: 0 0 10px; font-size: 15px; line-height: 1.45; color: #1F1B16; }
    .lc-card .lc-pair + .lc-pair { border-top: 1px solid #ECE3CF; padding-top: 12px; margin-top: 12px; }
    .lc-card .lc-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .lc-card button { border-radius: 8px; padding: 8px 14px; font-size: 14px; font-weight: 700; cursor: pointer; border: 1px solid #DFD2B3; background: #FFFFFF; color: #1F1B16; }
    .lc-card button.lc-primary { background: #DCEBFF; border-color: #9DC1F0; color: #1F4E8C; }
    .lc-card button.lc-x { position: absolute; top: 12px; right: 12px; padding: 2px 9px; font-size: 18px; line-height: 1.2; border-color: #ECE3CF; }
</style>
<script>
    // Close one alert; the next one (if any) shows after it.
    function lcClose(el) {
        var o = el.closest('.lc-overlay');
        var next = o.nextElementSibling;
        o.remove();
        if (next && next.classList.contains('lc-overlay')) next.style.display = 'flex';
    }
</script>

<div class="lc-overlay" role="dialog" aria-modal="true">
    <div class="lc-card">
        <button type="button" class="lc-x" aria-label="Close" onclick="lcClose(this)">&times;</button>
        <h3>Please put {{ $lc['items'] == 1 ? 'this' : 'these' }} out on the floor now</h3>
        <p style="margin:0">@if($lc['items'] == 1) This item is now for sale on nivessa.com. <strong>Please put it out on the floor right away</strong> so we can find it for online orders. Do not keep it behind the desk. @else These items are now for sale on nivessa.com. <strong>Please put them out on the floor right away</strong> so we can find them for online orders. Do not keep them behind the desk. @endif</p>
    </div>
</div>

@if(!empty($lc['pairs']))
<div class="lc-overlay" role="dialog" aria-modal="true" style="display:none">
    <div class="lc-card">
        <button type="button" class="lc-x" aria-label="Close" onclick="lcClose(this)">&times;</button>
        <h3>Did you just want to change the price?</h3>
        @foreach($lc['pairs'] as $pair)
            <div class="lc-pair">
                <p>We already have <strong>{{ $pair['old']['label'] }}</strong>{{ !empty($pair['old']['category']) ? ', ' . $pair['old']['category'] : '' }} (${{ number_format($pair['old']['price'], 2) }}).</p>
                <div class="lc-actions">
                    <button type="button" class="lc-primary" onclick="window.location.href='{{ action('ProductController@edit', [$pair['old']['id']]) }}';">Yes</button>
                    <button type="button" onclick="var c = this.closest('.lc-card'); this.closest('.lc-pair').remove(); if (!c.querySelector('.lc-pair')) lcClose(c);">No, it's another copy</button>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif
@endif
