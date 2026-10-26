@extends('layouts.app')

@section('title', 'Transfer Log')

@section('content')
@include('bulk_transfer.partials.style')

<div class="tr-wrap" style="max-width:1300px;">
  <div class="tr-head">
    <h1>Transfer Log</h1>
    <p>Every Transfer Items move: who sent it, who received it, and what was missing. <a class="tr-link" href="{{ url('/transfers/bulk') }}">Transfer Items</a> &middot; <a class="tr-link" href="{{ url('/transfers/receive') }}">Receive a transfer</a></p>
  </div>
  <div class="tr-actions" style="margin-bottom:14px;">
    @foreach(['' => 'All', 'in_transit' => 'On the way', 'received_missing' => 'Items missing', 'received' => 'Received'] as $k => $label)
      <a href="{{ url('/transfers/log' . ($k ? '?status=' . $k : '')) }}" class="tr-btn" style="{{ $status === $k ? 'background:var(--tr-sel); color:var(--tr-sel-ink); border-color:#BCD6F7;' : '' }}">{{ $label }}</a>
    @endforeach
  </div>
  <div class="tr-card">
    <div class="tr-table-wrap">
      <table class="tr-table">
        <thead><tr><th>Acceptance ID</th><th>From / To</th><th class="num">Items</th><th>Sent</th><th>Sent by</th><th>Status</th><th>Received</th><th>Received by</th><th></th></tr></thead>
        <tbody>
        @forelse($rows as $r)
          <tr>
            <td><a class="tr-link" href="{{ url('/transfers/receive?code=' . $r->code) }}">{{ $r->code }}</a></td>
            <td>{{ $r->from }} to {{ $r->to }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($r->items, 2), '0'), '.') }}</td>
            <td>{{ $r->sent_at }}</td>
            <td>{{ $r->sent_by }}</td>
            <td>
              @if($r->status === 'received')<span class="tr-pill ok">Received</span>
              @elseif($r->status === 'received_missing')<span class="tr-pill bad">{{ count($r->missing) }} missing</span>
              @else<span class="tr-pill dup">On the way</span>@endif
            </td>
            <td>{{ $r->received_at }}</td>
            <td>{{ $r->received_by }}</td>
            <td style="white-space:nowrap;"><a class="tr-link" target="_blank" href="{{ url('/transfers/slip/' . $r->id) }}">Slip</a> &middot; <a class="tr-link" target="_blank" href="{{ url('/transfers/labels/' . $r->id) }}">Labels</a></td>
          </tr>
          @if($r->missing || $r->note)
            <tr><td></td><td colspan="8" style="font-size:13px;">
              @if($r->missing)<strong>Missing:</strong> {{ collect($r->missing)->map(function ($m) { return $m['name'] . ($m['qty'] > 1 ? ' (x' . $m['qty'] . ')' : ''); })->implode('; ') }}@endif
              @if($r->note)<br><strong>Note:</strong> {{ $r->note }}@endif
            </td></tr>
          @endif
        @empty
          <tr><td colspan="9" class="tr-empty">No transfers yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
