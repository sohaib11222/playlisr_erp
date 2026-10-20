@extends('layouts.app')
@section('title', 'Merge Customers')
@section('content')
<section class="content" style="padding:20px;max-width:1200px;color:#000;">
  <h1 style="font-size:22px;margin:0 0 6px;">Duplicate customers</h1>
  <p style="margin:0 0 14px;max-width:75ch;">Customers with the same email are merged into one record: the one with the most sales is kept, and all sales, store credit, points, pickups and preorders from the others move onto it. The extra records are removed. You can undo the last merge.</p>
  @if(session('status'))<div style="padding:10px 14px;background:#dcfce7;border-radius:6px;margin-bottom:12px;">{{ session('status')['msg'] }}</div>@endif
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
    <strong style="font-size:15px;">{{ count($groups) }} customers have more than one record ({{ collect($groups)->sum(fn($g) => count($g) - 1) }} extra records)</strong>
    <form method="POST" action="{{ url('/admin/merge-customers/run') }}" onsubmit="this.querySelector('button').disabled=true;">
      @csrf
      <button type="submit" {{ empty($groups) ? 'disabled' : '' }} style="padding:9px 16px;border-radius:6px;border:0;background:#1e40af;color:#fff;font-weight:700;">Merge all {{ count($groups) }}</button>
    </form>
    @if($lastRun)
      <form method="POST" action="{{ url('/admin/merge-customers/undo') }}">
        @csrf
        <button type="submit" style="padding:9px 16px;border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#000;font-weight:600;">Undo last merge</button>
      </form>
    @endif
  </div>
  <table style="width:100%;border-collapse:collapse;background:#fff;font-size:14px;">
    <thead><tr style="text-align:left;"><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Email</th><th style="padding:8px;border-bottom:2px solid #e5e7eb;">Records (kept one first)</th></tr></thead>
    <tbody>
      @foreach($groups as $email => $g)
        <tr>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;vertical-align:top;">{{ $email }}</td>
          <td style="padding:8px;border-bottom:1px solid #e5e7eb;">
            @foreach($g as $i => $c)
              <div>@if($i === 0)<strong>Keep:</strong>@else Merge in:@endif <a href="{{ url('/contacts/' . $c->id) }}" target="_blank">{{ $c->name }} ({{ $c->contact_id }})</a> · {{ $c->sales }} sales · credit ${{ number_format((float) $c->balance, 2) }} · added {{ date('m/d/y', strtotime($c->created_at)) }}</div>
            @endforeach
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p style="margin-top:12px;font-size:13px;">Records that point at a customer and get moved: {{ implode(', ', $tables) }}.</p>
</section>
@endsection
