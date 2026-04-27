@extends('layouts.app')
@section('title', 'Recover Zeroed Cost Prices')

@section('content')
<section class="content-header">
    <h1>Recover Zeroed Cost Prices</h1>
    <p class="text-muted">
        Lists variations where both purchase-price columns are now $0 — likely
        wiped by the 2026-04-27 mismatch backfill on rows that were missing the
        ex-tax value. Recovery pulls the most recent <code>purchase_lines</code>
        entry for each variation and copies the ex/inc cost back onto it.
    </p>
</section>

<section class="content">

@if (session('status') && !empty(session('status')['success']))
    <div class="alert alert-success">{{ session('status')['msg'] }}</div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border: 3px solid {{ $totalZeroed ? '#dd4b39' : '#00a65a' }};">
            <div class="box-header" style="background: {{ $totalZeroed ? '#f2dede' : '#dff0d8' }};">
                <h3 class="box-title" style="font-size:22px;">
                    @if ($totalZeroed)
                        {{ number_format($totalZeroed) }} variation(s) currently have $0 cost
                    @else
                        ✅ Nothing to recover — every variation has a non-zero cost
                    @endif
                </h3>
            </div>

            @if ($totalZeroed)
            <div class="box-body">
                <p>
                    <strong>{{ number_format($recoverable) }}</strong> can be recovered from purchase history.<br>
                    <strong>{{ number_format($notRecoverable) }}</strong> have no purchase line on record — those will need manual entry or a Discogs lookup.
                </p>

                <form method="POST" action="{{ url('/admin/recover-zeroed-costs/run') }}"
                      onsubmit="return confirm('Recover {{ $recoverable }} variations from purchase history? Variations with no purchase line will be left at $0.');"
                      style="margin-bottom:16px;">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-lg">
                        Recover {{ number_format($recoverable) }} from purchase history
                    </button>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Created by</th>
                                <th>Last updated</th>
                                <th style="text-align:right;">Recovered cost (ex)</th>
                                <th style="text-align:right;">Recovered cost (inc)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r->sku }}</td>
                                    <td>{{ $r->name }}</td>
                                    <td>{{ $r->created_by }}</td>
                                    <td>{{ $r->updated_at }}</td>
                                    <td style="text-align:right;">
                                        @if ($r->recovered_cost)
                                            <strong style="color:#00a65a;">${{ number_format($r->recovered_cost, 2) }}</strong>
                                        @else
                                            <span style="color:#999;">no history</span>
                                        @endif
                                    </td>
                                    <td style="text-align:right;">
                                        @if ($r->recovered_cost_inc)
                                            ${{ number_format($r->recovered_cost_inc, 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ url('/products/' . $r->product_id . '/edit') }}"
                                           target="_blank" class="btn btn-default btn-xs">Edit</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (count($rows) >= 2000)
                    <p class="text-muted">Showing first 2,000 rows. Recovery applies to all {{ number_format($totalZeroed) }}.</p>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>

</section>
@endsection
