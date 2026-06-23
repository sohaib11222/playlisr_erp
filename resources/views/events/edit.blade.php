@extends('layouts.app')

@section('title', 'Edit Event')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

@php
  $checklist = (array) ($event['prepChecklist'] ?? []);
  $details   = (array) ($event['prepDetails'] ?? []);
  $host      = trim((string) ($details['eventHost'] ?? ''));
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>{{ $event['name'] ?: 'Edit event' }}</h1>
      @php
        $evWhen = !empty($event['date']) ? date('l, M j, Y', strtotime($event['date'])) : '';
        if (!empty($event['time'])) { $evWhen .= ($evWhen ? ' · ' : '') . date('g:i A', strtotime($event['time'])); }
      @endphp
      @if($evWhen)
        <p style="font-size:16px;font-weight:700;margin:2px 0 4px;color:var(--pos-ink);">{{ $evWhen }}</p>
      @endif
      <p class="sub"><a class="ev-edit" href="{{ route('events.index') }}">&larr; All events</a></p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  {{-- ---------- Event details (collapsed by default) ---------- --}}
  <details class="ev-card">
    <summary class="ev-create-summary">Event details</summary>
    <form method="POST" action="{{ route('events.update', ['id' => $event['id']]) }}" style="margin-top:14px;">
      {{ csrf_field() }}
      @include('events.partials._form', ['event' => $event, 'eventTypes' => $eventTypes, 'genres' => $genres])
      <div style="margin-top:14px;">
        <button type="submit" class="btn-accent">Save details</button>
      </div>
    </form>
  </details>

  {{-- ---------- Listening-party prep / task list (collapsed by default) ---------- --}}
  <details class="ev-card">
    <summary class="ev-create-summary">Listening-party prep</summary>
    <form method="POST" action="{{ route('events.prep', ['id' => $event['id']]) }}" style="margin-top:14px;">
      {{ csrf_field() }}

      <div class="ev-row">
        <div class="ev-field" style="flex:1 1 220px;">
          <label>Event host</label>
          <input type="text" name="details[eventHost]" value="{{ $details['eventHost'] ?? '' }}" placeholder="Who is running it">
        </div>
        <div class="ev-field" style="flex:2 1 280px;">
          <label>Playback / event link</label>
          <input type="text" name="details[eventLink]" value="{{ $details['eventLink'] ?? '' }}" placeholder="Stream / playback link">
        </div>
      </div>
      <div class="ev-row">
        <div class="ev-field" style="flex:1 1 220px;">
          <label>Giveaway box tracking</label>
          <input type="text" name="details[boxTracking]" value="{{ $details['boxTracking'] ?? '' }}" placeholder="Carrier tracking #">
        </div>
        <div class="ev-field" style="flex:1 1 220px;">
          <label>Box location</label>
          <input type="text" name="details[boxLocation]" value="{{ $details['boxLocation'] ?? '' }}" placeholder="Where the box is stored">
        </div>
      </div>

      <ul class="prep-list">
        @foreach($prepItems as $pi)
          @php
            $state = (array) ($checklist[$pi['id']] ?? []);
            $done = !empty($state['done']);
            $label = $pi['label'];
            if ($host !== '' && in_array($pi['id'], ['rules_confirmed_with_host','link_shared_with_host','link_confirmed_working'], true)) {
              $label = str_replace(['the person hosting', 'the designated employee'], $host, $label);
            }
          @endphp
          <li class="{{ $done ? 'is-done' : '' }}">
            <input type="hidden" name="checklist[{{ $pi['id'] }}][done]" value="0">
            <input type="checkbox" name="checklist[{{ $pi['id'] }}][done]" value="1" {{ $done ? 'checked' : '' }}>
            <div class="prep-main">
              <div class="lbl">{{ $label }}</div>
              @if(!empty($state['updatedBy']))
                <div class="ev-meta prep-by">last by {{ $state['updatedBy'] }}@if(!empty($state['updatedAt'])) &middot; {{ \Carbon\Carbon::parse($state['updatedAt'])->format('M j, g:ia') }}@endif</div>
              @endif
            </div>
            <input class="prep-note" type="text" name="checklist[{{ $pi['id'] }}][note]"
                   value="{{ $state['note'] ?? '' }}" placeholder="Add a note">
            <span class="due">due {{ $pi['due'] == 0 ? 'day of' : $pi['due'] . 'd before' }}</span>
          </li>
        @endforeach
      </ul>

      <div style="margin-top:14px;">
        <button type="submit" class="btn-accent">Save prep</button>
      </div>
    </form>
  </details>

  {{-- RSVPs, giveaway spin, preorders (live from nivessa.com via the bridge) --}}
  @include('events.partials._bridge')
</div>
@endsection
