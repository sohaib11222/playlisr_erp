@extends('layouts.app')
@section('title', 'Purchase Price Mismatch')

@section('content')
<section class="content-header">
    <h1>Purchase Price Mismatch</h1>
    <p class="text-muted">
        Nivessa has a resale certificate — purchase prices have no sales tax, so
        <strong>Exc. tax</strong> and <strong>Inc. tax</strong> should always be the same number.
        The product form auto-inflates one from the other, leaving phantom mismatches.
        This page lists them and one-click aligns both columns.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        @if (session('status') && !empty(session('status')['success']))
            <div class="alert alert-success">{{ session('status')['msg'] }}</div>
        @endif

        <div class="box box-solid" style="border: 3px solid {{ $totalMismatched ? '#dd4b39' : '#00a65a' }};">
            <div class="box-header" style="background: {{ $totalMismatched ? '#f2dede' : '#dff0d8' }};">
                <h3 class="box-title" style="font-size:22px;">
                    @if ($totalMismatched)
                        {{ number_format($totalMismatched) }} variation(s) mismatched
                        <small style="color:#666;">out of {{ number_format($totalProducts) }} products</small>
                    @else
                        ✅ All purchase prices are aligned — nothing to fix
                    @endif
                </h3>
            </div>

            @if ($totalMismatched)
            <div class="box-body">
                <p>
                    Pick which value to keep. Both columns will be set to the chosen value.
                    <strong>Use the bigger value</strong> if employees usually type the price-tag amount in the
                    Inc. tax field. <strong>Use the smaller value</strong> if they usually type in the Exc. tax field.
                </p>

                <form method="POST" action="{{ url('/admin/purchase-price-mismatch/run') }}"
                      onsubmit="return confirm('Align all {{ $totalMismatched }} mismatched variations? This writes to the DB.');"
                      style="margin-bottom:16px;">
                    @csrf
                    <button type="submit" name="direction" value="use_inc" class="btn btn-primary btn-lg">
                        Use Inc. tax value (bigger) for both
                    </button>
                    <button type="submit" name="direction" value="use_exc" class="btn btn-warning btn-lg">
                        Use Exc. tax value (smaller) for both
                    </button>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th style="text-align:right;">Exc. tax (stored)</th>
                                <th style="text-align:right;">Inc. tax (stored)</th>
                                <th style="text-align:right;">Diff</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r->sku }}</td>
                                    <td>{{ $r->name }}</td>
                                    <td>{{ $r->category ?? '—' }}</td>
                                    <td style="text-align:right;">${{ number_format((float) $r->exc_tax, 2) }}</td>
                                    <td style="text-align:right;">${{ number_format((float) $r->inc_tax, 2) }}</td>
                                    <td style="text-align:right;color:#c00;">
                                        ${{ number_format(abs((float) $r->inc_tax - (float) $r->exc_tax), 2) }}
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
                    <p class="text-muted">Showing first 2,000 rows. Run Apply to fix all {{ number_format($totalMismatched) }}.</p>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>

</section>
@endsection
