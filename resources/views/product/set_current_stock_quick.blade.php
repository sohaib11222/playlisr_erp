@extends('layouts.app')

@section('title', __('product.set_current_stock'))

@section('content')
<section class="content-header">
    <h1>@lang('product.set_current_stock')</h1>
    <p class="help-block" style="margin-top: 5px;">
        @lang('product.set_current_stock_help')
    </p>
</section>

<section class="content">
    @component('components.widget', ['class' => 'box-primary'])
        <div class="row">
            <div class="col-sm-12">
                <h4>{{ $product->name }}</h4>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <form id="set_current_stock_form_quick" action="{{ url('products/' . $product->id . '/set-current-stock') }}" method="post">
                    @csrf
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>@lang('business.location')</th>
                                @if($product->type == 'variable')
                                    <th>@lang('product.variation_name')</th>
                                @endif
                                <th>@lang('report.current_stock')</th>
                                <th>@lang('product.set_to_quantity')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $product_locations = $product->product_locations;
                                $all_variations = $product->product_variations->flatMap(function ($pv) { return $pv->variations; });
                            @endphp
                            @if($product_locations->isEmpty() || $all_variations->isEmpty())
                                <tr>
                                    <td colspan="{{ $product->type == 'variable' ? 4 : 3 }}" class="text-center text-muted">
                                        @lang('product.set_current_stock') — @lang('business.business_locations') / @lang('product.variations') required.
                                    </td>
                                </tr>
                            @else
                                @foreach($product_locations as $loc)
                                    @foreach($all_variations as $var)
                                        @php
                                            $vld = $var->variation_location_details->where('location_id', $loc->id)->first();
                                            $current_qty = $vld ? (float) $vld->qty_available : 0;
                                            $current_qty_int = (int) round($current_qty);
                                        @endphp
                                        <tr>
                                            @if($loop->first)
                                                <td rowspan="{{ $all_variations->count() }}">{{ $loc->name }}</td>
                                            @endif
                                            @if($product->type == 'variable')
                                                <td>{{ $var->name ?? $var->sub_sku }}</td>
                                            @endif
                                            <td>{{ $current_qty_int }}</td>
                                            <td>
                                                <input type="number" min="0" step="1" name="current_stock[{{ $loc->id }}][{{ $var->id }}]" value="{{ $current_qty_int }}" class="form-control input-sm" style="width: 100px;">
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-primary btn-sm" id="btn_set_current_stock_quick">
                        @lang('messages.update') @lang('product.set_current_stock')
                    </button>
                    <a href="{{ action('ProductController@index') }}" class="btn btn-default btn-sm">
                        @lang('messages.close')
                    </a>
                </form>
            </div>
        </div>
    @endcomponent
</section>
@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function () {
        function runSetCurrentStockQuick() {
            var $form = $('#set_current_stock_form_quick');
            var $btn = $('#btn_set_current_stock_quick');
            var currentStock = {};
            $form.find('input[name^="current_stock["]').each(function () {
                var name = $(this).attr('name');
                var match = name.match(/current_stock\[(\d+)\]\[(\d+)\]/);
                if (match) {
                    var locId = match[1], varId = match[2], val = $(this).val();
                    if (typeof currentStock[locId] === 'undefined') currentStock[locId] = {};
                    currentStock[locId][varId] = val;
                }
            });
            var payload = {
                _token: $form.find('input[name=\"_token\"]').val(),
                current_stock: currentStock
            };
            $btn.prop('disabled', true);
            $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function (data) {
                    if (data.success) {
                        toastr.success(data.msg + (data.updated_count ? ' (' + data.updated_count + ')' : ''));
                        // After a short delay, return to product list
                        setTimeout(function () {
                            window.location.href = "{{ action('ProductController@index') }}";
                        }, 800);
                    } else {
                        toastr.error(data.msg || '{{ __("messages.something_went_wrong") }}');
                    }
                },
                error: function () {
                    toastr.error('{{ __("messages.something_went_wrong") }}');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        }

        $('#set_current_stock_form_quick').on('submit', function (e) {
            e.preventDefault();
            runSetCurrentStockQuick();
        });
        $('#btn_set_current_stock_quick').on('click', function (e) {
            e.preventDefault();
            runSetCurrentStockQuick();
        });
    });
</script>
@endsection

