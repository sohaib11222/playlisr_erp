@extends('layouts.app')
@section('title', 'Return Approvals')

@section('content')
<section class="content-header">
    <h1>
        Return Approvals
        <small>every manager-approved return / exchange</small>
    </h1>
</section>

<section class="content">

    <div class="box box-solid">
        <div class="box-body">
            <form method="GET" action="{{ url('/admin/return-approvals') }}" class="form-inline">
                <div class="form-group">
                    <label for="days">Show last</label>
                    <select name="days" id="days" class="form-control" onchange="this.form.submit()">
                        @foreach([7, 30, 90, 180, 365] as $d)
                            <option value="{{ $d }}" {{ $days == $d ? 'selected' : '' }}>{{ $d }} days</option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-4">
            <div class="info-box">
                <div class="info-box-content">
                    <span class="info-box-text">Returns approved</span>
                    <span class="info-box-number">{{ $totals['count'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="info-box">
                <div class="info-box-content">
                    <span class="info-box-text">Self-approved by a manager</span>
                    <span class="info-box-number">{{ $totals['self_approved'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="info-box">
                <div class="info-box-content">
                    <span class="info-box-text">Total returned</span>
                    <span class="info-box-number"><span class="display_currency" data-currency_symbol="true">{{ $totals['amount'] }}</span></span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body">
            @if(count($rows) === 0)
                <p class="text-muted">No approved returns in this window.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Return invoice</th>
                                <th>Amount</th>
                                <th>Cashier</th>
                                <th>Approved by</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr>
                                    <td>{{ $r['created_at'] ?? '' }}</td>
                                    <td>{{ $r['invoice_no'] ?? ('#' . ($r['return_id'] ?? '')) }}</td>
                                    <td><span class="display_currency" data-currency_symbol="true">{{ $r['amount'] ?? 0 }}</span></td>
                                    <td>{{ $r['cashier_name'] ?? '-' }}</td>
                                    <td>
                                        {{ $r['approver_name'] ?? '-' }}
                                        @if(!empty($r['self_approved']))
                                            <span class="label label-default">self</span>
                                        @endif
                                    </td>
                                    <td>{{ $r['reason'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</section>
@endsection
