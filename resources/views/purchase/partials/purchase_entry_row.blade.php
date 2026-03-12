@foreach( $variations as $variation)
    <tr @if(!empty($purchase_order_line)) data-purchase_order_id="{{$purchase_order_line->transaction_id}}" @endif>
        <td><span class="sr_number"></span></td>
        <td style="white-space: nowrap;">
            <span>{{ $product->name }} ({{$variation->sub_sku}})</span>
            @if( $product->type == 'variable' )
                <span class="text-muted"> | <b>{{ $variation->product_variation->name }}</b>: {{ $variation->name }}</span>
            @endif
            @if($product->enable_stock == 1)
                <span class="text-muted"> | @lang('report.current_stock'): @if(!empty($variation->variation_location_details->first())) {{@num_format($variation->variation_location_details->first()->qty_available)}} @else 0 @endif {{ $product->unit->short_name }}</span>
            @endif
        </td>
        <td style="white-space: nowrap;">
            @if(!empty($purchase_order_line))
                {!! Form::hidden('purchases[' . $row_count . '][purchase_order_line_id]', $purchase_order_line->id ) !!}
            @endif

            {!! Form::hidden('purchases[' . $row_count . '][product_id]', $product->id ) !!}
            {!! Form::hidden('purchases[' . $row_count . '][variation_id]', $variation->id , ['class' => 'hidden_variation_id']) !!}

            @php
                $check_decimal = 'false';
                if($product->unit->allow_decimal == 0){
                    $check_decimal = 'true';
                }
                $currency_precision = session('business.currency_precision', 2);
                $quantity_precision = session('business.quantity_precision', 2);

                // Always default to 1 if no purchase order line or imported data
                $quantity_value = 1;
                if (!empty($purchase_order_line)) {
                    $quantity_value = $purchase_order_line->quantity;
                }
                if (!empty($imported_data) && !empty($imported_data['quantity'])) {
                    $quantity_value = $imported_data['quantity'];
                }
                
                $max_quantity = !empty($purchase_order_line) ? $purchase_order_line->quantity - $purchase_order_line->po_quantity_purchased : 0;
            @endphp

            <input type="hidden" class="base_unit_cost" value="{{$variation->default_purchase_price}}">
            <input type="hidden" class="base_unit_selling_price" value="{{$variation->sell_price_inc_tax}}">
            <input type="hidden" name="purchases[{{$row_count}}][product_unit_id]" value="{{$product->unit->id}}">
            
            <div style="display: flex !important; align-items: center !important; gap: 5px !important; white-space: nowrap !important; flex-wrap: nowrap !important; width: 100%;">
            <input type="text"
                name="purchases[{{$row_count}}][quantity]"
                value="{{@format_quantity($quantity_value)}}"
                class="form-control input-sm purchase_quantity input_number input_quantity mousetrap"
                required
                data-decimal="0"
                data-rule-abs_digit={{$check_decimal}}
                data-msg-abs_digit="{{__('lang_v1.decimal_value_not_allowed')}}"
                @if(!empty($max_quantity))
                    data-rule-max-value="{{$max_quantity}}"
                    data-msg-max-value="{{__('lang_v1.max_quantity_quantity_allowed', ['quantity' => $max_quantity])}}"
                @endif
                style="width: 70px !important; flex-shrink: 0 !important; margin: 0 !important; display: inline-block !important;"
            >
            @if(!empty($sub_units))
                <select name="purchases[{{$row_count}}][sub_unit_id]" class="form-control input-sm sub_unit" style="width: auto !important; min-width: 80px !important; margin: 0 !important; flex-shrink: 0 !important; display: inline-block !important;">
                    @foreach($sub_units as $key => $value)
                        <option value="{{$key}}" data-multiplier="{{$value['multiplier']}}">
                            {{$value['name']}}
                        </option>
                    @endforeach
                </select>
            @else
                <span style="font-size: 12px !important; white-space: nowrap !important; display: inline-block !important; margin-left: 3px !important;">{{ $product->unit->short_name }}</span>
            @endif

            @if(!empty($product->second_unit))
                <span style="font-size: 11px !important; white-space: nowrap !important; display: inline-block !important; margin-left: 5px !important;">@lang('lang_v1.quantity_in_second_unit', ['unit' => $product->second_unit->short_name])*:</span>
                <input type="text"
                name="purchases[{{$row_count}}][secondary_unit_quantity]"
                value=""
                class="form-control input-sm input_number input_quantity"
                data-decimal="0"
                style="width: 60px !important; margin: 0 !important; flex-shrink: 0 !important; display: inline-block !important;"
                required>
            @endif
            </div>
        </td>
        <td>
            @php
                $pp_without_discount = !empty($purchase_order_line) ? $purchase_order_line->pp_without_discount/$purchase_order->exchange_rate : $variation->default_purchase_price;

                $discount_percent = !empty($purchase_order_line) ? $purchase_order_line->discount_percent : 0;

                $purchase_price = !empty($purchase_order_line) ? $purchase_order_line->purchase_price/$purchase_order->exchange_rate : $variation->default_purchase_price;

                $tax_id = !empty($purchase_order_line) ? $purchase_order_line->tax_id : $product->tax;

                $tax_id = !empty($imported_data['tax_id']) ? $imported_data['tax_id'] : $tax_id;

                $pp_without_discount = !empty($imported_data['unit_cost_before_discount']) ? $imported_data['unit_cost_before_discount'] : $pp_without_discount;

                $discount_percent = !empty($imported_data['discount_percent']) ? $imported_data['discount_percent'] : $discount_percent;
            @endphp
            {!! Form::text('purchases[' . $row_count . '][pp_without_discount]',
            number_format($pp_without_discount, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator), ['class' => 'form-control input-sm purchase_unit_cost_without_discount input_number', 'required', 'style' => 'display: inline-block; width: 80px;']) !!}

            @if(!empty($last_purchase_line))
                <small class="text-muted" style="display: block; font-size: 10px; margin-top: 2px;">@lang('lang_v1.prev_unit_price'): @format_currency($last_purchase_line->pp_without_discount)</small>
            @endif
        </td>
        <td>
            {!! Form::text('purchases[' . $row_count . '][discount_percent]', number_format($discount_percent, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator), ['class' => 'form-control input-sm inline_discounts input_number', 'required', 'style' => 'display: inline-block; width: 60px;']) !!}

            @if(!empty($last_purchase_line))
                <small class="text-muted" style="display: block; font-size: 10px; margin-top: 2px;">
                    @lang('lang_v1.prev_discount'):
                    {{@num_format($last_purchase_line->discount_percent)}}%
                </small>
            @endif
        </td>
        <td>
            {!! Form::text('purchases[' . $row_count . '][purchase_price]',
            number_format($purchase_price, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator), ['class' => 'form-control input-sm purchase_unit_cost input_number', 'required', 'style' => 'width: 80px;']) !!}
        </td>
        <td class="{{$hide_tax}}">
            <span class="row_subtotal_before_tax display_currency">0</span>
            <input type="hidden" class="row_subtotal_before_tax_hidden" value=0>
        </td>
        <td class="{{$hide_tax}}">
            <div class="input-group">
                <select name="purchases[{{ $row_count }}][purchase_line_tax_id]" class="form-control select2 input-sm purchase_line_tax_id" placeholder="'Please Select'">
                    <option value="" data-tax_amount="0" @if( $hide_tax == 'hide' )
                    selected @endif >@lang('lang_v1.none')</option>
                    @foreach($taxes as $tax)
                        <option value="{{ $tax->id }}" data-tax_amount="{{ $tax->amount }}" @if( $tax_id == $tax->id && $hide_tax != 'hide') selected @endif >{{ $tax->name }}</option>
                    @endforeach
                </select>
                {!! Form::hidden('purchases[' . $row_count . '][item_tax]', 0, ['class' => 'purchase_product_unit_tax']) !!}
                <span class="input-group-addon purchase_product_unit_tax_text">
                    0.00</span>
            </div>
        </td>
        <td class="{{$hide_tax}}">
            @php
                $dpp_inc_tax = number_format($variation->dpp_inc_tax, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator);
                if($hide_tax == 'hide'){
                    $dpp_inc_tax = number_format($variation->default_purchase_price, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator);
                }

                $dpp_inc_tax = !empty($purchase_order_line) ? number_format($purchase_order_line->purchase_price_inc_tax/$purchase_order->exchange_rate, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator) : $dpp_inc_tax;

            @endphp
            {!! Form::text('purchases[' . $row_count . '][purchase_price_inc_tax]', $dpp_inc_tax, ['class' => 'form-control input-sm purchase_unit_cost_after_tax input_number', 'required', 'style' => 'width: 80px;']) !!}
        </td>
        <td>
            <span class="row_subtotal_after_tax display_currency">0</span>
            <input type="hidden" class="row_subtotal_after_tax_hidden" value=0>
        </td>
        <td class="@if(!session('business.enable_editing_product_from_purchase') || !empty($is_purchase_order)) hide @endif">
            {!! Form::text('purchases[' . $row_count . '][profit_percent]', number_format($variation->profit_percent, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator), ['class' => 'form-control input-sm input_number profit_percent', 'required', 'style' => 'width: 60px;']) !!}
        </td>
        @if(empty($is_purchase_order))
        <td>
            @if(session('business.enable_editing_product_from_purchase'))
                {!! Form::text('purchases[' . $row_count . '][default_sell_price]', number_format($variation->sell_price_inc_tax, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator), ['class' => 'form-control input-sm input_number default_sell_price', 'required', 'style' => 'width: 80px;']) !!}
            @else
                <span style="white-space: nowrap;">{{ number_format($variation->sell_price_inc_tax, $currency_precision, $currency_details->decimal_separator, $currency_details->thousand_separator)}}</span>
            @endif
        </td>
        @if(session('business.enable_lot_number'))
            @php
                $lot_number = !empty($imported_data['lot_number']) ? $imported_data['lot_number'] : null;
            @endphp
            <td>
                {!! Form::text('purchases[' . $row_count . '][lot_number]', $lot_number, ['class' => 'form-control input-sm', 'style' => 'width: 100px;']) !!}
            </td>
        @endif
        @if(session('business.enable_product_expiry'))
            <td style="text-align: left;">

                {{-- Maybe this condition for checkin expiry date need to be removed --}}
                @php
                    $expiry_period_type = !empty($product->expiry_period_type) ? $product->expiry_period_type : 'month';
                @endphp
                @if(!empty($expiry_period_type))
                <input type="hidden" class="row_product_expiry" value="{{ $product->expiry_period }}">
                <input type="hidden" class="row_product_expiry_type" value="{{ $expiry_period_type }}">

                @if(session('business.expiry_type') == 'add_manufacturing')
                    @php
                        $hide_mfg = false;
                    @endphp
                @else
                    @php
                        $hide_mfg = true;
                    @endphp
                @endif

                @php
                    $mfg_date = !empty($imported_data['mfg_date']) ? $imported_data['mfg_date'] : null;
                    $exp_date = !empty($imported_data['exp_date']) ? $imported_data['exp_date'] : null;
                @endphp

                <div style="white-space: nowrap; font-size: 11px;">
                <span class="@if($hide_mfg) hide @endif"><b>@lang('product.mfg_date'):</b></span>
                <div class="input-group @if($hide_mfg) hide @endif" style="display: inline-block; width: 90px; margin-left: 3px;">
                    <span class="input-group-addon" style="padding: 2px 5px;">
                        <i class="fa fa-calendar" style="font-size: 10px;"></i>
                    </span>
                    {!! Form::text('purchases[' . $row_count . '][mfg_date]', $mfg_date, ['class' => 'form-control input-sm expiry_datepicker mfg_date', 'readonly', 'style' => 'padding: 2px 5px; font-size: 11px;']) !!}
                </div>
                <span style="margin-left: 5px;"><b>@lang('product.exp_date'):</b></span>
                <div class="input-group" style="display: inline-block; width: 90px; margin-left: 3px;">
                    <span class="input-group-addon" style="padding: 2px 5px;">
                        <i class="fa fa-calendar" style="font-size: 10px;"></i>
                    </span>
                    {!! Form::text('purchases[' . $row_count . '][exp_date]', $exp_date, ['class' => 'form-control input-sm expiry_datepicker exp_date', 'readonly', 'style' => 'padding: 2px 5px; font-size: 11px;']) !!}
                </div>
                </div>
                @else
                <div class="text-center">
                    @lang('product.not_applicable')
                </div>
                @endif
            </td>
        @endif
        @endif
        <?php $row_count++ ;?>

        <td><i class="fa fa-times remove_purchase_entry_row text-danger" title="Remove" style="cursor:pointer;"></i></td>
    </tr>
@endforeach
