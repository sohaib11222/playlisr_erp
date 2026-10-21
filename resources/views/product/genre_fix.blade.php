@extends('layouts.app')
@section('title', 'Genre Fix')
@section('content')
@php
  $all = collect($fixes)->flatten(1)->sortByDesc('stock')->values();
  $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:18px;';
  $big = 'padding:12px 22px;border-radius:8px;border:0;background:#1e40af;color:#fff;font-weight:700;font-size:16px;cursor:pointer;';
  $pill = 'padding:10px 16px;border-radius:8px;border:1px solid #93c5fd;background:#dbeafe;color:#1e40af;font-weight:700;font-size:15px;cursor:pointer;';
  $td = 'padding:6px 8px;border-bottom:1px solid #e5e7eb;';
  $cards = array_slice($look, 0, 300);
@endphp
<section class="content" style="padding:20px;max-width:900px;color:#000;">
  <h1 style="font-size:24px;margin:0 0 14px;">Genre Fix</h1>
  @if(session('status'))<div style="padding:10px 14px;background:{{ session('status')['success'] ? '#dcfce7' : '#fee2e2' }};border-radius:6px;margin-bottom:14px;font-size:15px;">{{ session('status')['msg'] }}</div>@endif

  <div style="{{ $box }}background:#dbeafe;border-color:#93c5fd;">
    <div style="font-size:16px;font-weight:700;margin-bottom:4px;">Discogs is checking every record by itself</div>
    <div style="font-size:15px;">{{ number_format($dc['checked']) }} of {{ number_format($dc['total']) }} checked. {{ number_format($dc['fixed']) }} wrong genres fixed, {{ number_format($dc['filled']) }} missing genres filled. Nothing to do here.</div>
    @if(!empty($dc['recent']))
      <details style="margin-top:8px;">
        <summary style="cursor:pointer;font-size:15px;color:#1e40af;">See the latest changes</summary>
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;background:#fff;">
          @foreach(array_slice($dc['recent'], 0, 100) as $r)
            <tr>
              <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a></td>
              <td style="{{ $td }}">{{ $r['old'] !== '' ? $r['old'] : 'No genre' }} to <strong>{{ $r['new'] }}</strong></td>
              <td style="{{ $td }}">Discogs: {{ $r['discogs'] }}</td>
            </tr>
          @endforeach
        </table>
      </details>
    @endif
  </div>

  <div style="{{ $box }}">
    <h2 style="font-size:19px;margin:0 0 6px;">Step 1: Fix the obvious ones</h2>
    @if($all->isEmpty())
      <p style="margin:0;font-size:15px;">All done. No obvious wrong genres left.</p>
    @else
      <p style="margin:0 0 14px;font-size:15px;">{{ number_format($all->count()) }} records have a genre that doesn't match the artist's other records (like Sade under Metal). One click fixes them all. You can undo it.</p>
      <form method="POST" action="{{ url('/products/genre-fix/apply') }}" onsubmit="this.querySelector('button').disabled=true; return true;">
        @csrf <input type="hidden" name="type" value="all">
        <button type="submit" style="{{ $big }}">Fix all {{ number_format($all->count()) }}</button>
      </form>
      <details style="margin-top:12px;">
        <summary style="cursor:pointer;font-size:15px;color:#1e40af;">See what will change</summary>
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">
          @foreach($all->take(300) as $r)
            <tr>
              <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a></td>
              <td style="{{ $td }}">{{ $r['old_name'] !== '' ? $r['old_name'] : 'No genre' }} to <strong>{{ $r['new_name'] }}</strong></td>
            </tr>
          @endforeach
        </table>
        @if($all->count() > 300)<p style="margin:6px 0 0;">First 300 shown.</p>@endif
      </details>
    @endif
  </div>

  <div style="{{ $box }}">
    <h2 style="font-size:19px;margin:0 0 6px;">Step 2: Pick the genre for these artists</h2>
    @if(empty($cards))
      <p style="margin:0;font-size:15px;">All done.</p>
    @else
      <p style="margin:0 0 14px;font-size:15px;">These artists are split between genres. Tap the right one and all their records move there.</p>
      @foreach($cards as $i => $g)
        <div class="gf-card" data-key="{{ $g['artist'] }}|{{ $g['cat'] }}" style="display:none;">
          <div style="font-size:22px;font-weight:700;">{{ ucwords($g['artist']) }}</div>
          <div style="font-size:15px;margin:2px 0 14px;">{{ $g['format'] }}, {{ $g['n'] }} records. <a href="{{ url('/products/genre-fix?artist=' . urlencode($g['artist'])) }}#artist">See them</a></div>
          <form method="POST" action="{{ url('/products/genre-fix/set-artist') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            @csrf <input type="hidden" name="artist" value="{{ $g['artist'] }}"> <input type="hidden" name="cat" value="{{ $g['cat'] }}">
            @foreach($g['split'] as $s)
              @if($s['id'])<button type="submit" name="sub" value="{{ $s['id'] }}" style="{{ $pill }}">{{ $s['name'] }} ({{ $s['n'] }})</button>@endif
            @endforeach
            <select name="sub" disabled onchange="if (this.value) this.form.submit();" style="padding:9px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;" class="gf-other">
              <option value="">Something else</option>
              @foreach($genres[$g['cat']] ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <button type="button" class="gf-skip" style="padding:10px 16px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#000;font-size:15px;cursor:pointer;">Skip</button>
          </form>
          <div style="margin-top:12px;font-size:14px;"><span class="gf-left"></span> artists left</div>
        </div>
      @endforeach
      <p id="gf-cards-done" style="display:none;margin:0;font-size:15px;">You've gone through all of them for now.</p>
    @endif
  </div>

  <div id="artist" style="{{ $box }}">
    <h2 style="font-size:19px;margin:0 0 8px;">Fix one artist</h2>
    <form method="GET" action="{{ url('/products/genre-fix') }}#artist" style="display:flex;gap:8px;margin-bottom:10px;">
      <input type="text" name="artist" value="{{ $artist }}" placeholder="Artist name" style="padding:10px;border:1px solid #d1d5db;border-radius:8px;width:280px;font-size:15px;">
      <button type="submit" style="{{ $pill }}">Show records</button>
    </form>
    @if($artist !== '')
      @if(empty($records))
        <p style="margin:0;">No records found for "{{ $artist }}".</p>
      @else
        <form method="POST" action="{{ url('/products/genre-fix/save') }}" onsubmit="this.querySelectorAll('select[data-orig]').forEach(function(s){ if (s.value === s.dataset.orig) s.disabled = true; }); return true;">
          @csrf <input type="hidden" name="artist" value="{{ $artist }}">
          <table style="width:100%;border-collapse:collapse;font-size:14px;">
            @foreach($records as $r)
              <tr>
                <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a> <span style="font-size:13px;">({{ $formats[$r['cat']] ?? '' }})</span></td>
                <td style="{{ $td }}">
                  <select name="genre[{{ $r['id'] }}]" data-orig="{{ $r['sub'] ?: '' }}" style="padding:5px;border:1px solid {{ $r['sub'] ? '#d1d5db' : '#b91c1c' }};border-radius:6px;">
                    <option value="">{{ $r['sub'] ? '' : 'No genre' }}</option>
                    @foreach($genres[$r['cat']] ?? [] as $id => $name)<option value="{{ $id }}" {{ $r['sub'] === $id ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                  </select>
                </td>
              </tr>
            @endforeach
          </table>
          <button type="submit" style="{{ $big }}margin-top:12px;">Save</button>
        </form>
      @endif
    @endif
  </div>
</section>
<script>
(function () {
  var cards = Array.prototype.slice.call(document.querySelectorAll('.gf-card'));
  if (!cards.length) return;
  var skipped = {};
  try { skipped = JSON.parse(localStorage.getItem('gf-skipped') || '{}'); } catch (e) {}
  function show() {
    var left = cards.filter(function (c) { return !skipped[c.dataset.key]; });
    cards.forEach(function (c) { c.style.display = 'none'; });
    if (!left.length) { document.getElementById('gf-cards-done').style.display = 'block'; return; }
    left[0].style.display = 'block';
    left[0].querySelector('.gf-left').textContent = left.length;
  }
  cards.forEach(function (c) {
    // "Something else" only sends when picked, so it doesn't override a tapped button.
    var other = c.querySelector('.gf-other');
    other.disabled = false;
    c.querySelectorAll('button[name=sub]').forEach(function (b) { b.addEventListener('click', function () { other.disabled = true; }); });
    c.querySelector('.gf-skip').addEventListener('click', function () {
      skipped[c.dataset.key] = 1;
      try { localStorage.setItem('gf-skipped', JSON.stringify(skipped)); } catch (e) {}
      show();
    });
  });
  show();
})();
</script>
@endsection
