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
  {{-- Was missing here, so every save blanked it (Sarah, 2026-10-15). --}}
  <div class="ev-field" style="flex:2 1 200px;">
    <label>Artist sounds like</label>
    <input type="text" name="artistSoundsLike" maxlength="191" value="{{ $e['artistSoundsLike'] ?? '' }}" placeholder="e.g. SZA meets Kaytranada">
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

{{-- What we ordered, per store and per SKU. Always shows both stores —
     a store can carry stock for a release even when it isn't hosting the
     party (e.g. Pico stocking a Hollywood-only listening party). --}}
@php
  $ordered = (array) ($e['ordered'] ?? []);
  $orderStores = ['hollywood' => 'Hollywood', 'pico' => 'Pico'];
  $orderSkus = ['indieVinyl' => 'Indie vinyl', 'stdVinyl' => 'Standard vinyl', 'deluxeVinyl' => 'Deluxe vinyl', 'cassette' => 'Cassette', 'stdCd' => 'Standard CD', 'deluxeCd' => 'Deluxe CD'];
  $shownStores = array_keys($orderStores);
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
  <div class="ev-field" style="flex:3 1 280px;">
    <label>Image URL</label>
    <input type="text" name="image" value="{{ $e['image'] ?? '' }}" placeholder="https://nivessa.com/imageNiv/..." data-ev-image-url>
    {{-- Upload instead of pasting a link: the file goes to the website's image host and its URL lands above (Sarah, 2026-10-15). --}}
    <div style="display:flex; gap:8px; align-items:center; margin-top:6px; flex-wrap:wrap;">
      <input type="file" accept="image/*" data-ev-image-file style="font-size:12.5px;">
      <span class="ev-meta" data-ev-image-msg></span>
    </div>
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
<div class="ev-row">
  <div class="ev-field" style="flex:1 1 100%;">
    <label class="ev-checks" style="margin:0;">
      <input type="checkbox" name="hidePreorders" value="1" {{ filter_var($e['hidePreorders'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}> Taking sales at the event - hide preorders on this event's page
    </label>
    <div class="ev-meta" style="margin-top:4px;">Hides the preorder column and "Add preorder" buttons on this event's RSVP page. Use when you're ringing sales at the event instead of taking preorders ahead of time.</div>
  </div>
</div>
<div id="pre-fields" style="{{ $pre ? '' : 'display:none;' }}">
  <div class="ev-row">
    <div class="ev-field" style="flex:2 1 240px;">
      <label>Preorder note</label>
      <input type="text" name="preorderNote" value="{{ $e['preorderNote'] ?? '' }}">
      <div class="ev-meta" style="margin-top:4px;">Pickup date = the event's Street date (set above).</div>
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
        $fmtOpts = \App\Http\Controllers\EventsController::orderSkus(); // version => label
      @endphp
      <div style="display:flex;flex-direction:column;gap:8px;max-width:620px;">
        @for($i = 0; $i < $rows; $i++)
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <input type="text" name="preorderProducts[{{ $i }}][title]" value="{{ $pp[$i]['title'] ?? '' }}" placeholder="Product, e.g. Daughter From Hell" style="flex:2 1 220px;padding:7px 9px;border:1px solid var(--pos-line-2);border-radius:8px;font-size:13px;">
            <select name="preorderProducts[{{ $i }}][format]" style="flex:1 1 130px;padding:7px 9px;border:1px solid var(--pos-line-2);border-radius:8px;font-size:13px;">
              <option value="">Format…</option>
              @foreach($fmtOpts as $fk => $flabel)
                <option value="{{ $fk }}" {{ ($pp[$i]['format'] ?? '') === $fk ? 'selected' : '' }}>{{ $flabel }}</option>
              @endforeach
            </select>
            <input type="number" step="0.01" min="0" name="preorderProducts[{{ $i }}][price]" value="{{ $pp[$i]['price'] ?? '' }}" placeholder="Price $" style="flex:0 1 100px;padding:7px 9px;border:1px solid var(--pos-line-2);border-radius:8px;font-size:13px;">
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

<script>
(function () {
  document.querySelectorAll('[data-ev-image-file]').forEach(function (inp) {
    if (inp.dataset.wired) return;
    inp.dataset.wired = '1';
    inp.addEventListener('change', function () {
      var box = inp.closest('.ev-field');
      var url = box.querySelector('[data-ev-image-url]');
      var msg = box.querySelector('[data-ev-image-msg]');
      if (!inp.files || !inp.files[0]) return;
      var fd = new FormData();
      fd.append('image', inp.files[0]);
      msg.textContent = 'Uploading...';
      fetch('{{ route('wa.directory.eventImageUpload') }}', {
        method: 'POST', credentials: 'same-origin', body: fd,
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.success && j.url) { url.value = j.url; msg.textContent = 'Uploaded. Save the event to keep it.'; }
        else { msg.textContent = j.message || 'Upload failed.'; }
      }).catch(function () { msg.textContent = 'Upload failed.'; });
    });
  });
})();
</script>
