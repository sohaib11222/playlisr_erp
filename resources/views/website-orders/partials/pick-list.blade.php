{{-- Pick list for one shipping order (Sarah, 2026-10-15): the same warehouse
     pick records nivessa.com/admin/warehouse writes, so earlier picks made
     there show here. Buttons save in place (no reload) via woPick(). --}}
@php $pickDone = ['Picked', 'Packed', 'Shipped']; @endphp
<div class="wo-pick-count" style="font-size:11px;font-weight:600;color:{{ ($pick['picked'] ?? 0) >= ($pick['total'] ?? 0) ? '#047857' : '#000' }};">picked <span class="n">{{ $pick['picked'] ?? 0 }}</span>/<span class="t">{{ $pick['total'] ?? 0 }}</span></div>
@if(!empty($pick['order_id']) && !empty($pick['lines']))
  <div class="wo-pick" data-order="{{ $pick['order_id'] }}">
    @foreach($pick['lines'] as $pl)
      @php
        $plStatus = $pl['status'] ?? 'Not Picked';
        $plDone = in_array($plStatus, $pickDone, true);
        $plMissing = $plStatus === "Can't Find";
      @endphp
      <div class="wo-pick-line {{ $plDone ? 'is-done' : ($plMissing ? 'is-missing' : '') }}" data-item="{{ $pl['item_id'] }}" data-qty="{{ (int) ($pl['quantity'] ?? 1) }}">
        <div class="wo-pick-name">{{ \Illuminate\Support\Str::limit($pl['name'] ?? 'Item', 40) }} @if(($pl['quantity'] ?? 1) > 1) x{{ (int) $pl['quantity'] }} @endif</div>
        <div class="wo-pick-state">{{ $plDone ? $plStatus : ($plMissing ? "Can't find" : 'Not picked') }} @if(!empty($pl['picked_by'])) by {{ $pl['picked_by'] }} @endif @if(!empty($pl['picked_at'])) {{ date('n/j g:ia', strtotime($pl['picked_at'])) }} @endif</div>
        <div class="wo-pick-btns">
          @if($plDone || $plMissing)
            <button type="button" onclick="woPick(this, 'Not Picked')">Undo</button>
          @else
            <button type="button" class="go" onclick="woPick(this, 'Picked')">Picked</button>
            <button type="button" onclick="woPick(this, 'Can\'t Find')">Can't find</button>
          @endif
        </div>
      </div>
    @endforeach
    @if(count($pick['lines']) > 1)
      <button type="button" class="wo-pick-all" onclick="woPick(this, 'Picked', true)" @if(($pick['picked'] ?? 0) >= ($pick['total'] ?? 0)) hidden @endif>All picked</button>
    @endif
  </div>
@endif
