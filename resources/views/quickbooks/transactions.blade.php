@extends('layouts.app')
@section('title', 'QuickBooks Transaction List')

@section('content')
<section class="content-header">
    <h1>QuickBooks Transaction List by Date</h1>
    <p class="text-muted">Live from QuickBooks Online — refreshes on every load.</p>
</section>

<section class="content">
    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Date range</h3>
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
                <button type="submit" class="btn btn-primary" style="margin-left:8px;">
                    <i class="fa fa-refresh"></i> View
                </button>
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
                            @foreach($report['columns'] as $col)
                                <th>{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr>
                                @foreach($report['columns'] as $col)
                                    <td>{{ $row[$col] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ max(1, count($report['columns'])) }}" class="text-center">
                                    No transactions in this date range.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(!is_null($report['total']))
                        <tfoot>
                            <tr>
                                <th colspan="{{ max(1, count($report['columns']) - 1) }}" class="text-right">Total</th>
                                <th>{{ number_format($report['total'], 2) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    @endif
</section>
@endsection
