@extends('layouts.app')
@section('title', 'Wipe Audit')

@section('content')
<section class="content-header">
    <h1>2026-04-27 Wipe Audit</h1>
    <p class="text-muted">
        Lists variation rows whose <code>default_purchase_price</code> and
        <code>dpp_inc_tax</code> are both currently $0 <strong>and</strong>
        whose <code>updated_at</code> is in the 2026-04-27 11:00–12:00 PT
        backfill window. These are the actual victims of the buggy
        purchase-price-mismatch script. Restore <em>only these</em> from the
        04-24 backup — leave everything else alone.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border:3px solid {{ $count ? '#dd4b39' : '#00a65a' }};">
            <div class="box-header" style="background:{{ $count ? '#f2dede' : '#dff0d8' }};">
                <h3 class="box-title" style="font-size:22px;">
                    @if ($count)
                        Exact wipe count: <strong>{{ number_format($count) }}</strong> variation(s)
                    @else
                        ✅ No rows match the wipe signature.
                    @endif
                </h3>
            </div>

            @if ($count)
            <div class="box-body">
                <p>
                    <a href="{{ url('/admin/wipe-audit/csv') }}" class="btn btn-primary">
                        <i class="fa fa-download"></i> Download all {{ number_format($count) }} affected SKUs as CSV
                    </a>
                </p>

                <p style="margin-top:16px;">
                    For Sohaib's surgical restore — the precise SQL is:
                </p>
                <pre style="background:#f5f5f5;padding:12px;border-radius:6px;">
UPDATE variations v
JOIN restore_temp.variations t ON v.id = t.id
SET v.default_purchase_price = t.default_purchase_price,
    v.dpp_inc_tax            = t.dpp_inc_tax
WHERE v.id IN (
    {{ $rows->pluck('variation_id')->take(50)->implode(', ') }}{{ $rows->count() > 50 ? ', /* …' . ($rows->count() - 50) . ' more, full list in the table below */' : '' }}
);</pre>

                <div class="table-responsive" style="margin-top:16px;">
                    <table class="table table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Variation ID</th>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Created by</th>
                                <th>Updated at (server)</th>
                                <th style="text-align:right; background:#f2dede;">Purchase Price NOW (wiped)</th>
                                <th style="text-align:right;">Selling (ex)</th>
                                <th style="text-align:right;">Selling (inc / sticker)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td><code>{{ $r->variation_id }}</code></td>
                                    <td>{{ $r->sku }}</td>
                                    <td>{{ $r->name }}</td>
                                    <td>{{ trim($r->created_by) ?: '—' }}</td>
                                    <td>{{ $r->updated_at }}</td>
                                    <td style="text-align:right; color:#a94442;">
                                        <strong>${{ number_format((float) $r->default_purchase_price, 2) }}</strong>
                                        / ${{ number_format((float) $r->dpp_inc_tax, 2) }}
                                    </td>
                                    <td style="text-align:right;">${{ number_format((float) $r->default_sell_price, 2) }}</td>
                                    <td style="text-align:right;">${{ number_format((float) $r->sell_price_inc_tax, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="text-muted" style="margin-top:8px;">
                        The "Purchase Price NOW" column shows the current (wiped) values
                        — both ex-tax and inc-tax columns are $0 on every row, that's the
                        signature of the wipe. Original values existed pre-11:30 AM but
                        aren't stored anywhere I can read; they only live in the 04-24 backup.
                    </p>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

</section>
@endsection
