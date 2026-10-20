@extends('layouts.app')
@section('title', 'Sealed Barcodes')
@section('content')
<section class="content" style="padding:20px;max-width:1300px;color:#000;">
  <h1 style="font-size:22px;margin:0 0 6px;">Barcodes for sealed items</h1>
  <p style="margin:0 0 14px;">Sealed vinyl, CDs and cassettes with no real barcode get the barcode from their Discogs release, so they can be scanned. Discogs is checked automatically in the background; nothing changes until you click Apply.</p>
  @if(session('status'))<div style="padding:10px 14px;background:#dcfce7;border-radius:6px;margin-bottom:12px;">{{ session('status')['msg'] }}</div>@endif
  @php $ready = collect($proposals)->where('blocked', ''); $blocked = collect($proposals)->where('blocked', '!=', ''); @endphp
  <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px;font-size:15px;">
    <div><strong>{{ $ready->count() }}</strong> ready to add</div>
    <div><strong>{{ $counts['applied'] ?? 0 }}</strong> added</div>
    <div><strong>{{ $blocked->count() }}</strong> skipped (duplicate or name mismatch)</div>
    <div><strong>{{ ($counts['none'] ?? 0) + ($counts['several'] ?? 0) }}</strong> no single barcode on Discogs</div>
    <div><strong>{{ $remaining }}</strong> still to check</div>
  </div>
  <form method="POST" action="{{ url('/products/sealed-barcodes/apply') }}" style="display:inline-block;margin-bottom:16px;" onsubmit="return this.querySelector('button').disabled=true, true;">
    @csrf
    <button type="submit" {{ $ready->isEmpty() ? 'disabled' : '' }} style="padding:9px 16px;border-radius:6px;border:0;background:#1e40af;color:#fff;font-weight:700;">Apply {{ $ready->count() }} barcodes</button>
  </form>
  @if($remaining > 0)
    <form method="POST" action="{{ url('/products/sealed-barcodes/scan') }}" style="display:inline-block;margin-left:8px;">
      @csrf
      <button type="submit" style="padding:9px 16px;border-radius:6px;border:1px solid #93c5fd;background:#dbeafe;color:#1e40af;font-weight:700;">Check Discogs now</button>
    </form>
  @endif
  @if(($counts['applied'] ?? 0) > 0)
    <form method="POST" action="{{ url('/products/sealed-barcodes/apply') }}" style="display:inline-block;margin-left:8px;">
      @csrf <input type="hidden" name="undo" value="1">
      <button type="submit" style="padding:9px 16px;border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#000;font-weight:600;">Undo (put old SKUs back)</button>
    </form>
  @endif
  <table style="width:100%;border-collapse:collapse;background:#fff;font-size:14px;">
    <thead><tr style="text-align:left;"><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Product</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Category</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Old SKU</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Discogs release</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Barcode from Discogs</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Note</th></tr></thead>
    <tbody>
      @foreach(collect($proposals)->sortBy('blocked')->take(500) as $p)
        <tr>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;"><a href="{{ url('/products/' . $p['id'] . '/edit') }}" target="_blank">{{ $p['name'] }}</a></td>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $p['cat'] ?? '' }}</td>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $p['old'] }}</td>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ trim(($p['dartist'] ?? '') . ' - ' . ($p['dtitle'] ?? ''), ' -') }}</td>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;"><a href="https://www.discogs.com/release/{{ $p['release'] }}" target="_blank">{{ $p['new'] }}</a></td>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;color:{{ $p['blocked'] ? '#b91c1c' : '#000' }};">{{ $p['blocked'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @if(count($proposals) > 500)<p>Showing 500 of {{ count($proposals) }}.</p>@endif
</section>
@endsection
