{{--
  Itemized breakdown of a buy-from-customer offer — the calculator inputs the
  cashier entered. Expects $offer (a BuyCustomerOffer). Used on the history
  page and the purchase detail page.
--}}
@php
    $bfcLines = $offer->meaningful_lines;
@endphp
@if($bfcLines->isEmpty())
    <p class="text-muted" style="margin:0;">No itemized lines were recorded for this buy.</p>
@else
    <div class="table-responsive">
        <table class="table table-condensed table-bordered" style="margin-bottom:6px;">
            <thead>
                <tr class="bg-gray">
                    <th>#</th>
                    <th>Item type</th>
                    <th>Title</th>
                    <th>Grade</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Discogs median</th>
                    <th class="text-right">Line cash</th>
                    <th class="text-right">Line credit</th>
                </tr>
            </thead>
            <tbody>
                @php $cashSum = 0; $creditSum = 0; $qtySum = 0; @endphp
                @foreach($bfcLines as $bl)
                    @php
                        $cashSum += (float) $bl->line_cash_total;
                        $creditSum += (float) $bl->line_credit_total;
                        $qtySum += (float) $bl->quantity;
                        $qtyDisplay = rtrim(rtrim(number_format((float) $bl->quantity, 2), '0'), '.');
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $bl->item_type ? ucwords(str_replace('_', ' ', $bl->item_type)) : '—' }}</td>
                        <td>{{ $bl->title ?: '—' }}</td>
                        <td>{{ $bl->condition_grade ?: '—' }}</td>
                        <td class="text-right">{{ $qtyDisplay }}</td>
                        <td class="text-right">{{ !is_null($bl->discogs_median_price) ? '$'.number_format((float) $bl->discogs_median_price, 2) : '—' }}</td>
                        <td class="text-right">${{ number_format((float) $bl->line_cash_total, 2) }}</td>
                        <td class="text-right">${{ number_format((float) $bl->line_credit_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-right">Calculated totals</th>
                    <th class="text-right">{{ rtrim(rtrim(number_format($qtySum, 2), '0'), '.') }}</th>
                    <th></th>
                    <th class="text-right">${{ number_format($cashSum, 2) }}</th>
                    <th class="text-right">${{ number_format($creditSum, 2) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="text-muted" style="font-size:12px;">
        Negotiated final:
        <strong>${{ number_format((float) $offer->final_offer_cash, 2) }}</strong> cash
        / <strong>${{ number_format((float) $offer->final_offer_credit, 2) }}</strong> credit
        @if(!empty($offer->payment_method))
            · paid via {{ ucwords(str_replace('_', ' ', $offer->payment_method)) }}
        @endif
    </div>
@endif
