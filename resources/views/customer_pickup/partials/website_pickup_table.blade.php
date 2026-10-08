{{-- One pickup list: website orders, in-store event holds, and party /
     special-order preorders (Sarah, 2026-10-08). Older (30+ day) rows get
     .row-older for the Older tab. Expects $rows, $tableId, $showStreetDate. --}}
<div class="table-responsive">
<table class="table wp-table" id="{{ $tableId }}" style="width:100%; font-size:13px;">
    <thead>
        <tr>
            <th>#</th>
            <th>Store</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Item(s)</th>
            <th>Qty</th>
            <th>Paid</th>
            <th>Placed</th>
            @if($showStreetDate)
            <th>Street Date</th>
            @endif
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $wp)
            @php
                $shipTs = !empty($wp['shipDate']) ? strtotime($wp['shipDate']) : null;
                $notYetDue = !empty($wp['isPreorder']) && $shipTs && $shipTs > time();
                $placedTs = !empty($wp['placed']) ? strtotime($wp['placed']) : 0;
                $methodLabel = ['stripe' => 'Card', 'paypal' => 'PayPal', 'free' => 'No charge'][$wp['paymentMethod'] ?? ''] ?? ucfirst($wp['paymentMethod'] ?? '');
                if (!empty($wp['storeCredit'])) { $methodLabel = trim($methodLabel . ' + store credit'); }
                $statusSort = !empty($wp['isPreorder']) ? ($notYetDue ? 0 : 1) : ($wp['status'] === 'ready_for_pickup' ? 3 : 2);
                // Signing-event preorders get handed out at the event,
                // not pulled for a regular pickup (Sarah, 2026-10-06).
                $eventPickup = (bool) preg_grep('/axis mundi/i', $wp['items']);
                // Picked up: keep the row (staff still need the name), just green.
                $pickedUp = ($wp['status'] ?? '') === 'picked_up';
                if ($pickedUp) { $statusSort = 9; }
                $pre = ($wp['source'] ?? '') === 'preorder' ? $wp['pre'] : null;
                $storeLabel = $pre ? '—' : ($wp['location'] === 'pico' ? 'Pico' : 'Hollywood');
            @endphp
            <tr @if($pickedUp) class="row-picked-up" @elseif(isset($isOlder) && $isOlder($wp)) class="row-older" @elseif(!empty($wp['isPreorder'])) style="background:#fff7e0;" @endif>
                <td data-order="{{ $loop->iteration }}">{{ $loop->iteration }}</td>
                <td data-order="{{ $storeLabel }}">{{ $storeLabel }}</td>
                <td data-order="{{ strtolower($wp['customer']) }}">{{ $wp['customer'] }}</td>
                <td data-order="{{ strtolower($wp['email']) }}">@if($wp['email'] !== ''){!! str_replace('@', '<wbr>@', e($wp['email'])) !!}@else — @endif</td>
                <td style="white-space:nowrap;">{{ $wp['phone'] ?: '—' }}</td>
                <td data-order="{{ strtolower(implode(', ', $wp['items'])) }}" style="min-width:190px;">
                    @forelse($wp['items'] as $itemLabel)
                        <div>{{ $itemLabel }}</div>
                    @empty
                        —
                    @endforelse
                    @if($pre && $pre['type'] === 'event')
                        <form method="POST" action="{{ route('events.overviewEventSource', ['preorderId' => $pre['id']]) }}" style="margin:4px 0 0;">
                            {{ csrf_field() }}
                            <input type="hidden" name="filter" value="">
                            <select name="source" onchange="this.form.submit()" class="source-select" aria-label="Where placed" title="{{ $pre['source'] !== '' ? $pre['source'] : ('At event' . ($pre['eventName'] ? ' - ' . $pre['eventName'] : '')) }}">
                                <option value="" {{ $pre['source'] === '' ? 'selected' : '' }}>At event{{ $pre['eventName'] ? ' - ' . $pre['eventName'] : '' }}</option>
                                @foreach($sourceOpts as $opt)
                                    <option value="{{ $opt }}" {{ $pre['source'] === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                                @endforeach
                                @if($pre['source'] !== '' && !in_array($pre['source'], $sourceOpts, true))
                                    <option value="{{ $pre['source'] }}" selected>{{ $pre['source'] }}</option>
                                @endif
                            </select>
                        </form>
                        @if(!empty($pre['eventId']))
                            <a href="{{ route('events.edit', ['id' => $pre['eventId']]) }}" style="font-size:12px;">Open event</a>
                        @endif
                    @endif
                </td>
                <td data-order="{{ $wp['unitCount'] }}"><strong>{{ $wp['unitCount'] }}</strong></td>
                <td data-order="{{ $wp['total'] ?? 0 }}" style="white-space:nowrap;">
                    @if($wp['total'] !== null)<div>${{ number_format($wp['total'], 2) }}</div>@endif
                    @if(array_key_exists('paid', $wp) && $wp['paid'] === null)
                        <span class="sub">—</span>
                    @elseif(!empty($wp['paid']))
                        <span class="pill pill-paid">Paid</span>
                    @else
                        <span class="pill pill-unpaid">Unpaid</span>
                    @endif
                    @if($methodLabel !== '') <span class="sub">{{ $methodLabel }}</span> @endif
                </td>
                <td class="sub" data-order="{{ $placedTs }}" >@if($placedTs){{ date('n/j/y', $placedTs) }}<br>{{ date('g:ia', $placedTs) }}@else — @endif</td>
                @if($showStreetDate)
                <td data-order="{{ $shipTs ?: 0 }}" style="white-space:nowrap;">{{ ($shipTs && !$eventPickup) ? gmdate('n/j/y', $shipTs) : '—' }}</td>
                @endif
                <td data-order="{{ $statusSort }}">
                    @if($pickedUp)
                        <span class="label" style="background:#2e7d32; font-weight:700;">Picked up</span>
                    @elseif($pre)
                        <span class="label" style="background:#7a6a4a; font-weight:700;">{{ $pre['sourceTag'] }}</span>
                        @if($wp['status'] === 'ready_for_pickup')<br><span class="label label-warning">Ready for Pickup</span>@endif
                        @if(!empty($pre['pickup']))<br><span class="sub">Pickup {{ date('D, M j', strtotime($pre['pickup'])) }}</span>@endif
                    @elseif($wp['status'] === 'ready_for_pickup')
                        <span class="label label-warning">Ready for Pickup</span>
                    @elseif($eventPickup)
                        <span class="label" style="background:#2e7d32; font-weight:700;">Will pick up at event</span>
                    @elseif(!empty($wp['isPreorder']))
                        <span class="label" style="background:#c9720a; font-weight:700;">PREORDER - NOT IN STOCK</span><br>
                        @if($notYetDue)
                            <span class="sub" style="color:#a23;">Don't pull - ships {{ gmdate('M j, Y', $shipTs) }}</span>
                        @else
                            <span class="sub">Street date has passed - check it's in before pulling</span>
                        @endif
                    @else
                        <span class="label label-default">Preparing</span>
                    @endif
                </td>
                <td style="white-space:nowrap;">
                    @if($pickedUp)
                        <span class="sub">Done</span>
                    @elseif($pre)
                        @if($pre['type'] === 'event' && $pre['paidKnown'] && empty($pre['paid']))
                            <form method="POST" action="{{ route('events.overviewEventPaid', ['preorderId' => $pre['id']]) }}" style="display:inline;">
                                {{ csrf_field() }}
                                <input type="hidden" name="filter" value="">
                                <button type="submit" class="btn-ghost" style="padding:5px 12px;font-size:12px;">Mark paid</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ $pre['type'] === 'event' ? route('events.overviewEventPickup', ['preorderId' => $pre['id']]) : route('events.overviewSpecialPickup', ['id' => $pre['id']]) }}" style="display:inline;">
                            {{ csrf_field() }}
                            <input type="hidden" name="filter" value="">
                            <button type="submit" class="btn-accent" style="padding:5px 12px;font-size:12px;">Mark picked up</button>
                        </form>
                    @elseif(($wp['source'] ?? '') === 'store_hold')
                        <button type="button" class="btn btn-success btn-xs js-hold-picked-up" data-id="{{ $wp['id'] }}">Mark Picked Up</button>
                    @elseif($notYetDue)
                        <span class="sub">Available after street date</span>
                    @else
                        {{-- Change status from a dropdown (Sarah, 2026-10-06); event pickups too,
                             so leftover event orders can be set Ready for Pickup (2026-10-07).
                             Cancel stays on /website-orders since it can involve a refund. --}}
                        <form method="POST" action="{{ route('website-orders.updateStatus', ['id' => $wp['id']]) }}" style="display:inline;">
                            {{ csrf_field() }}
                            <select name="status" class="form-control input-sm" style="height:28px; padding:2px 6px; font-size:12px; width:auto;" onchange="this.form.submit()" aria-label="Order status">
                                @foreach(['processing' => 'Preparing', 'ready_for_pickup' => 'Ready for Pickup', 'picked_up' => 'Picked Up'] as $sv => $sl)
                                    <option value="{{ $sv }}" @if($wp['status'] === $sv) selected @endif>{{ $sl }}</option>
                                @endforeach
                            </select>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
</div>
