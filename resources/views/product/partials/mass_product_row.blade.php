<tr>

    <td>
        {!! Form::text("products[{$index}][name]", null, [
           'class' => 'form-control',
           'required' => true,
           'placeholder' => __('product.product_name')
       ]) !!}
    </td>


    <td>
        {!! Form::text("products[{$index}][sku]", null, [
            'class' => 'form-control sku-input',
            'placeholder' => __('product.sku'),
            'id' => "products[{$index}][sku]"
        ]) !!}
    </td>

    <td>
        <div class="input-group">
            {!! Form::select("products[{$index}][brand_id]", $brands, null, [
               'class' => 'form-control select2',
               'placeholder' => __('messages.please_select')
           ]) !!}
        </div>
    </td>

    <td>
        {!! Form::select("products[{$index}][category_id]", $categories, null, [
            'class' => 'form-control select2 category-select',
            'placeholder' => __('messages.please_select'),
            'id' => "products_{$index}_category_id"
        ]) !!}
    </td>

    <td>
        {!! Form::select("products[{$index}][sub_category_id]", [], null, [
            'class' => 'form-control select2 subcategory-select',
            'placeholder' => __('messages.please_select'),
            'id' => "products_{$index}_sub_category_id"
        ]) !!}
    </td>

    <td>
        {!! Form::text("products[{$index}][alert_quantity]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.alert_quantity')
        ]) !!}
    </td>

    <td>
        {!! Form::text("products[{$index}][single_dsp_inc_tax]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.selling_price')
        ]) !!}
    </td>

    <td>
        {!! Form::text("products[{$index}][single_dpp_inc_tax]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.purchase_price')
        ]) !!}
    </td>

    <td>
        {!! Form::select("products[{$index}][tax]", $taxes, null, [
            'class' => 'form-control select2',
            'placeholder' => __('messages.please_select'),
            'id' => "products_{$index}_tax"
        ]) !!}
    </td>


    <td>
        <button type="button" class="btn btn-danger btn-xs remove_row">
            <i class="fa fa-minus"></i>
        </button>
    </td>
</tr>
