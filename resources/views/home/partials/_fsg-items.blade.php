{{-- "Fastest selling genres" drill-down table, rendered by
     HomeController@getFastestSellingGenreItems into #fsg-detail-modal.
     $type = 'sold' (units sold in the window + days to sell) or
     'stock' (on hand now + days on the shelf). --}}
@if($items->isEmpty())
    <div class="fsg-empty">Nothing to show.</div>
@else
    <p class="fsg-sub" style="margin-bottom:8px;">
        @if($type === 'sold')
            {{ number_format($total) }} {{ $total === 1 ? 'sale' : 'sales' }} — fastest first. Days = intake date to sale date.
        @else
            {{ number_format($total) }} {{ $total === 1 ? 'item' : 'items' }} on hand — longest on the shelf first.
        @endif
        @if($total > $limit)
            Showing the first {{ number_format($limit) }}.
        @endif
    </p>
    <input type="text" class="form-control input-sm fsg-items-filter" placeholder="Search artist, title or SKU…" style="margin-bottom:8px;">
    <div style="max-height:60vh; overflow:auto;">
        <table class="table table-condensed table-striped fsg-items-table" style="margin:0; font-size:12px;">
            <thead>
                <tr>
                    <th>Artist</th>
                    <th>Title</th>
                    <th>SKU</th>
                    <th>{{ $type === 'sold' ? 'Intake' : 'On shelf since' }}</th>
                    @if($type === 'sold')<th>Sold</th>@endif
                    <th style="text-align:right;">{{ $type === 'sold' ? 'Days to sell' : 'Days on shelf' }}</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Price</th>
                    <th>Store</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $it)
                    <tr>
                        <td>{{ $it->artist }}</td>
                        <td>{{ $it->name }}</td>
                        <td>{{ $it->sku }}</td>
                        <td>{{ $it->intake_date ? \Carbon::parse($it->intake_date)->format('M j, Y') : '—' }}</td>
                        @if($type === 'sold')<td>{{ \Carbon::parse($it->sale_date)->format('M j, Y') }}</td>@endif
                        <td style="text-align:right; font-weight:600;">{{ is_null($it->days) ? '—' : number_format($it->days) }}</td>
                        <td style="text-align:right;">{{ number_format((float) $it->qty) }}</td>
                        <td style="text-align:right;">${{ number_format((float) $it->price, 2) }}</td>
                        <td>{{ $it->location }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
