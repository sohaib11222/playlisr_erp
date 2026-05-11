@extends('layouts.app')
@section('title', __('report.expense_report'))

@section('content')

<section class="content-header">
    <h1>{{ __('report.expense_report') }}</h1>
</section>

<section class="content">

    <div class="row no-print">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action('ReportController@getExpenseReport'), 'method' => 'get']) !!}
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('location_id', __('purchase.business_location') . ':') !!}
                            {!! Form::select('location_id', $business_locations, request('location_id'), ['class' => 'form-control select2', 'style' => 'width:100%']); !!}
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
                        <a href="{{ url('reports/expense-report?' . http_build_query(array_merge(request()->all(), ['export' => 'csv']))) }}"
                           class="btn btn-default"
                           title="Download current view as CSV">
                            <i class="fa fa-file-csv"></i> Export CSV
                        </a>
                    </div>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>

    @php
        $current_category_id = request('category');
        // Build a URL helper that preserves the other filters when swapping the category.
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

        $summary_total = 0;
        foreach ($expenses as $e) { $summary_total += (float) $e->total_expense; }

        $detail_total = 0;
        foreach ($detail as $d) {
            $detail_total += ($d->type === 'expense_refund' ? 1 : -1) * (float) $d->final_total;
        }
    @endphp

    <div class="row">
        <div class="col-md-5">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Summary by category'])
                <p class="text-muted" style="margin-top:-5px;">
                    Click any category to drill into its transactions.
                    @if(!empty($current_category_id))
                        <a href="{{ $clearCategoryLink() }}" class="btn btn-xs btn-default" style="margin-left:8px;">
                            <i class="fa fa-times"></i> Clear category filter
                        </a>
                    @endif
                </p>
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
            @endcomponent
        </div>
        <div class="col-md-7">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Transaction detail' . (!empty($current_category_id) ? ' — filtered to selected category' : '')])
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
                                <td>{{ $r->category ?: '(uncategorized)' }}</td>
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
            // Sortable detail table (no AJAX — already rendered).
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
