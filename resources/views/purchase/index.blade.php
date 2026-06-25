@extends('layouts.app')
@section('title', __('purchase.purchases'))

@section('content')

@include('purchase._posv2_skin')
<style>
/* Compact + theme the Add/Edit Payment modal so it isn't a sprawling wall of
   oversized labels. Scoped to the payment modals only. */
body.pos-v2 .payment_modal .modal-dialog, body.pos-v2 .edit_payment_modal .modal-dialog { width:700px; max-width:95%; }
body.pos-v2 .payment_modal .modal-content, body.pos-v2 .edit_payment_modal .modal-content { border-radius:14px; font-family:"Inter Tight",system-ui,sans-serif; }
body.pos-v2 .payment_modal .modal-title, body.pos-v2 .edit_payment_modal .modal-title { font-weight:700; font-size:18px; }
body.pos-v2 .payment_modal label, body.pos-v2 .edit_payment_modal label { font-size:12px !important; font-weight:600 !important; color:#5a5145; margin-bottom:4px; line-height:1.3; }
body.pos-v2 .payment_modal .well, body.pos-v2 .edit_payment_modal .well { padding:10px 12px; margin-bottom:12px; background:var(--pos-surface-2); border:1px solid var(--pos-line); border-radius:10px; box-shadow:none; font-size:13px; line-height:1.45; }
body.pos-v2 .payment_modal .well strong, body.pos-v2 .edit_payment_modal .well strong { font-weight:700; font-size:13px; }
body.pos-v2 .payment_modal .form-control, body.pos-v2 .edit_payment_modal .form-control { border:1px solid var(--pos-line-2); border-radius:9px; height:auto; padding:8px 10px; box-shadow:none; font-size:14px; }
body.pos-v2 .payment_modal .form-control:focus, body.pos-v2 .edit_payment_modal .form-control:focus { border-color:var(--pos-accent-deep); box-shadow:0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .payment_modal .input-group-addon, body.pos-v2 .edit_payment_modal .input-group-addon { background:var(--pos-surface-2); border:1px solid var(--pos-line-2); color:#8a8070; border-radius:9px 0 0 9px; }
body.pos-v2 .payment_modal .input-group .form-control, body.pos-v2 .edit_payment_modal .input-group .form-control { border-radius:0 9px 9px 0; }
body.pos-v2 .payment_modal .btn-primary, body.pos-v2 .edit_payment_modal .btn-primary { background:var(--pos-accent); border:1px solid var(--pos-accent-deep); color:var(--pos-accent-text); border-radius:10px; font-weight:700; }
body.pos-v2 .payment_modal .help-block, body.pos-v2 .edit_payment_modal .help-block { font-size:11px; color:#8a8070; }
</style>

<!-- Content Header (Page header) -->
<section class="content-header no-print">
    <h1>@lang('purchase.purchases')
        <small></small>
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content no-print">
    @component('components.filters', ['title' => __('report.filters')])
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_location_id',  __('purchase.business_location') . ':') !!}
                {!! Form::select('purchase_list_filter_location_id', $business_locations, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_supplier_id',  __('purchase.supplier') . ':') !!}
                {!! Form::select('purchase_list_filter_supplier_id', $suppliers, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_status',  __('purchase.purchase_status') . ':') !!}
                {!! Form::select('purchase_list_filter_status', $orderStatuses, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_payment_status',  __('purchase.payment_status') . ':') !!}
                {!! Form::select('purchase_list_filter_payment_status', ['paid' => __('lang_v1.paid'), 'due' => __('lang_v1.due'), 'partial' => __('lang_v1.partial'), 'overdue' => __('lang_v1.overdue')], null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_date_range', __('report.date_range') . ':') !!}
                {!! Form::text('purchase_list_filter_date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
            </div>
        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => __('purchase.all_purchases')])
        @can('purchase.create')
            @slot('tool')
                <div class="box-tools">
                    <a class="btn btn-block btn-primary" href="{{action('PurchaseController@create')}}">
                    <i class="fa fa-plus"></i> @lang('messages.add')</a>
                </div>
            @endslot
        @endcan
        @include('purchase.partials.purchase_table')
    @endcomponent

    <div class="modal fade product_modal" tabindex="-1" role="dialog" 
    	aria-labelledby="gridSystemModalLabel">
    </div>

    <div class="modal fade payment_modal" tabindex="-1" role="dialog" 
        aria-labelledby="gridSystemModalLabel">
    </div>

    <div class="modal fade edit_payment_modal" tabindex="-1" role="dialog" 
        aria-labelledby="gridSystemModalLabel">
    </div>

    @include('purchase.partials.update_purchase_status_modal')

</section>

<section id="receipt_section" class="print_section"></section>

@include('help.partials.tour_button', ['tourSteps' => \App\Help\Catalog::tour('purchase.index')])

<!-- /.content -->
@stop
@section('javascript')
<script src="{{ asset('js/purchase.js?v=' . $asset_v) }}"></script>
<script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
<script>
    var can_bulk_update_purchase_status = {{ (auth()->user()->can('purchase.update') || auth()->user()->can('purchase.update_status')) ? 'true' : 'false' }};

        //Date range as a button
    $('#purchase_list_filter_date_range').daterangepicker(
        dateRangeSettings,
        function (start, end) {
            $('#purchase_list_filter_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
           purchase_table.ajax.reload();
        }
    );
    $('#purchase_list_filter_date_range').on('cancel.daterangepicker', function(ev, picker) {
        $('#purchase_list_filter_date_range').val('');
        purchase_table.ajax.reload();
    });

    $(document).on('click', '.update_status', function(e){
        e.preventDefault();
        $('#update_purchase_status_form').find('#status').val($(this).data('status'));
        $('#update_purchase_status_form').find('#purchase_id').val($(this).data('purchase_id'));
        $('#update_purchase_status_modal').modal('show');
    });

    $(document).on('submit', '#update_purchase_status_form', function(e){
        e.preventDefault();
        var form = $(this);
        var data = form.serialize();

        $.ajax({
            method: 'POST',
            url: $(this).attr('action'),
            dataType: 'json',
            data: data,
            beforeSend: function(xhr) {
                __disable_submit_button(form.find('button[type="submit"]'));
            },
            success: function(result) {
                if (result.success == true) {
                    $('#update_purchase_status_modal').modal('hide');
                    toastr.success(result.msg);
                    purchase_table.ajax.reload();
                    $('#update_purchase_status_form')
                        .find('button[type="submit"]')
                        .attr('disabled', false);
                } else {
                    toastr.error(result.msg);
                }
            },
        });
    });
</script>
	
@endsection