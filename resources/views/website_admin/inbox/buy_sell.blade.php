@extends('layouts.app')

@section('title', 'Buy / Sell Queries')

@section('content')
@include('website_admin.inbox._styles')

@php
  $fmt = function ($iso) {
      if (empty($iso)) return '';
      try { return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format('M j, Y g:i a'); } catch (\Throwable $e) { return ''; }
  };
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Buy / Sell Queries</h1>
      <p class="sub">People who asked about selling their collection through nivessa.com. Newest first.</p>
    </div>
  </div>

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Could not load queries</h2>
      <p class="sub" style="margin:0;">{{ $bridgeError }}</p>
    </div>
  @else
    <div class="wi-card wi-scroll">
      <table class="wi-tbl" style="min-width:860px;">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Collection size</th>
            <th>Additional info</th>
            <th>Received</th>
          </tr>
        </thead>
        <tbody>
          @forelse($messages as $i => $m)
            <tr>
              <td class="wi-muted">{{ $i + 1 }}</td>
              <td class="wi-name">{{ $m['name'] ?? '' }}</td>
              <td>@if(!empty($m['email']))<a href="mailto:{{ $m['email'] }}">{{ $m['email'] }}</a>@endif</td>
              <td>{{ $m['phone'] ?? '' }}</td>
              <td>{{ $m['collectionSize'] ?? '' }}</td>
              <td style="white-space:pre-wrap;max-width:380px;">{{ ($m['additionalInfo'] ?? '') !== '' ? $m['additionalInfo'] : 'None' }}</td>
              <td style="white-space:nowrap;">{{ $fmt($m['createdAt'] ?? null) }}</td>
            </tr>
          @empty
            <tr><td colspan="7" class="wi-empty">No queries yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
