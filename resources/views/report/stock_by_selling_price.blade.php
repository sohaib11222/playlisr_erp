@extends('layouts.app')
@section('title', 'Stock by Sell Price')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Stock by Sell Price
        <small>Stock valued at selling price.</small>
    </h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="print_section"><h2>{{ session()->get('business.name') }} - Stock by Sell Price</h2></div>

    <div class="row no-print">
        <div class="col-md-3 col-md-offset-7 col-xs-6">
            <div class="input-group">
                <span class="input-group-addon bg-light-blue"><i class="fa fa-map-marker"></i></span>
                <select class="form-control select2" id="sbsp_location_filter">
                    @foreach($business_locations as $key => $value)
                        <option value="{{ $key }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="col-md-2 col-xs-6">
            <div class="form-group pull-right">
                <div class="input-group">
                    <button type="button" class="btn btn-primary" id="sbsp_date_filter">
                        <span>
                            <i class="fa fa-calendar"></i> {{ __('messages.filter_by_date') }}
                        </span>
                        <i class="fa fa-caret-down"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <div class="info-box bg-light-blue">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Opening Stock (by sell price)</span>
                    <span class="info-box-number"><h3 id="opening_stock_by_sp" class="mb-0 mt-0">--</h3></span>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Closing Stock (by sell price)</span>
                    <span class="info-box-number"><h3 id="closing_stock_by_sp" class="mb-0 mt-0">--</h3></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row no-print">
        <div class="col-sm-12">
            <button type="button" class="btn btn-primary pull-right"
                aria-label="Print" onclick="window.print();"><i class="fa fa-print"></i> @lang( 'messages.print' )</button>
        </div>
    </div>
</section>
<!-- /.content -->
@stop

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        function refreshStockBySellingPrice() {
            var picker = $('#sbsp_date_filter').data('daterangepicker');
            if (!picker) {
                return;
            }
            updateStockBySellingPrice({
                start_date: picker.startDate.format('YYYY-MM-DD'),
                end_date: picker.endDate.format('YYYY-MM-DD'),
                location_id: $('#sbsp_location_filter').val()
            });
        }

        $('#sbsp_date_filter').daterangepicker(dateRangeSettings, function(start, end) {
            $('#sbsp_date_filter span').html(
                start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
            );
            refreshStockBySellingPrice();
        });

        $('#sbsp_location_filter').change(function() {
            refreshStockBySellingPrice();
        });

        // Initial load with the default date range.
        refreshStockBySellingPrice();
    });
</script>
@endsection
