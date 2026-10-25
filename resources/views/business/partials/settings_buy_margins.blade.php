<div class="pos-tab-content">
<div class="row well">
    <div class="col-sm-12">
        <h4>Buy Margins</h4>
        <p class="help-block">
            For "Valuable item (eBay sold value)" lines on the Beta buy form. The cashier types
            the recent eBay sold price and we pay (100 &minus; margin)% of it in cash. The margin
            depends on how fast that category sells at the store compared with the rest of its
            format: fast sellers can take a thinner margin because the money comes back sooner.
            Bulk lines are not affected. Store credit offers are still 1.5&times; the cash offer.
        </p>
    </div>
    @php $bm = \App\Services\BuyMarginSettings::get($business->id); @endphp
    @foreach(['fast' => ['Fast sellers', 'Category sells at least 1.2x as fast as the rest of its format.'], 'normal' => ['Normal speed', 'Also used when no category is picked.'], 'slow' => ['Slow sellers', 'Category sells under 0.8x as fast as the rest of its format.'], 'floor' => ['Minimum margin', 'No line is ever priced below this margin.']] as $bmKey => $bmText)
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('buy_margin_' . $bmKey, $bmText[0] . ' margin (%):') !!}
                {!! Form::number('common_settings[buy_margin_' . $bmKey . ']', $bm[$bmKey], ['class' => 'form-control', 'id' => 'buy_margin_' . $bmKey, 'min' => 0, 'max' => 95, 'step' => 1]) !!}
                <p class="help-block">{{ $bmText[1] }}</p>
            </div>
        </div>
    @endforeach
    <div class="clearfix"></div>
    <div class="col-sm-12"><h4 style="margin-top:10px;">Items worth more</h4></div>
    @foreach(['hv_min' => ['Applies to items worth ($)', 'eBay sold value at or above this.', 1, 100000], 'hv_pay' => ['Most we pay (%)', 'Of the eBay value, for these items.', 0, 95], 'hv_fast_pay' => ['Super-fast sellers pay (%)', 'Category sold 10+ in 60 days and 1.5x+ as fast as the rest of its format.', 0, 95]] as $bmKey => $bmText)
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('buy_margin_' . $bmKey, $bmText[0] . ':') !!}
                {!! Form::number('common_settings[buy_margin_' . $bmKey . ']', $bm[$bmKey], ['class' => 'form-control', 'id' => 'buy_margin_' . $bmKey, 'min' => $bmText[2], 'max' => $bmText[3], 'step' => 1]) !!}
                <p class="help-block">{{ $bmText[1] }}</p>
            </div>
        </div>
    @endforeach
    <div class="clearfix"></div>
    <div class="col-sm-12">
        <h4 style="margin-top:10px;">Huge lots (Beta buy form)</h4>
        <p class="help-block">When someone brings a lot of one thing, each copy is priced by how long the lot itself would take to sell at this store's pace (the overstock cut already covers a full shelf). Copies past the first mark pay less, past the second mark less again. Lots under the minimum, and normal buys, are not affected.</p>
    </div>
    @foreach(['lot_min_qty' => ['Applies to lots of (copies)', 'Same kind in one buy, all lines added up. Also the size where a lot bigger than the shelf gets the overstock cut.', 1, 100000], 'lot_full_months' => ['Full rate up to (months)', 'Copies that would sell within this many months.', 0, 120], 'lot_half_months' => ['Then up to (months)', 'Copies selling after the first mark, up to this.', 0, 240], 'lot_half_pay' => ['…which pay (%)', 'Of the normal rate.', 0, 100], 'lot_quarter_pay' => ['Copies after that pay (%)', 'Of the normal rate.', 0, 100]] as $bmKey => $bmText)
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('buy_margin_' . $bmKey, $bmText[0] . ':') !!}
                {!! Form::number('common_settings[buy_margin_' . $bmKey . ']', $bm[$bmKey], ['class' => 'form-control', 'id' => 'buy_margin_' . $bmKey, 'min' => $bmText[2], 'max' => $bmText[3], 'step' => 1]) !!}
                <p class="help-block">{{ $bmText[1] }}</p>
            </div>
        </div>
    @endforeach
    <div class="clearfix"></div>
    <div class="col-sm-12">
        <h4 style="margin-top:10px;">Fast sellers (Beta buy form)</h4>
        <p class="help-block">Extra pay on bulk lines whose genre / category sells faster than the rest of its format at the buying store. Overstock cuts and huge-lot pricing still win when they apply. Set to 0 for no extra pay.</p>
    </div>
    @foreach(['bonus_warm' => ['Warm bonus (%)', 'Sells 1.2x+ as fast (5+ sold in 60 days).', 0, 50], 'bonus_hot' => ['Hot bonus (%)', 'Sells 1.5x+ as fast (10+ sold in 60 days).', 0, 50]] as $bmKey => $bmText)
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('buy_margin_' . $bmKey, $bmText[0] . ':') !!}
                {!! Form::number('common_settings[buy_margin_' . $bmKey . ']', $bm[$bmKey], ['class' => 'form-control', 'id' => 'buy_margin_' . $bmKey, 'min' => $bmText[2], 'max' => $bmText[3], 'step' => 1]) !!}
                <p class="help-block">{{ $bmText[1] }}</p>
            </div>
        </div>
    @endforeach
    <div class="clearfix"></div>
    <div class="col-sm-12">
        <h4 style="margin-top:10px;">"Last sold on Discogs" (single records, Beta)</h4>
        <p class="help-block">The cashier can type the Last Sold date from the release's Discogs page. A recent sale pays a little more; an old one means the Discogs median may be out of date, so it pays less. Never sold counts as the oldest.</p>
    </div>
    @foreach(['ls_min_median' => ['Records with a median of ($)', 'The date box only shows from this median up.', 0, 100000], 'ls_recent_days' => ['Recent = within (days)', '', 0, 3650], 'ls_recent_bonus' => ['Recent bonus (%)', '', 0, 50], 'ls_stale_days' => ['Old after (days)', '', 0, 3650], 'ls_stale_cut' => ['Old cut (%)', '', 0, 50], 'ls_old_days' => ['Very old after (days)', '', 0, 3650], 'ls_old_cut' => ['Very old / never cut (%)', '', 0, 50]] as $bmKey => $bmText)
        <div class="col-sm-3">
            <div class="form-group">
                {!! Form::label('buy_margin_' . $bmKey, $bmText[0] . ':') !!}
                {!! Form::number('common_settings[buy_margin_' . $bmKey . ']', $bm[$bmKey], ['class' => 'form-control', 'id' => 'buy_margin_' . $bmKey, 'min' => $bmText[2], 'max' => $bmText[3], 'step' => 1]) !!}
                @if($bmText[1] !== '')<p class="help-block">{{ $bmText[1] }}</p>@endif
            </div>
        </div>
    @endforeach
    <div class="clearfix"></div>
    <div class="col-sm-12">
        <p class="help-block">
            <strong>Examples:</strong> a $15 item in a normal-speed category at 65% margin: we pay up to
            $5.25 cash. A $100 card: we pay up to $20 (20%), or $25 if its category is a super-fast seller.
        </p>
    </div>
</div>
</div>
