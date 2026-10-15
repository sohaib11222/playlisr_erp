@extends('layouts.app')

@section('title', 'Warehouse Order')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

<style>
  .od-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; margin-bottom:18px; }
  .od-row { display:flex; justify-content:space-between; gap:10px; font-size:14px; padding:4px 0; }
  .od-row span:first-child { color:#6b7280; }
  .od-img { width:72px; height:72px; border-radius:8px; object-fit:cover; cursor:zoom-in; display:block; background:#e5e7eb; }
  .od-acts { display:flex; flex-direction:column; gap:6px; min-width:150px; }
  .od-acts .wh-btn { min-height:42px; }
  .od-acts input { font-size:13px; padding:7px 9px; border:1px solid #d1d5db; border-radius:7px; }
  .od-rate { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; margin-top:12px; font-size:14px; }
</style>

@php
  $WH = \App\Http\Controllers\WebsiteAdmin\WarehouseController::class;
  $tz = 'America/Los_Angeles';
@endphp

<div class="ev-wrap ev-wrap-wide">
  @if(!$order)
    <div class="ev-head"><div><h1>Order not found</h1><p class="sub">{{ $error }}</p></div></div>
    @include('website_admin.warehouse._nav')
  @else
    @php
      $oid = $order['_id'];
      $src = $order['source'] ?? '';
      $addr = $order['shipping_address'] ?? null;
      $pickup = ($order['fulfillment_method'] ?? '') === 'pickup';
      $pickupLabel = ['hollywood' => 'Hollywood', 'pico' => 'Pico'][$order['pickup_location'] ?? ''] ?? null;
      $tracking = $order['tracking_number'] ?? '';
      $curDiscogs = ($order['discogs_status'] ?? '') ?: ($order['status'] ?? '');
      $ordersLink = route('website-orders.index', array_filter([
        'tab' => $src === 'Discogs' ? 'to_ship' : null,
        'q' => $order['external_order_id'] ?? $order['order_number'] ?? '',
      ]));
    @endphp

    <div class="ev-head">
      <div>
        <h1>Order #{{ $order['order_number'] ?? substr($oid, -8) }}</h1>
        <p class="sub">{{ $src }} order · {{ $order['external_order_id'] ?? '' }}
          @if($src === 'Discogs' && !empty($order['external_order_id']))
            · <a class="wh-link" href="https://www.discogs.com/sell/order/{{ $order['external_order_id'] }}" target="_blank" rel="noopener">Open on Discogs</a>
          @endif
          · <a class="wh-link" href="{{ $ordersLink }}">Find on the ERP Orders page</a>
        </p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="wh-btn" onclick="document.getElementById('od-usps').showModal()" @if(!$addr) disabled title="This order has no shipping address." @endif>Print label</button>
        <button type="button" class="wh-btn wh-btn-danger" onclick="document.getElementById('od-void').showModal()" @if(!$tracking) disabled title="No tracking number to void." @endif>Void label</button>
        <button type="button" class="wh-btn wh-btn-green" id="od-all" onclick="odPickAll(this)">Mark all picked</button>
      </div>
    </div>

    @include('website_admin.warehouse._nav')

    <div class="wh-note info">Picks are saved under your ERP name, <b>{{ \App\Services\WebsiteBridge::actor() }}</b>.</div>

    <div class="od-grid">
      <div class="wh-card wh-card-pad" style="margin:0;">
        <h2>Customer</h2>
        <div style="font-weight:600;">{{ ($order['customer_name'] ?? '') ?: ($order['buyer']['name'] ?? '') }}</div>
        <div class="wh-muted" style="font-size:14px;">{{ ($order['customer_email'] ?? '') ?: ($order['buyer']['email'] ?? '') }}</div>
        @if(!empty($order['customer_phone']))<div style="font-size:14px;">{{ $order['customer_phone'] }}</div>@endif
      </div>
      <div class="wh-card wh-card-pad" style="margin:0;">
        <h2>{{ $pickup ? 'Pickup Location' : 'Shipping Address' }}</h2>
        @if($pickup)
          <div class="wh-note {{ $pickupLabel ? 'info' : 'err' }}" style="margin-bottom:8px;">In-store pickup, {{ $pickupLabel ?: 'No Location Set' }}</div>
        @endif
        @if(is_string($addr) && trim($addr) !== '')
          <div style="white-space:pre-line; font-size:14px;">{{ $addr }}</div>
        @elseif(is_array($addr))
          <div style="font-size:14px; line-height:1.5;">
            @if(($addr['name'] ?? '') || ($order['customer_name'] ?? ''))<div style="font-weight:600;">{{ ($addr['name'] ?? '') ?: $order['customer_name'] }}</div>@endif
            @if(!empty($addr['street']))<div>{{ $addr['street'] }}</div>@endif
            @if(!empty($addr['address2']))<div>{{ $addr['address2'] }}</div>@endif
            <div>{{ implode(', ', array_filter([$addr['city'] ?? null, ($addr['region'] ?? null) ?: ($addr['state'] ?? null), ($addr['postal_code'] ?? null) ?: ($addr['zip'] ?? null)])) }}</div>
            @if(!empty($addr['country']))<div class="wh-muted">{{ $addr['country'] }}</div>@endif
            @if(($addr['phone'] ?? '') || ($order['customer_phone'] ?? ''))<div>Phone: {{ ($addr['phone'] ?? '') ?: $order['customer_phone'] }}</div>@endif
          </div>
        @else
          <div class="wh-note warn" style="margin:0;">No shipping address on this order.</div>
        @endif
        @if($tracking)
          <div style="margin-top:8px; font-size:14px;">Tracking: <span class="wh-mono" style="font-weight:700;">{{ $tracking }}</span></div>
        @endif
      </div>
      <div class="wh-card wh-card-pad" style="margin:0;">
        <h2>Order Details</h2>
        <div class="od-row"><span>Status</span><span class="wh-pill {{ $WH::statusColor($order['status'] ?? $order['order_status'] ?? '') }}">{{ $order['status'] ?? $order['order_status'] ?? '' }}</span></div>
        <div class="od-row"><span>Fulfillment</span><span>{{ $pickup ? 'Pickup (' . ($pickupLabel ?: 'No Location Set') . ')' : 'Shipping' }}</span></div>
        <div class="od-row"><span>Picking</span><span class="wh-pill {{ $WH::pickingColor($order['overall_picking_status'] ?? '') }}">{{ $order['overall_picking_status'] ?? '' }}</span></div>
        <div class="od-row"><span>Total</span><span style="font-weight:700;">${{ number_format((float) ($order['total_amount'] ?? 0), 2) }} {{ $order['currency'] ?? '' }}</span></div>
        <div class="od-row"><span>Items</span><span>{{ count($order['items'] ?? []) }}</span></div>
        <div class="od-row"><span>Date</span><span>{{ !empty($order['order_date']) ? \Carbon\Carbon::parse($order['order_date'])->setTimezone($tz)->format('n/j/Y') : 'N/A' }}</span></div>
      </div>
    </div>

    @if($src === 'Discogs')
      <div class="wh-card wh-card-pad">
        <h2>Update Discogs Status</h2>
        <form method="POST" action="{{ route('wa.warehouse.discogsStatus', $oid) }}">
          {{ csrf_field() }}
          <div class="ev-row" style="align-items:flex-end;">
            <div class="ev-field"><label>Current status</label><div class="wh-pill {{ $WH::statusColor($curDiscogs) }}" style="font-size:14px; padding:8px 12px;">{{ $curDiscogs }}</div></div>
            <div class="ev-field">
              <label for="od-ds">New status</label>
              <select id="od-ds" name="status" onchange="odDs()">
                @foreach($settable as $s)
                  <option value="{{ $s }}" @if($s === $curDiscogs) selected @endif>{{ $s }}</option>
                @endforeach
              </select>
            </div>
            <div class="ev-field">
              <label for="od-tn">Tracking number (optional)</label>
              <input id="od-tn" type="text" name="tracking_number" value="{{ $tracking }}" placeholder="Tracking number">
            </div>
            <button type="submit" id="od-ds-btn" class="wh-btn wh-btn-primary">Update status on Discogs</button>
          </div>
          <p class="wh-muted" id="od-ds-hint" style="margin:6px 0 0;">This changes the order status on Discogs itself.</p>
        </form>
      </div>
      <script>
        function odDs() {
          var v = document.getElementById('od-ds').value;
          document.getElementById('od-ds-btn').disabled = (v === @json($curDiscogs));
          document.getElementById('od-ds-hint').textContent = v === 'Shipped'
            ? 'This changes the order status on Discogs. A tracking number is sent to the buyer in a Discogs message.'
            : 'This changes the order status on Discogs itself.';
        }
        odDs();
      </script>
    @endif

    <div class="wh-card wh-card-pad">
      <h2>Warehouse Notes</h2>
      <form method="POST" action="{{ route('wa.warehouse.notes', $oid) }}">
        {{ csrf_field() }}
        <div class="ev-field"><textarea name="warehouse_notes" rows="3" placeholder="Add warehouse notes here">{{ $order['warehouse_notes'] ?? '' }}</textarea></div>
        <button type="submit" class="wh-btn wh-btn-primary" style="margin-top:8px;">Save notes</button>
      </form>
    </div>

    <div class="wh-card">
      <div class="wh-card-pad" style="padding-bottom:0;"><h2>Items to pick</h2></div>
      <div class="wh-scroll">
        <table class="wh-tbl" style="min-width:980px;">
          <thead><tr><th>Item</th><th>Location / Zone</th><th>Photo</th><th>Qty</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            @foreach($order['items'] ?? [] as $it)
              @php
                $iid = $it['_id'] ?? '';
                $loc = $WH::effectiveLocation($it);
                $ps = $it['picking_status']['status'] ?? 'Not Picked';
                $by = $it['picking_status']['picked_by']['name'] ?? ($it['picking_status']['picked_by_erp_user'] ?? null);
                $img = ($it['image'] ?? '') ?: ($it['product_id']['image'] ?? '');
              @endphp
              <tr>
                <td style="max-width:280px;">
                  <div style="font-weight:600;">{{ $it['name'] ?? '' }}</div>
                  @if(!empty($it['product_id']['sku']))<div class="wh-muted">SKU {{ $it['product_id']['sku'] }}</div>@endif
                  @if(!empty($it['media_condition']) || !empty($it['sleeve_condition']))
                    <div class="wh-muted">{{ implode(' / ', array_filter([$it['media_condition'] ?? null, $it['sleeve_condition'] ?? null])) }}</div>
                  @endif
                </td>
                <td style="min-width:220px;">
                  @if($loc)
                    @if(!empty($loc['fromProduct']))<div class="wh-muted" style="font-size:10.5px; text-transform:uppercase;">From product catalog</div>@endif
                    @if(!empty($loc['zone']))<span class="wh-tag zone">{{ $loc['zone'] }}</span>@else<span class="wh-pill red">No zone set</span>@endif
                    @if(!empty($loc['store']))<div class="wh-muted">Store: {{ $loc['store'] }}</div>@endif
                    @if(!empty($loc['sub_location']))<span class="wh-tag sub">{{ $loc['sub_location'] }}</span>@endif
                    @if(!empty($loc['shelf_details']))<span class="wh-tag shelf">{{ $loc['shelf_details'] }}</span>@endif
                    @if(!empty($loc['discogs_location_code']))<span class="wh-tag code">{{ $loc['discogs_location_code'] }}</span>@endif
                  @else
                    <span class="wh-pill red">No location data</span>
                  @endif
                  <form method="POST" action="{{ route('wa.warehouse.location', ['id' => $oid, 'itemId' => $iid]) }}" style="display:flex; gap:6px; margin-top:8px;">
                    {{ csrf_field() }}
                    <select name="zone" style="font-size:13px; padding:6px; border:1px solid #d1d5db; border-radius:7px; max-width:170px;">
                      <option value="">Change zone</option>
                      @foreach($zones as $z)
                        <option value="{{ $z }}" @if(($loc['zone'] ?? '') === $z) selected @endif>{{ $z }}</option>
                      @endforeach
                    </select>
                    <button type="submit" class="wh-btn wh-btn-sm">Save</button>
                  </form>
                </td>
                <td>
                  @if($img)<img class="od-img" src="{{ $img }}" alt="" loading="lazy" onclick="odZoom(this.src)">@else<div class="od-img"></div>@endif
                </td>
                <td style="font-weight:700;">{{ $it['quantity'] ?? 1 }}</td>
                <td>${{ number_format((float) ($it['price'] ?? 0), 2) }}</td>
                <td>
                  <span class="wh-pill {{ $WH::pickingColor($ps) }}">{{ $ps }}</span>
                  @if($by && $ps !== 'Not Picked')<div class="wh-muted" style="margin-top:4px;">{{ $by }}@if(!empty($it['picking_status']['picked_at'])), {{ \Carbon\Carbon::parse($it['picking_status']['picked_at'])->setTimezone($tz)->format('M j, g:i a') }}@endif</div>@endif
                  @if(!empty($it['picking_status']['notes']))<div style="font-size:12.5px; color:#4b5563; margin-top:4px;">Note: {{ $it['picking_status']['notes'] }}</div>@endif
                </td>
                <td>
                  <div class="od-acts" data-item="{{ $iid }}">
                    @if($ps === 'Not Picked')
                      <button type="button" class="wh-btn wh-btn-green" onclick="odPick(this, 'Picked')">Picked</button>
                      <button type="button" class="wh-btn wh-btn-red" onclick="odPick(this, &quot;Can't Find&quot;)">Can't find</button>
                    @else
                      <button type="button" class="wh-btn" onclick="odPick(this, 'Not Picked')">Undo</button>
                    @endif
                    <input type="text" class="js-note" placeholder="Add a note (optional)">
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <dialog id="od-usps" class="wh-dialog">
      <div class="wh-dialog-body">
        <h3>USPS label</h3>
        <p class="wh-muted" style="margin:-6px 0 10px;">Pick a service, check the price, then buy the label.</p>
        <div id="od-usps-step1">
          <label for="od-svc">Service</label>
          <select id="od-svc" onchange="odSvcChanged()">
            @foreach($services as $v => $l)
              <option value="{{ $v }}" @if($v === 'GROUND_ADVANTAGE') selected @endif>{{ $l }}</option>
            @endforeach
          </select>
          <p class="wh-muted" style="margin-top:6px;">Media Mail is only for eligible items.</p>
          <button type="button" class="wh-btn" id="od-check" onclick="odCheck()" style="margin-top:6px;">Check price</button>
          <div class="od-rate" id="od-rate">Price not checked yet.</div>
          <p class="wh-muted" style="margin-top:10px;">Buying charges real postage.</p>
          <div class="wh-dialog-actions">
            <button type="button" class="wh-btn" onclick="document.getElementById('od-usps').close()">Close</button>
            <button type="button" class="wh-btn wh-btn-primary" id="od-buy" onclick="odBuy()" disabled>Buy and generate label</button>
          </div>
        </div>
        <div id="od-usps-step2" style="display:none;">
          <div class="wh-note info">Label created. Tracking number <b class="wh-mono" id="od-tn-out"></b> <span id="od-pp"></span></div>
          <div class="wh-dialog-actions" style="justify-content:flex-start;">
            <button type="button" class="wh-btn wh-btn-primary" onclick="odLabel('print')">Print label now</button>
            <button type="button" class="wh-btn" onclick="odLabel('download')">Download label (PDF)</button>
            <button type="button" class="wh-btn" onclick="location.reload()">Done</button>
          </div>
        </div>
      </div>
    </dialog>

    <dialog id="od-void" class="wh-dialog">
      <form method="POST" action="{{ route('wa.warehouse.uspsVoid', $oid) }}" class="wh-dialog-body">
        {{ csrf_field() }}
        <input type="hidden" name="tracking_number" value="{{ $tracking }}">
        <h3>Void this USPS label?</h3>
        <p style="font-size:14px;">Tracking <b class="wh-mono">{{ $tracking }}</b> is cancelled with USPS and cleared from the order.</p>
        <div class="wh-dialog-actions">
          <button type="button" class="wh-btn" onclick="document.getElementById('od-void').close()">Keep it</button>
          <button type="submit" class="wh-btn wh-btn-red">Void label</button>
        </div>
      </form>
    </dialog>

    <div id="od-msg" style="display:none; position:fixed; left:50%; bottom:22px; transform:translateX(-50%); background:#b91c1c; color:#fff; padding:12px 18px; border-radius:10px; font-size:14px; z-index:3000; max-width:90vw;"></div>
    <dialog id="od-zoom" class="wh-imgzoom" onclick="this.close()"><img id="od-zoom-img" src="" alt=""></dialog>

    <script>
      var odCsrf = @json(csrf_token());
      var odPickUrl = {!! json_encode(route('wa.warehouse.pick', ['orderId' => $oid, 'itemId' => '__I__']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      var odRatesUrl = {!! json_encode(route('wa.warehouse.uspsRates', $oid), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      var odBuyUrl = {!! json_encode(route('wa.warehouse.uspsLabel', $oid), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
      var odFile = @json('shipping-label-' . ($order['order_number'] ?? $oid));
      var odLabelData = null;
      var odMsgTimer = null;
      function odMsg(text) {
        var t = document.getElementById('od-msg');
        t.textContent = text; t.style.display = 'block';
        clearTimeout(odMsgTimer);
        odMsgTimer = setTimeout(function () { t.style.display = 'none'; }, 6000);
      }

      function odPost(url, body) {
        return fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': odCsrf },
          body: JSON.stringify(body)
        }).then(function (r) { return r.json().catch(function () { return { success: false, message: 'The ERP returned an error.' }; }); });
      }
      function odZoom(src) { document.getElementById('od-zoom-img').src = src; document.getElementById('od-zoom').showModal(); }
      function odPick(btn, status) {
        var box = btn.closest('.od-acts');
        box.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        var note = box.querySelector('.js-note').value.trim();
        var body = { status: status };
        if (note) body.notes = note;
        odPost(odPickUrl.replace('__I__', box.dataset.item), body).then(function (j) {
          if (!j.success) throw new Error(j.message);
          location.reload();
        }).catch(function (e) {
          box.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          odMsg((e && e.message) || 'Could not save the pick.');
        });
      }
      function odPickAll(btn) {
        btn.disabled = true; btn.textContent = 'Saving...';
        odPost(odPickUrl.replace('__I__', 'all'), { status: 'Picked' }).then(function (j) {
          if (!j.success) throw new Error(j.message);
          location.reload();
        }).catch(function (e) {
          btn.disabled = false; btn.textContent = 'Mark all picked';
          odMsg((e && e.message) || 'Could not save the picks.');
        });
      }
      function odSvcChanged() {
        document.getElementById('od-buy').disabled = true;
        document.getElementById('od-rate').textContent = 'Price not checked yet.';
      }
      function odCheck() {
        var b = document.getElementById('od-check');
        b.disabled = true; b.textContent = 'Checking...';
        fetch(odRatesUrl + '?service=' + encodeURIComponent(document.getElementById('od-svc').value), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            b.disabled = false; b.textContent = 'Check price';
            var out = document.getElementById('od-rate');
            if (!j.success) { out.textContent = j.message || 'Could not get USPS rates.'; return; }
            if (!j.rate) { out.textContent = 'USPS returned no rates for this order.'; return; }
            out.innerHTML = '';
            var name = document.createElement('div'); name.style.fontWeight = '700'; name.textContent = j.rate.name || '';
            var price = document.createElement('div');
            price.textContent = (j.rate.price != null ? '$' + Number(j.rate.price).toFixed(2) : 'No price') + (j.rate.days != null ? ', about ' + j.rate.days + ' days' : '');
            out.appendChild(name); out.appendChild(price);
            document.getElementById('od-buy').disabled = false;
          })
          .catch(function () { b.disabled = false; b.textContent = 'Check price'; document.getElementById('od-rate').textContent = 'Could not get USPS rates.'; });
      }
      function odBuy() {
        var b = document.getElementById('od-buy');
        b.disabled = true; b.textContent = 'Buying label...';
        odPost(odBuyUrl, { service: document.getElementById('od-svc').value }).then(function (j) {
          if (!j.success) throw new Error(j.message);
          odLabelData = j.label;
          document.getElementById('od-tn-out').textContent = j.label.trackingNumber || '';
          document.getElementById('od-pp').textContent = j.paypalSynced ? 'Tracking was sent to PayPal.' : '';
          document.getElementById('od-usps-step1').style.display = 'none';
          document.getElementById('od-usps-step2').style.display = 'block';
        }).catch(function (e) {
          b.disabled = false; b.textContent = 'Buy and generate label';
          document.getElementById('od-rate').textContent = (e && e.message) || 'Could not create the label.';
        });
      }
      function odLabel(mode) {
        var raw = odLabelData && odLabelData.labelImage;
        if (!raw) { document.getElementById('od-pp').textContent = 'The label has no image. Find it in the USPS account.'; return; }
        if (/^https?:\/\//.test(raw)) { window.open(raw, '_blank'); return; }
        var b64 = String(raw).indexOf(',') >= 0 ? String(raw).split(',').pop() : String(raw);
        var bin = atob(b64), bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        var url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        if (mode === 'download') {
          var a = document.createElement('a');
          a.href = url; a.download = odFile + '-' + (odLabelData.trackingNumber || 'label') + '.pdf';
          document.body.appendChild(a); a.click(); a.remove();
        } else {
          var w = window.open(url, '_blank');
          if (!w) { document.getElementById('od-pp').textContent = 'Pop-ups are blocked. Allow pop-ups to print, or download the label.'; return; }
          setTimeout(function () { try { w.focus(); w.print(); } catch (e) {} }, 800);
        }
        setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      }
    </script>
  @endif
</div>
@endsection
