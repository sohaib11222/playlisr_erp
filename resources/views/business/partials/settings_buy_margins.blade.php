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
        <p class="help-block">When someone brings a lot of one thing, each copy is priced by how long it would take to sell at this store's pace, counting what's already on the shelf. Copies past the first mark pay less, past the second mark less again. Lots under the minimum, and normal buys, are not affected.</p>
    </div>
    @foreach(['lot_min_qty' => ['Applies to lots of (copies)', 'Same kind in one buy, all lines added up.', 1, 100000], 'lot_full_months' => ['Full rate up to (months)', 'Copies that would sell within this many months.', 0, 120], 'lot_half_months' => ['Then up to (months)', 'Copies selling after the first mark, up to this.', 0, 240], 'lot_half_pay' => ['…which pay (%)', 'Of the normal rate.', 0, 100], 'lot_quarter_pay' => ['Copies after that pay (%)', 'Of the normal rate.', 0, 100]] as $bmKey => $bmText)
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
        <p class="help-block">
            <strong>Examples:</strong> a $15 item in a normal-speed category at 65% margin: we pay up to
            $5.25 cash. A $100 card: we pay up to $20 (20%), or $25 if its category is a super-fast seller.
        </p>
    </div>
</div>
</div>
