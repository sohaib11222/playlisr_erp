{{-- Quick-add preset tiles for items always entered manually (drinks, candy, pins, stickers).
     Clicking a tile opens the existing Add Manual Item modal pre-filled with name + price. --}}
<style>
    .pos-quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
    .pos-quick-grid-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; font-weight: 700; margin: 2px 0 8px 2px; }
    .pos-quick-tile {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 2px solid #f59e0b;
        color: #78350f;
        border-radius: 12px;
        padding: 18px 14px;
        font-size: 17px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        cursor: pointer;
        text-align: center;
        transition: transform 0.08s ease, box-shadow 0.08s ease;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.15);
    }
    .pos-quick-tile:hover { background: linear-gradient(135deg, #fde68a, #fcd34d); transform: translateY(-1px); box-shadow: 0 4px 8px rgba(245, 158, 11, 0.25); }
    .pos-quick-tile:active { transform: translateY(0); }
    .pos-quick-tile .pos-quick-price { display: block; font-size: 14px; opacity: 0.85; margin-top: 4px; }
    .pos-quick-tile i { display: block; font-size: 22px; margin-bottom: 4px; }
</style>
<div class="pos-quick-grid-title">Quick Add — Snacks & Swag</div>
<div class="pos-quick-grid">
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Soda (can)" data-preset-price="2.00">
        <i class="fa fa-wine-bottle"></i> Soda <span class="pos-quick-price">$2.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Candy" data-preset-price="2.00">
        <i class="fa fa-candy-cane"></i> Candy <span class="pos-quick-price">$2.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Pin" data-preset-price="3.00">
        <i class="fa fa-thumbtack"></i> Pin <span class="pos-quick-price">$3.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Sticker" data-preset-price="3.00">
        <i class="fa fa-sticky-note"></i> Sticker <span class="pos-quick-price">$3.00</span>
    </button>
</div>

<div class="row" id="featured_products_box" style="display: none;">
@if(!empty($featured_products))
	@include('sale_pos.partials.featured_products')
@endif
</div>
<div class="row">
	@if(!empty($categories))
		<div class="col-md-4" id="product_category_div">
			<select class="select2" id="product_category" style="width:100% !important">

				<option value="all">@lang('lang_v1.all_category')</option>

				@foreach($categories as $category)
					<option value="{{$category['id']}}">{{$category['name']}}</option>
				@endforeach

				@foreach($categories as $category)
					@if(!empty($category['sub_categories']))
						<optgroup label="{{$category['name']}}">
							@foreach($category['sub_categories'] as $sc)
								<i class="fa fa-minus"></i> <option value="{{$sc['id']}}">{{$sc['name']}}</option>
							@endforeach
						</optgroup>
					@endif
				@endforeach
			</select>
		</div>
	@endif

	@if(!empty($brands))
		<div class="col-sm-4" id="product_brand_div">
			{!! Form::select('size', $brands, null, ['id' => 'product_brand', 'class' => 'select2', 'name' => null, 'style' => 'width:100% !important']) !!}
		</div>
	@endif

	<!-- used in repair : filter for service/product -->
	<div class="col-md-6 hide" id="product_service_div">
		{!! Form::select('is_enabled_stock', ['' => __('messages.all'), 'product' => __('sale.product'), 'service' => __('lang_v1.service')], null, ['id' => 'is_enabled_stock', 'class' => 'select2', 'name' => null, 'style' => 'width:100% !important']) !!}
	</div>

	<div class="col-sm-4 @if(empty($featured_products)) hide @endif" id="feature_product_div">
		<button type="button" class="btn btn-primary btn-flat" id="show_featured_products">@lang('lang_v1.featured_products')</button>
	</div>
</div>
<br>
<div class="row">
	<input type="hidden" id="suggestion_page" value="1">
	<div class="col-md-12">
		<div class="eq-height-row" id="product_list_body"></div>
	</div>
	<div class="col-md-12 text-center" id="suggestion_page_loader" style="display: none;">
		<i class="fa fa-spinner fa-spin fa-2x"></i>
	</div>
</div>