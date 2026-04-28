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
    .rf-export { display: inline-flex; align-items: center; gap: 6px;
        background: #1F1B16; color: #FAF6EE !important; border: 1px solid #1F1B16;
        border-radius: 7px; padding: 8px 14px; font-size: 13px; font-weight: 600;
        text-decoration: none !important; transition: background .15s, border-color .15s; }
    .rf-export:hover { background: #3A3128; border-color: #3A3128; }

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

    /* Reconcile block: ERP column vs Clover column, side by side. */
    .rf-recon { display: flex; gap: 24px; font-variant-numeric: tabular-nums; }
    .rf-recon-col { min-width: 90px; text-align: right; }
    .rf-recon-col .lbl { color: #5A5045; font-weight: 600; font-size: 11px;
        text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
    .rf-recon-col .amt { color: #1F1B16; font-weight: 700; font-size: 16px;
        line-height: 1.2; }
    .rf-recon-col .sub { color: #8A7C6A; font-size: 11px; margin-top: 2px;
        line-height: 1.4; }
    .rf-recon.is-mismatch .rf-recon-clover .amt { color: #B0451A; }
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
            <button type="submit" class="rf-export"
                    formaction="{{ action('SellPosController@recentSalesFeedExport') }}"
                    title="Download these sales as CSV (one row per item)">
                Export CSV
            </button>
        </form>

        @if(!empty($clover_debug))
            <div style="background:#FFF8E1;border:1px solid #E6D58A;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-family:ui-monospace,Menlo,monospace;font-size:11px;color:#5A5045;line-height:1.5;">
                <div style="font-weight:700;font-size:12px;color:#1F1B16;margin-bottom:6px;">Clover match diagnostics</div>
                <div>ERP card payments: <strong>{{ $clover_debug['erp_payment_count'] }}</strong> · Clover payments in window: <strong>{{ $clover_debug['clover_payment_count'] }}</strong> · Matched: <strong>{{ $clover_debug['matched_tx_count'] }}</strong> · ±window: {{ $clover_debug['window_seconds'] }}s</div>
                <div>Clover data spans: <strong>{{ $clover_debug['clover_window_min'] }}</strong> → <strong>{{ $clover_debug['clover_window_max'] }}</strong></div>
                @if(!empty($clover_debug['unclaimed_erp']))
                    <div style="margin-top:10px;font-weight:700;color:#1F1B16;">Unmatched ERP card payments (newest first), with the 3 closest Clover payments:</div>
                    @foreach($clover_debug['unclaimed_erp'] as $u)
                        <div style="margin-top:6px;padding-left:4px;border-left:2px solid #E6D58A;">
                            <div><strong>ERP</strong> ${{ number_format($u['amount'], 2) }} · {{ $u['ts'] }} · tx#{{ $u['tx_id'] }} · {{ $u['cashier'] ?: '(no name)' }}</div>
                            @if(empty($u['closest_clover']))
                                <div style="color:#B0451A;">→ no Clover payments at all in window</div>
                            @else
                                @foreach($u['closest_clover'] as $c)
                                    <div style="padding-left:14px;color:{{ $c['why'] === 'WOULD MATCH' ? '#1F8B3F' : '#8A7C6A' }};">
                                        → ${{ number_format($c['amount'], 2) }} · {{ $c['paid_at'] }} · {{ $c['employee'] ?: '(no name)' }} · {{ $c['card'] ?: '(no card)' }} · <em>{{ $c['why'] }}</em>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
        @endif

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
                            <div class="rf-recon-col rf-recon-erp">
                                <div class="lbl">ERP</div>
                                <div class="amt">${{ number_format($total, 2) }}</div>
                            </div>
                            <div class="rf-recon-col rf-recon-clover">
                                <div class="lbl">
                                    Clover
                                    @if($cloverMismatch)<span class="rf-recon-mismatch-tag" title="Clover charged ≠ ERP total">mismatch</span>@endif
                                </div>
                                <div class="amt">${{ number_format($cloverInfo['amount_cents'] / 100, 2) }}</div>
                                <div class="sub">
                                    @if($cloverInfo['tax_cents'] > 0)Tax ${{ number_format($cloverInfo['tax_cents'] / 100, 2) }}@endif
                                    @if($cloverInfo['tip_cents'] > 0) · Tip ${{ number_format($cloverInfo['tip_cents'] / 100, 2) }}@endif
                                    @if(!empty($cloverInfo['cards']))<div>{{ implode(' · ', $cloverInfo['cards']) }}</div>@endif
                                </div>
                            </div>
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
