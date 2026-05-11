@extends('layouts.app')
@section('title', __('report.expense_report'))

@section('content')

{{-- Expense Report — POS-create-style reskin to match the Items Report.
     Inter Tight font + items-report-layout.css + items-report-v2 body class. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="{{ asset('css/items-report-layout.css?v=' . $asset_v) }}">
<script>document.body.classList.add('items-report-v2');</script>

<section class="content-header">
    <h1>{{ __('report.expense_report') }} <small>QuickBooks-style transaction list, live-synced every 30 minutes.</small></h1>
</section>

<section class="content">

    @php
        $current_category_id = request('category');
        $categoryLink = function ($cat_id) {
            $q = request()->all();
            $q['category'] = $cat_id;
            return action('ReportController@getExpenseReport') . '?' . http_build_query($q);
        };
        $clearCategoryLink = function () {
            $q = request()->all();
            unset($q['category']);
            return action('ReportController@getExpenseReport') . '?' . http_build_query($q);
        };
        $exportUrl = action('ReportController@getExpenseReport') . '?' . http_build_query(array_merge(request()->all(), ['export' => 'csv']));

        $summary_total = 0;
        foreach ($expenses as $e) { $summary_total += (float) $e->total_expense; }
        $detail_total = 0;
        foreach ($detail as $d) {
            $detail_total += ($d->type === 'expense_refund' ? 1 : -1) * (float) $d->final_total;
        }
    @endphp

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action('ReportController@getExpenseReport'), 'method' => 'get']) !!}
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('location_id', __('purchase.business_location') . ':') !!}
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-map-marker"></i></span>
                                {!! Form::select('location_id', $business_locations, request('location_id'), ['class' => 'form-control select2', 'style' => 'width:100%']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('category_id', __('category.category') . ':') !!}
                            {!! Form::select('category', $categories, request('category'), ['placeholder' => __('report.all'), 'class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'category_id']); !!}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('trending_product_date_range', __('report.date_range') . ':') !!}
                            {!! Form::text('date_range', request('date_range'), ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'id' => 'trending_product_date_range', 'readonly']); !!}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label style="display:block;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">@lang('report.apply_filters')</button>
                    </div>
                {!! Form::close() !!}
            @endcomponent

            <div style="margin: 0 0 14px; display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                @if(!empty($current_category_id))
                    <a href="{{ $clearCategoryLink() }}" class="btn btn-default btn-sm">
                        <i class="fa fa-times"></i> Clear category filter
                    </a>
                @endif
                <a href="{{ $exportUrl }}" class="btn">
                    <i class="fa fa-file-excel"></i> Export filtered (CSV)
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Summary by category — click any row to filter the detail table below'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="expense_summary_table">
                        <thead>
                            <tr>
                                <th>@lang('expense.expense_categories')</th>
                                <th class="text-right">@lang('report.total_expense')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($expenses as $expense)
                                <tr>
                                    <td>
                                        <a href="{{ $categoryLink($expense->expense_category_id ?? '') }}">
                                            {{ $expense->category ?? __('report.others') }}
                                        </a>
                                    </td>
                                    <td class="text-right">
                                        <span class="display_currency" data-currency_symbol="true">{{ $expense->total_expense }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-center text-muted">No expenses in this window.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><strong>@lang('sale.total')</strong></td>
                                <td class="text-right">
                                    <strong><span class="display_currency" data-currency_symbol="true">{{ $summary_total }}</span></strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Transaction detail' . (!empty($current_category_id) ? ' — filtered to selected category' : '')])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="expense_detail_table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Num</th>
                                <th>Vendor</th>
                                <th>Memo</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($detail as $r)
                                @php
                                    $signed = $r->type === 'expense_refund' ? (float) $r->final_total : -1 * (float) $r->final_total;
                                    $vendor = '';
                                    $memo = '';
                                    $tx_type_label = $r->type === 'expense_refund' ? 'Expense refund' : 'Expense';
                                    if (!empty($r->additional_notes)) {
                                        $parts = array_map('trim', explode(' · ', $r->additional_notes));
                                        $type_set = false;
                                        foreach ($parts as $p) {
                                            if (stripos($p, 'Vendor:') === 0) {
                                                $vendor = trim(substr($p, strlen('Vendor:')));
                                            } elseif ($p !== '') {
                                                if (!$type_set) {
                                                    $tx_type_label = $p; $type_set = true;
                                                } else {
                                                    $memo = $memo === '' ? $p : ($memo . ' · ' . $p);
                                                }
                                            }
                                        }
                                    }
                                @endphp
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($r->transaction_date)->format('m/d/Y') }}</td>
                                    <td>{{ $tx_type_label }}</td>
                                    <td>{{ $r->ref_no }}</td>
                                    <td>{{ $vendor }}</td>
                                    <td>{{ $memo }}</td>
                                    <td>
                                        @if(!empty($r->expense_category_id))
                                            <a href="{{ $categoryLink($r->expense_category_id) }}">{{ $r->category ?: '(uncategorized)' }}</a>
                                        @else
                                            {{ $r->category ?: '(uncategorized)' }}
                                        @endif
                                    </td>
                                    <td>{{ $r->location_name }}</td>
                                    <td class="text-right @if($signed < 0) text-danger @endif">
                                        {{ number_format($signed, 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted">No transactions match the current filters.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7" class="text-right"><strong>Total</strong></td>
                                <td class="text-right">
                                    <strong class="@if($detail_total < 0) text-danger @endif">{{ number_format($detail_total, 2) }}</strong>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-muted" style="margin-top:8px;">
                    Showing up to 5,000 most recent rows. Narrow the date range or pick a category to see more.
                </p>
            @endcomponent
        </div>
    </div>

</section>
@endsection

@section('javascript')
    <script>
        $(document).ready(function () {
            if ($.fn.DataTable.isDataTable('#expense_detail_table')) return;
            $('#expense_detail_table').DataTable({
                paging: true,
                pageLength: 50,
                lengthMenu: [25, 50, 100, 250, 500],
                order: [[0, 'desc']],
                searching: true,
                info: true,
            });
        });
    </script>
@endsection
