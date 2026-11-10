@php
    $st = $r['status'] ?? 'pending';
    $fmt = function ($d) {
        try { return $d ? \Carbon\Carbon::parse($d)->format('m/d/y') : ''; } catch (\Throwable $e) { return (string) $d; }
    };
    $rid = $r['id'] ?? '';
    $hasDetails = !empty($r['eta']) || !empty($r['tracking']) || !empty($r['manager_note']);
@endphp
{{-- Sarah 11/10/26: one compact row per request. Changing the status saves
     right away; ETA / tracking / note to employee sit behind "Details". --}}
<tr class="sr-row sr-{{ $st }}">
    @php
        $storeName = $r['location_name'] ?: 'No store';
        $sk = strtolower($storeName);
        $storeClass = strpos($sk, 'hollywood') !== false ? 'sr-hw' : (strpos($sk, 'pico') !== false ? 'sr-pico' : (strpos($sk, 'warehouse') !== false || strpos($sk, 'discogs') !== false ? 'sr-wh' : 'sr-none'));
    @endphp
    <td class="sr-store"><span class="sr-pill {{ $storeClass }}">{{ $storeName }}</span></td>
    <td class="sr-item">
        <strong>{{ $r['item'] ?? '' }}</strong>@if(!empty($r['qty'])) <span class="sr-qty">x {{ $r['qty'] }}</span>@endif
        @if(!empty($r['note']))<div class="sr-note">&ldquo;{{ $r['note'] }}&rdquo;</div>@endif
        @if(!empty($r['manager_note']) || !empty($r['eta']) || !empty($r['tracking']))
            <div class="sr-note">
                @if(!empty($r['eta']))Arriving {{ $r['eta'] }}. @endif
                @if(!empty($r['tracking']))Tracking {{ $r['tracking'] }}. @endif
                @if(!empty($r['manager_note']))Note: {{ $r['manager_note'] }}@endif
            </div>
        @endif
    </td>
    <td class="sr-who">{{ $r['requested_by_name'] ?? 'staff' }}<br>{{ $fmt($r['requested_at'] ?? '') }}</td>
    <td class="sr-act">
        <form method="POST" action="{{ action('SupplyRequestController@update') }}" class="sr-form">
            @csrf
            <input type="hidden" name="id" value="{{ $rid }}">
            <select name="status" class="sr-status" onchange="this.form.submit()">
                @foreach ($statuses as $val => $label)
                    <option value="{{ $val }}" @if($st === $val) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
            <details class="sr-details">
                <summary>Details</summary>
                <div class="sr-fields">
                    <input type="text" name="eta" placeholder="Arriving (ETA)" value="{{ $r['eta'] ?? '' }}">
                    <input type="text" name="tracking" placeholder="Tracking" value="{{ $r['tracking'] ?? '' }}">
                    <input type="text" name="manager_note" placeholder="Note to employee" value="{{ $r['manager_note'] ?? '' }}">
                    <button type="submit" class="sr-save">Save</button>
                </div>
            </details>
        </form>
    </td>
</tr>
