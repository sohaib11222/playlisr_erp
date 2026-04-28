@extends('layouts.app')
@section('title', 'Recent Sales Feed')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2','pos-list-v2');</script>

<style>
    body.pos-list-v2 section.content { background: #FAF6EE; }
    .rf-wrap { max-width: 860px; margin: 0 auto; }
    .rf-filters { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
        background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 10px;
        padding: 14px 16px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
    .rf-filters label { color: #5A5045; font-weight: 600; font-size: 12px;
        text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: 4px; }
    .rf-filters .form-control { border: 1px solid #DFD2B3; border-radius: 7px; color: #1F1B16; }
    .rf-count { color: #5A5045; font-size: 13px; margin-left: auto; }

    .rf-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 10px;
        padding: 14px 16px; margin-bottom: 12px; box-shadow: 0 1px 2px rgba(31,27,22,.06);
        font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; }
    .rf-head { display: flex; justify-content: space-between; align-items: baseline;
        gap: 10px; flex-wrap: wrap; padding-bottom: 10px;
        border-bottom: 1px dashed #ECE3CF; margin-bottom: 10px; }
    .rf-head-left { display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
    .rf-invoice { font-weight: 700; font-size: 15px; letter-spacing: -.01em; }
    .rf-invoice a { color: #1F1B16; text-decoration: none; border-bottom: 1px dotted #BFB096; }
    .rf-invoice a:hover { color: #8B6A1A; border-bottom-color: #8B6A1A; }
    .rf-time, .rf-store, .rf-customer, .rf-cashier { color: #5A5045; font-size: 13px; }
    .rf-cashier strong { color: #1F1B16; font-weight: 700; }
    .rf-store-badge { display: inline-block; padding: 1px 7px; border-radius: 999px;
        background: #F7F1E3; border: 1px solid #DFD2B3; color: #5A5045;
        font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }

    .rf-lines { list-style: none; padding: 0; margin: 0; }
    .rf-line { display: flex; justify-content: space-between; gap: 12px;
        padding: 4px 0; font-size: 14px; line-height: 1.4; }
    .rf-line-name { flex: 1; min-width: 0; }
    .rf-line-name .qty { color: #5A5045; font-weight: 600; margin-right: 6px; }
    .rf-line-price { color: #1F1B16; font-variant-numeric: tabular-nums;
        white-space: nowrap; font-weight: 500; }
    .rf-manual-tag { display: inline-block; margin-left: 6px; padding: 1px 6px;
        border-radius: 4px; background: #F7E8C2; color: #8B6A1A;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; vertical-align: middle; }

    .rf-foot { display: flex; justify-content: space-between; align-items: flex-start;
        gap: 10px; flex-wrap: wrap; padding-top: 10px; margin-top: 8px;
        border-top: 1px dashed #ECE3CF; }
    .rf-foot-meta { color: #8A7C6A; font-size: 12px; }
    .rf-total { font-weight: 700; font-size: 16px; font-variant-numeric: tabular-nums; }
    .rf-total .lbl { color: #5A5045; font-weight: 600; font-size: 12px;
        text-transform: uppercase; letter-spacing: .06em; margin-right: 6px; }

    /* Reconcile block: ERP total vs Clover charged, tax broken out. */
    .rf-recon { font-variant-numeric: tabular-nums; font-size: 13px; min-width: 200px; }
    .rf-recon-row { display: flex; justify-content: space-between; gap: 16px;
        padding: 1px 0; }
    .rf-recon-row .lbl { color: #5A5045; font-weight: 600; font-size: 11px;
        text-transform: uppercase; letter-spacing: .06em; }
    .rf-recon-row .val { color: #1F1B16; font-weight: 600; }
    .rf-recon-row.is-erp .val { font-size: 16px; font-weight: 700; }
    .rf-recon-row.is-clover .val { font-size: 14px; }
    .rf-recon-row.is-tax .lbl, .rf-recon-row.is-tax .val,
    .rf-recon-row.is-tip .lbl, .rf-recon-row.is-tip .val { color: #8A7C6A; font-size: 12px; }
    .rf-recon.is-mismatch .rf-recon-row.is-clover .val { color: #B0451A; }
    .rf-recon-cards { color: #8A7C6A; font-size: 11px; text-align: right;
        margin-top: 2px; letter-spacing: .02em; }
    .rf-recon-mismatch-tag { display: inline-block; margin-left: 6px; padding: 1px 6px;
        border-radius: 4px; background: #FBE0D2; color: #B0451A;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; vertical-align: middle; }

    .rf-empty { text-align: center; color: #8A7C6A; padding: 40px 20px;
        background: #FFFFFF; border: 1px dashed #DFD2B3; border-radius: 10px; }

    @media (max-width: 600px) {
        .rf-head, .rf-foot { flex-direction: column; align-items: flex-start; }
        .rf-line { font-size: 13px; }
    }
</style>

<section class="content-header">
    <h1>Recent Sales Feed <small>— items sold, expanded inline</small></h1>
</section>

<section class="content">
    <div class="rf-wrap">
        <form method="GET" action="{{ action('SellPosController@recentSalesFeed') }}" class="rf-filters">
            <div style="min-width: 180px;">
                <label for="rf-location">Store</label>
                <select name="location_id" id="rf-location" class="form-control" onchange="this.form.submit()">
                    <option value="">All stores</option>
                    @foreach($business_locations as $id => $name)
                        <option value="{{ $id }}" {{ (string)$location_id === (string)$id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width: 120px;">
                <label for="rf-limit">Show</label>
                <select name="limit" id="rf-limit" class="form-control" onchange="this.form.submit()">
                    @foreach([15, 30, 50, 100] as $n)
                        <option value="{{ $n }}" {{ (int)$limit === $n ? 'selected' : '' }}>{{ $n }} sales</option>
                    @endforeach
                </select>
            </div>
            <div class="rf-count">{{ $sales->count() }} sale{{ $sales->count() === 1 ? '' : 's' }}</div>
        </form>

        @forelse($sales as $sale)
            @php
                $dt = \Carbon\Carbon::parse($sale->transaction_date);
                $isToday = $dt->isToday();
                $when = $isToday ? $dt->format('g:i a') : $dt->format('M j · g:i a');
                $customer = optional($sale->contact)->name ?: 'Walk-In Customer';
                $store = optional($sale->location)->name ?: '—';
                $cashier = optional($sale->sales_person)->user_full_name;
                $cashier = $cashier ? trim($cashier) : null;
                $total = (float) $sale->final_total;
                $discount = (float) ($sale->discount_amount ?? 0);
            @endphp
            <div class="rf-card">
                <div class="rf-head">
                    <div class="rf-head-left">
                        <span class="rf-invoice">
                            <a href="{{ action('SellController@show', [$sale->id]) }}">#{{ $sale->invoice_no }}</a>
                        </span>
                        <span class="rf-time">{{ $when }}</span>
                        <span class="rf-store-badge">{{ $store }}</span>
                        <span class="rf-customer">· {{ $customer }}</span>
                        @if($cashier)<span class="rf-cashier">· by <strong>{{ $cashier }}</strong></span>@endif
                    </div>
                </div>

                @if($sale->sell_lines->isEmpty())
                    <div class="rf-line"><span class="rf-line-name" style="color:#8A7C6A;">(no items)</span></div>
                @else
                    <ul class="rf-lines">
                    @foreach($sale->sell_lines as $line)
                        @php
                            $product = $line->product;
                            // Prefer product relation (real inventory item); fall back to
                            // per-line product_name/product_artist captured for manual items.
                            $baseName = $product->name ?? ($line->product_name ?? 'Manual item');
                            $baseArtist = null;
                            if ($product && !empty($product->artist) && is_string($product->artist)) {
                                $baseArtist = $product->artist;
                            } elseif (!empty($line->product_artist)) {
                                $baseArtist = $line->product_artist;
                            }
                            $name = $baseArtist ? ($baseArtist . ' — ' . $baseName) : $baseName;
                            $isManual = empty($product);
                            $qty = (float) $line->quantity;
                            $unit = (float) ($line->unit_price_inc_tax ?: $line->unit_price);
                            $lineDisc = 0;
                            if (!empty($line->line_discount_amount)) {
                                $lineDisc = $line->line_discount_type === 'percentage'
                                    ? ($unit * $line->line_discount_amount / 100)
                                    : (float) $line->line_discount_amount;
                            }
                            $lineTotal = ($unit - $lineDisc) * $qty;
                        @endphp
                        <li class="rf-line">
                            <span class="rf-line-name">
                                @if($qty > 1)<span class="qty">{{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') }}×</span>@endif
                                {{ $name }}
                                @if($isManual)<span class="rf-manual-tag" title="Manual item (not from inventory)">manual</span>@endif
                            </span>
                            <span class="rf-line-price">${{ number_format($lineTotal, 2) }}</span>
                        </li>
                    @endforeach
                    </ul>
                @endif

                @php
                    $cloverInfo = $clover_by_transaction[$sale->id] ?? null;
                    // Mismatch = ERP total ≠ Clover gross (amount, which already
                    // includes tax). Tip is separate so it doesn't count as a
                    // mismatch. Tolerance: 1 cent for floating-point safety.
                    $cloverMismatch = false;
                    if ($cloverInfo) {
                        $cloverGross = $cloverInfo['amount_cents'] / 100;
                        $cloverMismatch = abs($cloverGross - $total) > 0.01;
                    }
                @endphp
                <div class="rf-foot">
                    <div class="rf-foot-meta">
                        {{ ucfirst(str_replace('_', ' ', $sale->payment_status ?? '')) }}
                        @if($discount > 0) · discount −${{ number_format($discount, 2) }} @endif
                    </div>
                    @if($cloverInfo)
                        <div class="rf-recon {{ $cloverMismatch ? 'is-mismatch' : '' }}">
                            <div class="rf-recon-row is-erp">
                                <span class="lbl">ERP</span>
                                <span class="val">${{ number_format($total, 2) }}</span>
                            </div>
                            <div class="rf-recon-row is-clover">
                                <span class="lbl">Clover</span>
                                <span class="val">
                                    ${{ number_format($cloverInfo['amount_cents'] / 100, 2) }}
                                    @if($cloverMismatch)<span class="rf-recon-mismatch-tag" title="Clover charged ≠ ERP total">mismatch</span>@endif
                                </span>
                            </div>
                            @if($cloverInfo['tax_cents'] > 0)
                                <div class="rf-recon-row is-tax">
                                    <span class="lbl">Tax</span>
                                    <span class="val">${{ number_format($cloverInfo['tax_cents'] / 100, 2) }}</span>
                                </div>
                            @endif
                            @if($cloverInfo['tip_cents'] > 0)
                                <div class="rf-recon-row is-tip">
                                    <span class="lbl">Tip</span>
                                    <span class="val">${{ number_format($cloverInfo['tip_cents'] / 100, 2) }}</span>
                                </div>
                            @endif
                            @if(!empty($cloverInfo['cards']))
                                <div class="rf-recon-cards">{{ implode(' · ', $cloverInfo['cards']) }}</div>
                            @endif
                        </div>
                    @else
                        <div class="rf-total"><span class="lbl">Total</span>${{ number_format($total, 2) }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="rf-empty">No sales yet for this filter.</div>
        @endforelse
    </div>
</section>
@endsection
