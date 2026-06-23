@php $e = $event ?? []; @endphp
<div class="ev-row">
  <div class="ev-field" style="flex:3 1 280px;">
    <label>Event name *</label>
    <input type="text" name="name" required value="{{ $e['name'] ?? '' }}" placeholder="e.g. New Wave Listening Party">
  </div>
  <div class="ev-field" style="flex:2 1 200px;">
    <label>Type *</label>
    <select name="eventType" required>
      @foreach($eventTypes as $val => $label)
        <option value="{{ $val }}" {{ ($e['eventType'] ?? 'listening_party') === $val ? 'selected' : '' }}>{{ $label }}</option>
      @endforeach
    </select>
  </div>
  <div class="ev-field" style="flex:2 1 200px;">
    <label>Genre</label>
    <select name="genre">
      <option value="">— none —</option>
      @foreach($genres as $val => $label)
        <option value="{{ $val }}" {{ ($e['genre'] ?? null) === $val ? 'selected' : '' }}>{{ $label }}</option>
      @endforeach
    </select>
  </div>
</div>

<div class="ev-row">
  <div class="ev-field" style="flex:1 1 150px;">
    <label>Date *</label>
    <input type="date" name="date" required value="{{ $e['date'] ?? '' }}">
  </div>
  <div class="ev-field" style="flex:1 1 110px;">
    <label>Start time *</label>
    <input type="time" name="time" required value="{{ $e['time'] ?? '' }}">
  </div>
  <div class="ev-field" style="flex:1 1 110px;">
    <label>End time</label>
    <input type="time" name="endTime" value="{{ $e['endTime'] ?? '' }}">
  </div>
  <div class="ev-field" style="flex:1 1 150px;">
    <label>Street date</label>
    <input type="date" name="streetDate" value="{{ $e['streetDate'] ?? '' }}">
  </div>
</div>

{{-- What we ordered, per store and per SKU. Only the store(s) this event is
     at are shown (both, for a brand-new event). --}}
@php
  $ordered = (array) ($e['ordered'] ?? []);
  $orderStores = ['hollywood' => 'Hollywood', 'pico' => 'Pico'];
  $orderSkus = ['indieVinyl' => 'Indie vinyl', 'stdVinyl' => 'Standard vinyl', 'deluxeVinyl' => 'Deluxe vinyl', 'cassette' => 'Cassette', 'stdCd' => 'Standard CD', 'deluxeCd' => 'Deluxe CD'];
  $orderLocs = (array) ($e['location'] ?? []);
  $shownStores = $orderLocs ?: array_keys($orderStores);
@endphp
<div class="ev-row">
  <div class="ev-field" style="flex:1 1 100%;">
    <label>What we ordered</label>
    <div style="display:flex;flex-wrap:wrap;gap:18px;">
      @foreach($orderStores as $sk => $slabel)
        @if(in_array($sk, $shownStores, true))
          <div style="border:1px solid var(--pos-line,#ECE3CF);border-radius:10px;padding:10px 12px;">
            <div style="font-weight:700;font-size:12px;margin-bottom:8px;">{{ $slabel }}</div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">
              @foreach($orderSkus as $sku => $skuLabel)
                <div style="display:flex;flex-direction:column;width:84px;">
                  <label style="font-size:11px;color:#6b6253;margin-bottom:3px;">{{ $skuLabel }}</label>
                  <input type="number" min="0" name="ordered[{{ $sk }}][{{ $sku }}]"
                         value="{{ $ordered[$sk][$sku] ?? '' }}" placeholder="—"
                         style="padding:6px 8px;border:1px solid var(--pos-line-2);border-radius:8px;font-size:13px;width:100%;">
                </div>
              @endforeach
            </div>
          </div>
        @endif
      @endforeach
    </div>
  </div>
</div>

<div class="ev-row">
  <div class="ev-field" style="flex:2 1 240px;">
    <label>Location</label>
    <div class="ev-checks" style="padding-top:6px;">
      @php $locs = (array) ($e['location'] ?? []); @endphp
      <label><input type="checkbox" name="location[]" value="hollywood" {{ in_array('hollywood', $locs) ? 'checked' : '' }}> Hollywood</label>
      <label><input type="checkbox" name="location[]" value="pico" {{ in_array('pico', $locs) ? 'checked' : '' }}> Pico</label>
    </div>
  </div>
  <div class="ev-field" style="flex:1 1 180px;">
    <label>Hollywood area</label>
    <select name="locationDetail">
      <option value="">— n/a —</option>
      <option value="stage" {{ ($e['locationDetail'] ?? null) === 'stage' ? 'selected' : '' }}>Stage</option>
      <option value="basement" {{ ($e['locationDetail'] ?? null) === 'basement' ? 'selected' : '' }}>Basement</option>
    </select>
  </div>
  <div class="ev-field" style="flex:3 1 280px;">
    <label>Image URL</label>
    <input type="text" name="image" value="{{ $e['image'] ?? '' }}" placeholder="https://nivessa.com/imageNiv/...">
  </div>
</div>

<div class="ev-row">
  <div class="ev-field" style="flex:1 1 100%;">
    <label>Description</label>
    <textarea name="description" placeholder="Event description shown on nivessa.com">{{ $e['description'] ?? '' }}</textarea>
  </div>
</div>

{{-- ---------- Preorder ---------- --}}
@php $pre = filter_var($e['preorderEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN); @endphp
<div class="ev-row">
  <div class="ev-field" style="flex:1 1 100%;">
    <label class="ev-checks" style="margin:0;">
      <input type="checkbox" name="preorderEnabled" value="1" id="pre-toggle" {{ $pre ? 'checked' : '' }}> Enable preorder for this event
    </label>
  </div>
</div>
<div id="pre-fields" style="{{ $pre ? '' : 'display:none;' }}">
  <div class="ev-row">
    <div class="ev-field" style="flex:1 1 150px;">
      <label>Pickup date</label>
      <input type="date" name="preorderPickupDate" value="{{ $e['preorderPickupDate'] ?? '' }}">
    </div>
    <div class="ev-field" style="flex:2 1 240px;">
      <label>Preorder note</label>
      <input type="text" name="preorderNote" value="{{ $e['preorderNote'] ?? '' }}">
    </div>
  </div>
  <div class="ev-row">
    <div class="ev-field" style="flex:1 1 100%;">
      <label>Preorder products (customers choose one)</label>
      @php
        $pp = array_values((array) ($e['preorderProducts'] ?? []));
        // Back-compat: seed from the old single title/price if no list yet.
        if (empty($pp) && !empty($e['preorderTitle'])) { $pp = [['title' => $e['preorderTitle'], 'price' => $e['preorderPrice'] ?? null]]; }
        $rows = max(6, count($pp) + 1);
      @endphp
      <div style="display:flex;flex-direction:column;gap:8px;max-width:480px;">
        @for($i = 0; $i < $rows; $i++)
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <input type="text" name="preorderProducts[{{ $i }}][title]" value="{{ $pp[$i]['title'] ?? '' }}" placeholder="Product, e.g. Indie LP" style="flex:2 1 240px;padding:7px 9px;border:1px solid var(--pos-line-2);border-radius:8px;font-size:13px;">
            <input type="number" step="0.01" min="0" name="preorderProducts[{{ $i }}][price]" value="{{ $pp[$i]['price'] ?? '' }}" placeholder="Price $" style="flex:0 1 110px;padding:7px 9px;border:1px solid var(--pos-line-2);border-radius:8px;font-size:13px;">
          </div>
        @endfor
      </div>
      <div class="ev-meta" style="margin-top:6px;">Leave a row blank to skip it. Customers pick one of these on the preorder page.</div>
    </div>
  </div>
</div>

<script>
(function () {
  var t = document.getElementById('pre-toggle');
  var f = document.getElementById('pre-fields');
  if (t && f) { t.addEventListener('change', function () { f.style.display = t.checked ? '' : 'none'; }); }
})();
</script>
