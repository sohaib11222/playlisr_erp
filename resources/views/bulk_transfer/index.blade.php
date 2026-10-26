@extends('layouts.app')

@section('title', 'Transfer Items')

@section('content')
@include('bulk_transfer.partials.style')

<div class="tr-wrap">
  <div class="tr-head">
    <h1>Transfer Items</h1>
    <p>Move a list of records from one store to another. Stock moves as a real stock transfer, so Stock History stays right. Each move gets an acceptance ID the other store scans to receive it. <a class="tr-link" href="{{ url('/transfers/receive') }}">Receive a transfer</a> &middot; <a class="tr-link" href="{{ url('/transfers/log') }}">Transfer log</a></p>
  </div>

  <div id="tr-result"></div>

  <div class="tr-card">
    <div class="tr-row">
      <div class="tr-field">
        <label for="tr-from">From store</label>
        <select id="tr-from">
          @foreach($locations as $id => $name)
            <option value="{{ $id }}" {{ (int) $id === (int) $default_from ? 'selected' : '' }}>{{ $name }}</option>
          @endforeach
        </select>
      </div>
      <button type="button" class="tr-btn tr-btn-swap" id="tr-swap" title="Swap From and To"><i class="fa fas fa-exchange-alt"></i></button>
      <div class="tr-field">
        <label for="tr-to">To store</label>
        <select id="tr-to">
          @foreach($locations as $id => $name)
            <option value="{{ $id }}" {{ (int) $id === (int) $default_to ? 'selected' : '' }}>{{ $name }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="tr-field" style="margin-bottom: 14px;">
      <label for="tr-list">Paste or scan product IDs / SKUs, one per line (a number like 158831 can be either)</label>
      <textarea id="tr-list" placeholder="158831&#10;0602455912345 x2&#10;AXM-004,3" autofocus>{{ $prefill ?? '' }}</textarea>
      <div class="tr-hint">One per line. Add a quantity with "SKU x2" or "SKU,2". Without one, it moves 1.</div>
    </div>

    <div class="tr-actions">
      <button type="button" class="tr-btn" id="tr-check">Check list</button>
      <button type="button" class="tr-btn tr-btn-primary" id="tr-move" disabled>Move items</button>
      <span class="tr-summary" id="tr-summary"></span>
    </div>
  </div>

  <div class="tr-card" id="tr-preview-card" style="display:none;">
    <h2>Check</h2>
    <div class="tr-table-wrap">
      <table class="tr-table">
        <thead><tr><th>Line</th><th>Artist - Title</th><th>SKU</th><th class="num">At From</th><th class="num">To move</th><th>Status</th></tr></thead>
        <tbody id="tr-preview"></tbody>
      </table>
    </div>
  </div>

  <div class="tr-card">
    <h2>Recent bulk transfers</h2>
    <div class="tr-table-wrap">
      <table class="tr-table">
        <thead><tr><th>Date</th><th>Acceptance ID</th><th>From / To</th><th class="num">Items</th><th>By</th><th>Status</th><th></th></tr></thead>
        <tbody id="tr-history"></tbody>
      </table>
    </div>
  </div>
</div>
@endsection

@section('javascript')
<script type="text/javascript">
(function () {
  var URL_PREVIEW = @json(url('/transfers/bulk/preview'));
  var URL_STORE = @json(url('/transfers/bulk'));
  var history = @json($history);
  var checkedKey = null;

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function num(n) { n = parseFloat(n || 0); return (Math.round(n * 100) / 100).toString(); }
  function payload() {
    return { from_location_id: $('#tr-from').val(), to_location_id: $('#tr-to').val(), list: $('#tr-list').val(),
      _token: $('meta[name="csrf-token"]').attr('content') };
  }
  function key() { var p = payload(); return p.from_location_id + '|' + p.to_location_id + '|' + p.list; }
  function pillClass(status) { return status === 'ok' ? 'ok' : (status === 'duplicate' ? 'dup' : 'bad'); }
  function msg(kind, html) { $('#tr-result').html('<div class="tr-msg ' + kind + '">' + html + '</div>'); }

  // Any edit after a check means the preview no longer matches what would move.
  function invalidate() {
    if (checkedKey !== null && checkedKey !== key()) {
      checkedKey = null;
      $('#tr-move').prop('disabled', true).text('Move items');
      $('#tr-summary').text('List changed, check it again.');
    }
  }
  $('#tr-from, #tr-to').on('change', invalidate);
  $('#tr-list').on('input', invalidate);
  $('#tr-swap').on('click', function () {
    var f = $('#tr-from').val(); $('#tr-from').val($('#tr-to').val()); $('#tr-to').val(f); invalidate();
  });

  function renderPreview(rows) {
    var html = '';
    rows.forEach(function (r) {
      html += '<tr class="' + (r.status === 'ok' ? '' : 'skip') + '">' +
        '<td>' + esc(r.line) + '</td>' +
        '<td>' + (r.name ? esc(r.name) : '<span class="tr-empty">' + esc(r.code) + '</span>') + '</td>' +
        '<td>' + esc(r.sku || '') + '</td>' +
        '<td class="num">' + (r.product_id ? num(r.qty_from) : '') + '</td>' +
        '<td class="num">' + (r.status === 'duplicate' ? '' : num(r.qty)) + '</td>' +
        '<td><span class="tr-pill ' + pillClass(r.status) + '">' + esc(r.status_text) + '</span></td></tr>';
    });
    $('#tr-preview').html(html || '<tr><td colspan="6" class="tr-empty">No lines.</td></tr>');
    $('#tr-preview-card').show();
  }

  function renderHistory() {
    if (!history.length) { $('#tr-history').html('<tr><td colspan="7" class="tr-empty">No bulk transfers yet.</td></tr>'); return; }
    var html = '';
    history.forEach(function (h) {
      var st = h.receipt_status === 'received' ? '<span class="tr-pill ok">Received</span>'
        : (h.receipt_status === 'received_missing' ? '<span class="tr-pill bad">Received, items missing</span>'
        : (h.receipt_status === 'in_transit' ? '<span class="tr-pill dup">On the way</span>' : ''));
      html += '<tr><td>' + esc(h.date) + '</td><td>' + esc(h.code || h.ref_no) + '</td><td>' + esc(h.from) + ' to ' + esc(h.to) + '</td>' +
        '<td class="num">' + esc(h.items) + '</td><td>' + esc(h.by) + '</td><td>' + st + '</td>' +
        '<td style="white-space:nowrap;">' + (h.slip_url ? '<a class="tr-link" target="_blank" href="' + esc(h.slip_url) + '">Slip</a> &middot; <a class="tr-link" target="_blank" href="' + esc(h.labels_url) + '">Labels</a>'
          : '<a href="#" class="tr-link print-invoice" data-href="' + esc(h.print_url) + '">Print</a>') + '</td></tr>';
    });
    $('#tr-history').html(html);
  }

  // Print links use the app's own a.print-invoice handler (public/js/app.js), same as the Stock Transfers list.

  $('#tr-check').on('click', function () {
    var $b = $(this).prop('disabled', true).text('Checking...');
    $('#tr-result').empty();
    $.post(URL_PREVIEW, payload()).done(function (res) {
      if (!res.success) { msg('bad', esc(res.msg)); return; }
      renderPreview(res.rows);
      checkedKey = key();
      var n = res.ok_qty;
      $('#tr-summary').text(res.ok_lines + ' of ' + res.rows.length + ' lines OK. Lines that are not OK are skipped.');
      $('#tr-move').prop('disabled', n <= 0).text('Move ' + num(n) + ' item' + (n == 1 ? '' : 's'));
    }).fail(function () { msg('bad', 'Check failed. Try again.'); })
      .always(function () { $b.prop('disabled', false).text('Check list'); });
  });
  // Came from Transfer Selected: check the list right away.
  if ($.trim($('#tr-list').val()) !== '') $('#tr-check').trigger('click');

  $('#tr-move').on('click', function () {
    if (checkedKey !== key()) return;
    var $b = $(this).prop('disabled', true).text('Moving...');
    $.post(URL_STORE, payload()).done(function (res) {
      if (!res.success) {
        msg('bad', esc(res.msg));
        if (res.rows) renderPreview(res.rows);
        $b.text('Move items');
        checkedKey = null;
        return;
      }
      var html = 'Moved ' + num(res.moved_qty) + ' item' + (res.moved_qty == 1 ? '' : 's') + ' (' + res.moved_lines + ' line' + (res.moved_lines == 1 ? '' : 's') + '). Acceptance ID <strong>' + esc(res.code) + '</strong>.' +
        '<div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">' +
        '<a class="tr-btn tr-btn-primary" target="_blank" href="' + esc(res.labels_url) + '">Print item labels</a>' +
        '<a class="tr-btn" target="_blank" href="' + esc(res.slip_url) + '">Print slip for the box</a></div>' +
        '<div style="margin-top:8px; font-weight:500;">Put a label on each item and the slip in the box. The other store scans the slip on Receive Transfer to accept it.</div>';
      if (res.skipped.length) {
        html += '<br>Skipped ' + res.skipped.length + ': ' + res.skipped.map(function (s) {
          return 'line ' + esc(s.line) + ' (' + esc(s.code) + ') ' + esc(s.status_text);
        }).join('; ') + '.';
      }
      msg('ok', html);
      history = res.history || history;
      renderHistory();
      $('#tr-list').val('');
      $('#tr-preview-card').hide();
      $('#tr-summary').text('');
      checkedKey = null;
      $b.text('Move items');
    }).fail(function () {
      msg('bad', 'Move failed. Check Recent bulk transfers before trying again.');
      $b.text('Move items');
      checkedKey = null;
    });
  });

  renderHistory();
})();
</script>
@endsection
