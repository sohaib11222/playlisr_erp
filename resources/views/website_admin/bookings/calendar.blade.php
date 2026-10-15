@extends('layouts.app')

@section('title', 'Bookings Calendar')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.bookings._styles')
<style>
  .wbc-layout { display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:20px; align-items:start; }
  @media (max-width: 991px) { .wbc-layout { grid-template-columns:1fr; } }
  .wbc-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #e5e7eb; }
  .wbc-head h2 { font-size:18px; font-weight:700; margin:0; }
  .wbc-nav { display:flex; gap:6px; }
  .wbc-grid { display:grid; grid-template-columns:repeat(7, minmax(0, 1fr)); gap:6px; padding:14px 18px 18px; }
  .wbc-wd { text-align:center; font-size:12px; font-weight:700; color:#6b7280; padding:4px 0; }
  .wbc-day { min-height:92px; padding:6px; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:inherit; display:block; overflow:hidden; }
  .wbc-day:hover { background:#f9fafb; text-decoration:none; color:inherit; }
  .wbc-day.blank { background:#f9fafb; border-color:transparent; }
  .wbc-day.today { background:#eff6ff; border-color:#93c5fd; }
  .wbc-day.sel { background:#dbeafe; border-color:#1e40af; }
  .wbc-num { font-size:13px; font-weight:700; color:#374151; margin-bottom:4px; }
  .wbc-day.today .wbc-num { color:#1d4ed8; }
  .wbc-ev { font-size:11px; padding:2px 6px; border-radius:4px; color:#fff; margin-bottom:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .wbc-more { font-size:11px; color:#6b7280; padding:0 4px; }
  .c-confirmed { background:#22c55e; } .c-pending { background:#eab308; } .c-cancelled { background:#ef4444; } .c-completed { background:#3b82f6; } .c-other { background:#6b7280; }
  .wbc-side { padding:18px; position:sticky; top:16px; }
  .wbc-side h3 { font-size:16px; font-weight:700; margin:0 0 14px; }
  .wbc-item { border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin-bottom:10px; }
  .wbc-item .top { display:flex; justify-content:space-between; gap:8px; margin-bottom:6px; }
  .wbc-item .meta { font-size:13px; color:#4b5563; line-height:1.6; margin-bottom:8px; }
  .wbc-legend { margin-top:18px; padding-top:14px; border-top:1px solid #e5e7eb; font-size:13px; color:#4b5563; }
  .wbc-legend div { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
  .wbc-legend span.sw { width:14px; height:14px; border-radius:3px; display:inline-block; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <a href="{{ route('wa.bookings.index') }}" style="font-size:13px;"><i class="fa fa-arrow-left"></i> Back to Bookings</a>
      <h1>Calendar View</h1>
      <p class="sub">View all bookings in calendar format.</p>
    </div>
  </div>

  @if($error)<div class="alert-err">{{ $error }}</div>@endif

  <div class="wbc-layout">
    <div class="wb-card">
      <div class="wbc-head">
        <h2>{{ $monthLabel }}</h2>
        <div class="wbc-nav">
          <a class="wb-btn wb-btn-sm" href="{{ route('wa.bookings.calendar', ['month' => $todayMonth, 'day' => $todayDay]) }}">Today</a>
          <a class="wb-btn wb-btn-sm" href="{{ route('wa.bookings.calendar', ['month' => $prev]) }}" title="Previous month"><i class="fa fa-chevron-left"></i></a>
          <a class="wb-btn wb-btn-sm" href="{{ route('wa.bookings.calendar', ['month' => $next]) }}" title="Next month"><i class="fa fa-chevron-right"></i></a>
        </div>
      </div>
      <div class="wbc-grid">
        @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd)
          <div class="wbc-wd">{{ $wd }}</div>
        @endforeach
        @for($i = 0; $i < $leading; $i++)
          <div class="wbc-day blank"></div>
        @endfor
        @for($d = 1; $d <= $daysInMonth; $d++)
          @php
            $list = $byDay[$d] ?? [];
            $cls = $d === $day ? 'sel' : (($month === $todayMonth && $d === $todayDay) ? 'today' : '');
          @endphp
          <a class="wbc-day {{ $cls }}" href="{{ route('wa.bookings.calendar', ['month' => $month, 'day' => $d]) }}">
            <div class="wbc-num">{{ $d }}</div>
            @foreach(array_slice($list, 0, 2) as $b)
              @php $c = in_array($b['status'], ['confirmed', 'pending', 'cancelled', 'completed']) ? 'c-' . $b['status'] : 'c-other'; @endphp
              <div class="wbc-ev {{ $c }}" title="{{ $b['venueName'] }}, {{ $b['customerName'] }}">{{ $b['venueName'] }}</div>
            @endforeach
            @if(count($list) > 2)
              <div class="wbc-more">+{{ count($list) - 2 }} more</div>
            @endif
          </a>
        @endfor
      </div>
    </div>

    <div class="wb-card wbc-side">
      <h3><i class="fa fa-calendar"></i> {{ $dayLabel ?: 'Select a Date' }}</h3>
      @if($day)
        @php $sel = $byDay[$day] ?? []; @endphp
        @if(count($sel))
          @foreach($sel as $b)
            @php $pc = in_array($b['status'], ['confirmed', 'pending', 'cancelled']) ? 'st-' . $b['status'] : 'st-completed'; @endphp
            <div class="wbc-item">
              <div class="top">
                <div>
                  <div class="wb-main">{{ $b['venueName'] }}</div>
                  <div class="wb-sub">{{ $b['title'] }}</div>
                </div>
                <span class="wb-pill {{ $pc }}" style="text-transform:none;">{{ $b['status'] }}</span>
              </div>
              <div class="meta">
                <div><i class="fa fa-clock-o"></i> {{ $b['timeLabel'] }}</div>
                <div><i class="fa fa-user"></i> {{ $b['customerName'] }}</div>
                <div><i class="fa fa-usd"></i> {{ number_format($b['total'], 2) }}</div>
              </div>
              <a href="https://nivessa.com/bookings/{{ urlencode($b['bookingNumber']) }}" target="_blank" rel="noopener" class="wb-btn wb-btn-blue" style="width:100%; justify-content:center;"><i class="fa fa-eye"></i> View Details</a>
            </div>
          @endforeach
        @else
          <p class="empty" style="text-align:center;">No bookings for this date.</p>
        @endif
      @else
        <p class="empty" style="text-align:center;">Click on a date to view bookings.</p>
      @endif

      <div class="wbc-legend">
        <strong style="display:block; margin-bottom:8px;">Status Legend</strong>
        <div><span class="sw c-confirmed"></span> Confirmed</div>
        <div><span class="sw c-pending"></span> Pending</div>
        <div><span class="sw c-completed"></span> Completed</div>
        <div><span class="sw c-cancelled"></span> Cancelled</div>
      </div>
    </div>
  </div>
</div>
@endsection
