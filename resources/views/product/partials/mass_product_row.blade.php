<div class="tr">
    <div class="td">
        {!! Form::text("products[{$index}][name]", null, [
            'class' => 'form-control',
            'required' => true,
            'placeholder' => __('product.product_name')
        ]) !!}
    </div>
    <div class="td">
        {!! Form::text("products[{$index}][sku]", null, [
            'class' => 'form-control sku-input',
            'placeholder' => __('product.sku'),
            'id' => "products[{$index}][sku]"
        ]) !!}
    </div>
    <div class="td">
        {!! Form::select("products[{$index}][brand_id]", $brands, null, [
            'class' => 'form-control select2',
            'placeholder' => __('messages.please_select')
        ]) !!}
    </div>
    <div class="td">
        {!! Form::select("products[{$index}][category_id]", $categories, null, [
            'class' => 'form-control select2 category-select',
            'placeholder' => __('messages.please_select'),
            'id' => "products_{$index}_category_id"
        ]) !!}
    </div>
    <div class="td">
        {!! Form::select("products[{$index}][sub_category_id]", [], null, [
            'class' => 'form-control select2 subcategory-select',
            'placeholder' => __('messages.please_select'),
            'id' => "products_{$index}_sub_category_id"
        ]) !!}
    </div>
    <div class="td">
        {!! Form::select("products[{$index}][business_locations][]", $business_locations, $default_location ?? [], [
            'class' => 'form-control select2',
            'multiple' => 'multiple',
            'id' => "products_{$index}_locations"
        ]) !!}
    </div>
    <div class="td">
        {!! Form::text("products[{$index}][single_dsp_inc_tax]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.selling_price')
        ]) !!}
    </div>
    <div class="td">
        {!! Form::text("products[{$index}][single_dpp_inc_tax]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.purchase_price')
        ]) !!}
    </div>
    <!-- Add Image URL Field -->
    <div class="td">
        {!! Form::text("products[{$index}][image_url]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.image_url'),
        ]) !!}
    </div>
    <!-- Add Product Image Upload Field -->
    <div class="td">
        {!! Form::file("products[{$index}][image]", [
            'class' => 'form-control',
            'accept' => 'image/*'
        ]) !!}
    </div>



    <!-- Новая колонка: Description -->
    <div class="td">
        {!! Form::textarea("products[{$index}][description]", null, [
            'class' => 'form-control',
            'placeholder' => __('product.description'),
            'rows' => 2
        ]) !!}
    </div>



    <div class="td">
        <button type="button" class="btn btn-danger btn-xs remove_row">
            <i class="fa fa-minus"></i>
        </button>
    </div>
</div>