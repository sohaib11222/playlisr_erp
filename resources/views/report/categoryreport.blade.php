@extends('layouts.app')
@section('title', __('Category Sales Report'))

@section('content')
    <link href="//code.jquery.com/ui/1.11.2/themes/smoothness/jquery-ui.css" rel="stylesheet" />
    <!-- Content Header (Page header) -->
     <style>
        .red{
            background-color: red;
            padding: 6% 8%;
        }
        </style>
            <style>
        .green{
            background-color: green;
            padding: 6% 8%;
        }
        .yellow{
            background-color: orange;
            padding: 4% 5%;
            font-size: 10px;
        }
        #replenishment_report_div{
            display:none;
        }
        .second_line{
            padding-top: 8%;
            display: block;
        }
        #replenishment_report, #replenishment_report thead tr th, #replenishment_report tfoot tr th{
            text-align:center;
        }
        .box{
            overflow:scroll;
        }
        #replenishment_report_wrapper{
            overflow-x:scroll;
        }
        .red_color{
            color:red;
        }
        .green_color{
            color:green;
        }
        .yellow_color{
            color:blue;
        }
        </style>
    <!-- Content Header (Page header) -->
    <section class="content-header" style="padding:2% 2%">
        <h3>Category Sales Report</h3>
    </section>
    <div style="padding:0% 2%">
    <!-- Main content -->
    <section class="content box box-primary" 
    @component('components.widget', ['class' => 'box-primary'])
        <div>
            <div class="row">
            <div class="col-md-4">
                    <label>Select Taxanomy</label>
                    <select class="form-control select2"  name="taxonomy" id="taxonomy">
                     <option value="1">Category</option>
                     <option value="2">Sub Category</option>
                     <option value="3">Brand</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Select Date</label>
                    <input placeholder="Select a date range" class="form-control" readonly="" name="ir_purchase_date_filter_new" type="text" id="ir_purchase_date_filter_new" fdprocessedid="xu2bv4">
                </div>
                <div class="col-md-4">
                    <label>Select Location</label>
                    <select class="form-control select2" name="supplier" id="location">
                        @foreach($business_locations as $key => $business_location)
                            <option value="{{$key}}">{{$business_location}}</option>
                        @endforeach
                    </select>
                </div>
            </div>
                <br>
            <button type="submit" class="btn btn-primary" id="view_forcasting_report"><i class="fas fa-eye"></i> View</button>
        </div>
    @endcomponent

 

    </section>
    <div style="padding:0% 2%" >
    <section class="content box box-primary"  id="replenishment_report_div" 
    @component('components.widget', ['class' => 'box-primary'])  
    <table class="table table-bordered table-striped" id="replenishment_report" style="width:100%">
    <thead>
        <tr>
            <th id="taxonomy_name">Category</th>
            <th>Available Quantity </th>
            <th>Total Cost</th>
            <th>Gross Quantity Sold</th>
            <th>Net Quantity Sold</th>
            <th>Total Sales</th>
        </tr>
    </thead>
    <tfoot>
        <tr>
            <th>Total:</th>
            <th id="available_total_qty"></th>
            <th id="available_total_cost"></th>
            <th id="total_qty_sold"></th>
            <th ></th>
            <th></th>
        </tr>
    </tfoot>
    </table>
    @endcomponent
    </section>
 

</div>
   @endsection
@section('javascript')
    <script src="{{ asset('js/report.js?v=' . $asset_v) }}"></script>
    <script>
        var startOfTheMonth = moment().startOf('month');
    var today = moment();

      // Calculate relevant dates
      var startOfTheMonth = moment().startOf('month');
    var endOfTheMonth = moment().endOf('month');
    var today = moment();
    var yesterday = moment().subtract(1, 'days');
    var startOfLastMonth = moment().subtract(1, 'month').startOf('month');
    var endOfLastMonth = moment().subtract(1, 'month').endOf('month');
    var startOfTheYear = moment().startOf('year');
    var endOfTheYear = moment().endOf('year');
    var startOfLastYear = moment().subtract(1, 'year').startOf('year');
    var endOfLastYear = moment().subtract(1, 'year').endOf('year');

    // Initialize the daterangepicker
    $('input#ir_purchase_date_filter_new').daterangepicker({
        startDate: startOfTheMonth,
        endDate: today,
        ranges: {
            'Today': [moment(), moment()],
            'Yesterday': [yesterday, yesterday],
            'Last 7 Days': [moment().subtract(6, 'days'), today],
            'Last 30 Days': [moment().subtract(29, 'days'), today],
            'This Month': [startOfTheMonth, endOfTheMonth],
            'Last Month': [startOfLastMonth, endOfLastMonth],
            'This Year': [startOfTheYear, endOfTheYear],
            'Last Year': [startOfLastYear, endOfLastYear]
        },
        locale: {
            format: 'DD-MM-YYYY'
        }
    });
        var i = 1;
       var replenishment_report;
        $("#view_forcasting_report").click(function(){


        if (typeof replenishment_report === 'undefined') {
            set_header_name();
            $("#replenishment_report_div").css('display', "block");
            replenishment_report = $('#replenishment_report').DataTable({
        processing: true,
        serverSide: true,
        "aaSorting": [],
        ajax: {
            url: '/reports/category-sales-report',
            "data": function ( d ) {
                if ($('#ir_purchase_date_filter_new').val()) {
                    d.start_date = $('input#ir_purchase_date_filter_new')
                            .data('daterangepicker')
                            .startDate.format('YYYY-MM-DD');
                    d.end_date = $('input#ir_purchase_date_filter_new')
                            .data('daterangepicker')
                            .endDate.format('YYYY-MM-DD');
                    }
                    d.location = $('#location').val();
                    d.taxonomy = $('#taxonomy').val();
            }
        },
        columns: [
            { data: 'name' },
            { data: 'total_quantity_available' , "searchable": false},
            { data: 'total_cost_available' , "searchable": false},
            { data: 'total_quantity_sold' , "searchable": false, "visible":false},
            { data: 'total_quantity_sold' , "searchable": false},
            { data: 'total_net_sales_rps' , "searchable": false},
            
        ],
        footerCallback: function (row, data, start, end, display) {
        var api = this.api().ajax.json().footer;
        $('#available_total_qty').text(api.sumTotalOrders);
        $('#available_total_cost').text(api.totalCost);
        $('#total_net_sales_rps_final').text(api.total_net_sales_rps_final);
        // $('#footer-total-dispatched').text(api.sumTotalDispatched);
        // $('#footer-total-cancelled').text(api.sumTotalCancelled);
        // $('#footer-total-delivery_failed').text(api.sumTotalDeliveryFailed);
        // $('#footer-total-sale_return').text(api.sumTotalSaleReturn);
        // $('#footer-total-pending').text(api.sumTotalSalePending);
        // $('#footer-total-total_logistics_returnd_to_shipper').text(api.sumTotalSaleLogisticsReturned);
        // $('#footer-total-total_logistics_delivered').text(api.sumTotalSaleLogisticsDelivered);
    }
    });
        }else{
            replenishment_report.ajax.reload();
            set_header_name()
        }

           });
        function set_header_name(){
            if($("#taxonomy").val() == 1){
                        $("#taxonomy_name").text("Category");
                    }else if($("#taxonomy").val() == 2){
                        $("#taxonomy_name").text("Sub Category");
                    }else{
                        $("#taxonomy_name").text("Brand");
                    }
        }
    </script>
@endsection