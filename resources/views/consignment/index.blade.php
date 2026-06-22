@extends('layouts.app')

@section('title', 'Consignment')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .consign-wrap { max-width: 1100px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .consign-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .consign-wrap .sub { color: #6b6253; margin: 0 0 20px; font-size: 14px; }
body.pos-v2 .consign-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 22px; }
body.pos-v2 .consign-card h2 { font-size: 17px; font-weight: 700; margin: 0 0 14px; }
body.pos-v2 .consign-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 12px; }
body.pos-v2 .consign-field { display: flex; flex-direction: column; gap: 5px; }
body.pos-v2 .consign-field label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .consign-field input, body.pos-v2 .consign-field select {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 9px 11px; font-size: 14px;
  font-family: inherit; background: #fff; min-width: 0; }
body.pos-v2 .consign-field input:focus, body.pos-v2 .consign-field select:focus {
  outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .items-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; }
body.pos-v2 .items-tbl th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
  color: #8a8070; font-weight: 700; padding: 6px 8px; }
body.pos-v2 .items-tbl td { padding: 5px 8px; }
body.pos-v2 .items-tbl input { width: 100%; border: 1px solid var(--pos-line-2); border-radius: 8px;
  padding: 8px 9px; font-size: 14px; font-family: inherit; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); }
body.pos-v2 .btn-ghost { background: transparent; border: 1px dashed var(--pos-line-2); border-radius: 9px;
  padding: 8px 14px; font-weight: 600; font-size: 13px; cursor: pointer; color: #5a5145; font-family: inherit; }
body.pos-v2 .btn-link { background: none; border: none; color: #a23b3b; cursor: pointer; font-size: 13px; padding: 4px; }
body.pos-v2 .pay-tbl { width: 100%; border-collapse: collapse; }
body.pos-v2 .pay-tbl th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
  color: #8a8070; font-weight: 700; padding: 8px 10px; border-bottom: 1px solid var(--pos-line); }
body.pos-v2 .pay-tbl td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 14px; vertical-align: middle; }
body.pos-v2 .owed { font-weight: 700; }
body.pos-v2 .owed.has { color: #2e7d32; }
body.pos-v2 .pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 999px;
  background: var(--pos-accent-soft); color: var(--pos-accent-text); border: 1px solid var(--pos-line-2); }
body.pos-v2 .pill.sold { background: #e7f3e8; color: #2e7d32; border-color: #bfe0c2; }
body.pos-v2 .pill.paid { background: #eef0f4; color: #555; border-color: #d8dce4; }
body.pos-v2 .pill.partial { background: #fdf3e0; color: #8a5a14; border-color: #f0dcb4; }
body.pos-v2 .total-owed { font-size: 15px; font-weight: 700; margin-bottom: 14px; }
body.pos-v2 .alert-ok { background: #e7f3e8; border: 1px solid #bfe0c2; color: #226128; border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; }
body.pos-v2 .alert-err { background: #fbe9e9; border: 1px solid #efc4c4; color: #9a2c2c; border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; }
body.pos-v2 .empty { color: #8a8070; padding: 14px 4px; font-size: 14px; }
body.pos-v2 details.consignor-block { border: 1px solid var(--pos-line); border-radius: 12px; margin-bottom: 12px; background: #fffdf7; }
body.pos-v2 details.consignor-block > summary { list-style: none; cursor: pointer; padding: 13px 16px; display: flex;
  align-items: center; justify-content: space-between; gap: 12px; }
body.pos-v2 details.consignor-block > summary::-webkit-details-marker { display: none; }
body.pos-v2 .cz-name { font-weight: 700; font-size: 15px; }
body.pos-v2 .cz-meta { color: #8a8070; font-size: 12.5px; font-weight: 500; }
body.pos-v2 .cz-body { padding: 0 16px 14px; }
body.pos-v2 .inline-pay { display: flex; gap: 8px; align-items: center; margin: 6px 0 14px; flex-wrap: wrap; }
body.pos-v2 .inline-pay input[type=text] { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 8px 10px; font-size: 13px; font-family: inherit; }
</style>

<div class="consign-wrap">
  <h1>Consignment</h1>
  <p class="sub">Take in records on consignment, sell them through POS like any other product, and track what you owe each artist. The artist is paid their split of each sale only when the record actually sells.</p>

  @if(session('status'))
    <div class="alert-ok">{{ session('status') }}</div>
  @endif
  @if(session('error'))
    <div class="alert-err">{{ session('error') }}</div>
  @endif

  {{-- ---------- Intake ---------- --}}
  <form class="consign-card" method="POST" action="{{ route('consignment.store') }}">
    {{ csrf_field() }}
    <h2>New consignment intake</h2>

    <div class="consign-row">
      <div class="consign-field" style="flex:2 1 220px;">
        <label>Artist / consignor *</label>
        <input type="text" name="consignor" required placeholder="e.g. Maya Cole">
      </div>
      <div class="consign-field" style="flex:1 1 120px;">
        <label>Artist split % *</label>
        <input type="number" name="pct" required step="0.01" min="0" max="100" value="60">
      </div>
    </div>

    <div class="consign-row">
      <div class="consign-field" style="flex:1 1 180px;">
        <label>Phone *</label>
        <input type="text" name="phone" required placeholder="(555) 123-4567">
      </div>
      <div class="consign-field" style="flex:1 1 200px;">
        <label>Email *</label>
        <input type="email" name="email" required placeholder="artist@email.com">
      </div>
      <div class="consign-field" style="flex:1 1 200px;">
        <label>How to pay them *</label>
        <input type="text" name="payment_method" required placeholder="Venmo @handle / Zelle / cash">
      </div>
    </div>

    <div class="consign-row">
      <div class="consign-field" style="flex:1 1 200px;">
        <label>Location *</label>
        <select name="location_id" required>
          @foreach($locations as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
          @endforeach
        </select>
      </div>
      <div class="consign-field" style="flex:1 1 200px;">
        <label>Category</label>
        <select name="category_id">
          <option value="">— none —</option>
          @foreach($categories as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <table class="items-tbl" id="items-tbl">
      <thead>
        <tr>
          <th style="width:42%;">Title *</th>
          <th style="width:30%;">Artist on label</th>
          <th style="width:14%;">Sticker $</th>
          <th style="width:8%;">Qty</th>
          <th style="width:6%;"></th>
        </tr>
      </thead>
      <tbody id="items-body">
        @for($i = 0; $i < 4; $i++)
        <tr>
          <td><input type="text" name="items[{{ $i }}][title]" placeholder="Record title"></td>
          <td><input type="text" name="items[{{ $i }}][artist]" placeholder="(defaults to consignor)"></td>
          <td><input type="number" name="items[{{ $i }}][sticker]" step="0.01" min="0" placeholder="0.00"></td>
          <td><input type="number" name="items[{{ $i }}][qty]" min="1" value="1"></td>
          <td><button type="button" class="btn-link" onclick="this.closest('tr').remove()">remove</button></td>
        </tr>
        @endfor
      </tbody>
    </table>

    <div style="display:flex;gap:10px;margin-top:14px;align-items:center;">
      <button type="button" class="btn-ghost" id="add-row">+ Add another record</button>
      <button type="submit" class="btn-accent">Take in &amp; create products</button>
    </div>
    <p class="sub" style="margin-top:10px;margin-bottom:0;">Each record becomes a normal POS product priced at its sticker. Cost is $0 — the artist's cut is only owed once it sells.</p>
  </form>

  {{-- ---------- Payables ---------- --}}
  <div class="consign-card">
    <h2>What you owe</h2>
    <div class="total-owed">Outstanding to artists: <span class="owed {{ $total_owed > 0 ? 'has' : '' }}">${{ number_format($total_owed, 2) }}</span></div>

    @if(empty($consignors))
      <div class="empty">No consignment records yet. Take some in above.</div>
    @else
      @foreach($consignors as $cz)
      <details class="consignor-block" {{ $cz['owed'] > 0 ? 'open' : '' }}>
        <summary>
          <span>
            <span class="cz-name">{{ $cz['name'] }}</span>
            @if($cz['phone'])<span class="cz-meta"> &middot; {{ $cz['phone'] }}</span>@endif
            @if($cz['email'])<span class="cz-meta"> &middot; {{ $cz['email'] }}</span>@endif
            @if($cz['payment_method'])<span class="cz-meta"> &middot; pay: {{ $cz['payment_method'] }}</span>@endif
            <span class="cz-meta"> &middot; {{ $cz['in_stock'] }} in stock, {{ $cz['sold'] }} sold</span>
          </span>
          <span class="owed {{ $cz['owed'] > 0 ? 'has' : '' }}">${{ number_format($cz['owed'], 2) }} owed</span>
        </summary>
        <div class="cz-body">
          @if($cz['owed'] > 0)
          <form class="inline-pay" method="POST" action="{{ route('consignment.markPaid') }}"
                onsubmit="return confirm('Record ${{ number_format($cz['owed'], 2) }} paid to {{ addslashes($cz['name']) }}? This clears what is owed.');">
            {{ csrf_field() }}
            <input type="hidden" name="consignor" value="{{ $cz['name'] }}">
            <input type="text" name="note" placeholder="payout note (optional)">
            <button type="submit" class="btn-accent">Mark ${{ number_format($cz['owed'], 2) }} paid</button>
          </form>
          @endif

          <table class="pay-tbl">
            <thead>
              <tr><th>Record</th><th>Sticker</th><th>Split</th><th>Status</th><th style="text-align:right;">Owed</th><th style="text-align:right;">Paid</th></tr>
            </thead>
            <tbody>
              @foreach($cz['items'] as $it)
              <tr>
                <td>
                  <strong>{{ $it['title'] }}</strong>
                  @if(!empty($it['artist'])) <span class="cz-meta">/ {{ $it['artist'] }}</span>@endif
                  <span class="cz-meta"> &middot; #{{ $it['product_id'] }}</span>
                </td>
                <td>${{ number_format($it['sticker'], 2) }}</td>
                <td>{{ rtrim(rtrim(number_format($it['pct'], 2), '0'), '.') }}%</td>
                <td>
                  @php $st = $it['status'] ?? 'in_stock'; @endphp
                  @if($st === 'sold')<span class="pill sold">sold</span>
                  @elseif($st === 'paid')<span class="pill paid">paid</span>
                  @elseif($st === 'partially_sold')<span class="pill partial">{{ (int)$it['sold_qty'] }}/{{ (int)$it['qty'] }} sold</span>
                  @else<span class="pill">in stock</span>@endif
                </td>
                <td style="text-align:right;" class="owed {{ ($it['owed'] ?? 0) > 0 ? 'has' : '' }}">${{ number_format($it['owed'] ?? 0, 2) }}</td>
                <td style="text-align:right;color:#8a8070;">${{ number_format($it['paid'] ?? 0, 2) }}</td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </details>
      @endforeach
    @endif
  </div>
</div>

<script>
(function () {
  var body = document.getElementById('items-body');
  var addBtn = document.getElementById('add-row');
  var n = {{ 4 }};
  addBtn.addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" name="items[' + n + '][title]" placeholder="Record title"></td>' +
      '<td><input type="text" name="items[' + n + '][artist]" placeholder="(defaults to consignor)"></td>' +
      '<td><input type="number" name="items[' + n + '][sticker]" step="0.01" min="0" placeholder="0.00"></td>' +
      '<td><input type="number" name="items[' + n + '][qty]" min="1" value="1"></td>' +
      '<td><button type="button" class="btn-link">remove</button></td>';
    tr.querySelector('.btn-link').addEventListener('click', function () { tr.remove(); });
    body.appendChild(tr);
    n++;
  });
})();
</script>
@endsection
