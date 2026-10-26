@extends('layouts.app')

@section('title', 'Receive Transfer')

@section('content')
@include('bulk_transfer.partials.style')

<div class="tr-wrap">
  <div class="tr-head">
    <h1>Receive Transfer</h1>
    <p>Scan the acceptance ID on the slip in the box (it starts with TR), then scan every item. <a class="tr-link" href="{{ url('/transfers/bulk') }}">Transfer Items</a> &middot; <a class="tr-link" href="{{ url('/transfers/log') }}">Transfer log</a></p>
  </div>

  @if(session('status'))
    <div class="tr-msg {{ session('status.success') ? 'ok' : 'bad' }}">{{ session('status.msg') }}</div>
  @endif

  <div class="tr-card">
    <form method="get" action="{{ url('/transfers/receive') }}" class="tr-row" style="margin-bottom:0;">
      <div class="tr-field">
        <label for="tr-code">Acceptance ID</label>
        <input id="tr-code" name="code" value="{{ request('code') }}" placeholder="Scan or type TR12345" autofocus
          style="border:1px solid var(--tr-line-2); border-radius:9px; padding:9px 11px; font-size:18px; height:44px; font-weight:700; letter-spacing:1px;">
      </div>
      <button type="submit" class="tr-btn tr-btn-primary" style="height:44px;">Open transfer</button>
    </form>
    @if($notFound)
      <div class="tr-msg bad" style="margin-top:12px;">No transfer with acceptance ID {{ request('code') }}.</div>
    @endif
  </div>

  @if($receipt)
    <div class="tr-card">
      <h2>{{ $receipt->code }}: {{ $receipt->from_name }} to {{ $receipt->to_name }}</h2>
      <p style="font-size:14px; margin:0 0 12px;">Sent {{ \Carbon::parse($receipt->sent_at)->format('m/d/y g:ia') }} by <strong>{{ $receipt->sent_by_name }}</strong>.
        @if($receipt->status !== 'in_transit')
          Received {{ \Carbon::parse($receipt->received_at)->format('m/d/y g:ia') }} by <strong>{{ $receipt->received_by_name }}</strong>.
        @endif
      </p>
      @if($receipt->status === 'in_transit' && $dutyLocation && $dutyLocation != $receipt->to_location_id)
        <div class="tr-msg bad">This box is going to {{ $receipt->to_name }}. You are signed in at another store. Only receive it if it is in front of you.</div>
      @endif

      @if($receipt->status === 'in_transit')
        <div class="tr-row">
          <div class="tr-field">
            <label for="tr-scan">Scan each item (barcode, SKU or product ID)</label>
            <input id="tr-scan" placeholder="Scan here" autocomplete="off"
              style="border:1px solid var(--tr-line-2); border-radius:9px; padding:9px 11px; font-size:16px; height:44px;">
          </div>
          <div class="tr-summary" id="tr-count"></div>
        </div>
        <div id="tr-scan-msg"></div>
      @endif

      <form method="post" action="{{ url('/transfers/receive/' . $receipt->id) }}" id="tr-receive-form">
        @csrf
        <div class="tr-table-wrap">
          <table class="tr-table">
            <thead><tr><th>Artist - Title</th><th>SKU</th><th class="num">Sent</th><th class="num">Got</th></tr></thead>
            <tbody>
            @foreach($items as $it)
              <tr data-vid="{{ $it['variation_id'] }}" data-pid="{{ $it['product_id'] }}" data-sku="{{ strtolower($it['sku']) }}" data-qty="{{ $it['qty'] }}">
                <td>{{ $it['name'] }}</td>
                <td>{{ $it['sku'] }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($it['qty'], 2), '0'), '.') }}</td>
                <td class="num">
                  @if($receipt->status === 'in_transit')
                    <input type="number" min="0" step="1" name="got[{{ $it['variation_id'] }}]" value="0" class="tr-got" style="width:70px; text-align:right; border:1px solid var(--tr-line-2); border-radius:7px; padding:4px 6px;">
                  @else
                    @php $miss = collect($receipt->missing_json ? json_decode($receipt->missing_json, true) : [])->firstWhere('product_id', $it['product_id']); @endphp
                    @if($miss)<span class="tr-pill bad">Missing {{ rtrim(rtrim(number_format($miss['qty'], 2), '0'), '.') }}</span>@else<span class="tr-pill ok">Got it</span>@endif
                  @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        @if($receipt->status === 'in_transit')
          <div class="tr-field" style="margin-top:14px;">
            <label for="tr-note">Note (optional)</label>
            <input id="tr-note" name="note" maxlength="250" placeholder="Anything wrong with the box?" style="border:1px solid var(--tr-line-2); border-radius:9px; padding:9px 11px; font-size:14px;">
          </div>
          <div class="tr-actions" style="margin-top:14px;">
            <button type="submit" class="tr-btn tr-btn-primary" id="tr-mark">Mark received</button>
            <span class="tr-hint" id="tr-mark-hint"></span>
          </div>
        @elseif($receipt->receive_note)
          <p style="margin-top:12px;"><strong>Note:</strong> {{ $receipt->receive_note }}</p>
        @endif
      </form>
    </div>
  @endif

  <div class="tr-card">
    <h2>On the way</h2>
    <div class="tr-table-wrap">
      <table class="tr-table">
        <thead><tr><th>Acceptance ID</th><th>From / To</th><th class="num">Items</th><th>Sent</th><th>By</th></tr></thead>
        <tbody>
        @forelse($inTransit as $r)
          <tr><td><a class="tr-link" href="{{ url('/transfers/receive?code=' . $r->code) }}">{{ $r->code }}</a></td><td>{{ $r->from }} to {{ $r->to }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($r->items, 2), '0'), '.') }}</td><td>{{ $r->sent_at }}</td><td>{{ $r->sent_by }}</td></tr>
        @empty
          <tr><td colspan="5" class="tr-empty">Nothing on the way.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection

@section('javascript')
<script type="text/javascript">
(function () {
  var $scan = $('#tr-scan');
  if (!$scan.length) return;
  $scan.trigger('focus');
  function count() {
    var sent = 0, got = 0;
    $('tr[data-vid]').each(function () {
      var q = parseFloat($(this).data('qty')) || 0, g = parseFloat($(this).find('.tr-got').val()) || 0;
      sent += q; got += Math.min(g, q);
      $(this).css('background', g >= q ? '#E4F0E7' : (g > 0 ? '#FFF8E1' : ''));
    });
    $('#tr-count').text(got + ' of ' + sent + ' scanned');
    $('#tr-mark-hint').text(got < sent ? (sent - got) + ' not scanned. They will be marked missing.' : 'Everything is here.');
  }
  $(document).on('input change', '.tr-got', count);
  $scan.on('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var code = $.trim($scan.val()).toLowerCase().replace(/^id\s*/, '');
    $scan.val('');
    if (!code) return;
    var $row = $('tr[data-vid]').filter(function () { return $(this).data('sku') == code || String($(this).data('pid')) === code; })
      .filter(function () { return (parseFloat($(this).find('.tr-got').val()) || 0) < (parseFloat($(this).data('qty')) || 0); }).first();
    if (!$row.length) {
      var any = $('tr[data-vid]').filter(function () { return $(this).data('sku') == code || String($(this).data('pid')) === code; }).length;
      $('#tr-scan-msg').html('<div class="tr-msg bad">' + (any ? 'Already scanned all of that one.' : 'That item is not on this transfer.') + '</div>');
      return;
    }
    var $g = $row.find('.tr-got'); $g.val((parseFloat($g.val()) || 0) + 1);
    $('#tr-scan-msg').html('<div class="tr-msg ok">' + $('<div>').text($row.find('td').first().text()).html() + '</div>');
    count();
  });
  $('#tr-receive-form').on('submit', function () { $('#tr-mark').prop('disabled', true).text('Saving...'); });
  count();
})();
</script>
@endsection
