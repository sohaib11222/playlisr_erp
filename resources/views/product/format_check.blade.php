@extends('layouts.app')
@section('title', 'Format Check')
@section('content')
@php
  $box = 'background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:18px;';
  $big = 'padding:12px 22px;border-radius:8px;border:0;background:#1e40af;color:#fff;font-weight:700;font-size:16px;cursor:pointer;';
  $td = 'padding:6px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top;';
@endphp
<section class="content" style="padding:20px;max-width:1000px;color:#000;">
  <h1 style="font-size:24px;margin:0 0 6px;">Format Check</h1>
  <p style="font-size:15px;margin:0 0 14px;">Records filed under the wrong format, like a CD in Vinyl - Used. The website shows CD or Vinyl from this category, so these are the ones customers buy thinking they're getting the other format.</p>
  @if(session('status'))<div style="padding:10px 14px;background:{{ session('status')['success'] ? '#dcfce7' : '#fee2e2' }};border-radius:6px;margin-bottom:14px;font-size:15px;">{{ session('status')['msg'] }}</div>@endif

  <div style="{{ $box }}background:#dbeafe;border-color:#93c5fd;">
    <div style="font-size:15px;">Discogs is reading every linked record's format in the background: {{ number_format($checked) }} of {{ number_format($linked) }} done. New ones show up here by themselves.</div>
  </div>

  <div style="{{ $box }}">
    <h2 style="font-size:19px;margin:0 0 6px;">Step 1: Fix the sure ones</h2>
    @if(empty($fix))
      <p style="margin:0;font-size:15px;">All done. Nothing in the wrong format that we're sure about.</p>
    @else
      <p style="margin:0 0 14px;font-size:15px;">{{ number_format(count($fix)) }} records are in the wrong format for sure (the Discogs listing or the barcode says so). One click moves them all, keeping condition and genre. You can undo it.</p>
      <form method="POST" action="{{ url('/products/format-check/apply') }}" onsubmit="this.querySelector('button').disabled=true; return true;">
        @csrf <input type="hidden" name="list" value="fix">
        <button type="submit" style="{{ $big }}">Fix all {{ number_format(count($fix)) }}</button>
      </form>
      <details style="margin-top:12px;">
        <summary style="cursor:pointer;font-size:15px;color:#1e40af;">See what will change</summary>
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-top:8px;">
          <tr><th style="{{ $td }}text-align:left;">Record</th><th style="{{ $td }}text-align:left;">Now</th><th style="{{ $td }}text-align:left;">Moves to</th><th style="{{ $td }}text-align:left;">How we know</th><th style="{{ $td }}text-align:right;">In stock</th></tr>
          @foreach(array_slice($fix, 0, 500) as $r)
            <tr>
              <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a></td>
              <td style="{{ $td }}">{{ $r['old_name'] }}</td>
              <td style="{{ $td }}"><strong>{{ $r['new_name'] }}</strong></td>
              <td style="{{ $td }}">{{ $r['why'] }}</td>
              <td style="{{ $td }}text-align:right;">{{ (int) $r['stock'] }}</td>
            </tr>
          @endforeach
        </table>
        @if(count($fix) > 500)<p style="margin:6px 0 0;">First 500 shown.</p>@endif
      </details>
    @endif
  </div>

  <div style="{{ $box }}">
    <h2 style="font-size:19px;margin:0 0 6px;">Step 2: Needs a look</h2>
    @if(empty($look))
      <p style="margin:0;font-size:15px;">Nothing here.</p>
    @else
      <p style="margin:0 0 14px;font-size:15px;">The Discogs release linked to these is the other format, but it might be a different pressing than the copy we have. If the copy is in stock, check it on the shelf. Tick the ones that are wrong and move them.</p>
      <form method="POST" action="{{ url('/products/format-check/apply') }}" onsubmit="this.querySelector('button[type=submit]').disabled=true; return true;">
        @csrf <input type="hidden" name="list" value="look">
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:12px;">
          <tr><th style="{{ $td }}"><input type="checkbox" onclick="document.querySelectorAll('.fc-look').forEach(c => c.checked = this.checked)"></th><th style="{{ $td }}text-align:left;">Record</th><th style="{{ $td }}text-align:left;">Now</th><th style="{{ $td }}text-align:left;">Would move to</th><th style="{{ $td }}text-align:right;">In stock</th></tr>
          @foreach(array_slice($look, 0, 500) as $r)
            <tr>
              <td style="{{ $td }}"><input type="checkbox" class="fc-look" name="ids[]" value="{{ $r['id'] }}"></td>
              <td style="{{ $td }}"><a href="{{ url('/products/' . $r['id'] . '/edit') }}" target="_blank">{{ $r['name'] }}</a></td>
              <td style="{{ $td }}">{{ $r['old_name'] }}</td>
              <td style="{{ $td }}"><strong>{{ $r['new_name'] }}</strong></td>
              <td style="{{ $td }}text-align:right;">{{ (int) $r['stock'] }}</td>
            </tr>
          @endforeach
        </table>
        @if(count($look) > 500)<p style="margin:0 0 10px;">First 500 shown.</p>@endif
        <button type="submit" style="{{ $big }}">Move the ticked ones</button>
      </form>
    @endif
  </div>
</section>
@endsection
