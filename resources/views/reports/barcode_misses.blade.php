@extends('layouts.app')

@section('title', 'Barcode Misses')

@section('content')
<style>
.bm-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; color: #1F1B16; }
.bm-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
.bm-wrap p { margin: 0 0 14px; font-size: 14px; color: #3B3226; }
.bm-pills a { display: inline-block; padding: 5px 12px; border-radius: 999px; margin-right: 6px; border: 1px solid #DFD2B3; color: #1F1B16; font-size: 13px; }
.bm-pills a.on { background: #DCEBFF; border-color: #DCEBFF; color: #1F4E8C; font-weight: 600; }
.bm-table { width: 100%; background: #fff; border: 1px solid #ECE3CF; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
.bm-table th, .bm-table td { padding: 8px 10px; border-bottom: 1px solid #ECE3CF; text-align: left; vertical-align: top; color: #1F1B16; }
.bm-table th { background: #F7F1E3; font-weight: 700; }
.bm-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
.bm-tag.bad { background: #FBE3E3; color: #7A1F1F; }
.bm-tag.erp { background: #FFF1D6; color: #6B4A00; }
.bm-tag.none { background: #E9F3EC; color: #2F6B3E; }
</style>
<div class="bm-wrap">
  <h1>Barcode Misses</h1>
  <p>Records, CDs, tapes and movies rung up as Item With No Barcode. Barcodes that did not scan are on top, then items the ERP already had in stock.</p>
  <div class="bm-pills">
    @foreach([7, 30, 90] as $d)
      <a href="?days={{ $d }}" class="{{ $days == $d ? 'on' : '' }}">Last {{ $d }} days</a>
    @endforeach
  </div>
  <table class="bm-table">
    <thead><tr><th>Date</th><th>Store</th><th>Cashier</th><th>Item</th><th>Category</th><th>Price</th><th>Why</th><th>Already in the ERP</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr>
        <td>{{ \Carbon\Carbon::parse($r->created_at)->format('m/d/y g:ia') }}</td>
        <td>{{ $r->location_name }}</td>
        <td>{{ $r->cashier }}</td>
        <td>{{ $r->product_name }}@if($r->artist) <br>{{ $r->artist }}@endif</td>
        <td>{{ $r->category_name }}</td>
        <td>${{ number_format($r->price, 2) }}</td>
        <td><span class="bm-tag {{ stripos($r->reason, 'Has a barcode') === 0 ? 'bad' : ($r->erp_matches ? 'erp' : 'none') }}">{{ $r->reason }}</span></td>
        <td>{{ $r->erp_matches }}</td>
      </tr>
    @empty
      <tr><td colspan="8">Nothing yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
