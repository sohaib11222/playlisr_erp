<span id="view_contact_page"></span>
<div class="row">
    <div class="col-md-4">
        @include('contact.contact_basic_info')
    </div>
    <div class="col-md-4">
        @include('contact.contact_more_info')
    </div>
    @if( $contact->type != 'customer')
        <div class="col-md-4">
            @include('contact.contact_tax_info')
        </div>
    @else
        <div class="col-md-4"></div>
    @endif
</div>
<div class="row" style="margin-top: 12px;">
    <div class="col-md-12">
        @if( $contact->type == 'supplier' || $contact->type == 'both')
            @if(($contact->total_purchase - $contact->purchase_paid) > 0)
                <a href="{{action('TransactionPaymentController@getPayContactDue', [$contact->id])}}?type=purchase" class="pay_purchase_due btn btn-primary btn-sm"><i class="fas fa-money-bill-alt" aria-hidden="true"></i> @lang("contact.pay_due_amount")</a>
            @endif
        @endif
        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#add_discount_modal" style="margin-left: 8px;">@lang('lang_v1.add_discount')</button>
    </div>
</div>