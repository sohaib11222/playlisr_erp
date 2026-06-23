@extends('layouts.app')

@section('title', 'Events / Listening Parties')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>{{ ($filterType ?? null) ? $filterLabel . 's' : 'Events / Listening Parties' }}</h1>
      @if($filterType ?? null)
        <p class="sub">Showing {{ strtolower($filterLabel) }} events only. &middot; <a href="{{ route('events.index') }}">Show all events</a></p>
      @else
        <p class="sub">The ERP is the source of truth for all event detail and listening-party prep. nivessa.com reads from here.</p>
      @endif
    </div>
    <form method="POST" action="{{ route('events.import') }}"
          onsubmit="return confirm('Pull the latest events from nivessa.com into the ERP? Existing prep-checklist progress entered here is preserved.');">
      {{ csrf_field() }}
      <button type="submit" class="btn-ghost">Import from nivessa.com</button>
    </form>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  {{-- ---------- Create ---------- --}}
  <details class="ev-card" id="create-block">
    <summary class="ev-create-summary">+ New event</summary>
    <form method="POST" action="{{ route('events.store') }}" style="margin-top:14px;">
      {{ csrf_field() }}
      @include('events.partials._form', ['event' => null, 'eventTypes' => $eventTypes, 'genres' => $genres])
      <div style="margin-top:14px;">
        <button type="submit" class="btn-accent">Create event</button>
      </div>
    </form>
  </details>

  {{-- ---------- Upcoming ---------- --}}
  <div class="ev-card">
    <h2>Upcoming ({{ count($upcoming) }})</h2>
    @if(empty($upcoming))
      <div class="empty">No upcoming events. Add one above or import from the website.</div>
    @else
      @include('events.partials._list', ['rows' => $upcoming, 'prepItems' => $prepItems, 'eventTypes' => $eventTypes])
    @endif
  </div>

  {{-- ---------- Past ---------- --}}
  <details class="ev-card">
    <summary class="ev-create-summary">Past events ({{ count($past) }})</summary>
    <div style="margin-top:12px;">
      @if(empty($past))
        <div class="empty">No past events.</div>
      @else
        @include('events.partials._list', ['rows' => $past, 'prepItems' => $prepItems, 'eventTypes' => $eventTypes])
      @endif
    </div>
  </details>
</div>
@endsection
