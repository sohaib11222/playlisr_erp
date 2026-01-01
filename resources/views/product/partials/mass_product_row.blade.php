<tr class="tr product-row" data-row-index="{{ $index }}">
    <td class="td">
        {{-- {!! Form::text("products[{$index}][name]", null, [
            'class' => 'form-control',
            'required' => true,
            'placeholder' => __('product.product_name')
        ]) !!} --}}

        <div style="display: flex; position: relative; gap: 8px; flex-direction: column;" data-row-index="{{ $index }}" class="product-name-select2-container">
            <input type="text" name="{{ "products[{$index}][name]" }}" class="form-control product-name-autocomplete" data-row-index="{{ $index }}" placeholder="{{ __('product.product_name') }}"/>
        </div>
        <input type="hidden" name="{{ "products[{$index}][id]" }}" class="product-id" data-row-index="{{ $index }}"/>
        <input type="hidden" name="{{ "products[{$index}][variation_id]" }}" class="variation-id" data-row-index="{{ $index }}"/>
    </td>
    <td class="td" data-hide-on-selection="yes" data-row-index="{{ $index }}" style="min-width: 150px !important;">
        {!! Form::text("products[{$index}][sku]", null, [
            'class' => 'form-control sku-input',
            'placeholder' => __('product.sku'),
            'data-row-index' => $index,
            'id' => "products[{$index}][sku]"
        ]) !!}
    </td>
    <td class="td" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        <div class="form-group category-selection-container" style="max-width: 300px; display: flex; gap: 2px; align-items: center;">
            {!! Form::select("products[{$index}][category_id]", $categories, null, [
                'class' => 'form-control select2 category-select',
                'placeholder' => __('messages.please_select'),
                'data-row-index' => $index,
                'id' => "products_{$index}_category_id"
            ]) !!}

            <button type="button" class="btn btn-primary btn-xs copy-down" style="padding: 6.5px; border-radius: 0;" data-class="category-select" data-row-index="{{ $index }}">
                Copy Down
            </button>
        </div>
    </td>
    <td class="td" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        <div class="form-group" style="max-width: 300px; display: flex; gap: 2px; align-items: center;">
            {!! Form::select("products[{$index}][sub_category_id]", [], null, [
                'class' => 'form-control select2 subcategory-select',
                'placeholder' => __('messages.please_select'),
                'id' => "products_{$index}_sub_category_id"
            ]) !!}

            <button type="button" class="btn btn-primary btn-xs copy-down" style="padding: 6.5px; border-radius: 0;" data-class="subcategory-select" data-row-index="{{ $index }}">
                Copy Down
            </button>
        </div>
        <div class="sub-category-suggestions-container" data-row-index="{{ $index }}">
            {{-- Suggestions will be added here --}}
        </div>
    </td>
    <td class="td">
        {!! Form::text("products[{$index}][artist]", null, [
            'class' => 'form-control',
            'placeholder' => "Artist",
            'id' => "products_{$index}_artist"
        ]) !!}
    </td>
    <td class="td">
        <div class="form-group" style="max-width: 300px; display: flex; gap: 2px; align-items: center;">
            {!! Form::select("products[{$index}][business_locations][]", $business_locations, $default_location ?? [], [
                'class' => 'form-control select2 select2_business_locations',
                'multiple' => 'multiple',
                'id' => "products_{$index}_locations"
            ]) !!}

            <button type="button" class="btn btn-primary btn-xs copy-down" style="padding: 6.5px; border-radius: 0;" data-class="select2_business_locations" data-row-index="{{ $index }}">
                Copy Down
            </button>
        </div>
    </td>
    <td class="td" id="{{ "qty-container-{$index}" }}">
        <span id="no_location_selected_message">Select Business Location to Edit Stock<span>
    </td>
    <td class="td product-selling-price-row" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        {!! Form::text("products[{$index}][single_dsp_inc_tax]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.selling_price')
        ]) !!}
        <div class="product-price-recommendation-container" style="display: flex; flex-direction: column; gap: 5px;" data-row-index="{{ $index }}">

        </div>
    </td>
    <td class="td" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        {!! Form::text("products[{$index}][single_dpp_inc_tax]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.purchase_price')
        ]) !!}
    </td>

    <td class="th" style="min-width: 75px;">-</td>

    <!-- Add Image URL Field -->
    <td class="td expandable" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        {!! Form::text("products[{$index}][image_url]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.image_url'),
        ]) !!}
    </td>
    <!-- Add Product Image Upload Field -->
    <td class="td expandable" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        {!! Form::file("products[{$index}][image]", [
            'class' => 'form-control',
            'accept' => 'image/*'
        ]) !!}
    </td>

    <!-- Новая колонка: Description -->
    <td class="td expandable" data-hide-on-selection="yes" data-row-index="{{ $index }}">
        {!! Form::textarea("products[{$index}][description]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.description'),
            'rows' => 2
        ]) !!}
    </td>

    <td class="td">
        <button type="button" class="btn btn-danger btn-xs remove_row">
            <i class="fa fa-minus"></i>
        </button>
    </td>
</tr>