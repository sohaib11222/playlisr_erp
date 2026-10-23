@extends('layouts.app')

@section('title', 'Transfer Items')

@section('content')
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
<script>document.body.classList.add('transfer-v2');</script>

<style>
body.transfer-v2 {
  --tr-bg: #FAF6EE; --tr-surface: #FFFFFF; --tr-surface-2: #F7F1E3; --tr-ink: #1F1B16; --tr-ink-2: #4A4036;
  --tr-line: #ECE3CF; --tr-line-2: #DFD2B3; --tr-success: #2F6B3E; --tr-success-soft: #E4F0E7;
  --tr-danger: #7A1F1F; --tr-danger-soft: #F6E4E4; --tr-sel: #DCEBFF; --tr-sel-ink: #1F4E8C;
}
body.transfer-v2 .content-wrapper { background: var(--tr-bg); }
body.transfer-v2 .tr-wrap { max-width: 1100px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--tr-ink); }
body.transfer-v2 .tr-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.transfer-v2 .tr-head p { color: var(--tr-ink-2); margin: 0 0 18px; font-size: 14px; }
body.transfer-v2 .tr-card { background: var(--tr-surface); border: 1px solid var(--tr-line); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
body.transfer-v2 .tr-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
body.transfer-v2 .tr-row { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 14px; }
body.transfer-v2 .tr-field { display: flex; flex-direction: column; gap: 6px; min-width: 220px; flex: 1; }
body.transfer-v2 .tr-field label { font-size: 12px; font-weight: 700; color: var(--tr-ink-2); margin: 0; }
body.transfer-v2 select, body.transfer-v2 textarea {
  border: 1px solid var(--tr-line-2); border-radius: 9px; padding: 9px 11px; font-size: 14px; font-family: inherit;
  background: #fff; color: var(--tr-ink); box-shadow: none; width: 100%; }
body.transfer-v2 select { height: 40px; }
body.transfer-v2 textarea { min-height: 220px; font-family: ui-monospace, Menlo, monospace; font-size: 14px; line-height: 1.5; resize: vertical; }
body.transfer-v2 select:focus, body.transfer-v2 textarea:focus { outline: none; border-color: var(--tr-sel-ink); box-shadow: 0 0 0 3px var(--tr-sel); }
body.transfer-v2 .tr-hint { font-size: 13px; color: var(--tr-ink-2); margin-top: 6px; }
body.transfer-v2 .tr-btn { border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit;
  display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--tr-line-2); background: var(--tr-surface); color: var(--tr-ink); text-decoration: none; }
body.transfer-v2 .tr-btn:hover { background: var(--tr-surface-2); color: var(--tr-ink); }
body.transfer-v2 .tr-btn-primary { background: var(--tr-success); border-color: var(--tr-success); color: #fff; }
body.transfer-v2 .tr-btn-primary:hover { background: #24552F; color: #fff; }
body.transfer-v2 .tr-btn[disabled] { opacity: .55; cursor: not-allowed; }
body.transfer-v2 .tr-btn-swap { height: 40px; padding: 0 12px; }
body.transfer-v2 .tr-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
body.transfer-v2 .tr-summary { font-size: 14px; font-weight: 600; }
body.transfer-v2 .tr-table-wrap { overflow-x: auto; }
body.transfer-v2 table.tr-table { width: 100%; border-collapse: collapse; }
body.transfer-v2 .tr-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--tr-ink-2);
  font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--tr-line); white-space: nowrap; }
body.transfer-v2 .tr-table td { padding: 10px; border-bottom: 1px solid var(--tr-line); font-size: 13.5px; vertical-align: middle; color: var(--tr-ink); }
body.transfer-v2 .tr-table td.num { text-align: right; white-space: nowrap; }
body.transfer-v2 .tr-table tr.skip td { background: #FDF9F2; }
body.transfer-v2 .tr-pill { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; border: 1px solid transparent; white-space: nowrap; }
body.transfer-v2 .tr-pill.ok { background: var(--tr-success-soft); color: var(--tr-success); border-color: #C6DFCC; }
body.transfer-v2 .tr-pill.bad { background: var(--tr-danger-soft); color: var(--tr-danger); border-color: #EBC9C9; }
body.transfer-v2 .tr-pill.dup { background: var(--tr-sel); color: var(--tr-sel-ink); border-color: #BCD6F7; }
body.transfer-v2 .tr-msg { border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; font-size: 14px; font-weight: 600; }
body.transfer-v2 .tr-msg.ok { background: var(--tr-success-soft); color: var(--tr-success); border: 1px solid #C6DFCC; }
body.transfer-v2 .tr-msg.bad { background: var(--tr-danger-soft); color: var(--tr-danger); border: 1px solid #EBC9C9; }
body.transfer-v2 .tr-msg a { color: inherit; text-decoration: underline; }
body.transfer-v2 .tr-empty { color: var(--tr-ink-2); font-size: 14px; }
body.transfer-v2 .tr-link { color: var(--tr-sel-ink); font-weight: 600; cursor: pointer; }
@media (max-width: 640px) { body.transfer-v2 .tr-field { min-width: 100%; } }
</style>

<div class="tr-wrap">
  <div class="tr-head">
    <h1>Transfer Items</h1>
    <p>Move a list of records from one store to another. Stock moves as a real stock transfer, so Stock History stays right.</p>
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
      <textarea id="tr-list" placeholder="158831&#10;0602455912345 x2&#10;AXM-004,3" autofocus></textarea>
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
        <thead><tr><th>Date</th><th>Ref</th><th>From / To</th><th class="num">Items</th><th>By</th><th></th></tr></thead>
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
    if (!history.length) { $('#tr-history').html('<tr><td colspan="6" class="tr-empty">No bulk transfers yet.</td></tr>'); return; }
    var html = '';
    history.forEach(function (h) {
      html += '<tr><td>' + esc(h.date) + '</td><td>' + esc(h.ref_no) + '</td><td>' + esc(h.from) + ' to ' + esc(h.to) + '</td>' +
        '<td class="num">' + esc(h.items) + '</td><td>' + esc(h.by) + '</td>' +
        '<td><a href="#" class="tr-link print-invoice" data-href="' + esc(h.print_url) + '">Print</a></td></tr>';
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
      var html = 'Moved ' + num(res.moved_qty) + ' item' + (res.moved_qty == 1 ? '' : 's') + ' (' + res.moved_lines + ' line' + (res.moved_lines == 1 ? '' : 's') + '), stock transfer ' + esc(res.ref_no) + '. ' +
        '<a href="#" class="print-invoice" data-href="' + esc(res.print_url) + '">Print transfer</a> or see <a href="' + esc(res.list_url) + '">all stock transfers</a>.';
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
