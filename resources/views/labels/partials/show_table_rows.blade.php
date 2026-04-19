@forelse ($products as $product) 

    @php
        
        $row_index = $loop->index + $index;
    @endphp
    <tr id="row_{{$row_index}}">
        <td>
            {{$product->product_name}} - {{$product->sub_sku}} - {{$product->price}} - {{$product->catname}}
                
            @if($product->variation_name != "DUMMY")
                <b>{{$product->variation_name}}</b>
            @endif
            <input type="hidden" name="products[{{$loop->index + $index}}][product_id]" value="{{$product->product_id}}">
            <input type="hidden" name="products[{{$loop->index + $index}}][variation_id]" value="{{$product->variation_id}}">
        </td>
        <td>
            <input type="number" class="form-control" min=1
            name="products[{{$loop->index + $index}}][quantity]" value="@if(isset($product->quantity)){{$product->quantity}}@else{{1}}@endif">
        </td>
        @if(request()->session()->get('business.enable_lot_number') == 1)
            <td>
                <input type="text" class="form-control"
                name="products[{{$loop->index + $index}}][lot_number]" value="@if(isset($product->lot_number)){{$product->lot_number}}@endif">
            </td>
        @endif
        @if(request()->session()->get('business.enable_product_expiry') == 1)
            <td>
                <input type="text" class="form-control label-date-picker"
                name="products[{{$loop->index + $index}}][exp_date]" value="@if(isset($product->exp_date)){{@format_date($product->exp_date)}}@endif" placeholder="" autocomplete="off">
            </td>
        @endif
        <td>
            <input type="text" class="form-control label-date-picker"
            name="products[{{$loop->index + $index}}][packing_date]" value="" placeholder="" autocomplete="off">
        </td>
        <td>
            <input type="text" class="form-control label-date-picker"
            name="products[{{$loop->index + $index}}][purchase_date]" value="@if(isset($product->purchase_date)){{$product->purchase_date}}@endif" placeholder="" autocomplete="off">
        </td>
        <td>
            {!! Form::select('products[' . $row_index . '][price_group_id]', $price_groups, null, ['class' => 'form-control', 'placeholder' => __('lang_v1.none')]); !!}
        </td>
        
        <td>
            <button type="button" class="btn btn-danger btn-sm delete-product" data-row-id="row_{{$row_index}}">
                <i class="fa fa-trash"></i> Delete
            </button>
        </td>
    </tr>
@empty

@endforelse
<!--<script src="https://code.jquery.com/jquery-3.6.4.min.js" crossorigin="anonymous"></script>-->
