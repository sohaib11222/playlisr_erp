@extends('layouts.app')

@section('title', 'Add a Missed Booking')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.bookings._styles')
<style>
  .wba-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; margin-bottom:14px; }
  .wba-grid.four { grid-template-columns:repeat(4, minmax(0, 1fr)); }
  @media (max-width: 767px) { .wba-grid, .wba-grid.four { grid-template-columns:1fr; } }
  .wba-field label { font-size:13px; font-weight:600; color:#374151; text-transform:none; letter-spacing:0; }
</style>

<div class="ev-wrap" style="max-width:820px;">
  <div class="ev-head">
    <div>
      <a href="{{ route('wa.bookings.index') }}" style="font-size:13px;"><i class="fa fa-arrow-left"></i> Back to Bookings</a>
      <h1>Add a Missed Booking</h1>
      <p class="sub">Use this for a reservation that was paid off-site (for example a manual charge) and never recorded, so it shows under the customer's "My Bookings." It does <strong>not</strong> add a calendar event or post to Slack. Leave the box checked to email the customer their confirmation and receipt.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($venueError)<div class="alert-err">Could not load venues: {{ $venueError }}</div>@endif

  <form method="POST" action="{{ route('wa.bookings.addStore') }}" class="ev-card">
    {{ csrf_field() }}
    <div class="wba-grid">
      <div class="wb-field wba-field">
        <label for="customerName">Customer name</label>
        <input id="customerName" name="customerName" value="{{ old('customerName') }}" required>
      </div>
      <div class="wb-field wba-field">
        <label for="customerEmail">Email (must match their login)</label>
        <input type="email" id="customerEmail" name="customerEmail" value="{{ old('customerEmail') }}" required>
      </div>
      <div class="wb-field wba-field">
        <label for="customerPhone">Phone</label>
        <input id="customerPhone" name="customerPhone" value="{{ old('customerPhone') }}">
      </div>
      <div class="wb-field wba-field">
        <label for="customerCompany">Company</label>
        <input id="customerCompany" name="customerCompany" value="{{ old('customerCompany') }}">
      </div>
    </div>

    <div class="wba-grid">
      <div class="wb-field wba-field">
        <label for="venue">Venue</label>
        @php $selVenue = old('venue', $preferred); @endphp
        <select id="venue" name="venue" required>
          @if(count($venues) === 0)
            <option value="">No venues found</option>
          @endif
          @foreach($venues as $v)
            <option value="{{ $v['_id'] }}" @if($selVenue === $v['_id']) selected @endif>{{ $v['name'] ?? '' }}{{ !empty($v['location']['store']) ? ', ' . $v['location']['store'] : '' }}</option>
          @endforeach
        </select>
      </div>
      <div class="wb-field wba-field">
        <label for="eventType">Event type</label>
        @php $selType = old('eventType', 'Filming'); @endphp
        <select id="eventType" name="eventType">
          @foreach($eventTypes as $t)
            <option value="{{ $t }}" @if($selType === $t) selected @endif>{{ $t }}</option>
          @endforeach
        </select>
      </div>
      <div class="wb-field wba-field">
        <label for="eventTitle">Event title</label>
        <input id="eventTitle" name="eventTitle" value="{{ old('eventTitle') }}">
      </div>
      <div class="wb-field wba-field">
        <label for="expectedAttendees">Expected guests</label>
        <input type="number" min="1" id="expectedAttendees" name="expectedAttendees" value="{{ old('expectedAttendees', 10) }}">
      </div>
    </div>

    <div class="wb-field wba-field" style="margin-bottom:14px;">
      <label for="eventDescription">Description</label>
      <textarea rows="2" id="eventDescription" name="eventDescription">{{ old('eventDescription') }}</textarea>
    </div>

    <div class="wba-grid four">
      <div class="wb-field wba-field">
        <label for="date">Date</label>
        <input type="date" id="date" name="date" value="{{ old('date') }}" required>
      </div>
      <div class="wb-field wba-field">
        <label for="startTime">Start time</label>
        <input type="time" id="startTime" name="startTime" value="{{ old('startTime') }}" required>
      </div>
      <div class="wb-field wba-field">
        <label for="endTime">End time</label>
        <input type="time" id="endTime" name="endTime" value="{{ old('endTime') }}" required>
      </div>
      <div class="wb-field wba-field">
        <label for="amount">Amount paid ($)</label>
        <input type="number" min="0" step="0.01" id="amount" name="amount" value="{{ old('amount') }}" required>
      </div>
    </div>

    <label style="display:flex; align-items:center; gap:8px; font-size:14px; font-weight:500; margin:6px 0 18px;">
      <input type="checkbox" name="sendConfirmationEmail" value="1" @if(!old('_token') || old('sendConfirmationEmail')) checked @endif>
      Email the customer their confirmation and receipt
    </label>

    <button type="submit" class="wb-btn wb-btn-primary" id="wba-submit" @if(count($venues) === 0) disabled @endif><i class="fa fa-calendar-plus"></i> Create booking</button>
  </form>
</div>
<script>
  document.querySelector('form.ev-card').addEventListener('submit', function () {
    var b = document.getElementById('wba-submit');
    b.disabled = true; b.textContent = 'Creating...';
  });
</script>
@endsection
