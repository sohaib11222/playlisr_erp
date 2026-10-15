{{-- One preorder rendered inline inside the guest table: item + price, a
     status pill, and the Ready / Picked up / Cancel actions. Expects $p (the
     preorder) and $event in scope (Blade @include inherits parent vars). --}}
@php
  $st = $p['status'] ?? 'pending';
  if ($st === 'picked_up')    { $ptxt = 'picked up';        $pcls = 'sold'; }
  elseif ($st === 'ready')    { $ptxt = 'ready';            $pcls = 'sold'; }
  else                        { $ptxt = 'paid for at event'; $pcls = 'sold'; }
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
  {{-- Paid / unpaid and an internal note, as on the website's preorders page (Sarah, 2026-10-15). --}}
  @if($pid)
    <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:2px;">
      @if(array_key_exists('paid', $p))
        <span class="ev-meta">{{ !empty($p['paid']) ? 'Paid' : 'Not paid' }}</span>
        <form method="POST" action="{{ route('wa.directory.preorderPaid', ['preorderId' => $pid]) }}" style="display:inline;">
          {{ csrf_field() }}
          <input type="hidden" name="paid" value="{{ !empty($p['paid']) ? 0 : 1 }}">
          <button type="submit" class="btn-link" style="color:#2563eb; padding:0; font-size:12px;">{{ !empty($p['paid']) ? 'Mark unpaid' : 'Mark paid' }}</button>
        </form>
      @endif
    </div>
    <form method="POST" action="{{ route('wa.directory.preorderNotes', ['preorderId' => $pid]) }}" style="display:flex; gap:6px; margin-top:4px;">
      {{ csrf_field() }}
      <input type="text" name="adminNotes" value="{{ $p['adminNotes'] ?? '' }}" placeholder="Internal note" maxlength="2000" style="font-size:12px; padding:3px 7px; border:1px solid #d1d5db; border-radius:6px; min-width:0; flex:1;">
      <button type="submit" class="btn-ghost" style="padding:3px 9px; font-size:12px;">Save</button>
    </form>
  @endif
</div>
