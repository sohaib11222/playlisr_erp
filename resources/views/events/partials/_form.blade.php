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
  <div class="ev-field" style="flex:0 1 110px;">
    <label>Vinyl ordered</label>
    <input type="number" min="0" name="orderedVinyl" value="{{ $e['orderedVinyl'] ?? '' }}" placeholder="—">
  </div>
  <div class="ev-field" style="flex:0 1 110px;">
    <label>CD ordered</label>
    <input type="number" min="0" name="orderedCd" value="{{ $e['orderedCd'] ?? '' }}" placeholder="—">
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
<div class="ev-row" id="pre-fields" style="{{ $pre ? '' : 'display:none;' }}">
  <div class="ev-field" style="flex:2 1 220px;">
    <label>Preorder title</label>
    <input type="text" name="preorderTitle" value="{{ $e['preorderTitle'] ?? '' }}">
  </div>
  <div class="ev-field" style="flex:1 1 120px;">
    <label>Price $</label>
    <input type="number" step="0.01" min="0" name="preorderPrice" value="{{ $e['preorderPrice'] ?? '' }}">
  </div>
  <div class="ev-field" style="flex:1 1 150px;">
    <label>Pickup date</label>
    <input type="date" name="preorderPickupDate" value="{{ $e['preorderPickupDate'] ?? '' }}">
  </div>
  <div class="ev-field" style="flex:2 1 220px;">
    <label>Preorder note</label>
    <input type="text" name="preorderNote" value="{{ $e['preorderNote'] ?? '' }}">
  </div>
</div>

<script>
(function () {
  var t = document.getElementById('pre-toggle');
  var f = document.getElementById('pre-fields');
  if (t && f) { t.addEventListener('change', function () { f.style.display = t.checked ? '' : 'none'; }); }
})();
</script>
