<tr>
    <td>
        {!! Form::text("products[{$index}][name]", null, ['class' => 'form-control', 'required', 'placeholder' => __('product.product_name')]) !!}
    </td>
    <td>
        {!! Form::text("products[{$index}][sku]", null, ['class' => 'form-control', 'placeholder' => __('product.sku')]) !!}
    </td>
    <td>
        {!! Form::number("products[{$index}][alert_quantity]", null, ['class' => 'form-control', 'placeholder' => __('product.alert_quantity')]) !!}
    </td>
    <td>
        {!! Form::text("products[{$index}][single_dsp_inc_tax]", null, ['class' => 'form-control input_number', 'required', 'placeholder' => __('product.selling_price')]) !!}
    </td>
    <td>
        {!! Form::text("products[{$index}][single_dpp_inc_tax]", null, ['class' => 'form-control input_number', 'required', 'placeholder' => __('product.purchase_price')]) !!}
    </td>
    <td>
        <button type="button" class="btn btn-danger btn-xs remove_row">
            <i class="fa fa-minus"></i>
        </button>
    </td>
</tr>
