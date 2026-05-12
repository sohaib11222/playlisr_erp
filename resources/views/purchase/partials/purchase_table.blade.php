@php
    $can_bulk_update_purchase_status = auth()->user()->can('purchase.update') || auth()->user()->can('purchase.update_status');
@endphp

@if($can_bulk_update_purchase_status)
    <div class="row" style="margin-bottom: 10px;">
        <div class="col-sm-3">
            {!! Form::select('bulk_purchase_status', $orderStatuses, null, ['class' => 'form-control select2', 'id' => 'bulk_purchase_status', 'placeholder' => __('messages.please_select')]) !!}
        </div>
        <div class="col-sm-3">
            <button type="button" class="btn btn-primary" id="bulk_update_purchase_status">
                <i class="fas fa-edit"></i> @lang('lang_v1.update_status')
            </button>
        </div>
    </div>
@endif

<table class="table table-bordered table-striped ajax_view" id="purchase_table" style="width: 100%;">
    <thead>
        <tr>
            @if($can_bulk_update_purchase_status)
                <th>
                    <input type="checkbox" id="select_all_purchases">
                </th>
            @endif
            <th>@lang('messages.action')</th>
            <th>@lang('messages.date')</th>
            <th>@lang('purchase.ref_no')</th>
            <th>@lang('purchase.location')</th>
            <th>@lang('purchase.supplier')</th>
            <th>@lang('purchase.purchase_status')</th>
            <th>@lang('purchase.payment_status')</th>
            <th>@lang('purchase.grand_total')</th>
            <th>@lang('purchase.payment_due') &nbsp;&nbsp;<i class="fa fa-info-circle text-info no-print" data-toggle="tooltip" data-placement="bottom" data-html="true" data-original-title="{{ __('messages.purchase_due_tooltip')}}" aria-hidden="true"></i></th>
            <th>@lang('lang_v1.added_by')</th>
        </tr>
    </thead>
    <tfoot>
        <tr class="bg-gray font-17 text-center footer-total">
            <td colspan="{{ $can_bulk_update_purchase_status ? 6 : 5 }}"><strong>@lang('sale.total'):</strong></td>
            <td class="footer_status_count"></td>
            <td class="footer_payment_status_count"></td>
            <td class="footer_purchase_total"></td>
            <td class="text-left"><small>@lang('report.purchase_due') - <span class="footer_total_due"></span><br>
            @lang('lang_v1.purchase_return') - <span class="footer_total_purchase_return_due"></span>
            </small></td>
            <td></td>
        </tr>
    </tfoot>
</table>