@extends('layouts.app')
@section('title', 'Sell Price Mismatch')

@section('content')
<section class="content-header">
    <h1>Sell Price Mismatch</h1>
    <p class="text-muted">
        Variations where the POS sticker (<strong>default_sell_price</strong>) is below
        the price entered on the Add Purchase form (<strong>sell_price_inc_tax</strong>).
        This is the signature of the May 1, 2026 bug — POS sold these items
        for less than what was typed in. The fix is live; this page lets you
        backfill historical rows so future sales charge the right amount.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        @if (session('status') && !empty(session('status')['success']))
            <div class="alert alert-success">{{ session('status')['msg'] }}</div>
        @endif

        <div class="box box-solid" style="border: 3px solid {{ $totalAffected ? '#dd4b39' : '#00a65a' }};">
            <div class="box-header" style="background: {{ $totalAffected ? '#f2dede' : '#dff0d8' }};">
                <h3 class="box-title" style="font-size:22px;">
                    @if ($totalAffected)
                        {{ number_format($totalAffected) }} variation(s) deflated below their sticker
                    @else
                        ✅ No deflated sell prices found
                    @endif
                </h3>
                @if ($totalAffected)
                    <p style="margin:8px 0 0;font-size:16px;">
                        <strong>Estimated lost revenue:</strong>
                        <span style="color:#c00;">${{ number_format($lostRevenue, 2) }}</span>
                        across {{ number_format($affectedSales) }} historical sale lines.
                        <br>
                        <small class="text-muted">
                            Calculated as <code>SUM(MAX(entered_sticker − price_charged, 0) × quantity)</code>
                            on past sales of the affected variations — the gap between what was typed on
                            Add Purchase and what the customer actually paid per unit at POS.
                        </small>
                    </p>
                @endif
            </div>

            @if ($totalAffected)
            <div class="box-body">
                <p>
                    Click below to set <code>default_sell_price = sell_price_inc_tax</code> for
                    every affected variation. A snapshot is written to <code>storage/admin-snapshots/</code>
                    first — undo any time at <a href="/admin/admin-action-history">/admin/admin-action-history</a>.
                </p>

                <form method="POST" action="{{ url('/admin/sell-price-mismatch/run') }}"
                      onsubmit="return confirm('Align all {{ $totalAffected }} variations to their entered sticker price? This writes to the DB.');"
                      style="margin-bottom:16px;">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-lg">
                        Restore stickers ({{ number_format($totalAffected) }} variations)
                    </button>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th style="text-align:right;">POS sticker (stored)</th>
                                <th style="text-align:right;">Entered price</th>
                                <th style="text-align:right;">Per-sale loss</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r->sku }}</td>
                                    <td>{{ $r->name }}</td>
                                    <td>{{ $r->category ?? '—' }}</td>
                                    <td style="text-align:right;color:#c00;">${{ number_format((float) $r->exc_tax, 2) }}</td>
                                    <td style="text-align:right;">${{ number_format((float) $r->inc_tax, 2) }}</td>
                                    <td style="text-align:right;color:#c00;">
                                        ${{ number_format((float) $r->inc_tax - (float) $r->exc_tax, 2) }}
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
                    <p class="text-muted">Showing first 2,000 rows. Run Apply to fix all {{ number_format($totalAffected) }}.</p>
                @endif
            </div>
            @endif
        </div>

        @if (!empty($undercharged) && $undercharged->count())
        <div class="box box-solid" style="margin-top:24px;border:2px solid #c00;">
            <div class="box-header" style="background:#f2dede;">
                <h3 class="box-title" style="font-size:20px;">
                    Sales that undercharged ({{ number_format($undercharged->count()) }})
                </h3>
                <p class="text-muted" style="margin:6px 0 0;">
                    Each row is a real sale where the customer paid less per unit than the price typed on Add Purchase.
                    Newest first, capped at 1,000.
                </p>
            </div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Location</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th style="text-align:right;">Qty</th>
                                <th style="text-align:right;">Charged</th>
                                <th style="text-align:right;">Should have charged</th>
                                <th style="text-align:right;">Loss</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($undercharged as $u)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($u->transaction_date)->format('Y-m-d') }}</td>
                                    <td>{{ $u->invoice_no ?? '—' }}</td>
                                    <td>{{ $u->location ?? '—' }}</td>
                                    <td>
                                        <a href="{{ url('/products/' . $u->product_id . '/edit') }}" target="_blank">
                                            {{ $u->product_name }}
                                        </a>
                                    </td>
                                    <td>{{ $u->sku }}</td>
                                    <td style="text-align:right;">{{ rtrim(rtrim(number_format((float)$u->quantity, 2), '0'), '.') }}</td>
                                    <td style="text-align:right;color:#c00;">${{ number_format((float)$u->charged, 2) }}</td>
                                    <td style="text-align:right;">${{ number_format((float)$u->intended, 2) }}</td>
                                    <td style="text-align:right;color:#c00;font-weight:bold;">
                                        ${{ number_format((float)$u->loss, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

</section>
@endsection
