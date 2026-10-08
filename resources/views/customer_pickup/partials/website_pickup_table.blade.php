{{-- One pickup list: website orders, in-store event holds, and party /
     special-order preorders (Sarah, 2026-10-08). Older (30+ day) rows get
     .row-older for the Older tab. Expects $rows, $tableId. --}}
<div class="table-responsive">
<table class="table wp-table" id="{{ $tableId }}" style="width:100%; font-size:13px;">
    <thead>
        <tr>
            <th>#</th>
            <th>Type</th>
            <th>Customer</th>
            <th>Item(s)</th>
            <th>Qty</th>
            <th>Paid</th>
            <th>Placed</th>
            <th>Street</th>
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
                $sourceLabel = $pre ? $pre['sourceTag'] : (($wp['source'] ?? '') === 'store_hold' ? 'In-store hold' : 'Web order');
                // Ready but before the street date: they can only collect from that day.
                $streetTs = $shipTs ?: ($pre && !empty($pre['pickup']) ? strtotime($pre['pickup']) : null);
                $readyFrom = ($wp['status'] ?? '') === 'ready_for_pickup' && $streetTs && $streetTs > strtotime('today') ? $streetTs : null;
                $storeLabel = $wp['location'] === 'pico' ? 'Pico' : ($wp['location'] === 'hollywood' || !$pre ? 'Hollywood' : '—');
            @endphp
            <tr @if($pickedUp) class="row-picked-up" @elseif(isset($isOlder) && $isOlder($wp)) class="row-older" @elseif(!empty($wp['isPreorder'])) style="background:#fff7e0;" @endif>
                <td data-order="{{ $loop->iteration }}">{{ $loop->iteration }}</td>
                <td data-order="{{ $sourceLabel }}">
                    <span class="src-tag">{{ $sourceLabel }}</span>
                    @if($storeLabel !== '—')<span class="status-sub">{{ $storeLabel }}</span>@endif
                    @if($pre && $pre['type'] === 'event')
                        <div class="item-meta src-meta">
                        <form method="POST" action="{{ route('events.overviewEventSource', ['preorderId' => $pre['id']]) }}" style="margin:0;">
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
                            <a href="{{ route('events.edit', ['id' => $pre['eventId']]) }}">Open event</a>
                        @endif
                        </div>
                    @endif
                </td>
                <td data-order="{{ strtolower($wp['customer']) }}" class="cust-cell">
                    <strong>{{ $wp['customer'] }}</strong>
                    @if($wp['email'] !== '')<span class="status-sub cust-line">{!! str_replace('@', '<wbr>@', e($wp['email'])) !!}</span>@endif
                    @php
                        // One phone format for every row: (843) 714-9197 (Sarah, 2026-10-08).
                        $digits = preg_replace('/\D/', '', (string) $wp['phone']);
                        if (strlen($digits) === 11 && $digits[0] === '1') { $digits = substr($digits, 1); }
                        $phoneOut = strlen($digits) === 10
                            ? '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6)
                            : $wp['phone'];
                    @endphp
                    @if($wp['phone'])<span class="status-sub">{{ $phoneOut }}</span>@endif
                </td>
                <td data-order="{{ strtolower(implode(', ', $wp['items'])) }}" class="item-cell">
                    @forelse($wp['items'] as $itemLabel)
                        <div>{{ $itemLabel }}</div>
                    @empty
                        —
                    @endforelse
                </td>
                <td data-order="{{ $wp['unitCount'] }}"><strong>{{ $wp['unitCount'] }}</strong></td>
                <td data-order="{{ $wp['total'] ?? 0 }}">
                    @if($wp['total'] !== null)<div>${{ number_format($wp['total'], 2) }}</div>@endif
                    @if(array_key_exists('paid', $wp) && $wp['paid'] === null)
                        <span class="sub">—</span>
                    @elseif(!empty($wp['paid']))
                        <span class="pill pill-paid">Paid</span>
                    @else
                        <span class="pill pill-unpaid">Unpaid</span>
                    @endif
                    @if($methodLabel !== '')<span class="status-sub">{{ $methodLabel }}</span>@endif
                </td>
                <td class="sub" data-order="{{ $placedTs }}" >@if($placedTs){{ date('n/j/y', $placedTs) }}@else — @endif</td>
                <td data-order="{{ $shipTs ?: 0 }}" style="white-space:nowrap;">{{ ($shipTs && !$eventPickup) ? gmdate('n/j/y', $shipTs) : '—' }}</td>
                <td data-order="{{ $statusSort }}">
                    @if($pickedUp)
                        <span class="label" style="background:#2e7d32;">Picked up</span>
                    @elseif($pre)
                        @if($readyFrom)
                            <span class="label pill-ready-from">Ready from {{ gmdate('M j', $readyFrom) }}</span>
                        @elseif($wp['status'] === 'ready_for_pickup')
                            <span class="label pill-ready">Ready for Pickup</span>
                        @else
                            <span class="label" style="background:#7a6a4a;">Preorder</span>
                        @endif
                        @if(!empty($pre['pickup']))<span class="status-sub">Pickup {{ date('D M j', strtotime($pre['pickup'])) }}</span>@endif
                    @elseif(!empty($wp['waitingStock']))
                        <span class="label" style="background:#6a5acd;">Waiting on Stock</span>
                        <span class="status-sub">Customer not notified</span>
                    @elseif($readyFrom)
                        <span class="label pill-ready-from">Ready from {{ gmdate('M j', $readyFrom) }}</span>
                        <span class="status-sub">Can't pick up before street date</span>
                    @elseif($wp['status'] === 'ready_for_pickup')
                        <span class="label pill-ready">Ready for Pickup</span>
                    @elseif($eventPickup)
                        <span class="label" style="background:#2e7d32;">Pickup at event</span>
                    @elseif(!empty($wp['isPreorder']))
                        <span class="label" style="background:#c9720a;">Preorder, not in stock</span>
                        @if($notYetDue)
                            <span class="status-sub" style="color:#a23;">Don't pull, ships {{ gmdate('M j, Y', $shipTs) }}</span>
                        @else
                            <span class="status-sub">Street date passed, check it's in</span>
                        @endif
                    @else
                        <span class="label label-default">Preparing</span>
                    @endif
                </td>
                <td>
                    @if($pickedUp)
                        <span class="sub">Done</span>
                    @elseif($pre)
                        {{-- Same dropdown as the website rows; picking "Picked Up" posts to the preorder pickup endpoint. --}}
                        <form method="POST" action="{{ $pre['type'] === 'event' ? route('events.overviewEventPickup', ['preorderId' => $pre['id']]) : route('events.overviewSpecialPickup', ['id' => $pre['id']]) }}" style="margin:0;">
                            {{ csrf_field() }}
                            <input type="hidden" name="filter" value="">
                            <select class="act-select" onchange="if (this.value === 'picked_up') { this.form.submit(); }" aria-label="Order status">
                                <option value="" selected>{{ $wp['status'] === 'ready_for_pickup' ? 'Ready for Pickup' : 'Waiting' }}</option>
                                <option value="picked_up">Picked Up</option>
                            </select>
                        </form>
                        @if($pre['type'] === 'event' && $pre['paidKnown'] && empty($pre['paid']))
                            <form method="POST" action="{{ route('events.overviewEventPaid', ['preorderId' => $pre['id']]) }}" style="margin:0;">
                                {{ csrf_field() }}
                                <input type="hidden" name="filter" value="">
                                <button type="submit" class="btn-ghost act-btn">Mark paid</button>
                            </form>
                        @endif
                    @elseif(($wp['source'] ?? '') === 'store_hold')
                        <button type="button" class="btn-ghost act-btn js-hold-picked-up" data-id="{{ $wp['id'] }}" style="margin-top:0;">Mark picked up</button>
                    @else
                        {{-- Change status from a dropdown (Sarah, 2026-10-06); event pickups too,
                             so leftover event orders can be set Ready for Pickup (2026-10-07).
                             Cancel stays on /website-orders since it can involve a refund. --}}
                        <form method="POST" action="{{ route('website-orders.updateStatus', ['id' => $wp['id']]) }}" style="margin:0;">
                            {{ csrf_field() }}
                            <select name="status" class="act-select" onchange="this.form.submit()" aria-label="Order status">
                                {{-- Waiting on Stock is ERP-only, never sent to the website, so no customer notice (2026-10-08). --}}
                                @php $curStatus = !empty($wp['waitingStock']) ? 'waiting_stock' : $wp['status']; @endphp
                                @php $readyLabel = ($streetTs && $streetTs > strtotime('today')) ? 'Ready from ' . gmdate('M j', $streetTs) : 'Ready for Pickup'; @endphp
                                @foreach(['processing' => 'Preparing', 'waiting_stock' => 'Waiting on Stock', 'ready_for_pickup' => $readyLabel, 'picked_up' => 'Picked Up'] as $sv => $sl)
                                    <option value="{{ $sv }}" @if($curStatus === $sv) selected @endif>{{ $sl }}</option>
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
