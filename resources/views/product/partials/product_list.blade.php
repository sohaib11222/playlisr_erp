@php 
    // Columns: select, actions, store, product, artist, category, subcategory, price, current stock, units sold, sku, last updated, created by
    $colspan = 13;
    $custom_labels = json_decode(session('business.custom_labels'), true);
@endphp
<table class="table table-bordered table-striped ajax_view hide-footer" id="product_table">
    <thead>
        <tr>
            <th><input type="checkbox" id="select-all-row" data-table-id="product_table"></th>
            <th>@lang('messages.action')</th>
            <th>@lang('purchase.business_location')</th>
            <th>@lang('sale.product')</th>
            <th>Artist</th>
            <th>@lang('product.category')</th>
            <th>@lang('product.sub_category')</th>
            @can('view_purchase_price')
                <th>@lang('lang_v1.purchase_price')</th>
            @endcan
            @can('access_default_selling_price')
                <th>@lang('lang_v1.selling_price')</th>
            @endcan
            <th>@lang('report.current_stock')</th>
            <th>Units Sold</th>
            <th>@lang('product.sku')</th>
            <th>Last updated at</th>
            <th>Created by</th>
        </tr>
    </thead>
    <tfoot>
        <tr>
            <td colspan="{{$colspan}}">
            <div style="display: flex; width: 100%;">
                @can('product.delete')
                    {!! Form::open(['url' => action('ProductController@massDestroy'), 'method' => 'post', 'id' => 'mass_delete_form' ]) !!}
                    {!! Form::hidden('selected_rows', null, ['id' => 'selected_rows']) !!}
                    {!! Form::submit(__('lang_v1.delete_selected'), array('class' => 'btn btn-xs btn-danger', 'id' => 'delete-selected')) !!}
                    {!! Form::close() !!}
                @endcan


                    @if($is_admin || auth()->user()->can('product.update'))

                        @if(config('constants.enable_product_bulk_edit'))
                            &nbsp;
                            {!! Form::open(['url' => action('ProductController@bulkEdit'), 'method' => 'post', 'id' => 'bulk_edit_form' ]) !!}
                            {!! Form::hidden('selected_products', null, ['id' => 'selected_products_for_edit']) !!}
                            <button type="submit" class="btn btn-xs btn-primary" id="edit-selected"> <i class="fa fa-edit"></i>{{__('lang_v1.bulk_edit')}}</button>
                            {!! Form::close() !!}
                        @endif
                        &nbsp;
                        <button type="button" class="btn btn-xs btn-success update_product_location" data-type="add">@lang('lang_v1.add_to_location')</button>
                        &nbsp;
                        <button type="button" class="btn btn-xs bg-navy update_product_location" data-type="remove">@lang('lang_v1.remove_from_location')</button>
                    @endif

                &nbsp;
                {!! Form::open(['url' => action('ProductController@massDeactivate'), 'method' => 'post', 'id' => 'mass_deactivate_form' ]) !!}
                {!! Form::hidden('selected_products', null, ['id' => 'selected_products']) !!}
                {!! Form::submit(__('lang_v1.deactivate_selected'), array('class' => 'btn btn-xs btn-warning', 'id' => 'deactivate-selected')) !!}
                {!! Form::close() !!} @show_tooltip(__('lang_v1.deactive_product_tooltip'))
                &nbsp;
                {!! Form::open(['url' => route('products.bulkSendToPurchase'), 'method' => 'post', 'id' => 'bulk_send_to_purchase_form' ]) !!}
                {!! Form::hidden('selected_products_for_purchase', null, ['id' => 'selected_products_for_purchase']) !!}
                <button type="submit" class="btn btn-xs btn-success" id="send-to-purchase-selected">
                    <i class="fa fa-arrow-right"></i> Send to Add Purchase
                </button>
                {!! Form::close() !!}
                &nbsp;
                @if($is_woocommerce)
                    <button type="button" class="btn btn-xs btn-warning toggle_woocomerce_sync">
                        @lang('lang_v1.woocommerce_sync')
                    </button>
                @endif
                </div>
            </td>
        </tr>
    </tfoot>
</table>
