@php
	// Embed the Nivessa logo inline (base64) for the 2x2 label, same as the
	// barcode image, so it renders reliably in the print view regardless of
	// asset-URL resolution. Computed once per page render.
	$is_nivessa_2x2_page = (abs(($barcode_details->width * 1) - 2) < 0.01 && abs(($barcode_details->height * 1) - 2) < 0.01);
	$nivessa_logo_b64 = '';
	if ($is_nivessa_2x2_page) {
		$logo_path = public_path('img/nivessa-logo.png');
		if (is_file($logo_path)) {
			$nivessa_logo_b64 = base64_encode(file_get_contents($logo_path));
		}
	}
@endphp
<table align="center" style="border-spacing: {{$barcode_details->col_distance * 1}}in {{$barcode_details->row_distance * 1}}in; overflow: hidden !important;">
@foreach($page_products as $page_product)

	@if($loop->index % $barcode_details->stickers_in_one_row == 0)
		<!-- create a new row -->
		<tr>
		<!-- <columns column-count="{{$barcode_details->stickers_in_one_row}}" column-gap="{{$barcode_details->col_distance*1}}"> -->
	@endif
		<td align="center" valign="center">
			@php $is_nivessa_2x2 = (abs(($barcode_details->width * 1) - 2) < 0.01 && abs(($barcode_details->height * 1) - 2) < 0.01); @endphp
			@if($is_nivessa_2x2)
			{{-- Nivessa 2"x2" large-barcode layout --}}
			<div style="overflow: hidden !important; width: {{$barcode_details->width * 1}}in; height: {{$barcode_details->height * 1}}in; display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 0.02in; box-sizing: border-box; text-align: center;">

				{{-- Nivessa logo --}}
				@if(!empty($nivessa_logo_b64))<img src="data:image/png;base64,{{ $nivessa_logo_b64 }}" style="height: 0.3in; width: auto; display: block; margin: 0 auto; flex-shrink: 0;">@endif

				{{-- Optional text fields (kept compact so the barcode dominates) --}}
				<div style="line-height: 1.05;">
					@if(!empty($print['name']))
						<span style="display: block !important; font-weight: bold; font-size: {{ max((int)($print['name_size'] ?? 10) + 5, 16) }}px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">{{$page_product->product_actual_name}}</span>
						@endif
						@if(!empty($page_product->artist))
							<span style="display: block !important; font-size: {{ max((int)($print['name_size'] ?? 10) + 4, 15) }}px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">{{$page_product->artist}}</span>
						@endif
						@if(!empty($page_product->category) || !empty($page_product->sub_category))
							<span style="display: block !important; font-size: {{ max((int)($print['name_size'] ?? 10) + 3, 14) }}px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">{{ trim(($page_product->category ?? '') . (!empty($page_product->category) && !empty($page_product->sub_category) ? ' / ' : '') . ($page_product->sub_category ?? '')) }}</span>
					@endif

					@if(!empty($print['variations']) && $page_product->is_dummy != 1)
						<span style="display: block !important; font-size: {{$print['variations_size']}}px;">{{$page_product->product_variation_name}}:<b>{{$page_product->variation_name}}</b></span>
					@endif

					@if(!empty($print['price']))
						<span style="display: block !important; font-weight: bold; font-size: {{ max((int)($print['price_size'] ?? 17), 24) }}px; line-height: 1.1;">
							<b>{{session('currency')['symbol'] ?? ''}}@if($print['price_type'] == 'inclusive'){{@num_format($page_product->sell_price_inc_tax)}}@else{{@num_format($page_product->default_sell_price)}}@endif</b>
							@if(!empty($page_product->purchase_date) && array_key_exists('purchase_date', $print ?? []))
								<span style="font-size: {{ (int) ($print['purchase_date_size'] ?? 12) }}px;">&nbsp;<b>{{ $page_product->purchase_date }}</b></span>
							@endif
						</span>
					@elseif(!empty($page_product->purchase_date) && array_key_exists('purchase_date', $print ?? []))
						<span style="display: block !important; font-size: {{$print['purchase_date_size'] ?? 12}}px;"><b>{{ $page_product->purchase_date }}</b></span>
					@endif
				</div>

				{{-- Large barcode fills the remaining width --}}
				<div style="width: 100%; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: flex-end; align-items: center;">
					<img style="max-width: 96% !important; height: 0.45in !important; width: auto; display: block;" src="data:image/png;base64,{{DNS1D::getBarcodePNG($page_product->sub_sku, $page_product->barcode_type, 1, 30, array(0, 0, 0), false)}}">
					<span style="font-size: 13px !important; letter-spacing: 1px; flex-shrink: 0;">{{ $page_product->sub_sku }}</span>
				</div>
			</div>
			@else
			@php
				// 2"x1" detection + a text downscale so the barcode can dominate the label.
				$is_2x1 = (abs(($barcode_details->width * 1) - 2) < 0.01 && abs(($barcode_details->height * 1) - 1) < 0.01);
				$txt = $is_2x1 ? 0.8 : 1;
			@endphp
			<div style="overflow: hidden !important;display: flex; flex-wrap: wrap;align-content: center;width: {{$barcode_details->width * 1}}in; height: {{$barcode_details->height * 1}}in; justify-content: center;">


				<div style="@if($is_2x1) width:{{ $barcode_details->width * 1 }}in; text-align:center; @endif">

					{{-- Business Name --}}
					@if(!empty($print['business_name']))
						<b style="display: block !important; font-size: {{ round($print['business_name_size']*$txt) }}px">{{$business_name}}</b>
					@endif

					{{-- Product Name --}}
					@if(!empty($print['name']))
						<span style="display: block !important; font-size: {{ round($print['name_size']*$txt) }}px">
							{{$page_product->product_actual_name}}

							@if(!empty($print['lot_number']) && !empty($page_product->lot_number))
								<span style="font-size: {{12*$factor}}px">
									 ({{$page_product->lot_number}})
								</span>
							@endif
						</span>
					@endif

					{{-- Variation --}}
					@if(!empty($print['variations']) && $page_product->is_dummy != 1)
						<span style="display: block !important; font-size: {{ round($print['variations_size']*$txt) }}px">
							{{$page_product->product_variation_name}}:<b>{{$page_product->variation_name}}</b>
						</span>
					@endif
					
					{{-- Genre --}}
					@if(!empty($print['price']))
						<span style="display: block !important; font-size: {{ round($print['name_size']*$txt) }}px">
							Genre:<b>{{$page_product->sub_category}}</b>
						</span>

					{{-- Artist --}}
					@if(!empty($page_product->artist))
						<span style="display: block !important; font-size: {{ round($print['name_size']*$txt) }}px">
							Artist:<b>{{ $is_2x1 ? \Illuminate\Support\Str::limit($page_product->artist, 30, '') : $page_product->artist }}</b>
						</span>
					@endif
				@endif

				{{-- Bin Position --}}
				@if(!empty($page_product->bin_position))
					<span style="display: block !important; font-size: {{ round(($print['name_size'] ?? 12)*$txt) }}px; font-weight: bold;">
						Bin: {{ $page_product->bin_position }}
					</span>
				@endif

				{{-- Price + purchase date on one line (no "Price" label) --}}
					@if(!empty($print['price']))
					<span style="font-size: {{ round($print['price_size']*$txt) }}px;">
						<b>{{session('currency')['symbol'] ?? ''}}
						@if($print['price_type'] == 'inclusive')
							{{@num_format($page_product->sell_price_inc_tax)}}
						@else
							{{@num_format($page_product->default_sell_price)}}
						@endif</b>
						@if(!empty($page_product->purchase_date) && array_key_exists('purchase_date', $print ?? []))
							<span style="font-size: {{ (int) ($print['purchase_date_size'] ?? 12) }}px;">&nbsp;<b>{{ $page_product->purchase_date }}</b></span>
						@endif
					</span>
					@elseif(!empty($page_product->purchase_date) && array_key_exists('purchase_date', $print ?? []))
					<span style="font-size: {{$print['purchase_date_size'] ?? 12}}px;"><b>{{ $page_product->purchase_date }}</b></span>
					@endif
					@if(!empty($print['exp_date']) && !empty($page_product->exp_date))
						<br>
						<span style="font-size: {{$print['exp_date_size']}}px">
							<b>@lang('product.exp_date'):</b>
							{{$page_product->exp_date}}
						</span>
						@if($barcode_details->is_continuous)
						<br>
						@endif
					@endif

					@if(!empty($print['packing_date']) && !empty($page_product->packing_date))
						<span style="font-size: {{$print['packing_date_size']}}px">
							<b>@lang('lang_v1.packing_date'):</b>
							{{$page_product->packing_date}}
						</span>
					@endif
					<br>

					{{-- Barcode --}}
					@php
						// 2"x1" labels: render the barcode at a higher NATIVE resolution so
						// the bars stay crisp at print size (CSS only constrains, never upscales).
						// Symbology (barcode_type) is unchanged — only widthFactor/native height
						// are raised. All other label sizes keep the standard (1,30) call.
						$barcode_height_in   = $is_2x1 ? ($barcode_details->height * 0.66) : ($barcode_details->height * 0.24);
						$barcode_width_factor   = $is_2x1 ? 4  : 1;
						$barcode_native_height  = $is_2x1 ? 60 : 30;
						// 2"x1": size the bar image in ABSOLUTE inches (not %), because the
						// flex container collapses percentage widths down to the text width.
						// Width = label width minus a ~0.12in quiet zone (white space) each side.
						$barcode_width_in = ($barcode_details->width * 1) - 0.24;
						$barcode_css = $is_2x1
							? 'display:block; width:'.$barcode_width_in.'in !important; height:'.$barcode_height_in.'in !important; margin:0.02in auto; background:#fff;'
							: 'display:block; max-width:90% !important; height:'.$barcode_height_in.'in !important;';
					@endphp
					<img style="{{ $barcode_css }}" src="data:image/png;base64,{{DNS1D::getBarcodePNG($page_product->sub_sku, $page_product->barcode_type, $barcode_width_factor, $barcode_native_height, array(0, 0, 0), false)}}">
					<span style="font-size: {{ $is_2x1 ? 9 : 10 }}px !important">
						{{ $page_product->sub_sku }}
					</span>
				</div>
			</div>
			@endif

		</td>

	@if($loop->iteration % $barcode_details->stickers_in_one_row == 0)
		</tr>
	@endif
@endforeach
</table>

<style type="text/css">

@if(!empty($is_nivessa_2x2_page))
	table{ border-spacing: 0 !important; border-collapse: collapse !important; }
	td{ border: none !important; padding: 0 !important; }
@else
	td{
		border: 1px dotted lightgray;
	}
@endif
	@media print{

		/* Keep the dotted sticker outline on-screen only; never print it. */
		td{
			border: none !important;
		}

		table{
			page-break-after: always;
		}

		
		@page {
		size: {{$paper_width}}in {{$paper_height}}in;

		/*width: {{$barcode_details->paper_width}}in !important;*/
		/*height:@if($barcode_details->paper_height != 0){{$barcode_details->paper_height}}in !important @else auto @endif;*/
		margin-top: {{$margin_top}}in !important;
		margin-bottom: {{$margin_top}}in !important;
		margin-left: {{$margin_left}}in !important;
		margin-right: {{$margin_left}}in !important;
	}
	}
</style>