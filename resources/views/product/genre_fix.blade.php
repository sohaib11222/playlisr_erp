@extends('layouts.app')
@section('title', 'Genre Fix')
@section('content')
@php
  $th = 'padding:8px;border-bottom:2px solid #e5e7eb;text-align:left;';
  $td = 'padding:7px 8px;border-bottom:1px solid #e5e7eb;';
  $btn = 'padding:8px 14px;border-radius:6px;border:0;background:#1e40af;color:#fff;font-weight:700;cursor:pointer;';
  $btn2 = 'padding:6px 12px;border-radius:6px;border:1px solid #93c5fd;background:#dbeafe;color:#1e40af;font-weight:700;cursor:pointer;';
@endphp
<section class="content" style="padding:20px;max-width:1300px;color:#000;">
  <h1 style="font-size:22px;margin:0 0 6px;">Genre Fix</h1>
  <p style="margin:0 0 14px;">Finds wrong or missing genres across the whole catalog and proposes the fix, so nobody has to fix genres during counts. Untick anything that looks wrong, then Apply. Every apply can be undone in <a href="{{ url('/admin/admin-action-history') }}">Admin Action History</a>.</p>
  @if(session('status'))<div style="padding:10px 14px;background:{{ session('status')['success'] ? '#dcfce7' : '#fee2e2' }};border-radius:6px;margin-bottom:12px;">{{ session('status')['msg'] }}</div>@endif

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
    @foreach($types as $key => $label)
      <a href="#{{ $key }}" style="padding:10px 14px;border-radius:8px;background:#dbeafe;color:#1e40af;font-weight:700;text-decoration:none;">{{ number_format(count($fixes[$key] ?? [])) }} {{ $label }}</a>
    @endforeach
    <a href="#look" style="padding:10px 14px;border-radius:8px;background:#dbeafe;color:#1e40af;font-weight:700;text-decoration:none;">{{ number_format(count($look)) }} artists need a look</a>
    <a href="#artist" style="padding:10px 14px;border-radius:8px;background:#dbeafe;color:#1e40af;font-weight:700;text-decoration:none;">Fix one artist</a>
  </div>

  @foreach($types as $key => $label)
    @php $rows = $fixes[$key] ?? []; @endphp
    <h2 id="{{ $key }}" style="font-size:18px;margin:24px 0 8px;">{{ $label }} ({{ number_format(count($rows)) }})</h2>
    @if(empty($rows))
      <p style="margin:0;">Nothing to fix.</p>
    @else
      <form method="POST" action="{{ url('/products/genre-fix/apply') }}" onsubmit="this.querySelector('button').disabled=true; this.querySelectorAll('input.gf-keep').forEach(function(c){ if(!c.checked){ var h=document.createElement('input'); h.type='hidden'; h.name='skip[]'; h.value=c.value; c.form.appendChild(h);} }); return true;">
        @csrf <input type="hidden" name="type" value="{{ $key }}">
        <button type="submit" style="{{ $btn }}margin-bottom:8px;">Apply all {{ number_format(count($rows)) }} ticked</button>
        @if(count($rows) > 400)<span style="margin-left:8px;">Showing the first 400 (most stock first). Apply covers all {{ number_format(count($rows)) }}; untick any shown here to leave them.</span>@endif
        <table style="width:100%;border-collapse:collapse;background:#fff;font-size:14px;">
          <thead><tr><th style="{{ $th }}width:30px;"></th><th style="{{ $th }}">Product</th><th style="{{ $th }}">Artist</th><th style="{{ $th }}">Format</th><th style="{{ $th }}">Now</th><th style="{{ $th }}">Change to</th><th style="{{ $th }}">Why</th><th style="{{ $th }}">Stock</th></tr></thead>
          <tbody>
            @foreach(collect($rows)->sortByDesc('stock')->take(400) as $r)
              <tr>
                <td style="{{ $td }}"><input type="checkbox" class="gf-keep" value="{{ $r['id'] }}" checked></td>
                <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a></td>
                <td style="{{ $td }}">{{ $r['artist'] }}</td>
                <td style="{{ $td }}">{{ $r['format'] }}</td>
                <td style="{{ $td }}color:#b91c1c;">{{ $r['old_name'] !== '' ? $r['old_name'] : 'none' }}</td>
                <td style="{{ $td }}font-weight:700;">{{ $r['new_name'] }}</td>
                <td style="{{ $td }}">{{ $r['why'] }}</td>
                <td style="{{ $td }}">{{ $r['stock'] + 0 }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </form>
    @endif
  @endforeach

  <h2 id="look" style="font-size:18px;margin:28px 0 4px;">Artists that need a look ({{ number_format(count($look)) }})</h2>
  <p style="margin:0 0 8px;">These artists have records under more than one genre in the same format and no clear winner. Pick the right genre and Set; it applies to all of that artist's records in that format. Or open the artist to set records one by one.</p>
  <table style="width:100%;border-collapse:collapse;background:#fff;font-size:14px;">
    <thead><tr><th style="{{ $th }}">Artist</th><th style="{{ $th }}">Format</th><th style="{{ $th }}">Now</th><th style="{{ $th }}">Set all to</th></tr></thead>
    <tbody>
      @foreach(array_slice($look, 0, 300) as $g)
        <tr>
          <td style="{{ $td }}"><a href="{{ url('/products/genre-fix?artist=' . urlencode($g['artist'])) }}#artist">{{ ucwords($g['artist']) }}</a></td>
          <td style="{{ $td }}">{{ $g['format'] }}</td>
          <td style="{{ $td }}">{{ collect($g['split'])->map(fn($s) => $s['name'] . ' ' . $s['n'])->implode(', ') }}</td>
          <td style="{{ $td }}">
            <form method="POST" action="{{ url('/products/genre-fix/set-artist') }}" style="display:flex;gap:6px;" onsubmit="if (this.sub.value === '') return false; this.querySelector('button').disabled = true; return true;">
              @csrf <input type="hidden" name="artist" value="{{ $g['artist'] }}"> <input type="hidden" name="cat" value="{{ $g['cat'] }}">
              <select name="sub" style="padding:5px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="">Pick genre</option>
                @foreach($genres[$g['cat']] ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
              </select>
              <button type="submit" style="{{ $btn2 }}">Set</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @if(count($look) > 300)<p style="margin:6px 0 0;">Showing the 300 artists with the most records. The rest shrink as these get set.</p>@endif

  <h2 id="artist" style="font-size:18px;margin:28px 0 8px;">Fix one artist</h2>
  <form method="GET" action="{{ url('/products/genre-fix') }}" style="display:flex;gap:8px;margin-bottom:10px;">
    <input type="text" name="artist" value="{{ $artist }}" placeholder="Artist name, e.g. Sade" style="padding:8px;border:1px solid #d1d5db;border-radius:6px;width:320px;">
    <button type="submit" style="{{ $btn }}">Show records</button>
  </form>
  @if($artist !== '')
    @if(empty($records))
      <p style="margin:0;">No active music records found for "{{ $artist }}".</p>
    @else
      <form method="POST" action="{{ url('/products/genre-fix/save') }}" onsubmit="this.querySelectorAll('select[data-orig]').forEach(function(s){ if (s.value === s.dataset.orig) s.disabled = true; }); return true;">
        @csrf <input type="hidden" name="artist" value="{{ $artist }}">
        <table style="width:100%;border-collapse:collapse;background:#fff;font-size:14px;">
          <thead><tr><th style="{{ $th }}">Product</th><th style="{{ $th }}">Format</th><th style="{{ $th }}">Genre</th></tr></thead>
          <tbody>
            @foreach($records as $r)
              <tr>
                <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a></td>
                <td style="{{ $td }}">{{ $formats[$r['cat']] ?? '' }}</td>
                <td style="{{ $td }}">
                  <select name="genre[{{ $r['id'] }}]" data-orig="{{ $r['sub'] ?: '' }}" style="padding:5px;border:1px solid {{ $r['sub'] ? '#d1d5db' : '#b91c1c' }};border-radius:6px;">
                    <option value="">{{ $r['sub'] ? '' : 'No genre' }}</option>
                    @foreach($genres[$r['cat']] ?? [] as $id => $name)<option value="{{ $id }}" {{ $r['sub'] === $id ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                  </select>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <button type="submit" style="{{ $btn }}margin-top:10px;">Save genres</button>
      </form>
    @endif
  @endif
</section>
@endsection
