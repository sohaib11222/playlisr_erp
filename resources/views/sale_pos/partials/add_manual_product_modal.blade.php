<div class="modal fade" id="add_manual_product_modal" tabindex="-1" role="dialog" 
	aria-labelledby="gridSystemModalLabel">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>

				<h4 class="modal-title">
					Add Manual Products
				</h4>
			</div>
			<div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="manual_products_table">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="20%">Product Name *</th>
                                <th width="15%">Artist</th>
                                <th width="30%">Category / Sub Category *</th>
                                <th width="10%">Price *</th>
                                <th width="5%">Action</th>
                            </tr>
                        </thead>
                        <tbody id="manual_products_container">
                            <tr class="manual_product_row" data-row="0">
                                <td>1</td>
                                <td>
                                    <input type="text"
                                           name="products[0][name]"
                                           class="form-control manual-product-name-input"
                                           required
                                           minlength="3"
                                           maxlength="150"
                                           placeholder="e.g. Airheads candy, Used Sharpie, Soda can">
                                    <small class="text-muted" style="display:block;margin-top:4px;">
                                        Describe what was sold — no lazy names (no "manual", "item", "misc", "n/a").
                                    </small>
                                </td>
                                <td>
                                    <input type="text" 
                                           name="products[0][artist]" 
                                           class="form-control artist-autocomplete-input" 
                                           placeholder="Artist">
                                </td>
                                <td>
                                    @php
                                        $categoryCombos = [];
                                        if (!empty($categories) && is_array($categories)) {
                                            foreach ($categories as $cat) {
                                                if (!empty($cat['sub_categories'])) {
                                                    foreach ($cat['sub_categories'] as $subCat) {
                                                        $categoryCombos[] = [
                                                            'id' => $cat['id'] . '_' . $subCat['id'],
                                                            'category_id' => $cat['id'],
                                                            'sub_category_id' => $subCat['id'],
                                                            'label' => $cat['name'] . ' > ' . $subCat['name'],
                                                        ];
                                                    }
                                                } else {
                                                    $categoryCombos[] = [
                                                        'id' => $cat['id'] . '_0',
                                                        'category_id' => $cat['id'],
                                                        'sub_category_id' => null,
                                                        'label' => $cat['name'],
                                                    ];
                                                }
                                            }
                                        } elseif (!empty($categoriesForDropdown)) {
                                            foreach ($categoriesForDropdown as $key => $value) {
                                                $categoryCombos[] = [
                                                    'id' => $key . '_0',
                                                    'category_id' => $key,
                                                    'sub_category_id' => null,
                                                    'label' => $value,
                                                ];
                                            }
                                        }
                                    @endphp

                                    <div style="display:flex; gap:4px; align-items:center;">
                                        <select name="products[0][category_combo]" 
                                                class="form-control select2 manual_category_combo" 
                                                data-row="0"
                                                required>
                                            <option value="">Please select</option>
                                            @foreach($categoryCombos as $combo)
                                                <option value="{{ $combo['id'] }}"
                                                        data-category-id="{{ $combo['category_id'] }}"
                                                        data-sub-category-id="{{ $combo['sub_category_id'] ?? '' }}">
                                                    {{ $combo['label'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="btn btn-primary btn-xs manual-copy-down" data-class="manual_category_combo" data-row-index="0" title="Copy Down">
                                            <i class="fa fa-arrow-down"></i>
                                        </button>
                                    </div>
                                    {{-- Hidden fields actually sent to backend --}}
                                    <input type="hidden" name="products[0][category_id]" class="manual_category_id" data-row="0">
                                    <input type="hidden" name="products[0][sub_category_id]" class="manual_sub_category_id" data-row="0">
                                </td>
                                <td>
                                    <input type="text" 
                                           name="products[0][price]" 
                                           class="form-control input_number" 
                                           required 
                                           placeholder="0.00">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-danger btn-sm remove_product_row" style="display: none;">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="row">
                    <div class="col-md-12 text-center">
                        <button type="button" class="btn btn-success" id="add_another_product">
                            <i class="fa fa-plus"></i> Add Another Product
                        </button>
                    </div>
                </div>
                
                <div class="row" style="margin-top: 15px;">
                    <div class="col-md-12 text-right">
                        <strong>Subtotal: <span id="manual_products_subtotal">$0.00</span></strong>
                    </div>
                </div>
			</div>
			<div class="modal-footer">
                <button type="button" class="btn btn-primary" id="add_manual_product_button">Add Products</button>
			    <button type="button" class="btn btn-default" data-dismiss="modal">@lang('messages.close')</button>
			</div>
		</div>
	</div>
</div>
