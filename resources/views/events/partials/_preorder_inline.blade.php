{{-- One preorder rendered inline inside the guest table: item + price, a
     status pill, and the Ready / Picked up / Cancel actions. Expects $p (the
     preorder) and $event in scope (Blade @include inherits parent vars). --}}
@php
  $st = $p['status'] ?? 'pending';
  $isPaid = !empty($p['paid']);
  if ($st === 'picked_up')    { $ptxt = 'picked up';     $pcls = 'sold'; }
  elseif ($st === 'ready')    { $ptxt = $isPaid ? 'ready · paid' : 'ready'; $pcls = 'sold'; }
  elseif ($isPaid)            { $ptxt = 'paid at event';  $pcls = 'sold'; }
  else                        { $ptxt = str_replace('_', ' ', $st); $pcls = ''; }
  $pid = $p['_id'] ?? $p['id'] ?? '';
@endphp
<div style="margin-bottom:6px;">
  <div>
    <span class="ev-name">{{ $p['preorderTitle'] ?? '' }}</span>@if(isset($p['preorderPrice'])) <span class="ev-meta">${{ number_format((float) $p['preorderPrice'], 2) }}</span>@endif
  </div>
  <div style="margin:3px 0;"><span class="pill {{ $pcls }}">{{ $ptxt }}</span></div>
  @if($pid)
    <div style="white-space:nowrap;">
      @foreach(['ready' => 'Ready', 'picked_up' => 'Picked up', 'canceled' => 'Cancel'] as $sval => $slabel)
        @if($st !== $sval)
        <form method="POST" action="{{ route('events.preorderStatus', ['id' => $event['id'], 'preorderId' => $pid]) }}" style="display:inline-block;margin:2px 4px 2px 0;">
          {{ csrf_field() }}
          <input type="hidden" name="status" value="{{ $sval }}">
          <button type="submit" class="{{ $sval === 'canceled' ? 'btn-ghost' : 'btn-accent' }}" style="padding:4px 10px;font-size:12px;">{{ $slabel }}</button>
        </form>
        @endif
      @endforeach
    </div>
  @endif
</div>
