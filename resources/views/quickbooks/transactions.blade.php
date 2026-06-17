@extends('layouts.app')
@section('title', 'QuickBooks Transaction List by Date')

@section('content')
<section class="content-header">
    <h1>QuickBooks Transaction List by Date</h1>
    <p class="text-muted">Live from QuickBooks Online — refreshes on every load.</p>
</section>

<section class="content">
    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Date range &amp; filters</h3>
        </div>
        <div class="box-body">
            <form method="GET" action="{{ action('QuickBooksController@transactionList') }}" class="form-inline">
                <div class="form-group">
                    <label>From</label>
                    <input type="date" class="form-control" name="from_date" value="{{ $from_date }}">
                </div>
                <div class="form-group" style="margin-left:8px;">
                    <label>To</label>
                    <input type="date" class="form-control" name="to_date" value="{{ $to_date }}">
                </div>
                <div class="form-group" style="margin-left:8px;">
                    <label>Type</label>
                    <select name="f_type" class="form-control">
                        <option value="">All</option>
                        @foreach($filter_options['type'] as $opt)
                            <option value="{{ $opt }}" {{ ($filters['type'] ?? '') === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-left:8px;">
                    <label>Account</label>
                    <select name="f_account" class="form-control">
                        <option value="">All</option>
                        @foreach($filter_options['account'] as $opt)
                            <option value="{{ $opt }}" {{ ($filters['account'] ?? '') === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-left:8px;">
                    <label>Split</label>
                    <select name="f_split" class="form-control">
                        <option value="">All</option>
                        @foreach($filter_options['split'] as $opt)
                            <option value="{{ $opt }}" {{ ($filters['split'] ?? '') === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-left:8px;">
                    <i class="fa fa-refresh"></i> View
                </button>
                <a href="{{ action('QuickBooksController@transactionList') }}?{{ http_build_query(array_merge(request()->query(), ['export' => 'csv'])) }}"
                   class="btn btn-success" style="margin-left:8px;">
                    <i class="fa fa-download"></i> Export CSV
                </a>
            </form>
        </div>
    </div>

    @if(empty($report['success']))
        <div class="alert alert-danger">
            Could not load the QuickBooks report: {{ $report['msg'] ?? 'Unknown error.' }}
        </div>
    @else
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $from_date }} &rarr; {{ $to_date }} &middot; {{ count($report['rows']) }} transactions</h3>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            @foreach($columns as $col)
                                <th>{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr>
                                @foreach($columns as $col)
                                    <td>{{ $row[$col] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ max(1, count($columns)) }}" class="text-center">
                                    No transactions match these filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(!is_null($total))
                        <tfoot>
                            <tr>
                                <th colspan="{{ max(1, count($columns) - 1) }}" class="text-right">Total</th>
                                <th>{{ number_format($total, 2) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    @endif
</section>
@endsection
