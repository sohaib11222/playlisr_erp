@extends('layouts.app')
@section('title', __( 'sale.list_pos'))

@section('content')
{{-- Inject the v2 Nivessa palette tokens (already defined scoped to
     body.pos-v2) and tag the body so the styles apply here too. --}}
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2','pos-list-v2');</script>

<style>
    /* Nivessa cream palette on the POS list page — reuses the same tokens
       defined by _redesign_v2 but scoped to .pos-list-v2 so we don't touch
       the checkout screen's layout rules. */
    body.pos-list-v2 .content-header h1 {
        font-family: "Inter Tight", system-ui, sans-serif;
        font-size: 20px; font-weight: 700; color: #1F1B16; letter-spacing: -.01em;
    }
    body.pos-list-v2 section.content { background: #FAF6EE; }
    body.pos-list-v2 .box, body.pos-list-v2 .box.box-primary {
        background: #FFFFFF; border: 1px solid #ECE3CF;
        border-radius: 10px; box-shadow: 0 1px 2px rgba(31,27,22,.06);
    }
    body.pos-list-v2 .box-header { border-bottom: 1px solid #ECE3CF; }
    body.pos-list-v2 .box-title { color: #1F1B16; font-weight: 700; }
    body.pos-list-v2 .form-control,
    body.pos-list-v2 .select2-selection {
        border: 1px solid #DFD2B3 !important;
        border-radius: 7px !important;
        color: #1F1B16;
    }
    body.pos-list-v2 .form-control:focus {
        border-color: #F0DC7A !important;
        box-shadow: 0 0 0 3px rgba(255, 242, 179, .5) !important;
    }
    body.pos-list-v2 .control-label { color: #5A5045; font-weight: 600; font-size: 13px; }
    body.pos-list-v2 .btn-primary {
        background: #1F1B16; border-color: #1F1B16; color: #FAF6EE;
        border-radius: 7px; font-weight: 600;
    }
    body.pos-list-v2 .btn-primary:hover,
    body.pos-list-v2 .btn-primary:focus { background: #0F0A06; border-color: #0F0A06; color: #FAF6EE; }
    body.pos-list-v2 table.dataTable thead th {
        background: #F7F1E3; color: #5A5045;
        font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
        border-bottom: 1px solid #DFD2B3 !important;
    }
    body.pos-list-v2 table.dataTable tbody td { color: #1F1B16; }

    /* Whatnot-only checkbox chip — mustard pill styled like the POS-create
       Mark as Whatnot toggle for visual consistency. */
    .pos-list-whatnot-chip {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 6px 14px;
        background: #fff;
        border: 1px dashed #d1d5db;
        border-radius: 999px;
        font-size: 13px; font-weight: 500; color: #6b7280;
        cursor: pointer; user-select: none; margin: 0;
    }
    .pos-list-whatnot-chip input[type="checkbox"] { margin: 0; accent-color: #d4a92a; }
    .pos-list-whatnot-chip:hover { border-color: #9ca3af; color: #374151; }
    .pos-list-whatnot-chip.is-on {
        background: #f5ce3e;
        border: 1px solid #d4a92a;
        color: #2b1e16;
        font-weight: 700;
    }
</style>

<!-- Content Header (Page header) -->
<section class="content-header no-print">
    <h1>@lang('sale.pos_sale')
    </h1>
</section>

<!-- Main content -->
<section class="content no-print">
    @component('components.filters', ['title' => __('report.filters')])
        {{-- Only show the filters we want on the POS list. Payment status,
             shipping status, 'only subscriptions', and the whatnot-as-select
             are intentionally omitted (Sarah's 2026-04-21 cleanup). The
             whatnot filter is re-rendered below as a single checkbox. --}}
        @include('sell.partials.sell_list_filters', ['only' => [
            'sell_list_filter_location_id',
            'sell_list_filter_customer_id',
            'sell_list_filter_date_range',
            'created_by',
        ]])
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label>Text Search (recent transactions)</label>
                    <input type="text" id="pos_text_search" class="form-control" placeholder="Invoice, customer, mobile, notes...">
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label>&nbsp;</label>
                    <div>
                        <label class="pos-list-whatnot-chip" id="whatnot_only_chip">
                            <input type="checkbox" id="sell_list_filter_is_whatnot" name="sell_list_filter_is_whatnot" value="1">
                            <i class="fa fa-broadcast-tower" style="opacity:.7;"></i>
                            <span>Whatnot transactions only</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => __( 'sale.list_pos')])
        @can('sell.create')
            @slot('tool')
                <div class="box-tools">
                    <a class="btn btn-block btn-primary" href="{{action('SellPosController@create')}}">
                    <i class="fa fa-plus"></i> @lang('messages.add')</a>
                </div>
            @endslot
        @endcan
        @can('sell.view')
            <input type="hidden" name="is_direct_sale" id="is_direct_sale" value="0">
            @include('sale_pos.partials.sales_table')
        @endcan
    @endcomponent
</section>
<!-- /.content -->
<div class="modal fade payment_modal" tabindex="-1" role="dialog"
    aria-labelledby="gridSystemModalLabel">
</div>

<div class="modal fade edit_payment_modal" tabindex="-1" role="dialog"
    aria-labelledby="gridSystemModalLabel">
</div>

<div class="modal fade register_details_modal" tabindex="-1" role="dialog"
    aria-labelledby="gridSystemModalLabel">
</div>
<div class="modal fade close_register_modal" tabindex="-1" role="dialog"
    aria-labelledby="gridSystemModalLabel">
</div>

<!-- This will be printed -->
<!-- <section class="invoice print_section" id="receipt_section">
</section> -->


@stop

@section('javascript')
@include('sale_pos.partials.sale_table_javascript')
<script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
<script>
// Visual toggle for the whatnot chip + trigger DataTable reload.
// The DataTable ajax function reads #sell_list_filter_is_whatnot by name;
// keeping the id the same means the existing reload handler still works.
$(document).on('change', '#sell_list_filter_is_whatnot', function () {
    $('#whatnot_only_chip').toggleClass('is-on', this.checked);
});
</script>
@endsection
