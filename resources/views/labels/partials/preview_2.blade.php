<table align="center" style="border-spacing: {{$barcode_details->col_distance * 1}}in {{$barcode_details->row_distance * 1}}in; overflow: hidden !important;">
@foreach($page_products as $page_product)

	@if($loop->index % $barcode_details->stickers_in_one_row == 0)
		<!-- create a new row -->
		<tr>
		<!-- <columns column-count="{{$barcode_details->stickers_in_one_row}}" column-gap="{{$barcode_details->col_distance*1}}"> -->
	@endif
		<td align="center" valign="top" style="vertical-align: top;">
			@php
				$stickerW = $barcode_details->width * 1;
				$stickerH = $barcode_details->height * 1;
				// Barcode height: cap so long titles + date + code still fit (was 0.24 × sticker height — too tall on small labels)
				$barcodeImgH = min($stickerH * 0.17, 0.38);
			@endphp
			<div class="label-sticker-outer" style="box-sizing: border-box; width: {{ $stickerW }}in; height: {{ $stickerH }}in; padding: 2px 3px 3px; overflow: hidden; display: flex; flex-direction: column; align-items: center; justify-content: flex-start;">
				<div class="label-sticker-inner" style="width: 100%; max-width: 100%; text-align: center; line-height: 1.05; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; gap: 0; overflow: hidden; word-wrap: break-word; overflow-wrap: anywhere;">

					{{-- Business Name --}}
					@if(!empty($print['business_name']))
						<b style="display: block !important; font-size: {{$print['business_name_size']}}px; margin: 0; padding: 0; line-height: 1.1;">{{$business_name}}</b>
					@endif

					{{-- Product Name --}}
					@if(!empty($print['name']))
						<span style="display: block !important; font-size: {{$print['name_size']}}px; margin: 0; padding: 0; line-height: 1.08;">
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
						<span style="display: block !important; font-size: {{$print['variations_size']}}px; margin: 0; padding: 0; line-height: 1.08;">
							{{$page_product->product_variation_name}}:<b>{{$page_product->variation_name}}</b>
						</span>
					@endif
					
					{{-- Genre (no "Genre:" prefix — saves vertical space) --}}
					@if(!empty($print['price']))
						<span style="display: block !important; font-size: {{$print['name_size']}}px; margin: 0; padding: 0; line-height: 1.08;">
							<b>{{$page_product->sub_category}}</b>
						</span>

					{{-- Artist (no "Artist:" prefix) --}}
					@if(!empty($page_product->artist))
						<span style="display: block !important; font-size: {{$print['name_size']}}px; margin: 0; padding: 0; line-height: 1.08;">
							<b>{{$page_product->artist}}</b>
						</span>
					@endif
				@endif

				{{-- Bin Position --}}
				@if(!empty($page_product->bin_position))
					<span style="display: block !important; font-size: {{$print['name_size'] ?? 12}}px; font-weight: bold; margin: 0; padding: 0; line-height: 1.08;">
						{{ $page_product->bin_position }}
					</span>
				@endif

				{{-- Price (amount only — no "Price" label) --}}
					@if(!empty($print['price']))
					<span style="display: block; font-size: {{$print['price_size']}}px; font-weight: bold; margin: 0; padding: 0; line-height: 1.05;">
						{{session('currency')['symbol'] ?? ''}}
						@if($print['price_type'] == 'inclusive')
							{{@num_format($page_product->sell_price_inc_tax)}}
						@else
							{{@num_format($page_product->default_sell_price)}}
						@endif
					</span>
					@endif
					@if(!empty($print['exp_date']) && !empty($page_product->exp_date))
						<span style="display: block; font-size: {{$print['exp_date_size']}}px; margin: 0; padding: 0; line-height: 1.05;">
							{{$page_product->exp_date}}
						</span>
					@endif

					@if(!empty($print['packing_date']) && !empty($page_product->packing_date))
						<span style="display: block; font-size: {{$print['packing_date_size']}}px; margin: 0; padding: 0; line-height: 1.05;">
							{{$page_product->packing_date}}
						</span>
					@endif
					@if(array_key_exists('purchase_date', $print ?? []) && !empty($page_product->purchase_date))
						@php
							$purchaseDatePx = (int) ($print['purchase_date_size'] ?? 12);
							if ($purchaseDatePx < 10) { $purchaseDatePx = 10; }
							if ($purchaseDatePx > 36) { $purchaseDatePx = 36; }
						@endphp
						<span style="display: block; line-height: 1.05; margin: 0; padding: 0; font-size: {{ $purchaseDatePx }}px; font-weight: bold;">
							{{ $page_product->purchase_date }}
						</span>
					@endif

					{{-- Barcode + SKU: flex-shrink 0 so they stay readable; shorter bar image saves vertical space --}}
					<img class="label-barcode-img" style="margin-top: 1px; flex-shrink: 0; max-width: 92% !important; width: auto; height: {{ $barcodeImgH }}in !important; max-height: {{ $barcodeImgH }}in !important; object-fit: contain; display: block;" src="data:image/png;base64,{{DNS1D::getBarcodePNG($page_product->sub_sku, $page_product->barcode_type, 1,30, array(0, 0, 0), false)}}" alt="">
					<span style="font-size: 8px !important; line-height: 1 !important; margin: 0; padding: 0 0 1px; display: block;">
						{{ $page_product->sub_sku }}
					</span>
				</div>
			</div>
		
		</td>

	@if($loop->iteration % $barcode_details->stickers_in_one_row == 0)
		</tr>
	@endif
@endforeach
</table>

<style type="text/css">

	td{
		border: 1px dotted lightgray;
		vertical-align: top;
	}
	.label-sticker-outer,
	.label-sticker-inner {
		-webkit-print-color-adjust: exact;
		print-color-adjust: exact;
	}
	@media print{
		
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