{{-- After a purchase is saved (Jon 10/23): remind the lister the items are on
     nivessa.com now, and flag a new product that looks like one we
     already had. Data comes from App\Services\ListingCheck via session flash.
     Self-contained: plain JS, no Bootstrap modal, so it can't break the page. --}}
@php($lc = session('listing_check'))
@if(!empty($lc))
<style>
    #lc-overlay { position: fixed; inset: 0; background: rgba(31,27,22,.45); z-index: 100000; display: flex; align-items: flex-start; justify-content: center; padding: 60px 16px; overflow-y: auto; font-family: 'Inter Tight', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    #lc-card { background: #FFFFFF; color: #1F1B16; border: 1px solid #DFD2B3; border-radius: 12px; box-shadow: 0 10px 40px rgba(31,27,22,.25); max-width: 560px; width: 100%; padding: 22px 24px; }
    #lc-card h3 { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: #1F1B16; }
    #lc-card p { margin: 0 0 12px; font-size: 15px; line-height: 1.45; color: #1F1B16; }
    #lc-card .lc-floor { background: #F7F1E3; border: 1px solid #ECE3CF; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; }
    #lc-card .lc-pair { border: 1px solid #ECE3CF; border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
    #lc-card .lc-row { font-size: 14px; margin-bottom: 4px; }
    #lc-card .lc-tag { display: inline-block; min-width: 92px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #1F1B16; }
    #lc-card .lc-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    #lc-card button { border-radius: 8px; padding: 8px 14px; font-size: 14px; font-weight: 700; cursor: pointer; border: 1px solid #DFD2B3; background: #FFFFFF; color: #1F1B16; }
    #lc-card button.lc-primary { background: #DCEBFF; border-color: #9DC1F0; color: #1F4E8C; }
    #lc-card .lc-done { width: 100%; margin-top: 6px; background: #2F6B3E; border-color: #2F6B3E; color: #FFFFFF; }
</style>
<div id="lc-overlay" role="dialog" aria-modal="true" aria-labelledby="lc-title">
    <div id="lc-card">
        <h3 id="lc-title">Put {{ $lc['items'] == 1 ? 'it' : 'them' }} on the floor now</h3>
        <div class="lc-floor">
            <p style="margin:0">@if($lc['items'] == 1) This item is now for sale on nivessa.com. <strong>Please put it out on the floor right away</strong> so we can find it for online orders. Do not keep it behind the desk. @else These items are now for sale on nivessa.com. <strong>Please put them out on the floor right away</strong> so we can find them for online orders. Do not keep them behind the desk. @endif</p>
        </div>

        @foreach(($lc['pairs'] ?? []) as $pair)
            <div class="lc-pair">
                <p style="margin:0 0 6px"><strong>We already have this:</strong> {{ $pair['old']['label'] }} (${{ number_format($pair['old']['price'], 2) }})</p>
                <p style="margin:0">If you only meant to change its price, tell a manager.</p>
            </div>
        @endforeach

        <button type="button" class="lc-done" onclick="document.getElementById('lc-overlay').remove();">Got it</button>
    </div>
</div>
@endif
