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
  // Per-store event lead when the party runs at both stores.
  $evLocs     = array_filter((array) ($event['location'] ?? []));
  $multiStore = count($evLocs) > 1;
  $hostHw     = trim((string) ($details['eventHostHollywood'] ?? ''));
  $hostPico   = trim((string) ($details['eventHostPico'] ?? ''));
  // Name used to personalize the prep checklist labels (combined when split).
  $hostForLabel = $multiStore
    ? implode(' / ', array_filter([$hostHw !== '' ? 'HW: ' . $hostHw : '', $hostPico !== '' ? 'Pico: ' . $hostPico : '']))
    : $host;
  // Prep progress flag — how many checklist items are still open.
  $prepTotal = count($prepItems);
  $prepDone  = 0;
  foreach ($prepItems as $pi) { if (!empty($checklist[$pi['id']]['done'])) { $prepDone++; } }
  $prepLeft  = $prepTotal - $prepDone;
@endphp

<div class="ev-wrap">
  <div class="ev-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
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
    @if(!empty($event['streetDate']))
      <div style="text-align:right;flex:0 1 auto;">
        <div style="display:inline-block;background:var(--pos-accent,#FFF2B3);color:#1c2150;font-weight:800;font-size:22px;padding:10px 22px;border-radius:14px;box-shadow:0 2px 6px rgba(0,0,0,.1);">
          Street date — {{ date('l, F j, Y', strtotime($event['streetDate'])) }}
        </div>
        @if(empty($event['hidePreorders']))
          <p style="font-size:14px;font-weight:700;margin:6px 0 0;color:var(--pos-ink);">All preorders will be ready for pickup from 10 AM on street date.</p>
        @endif
      </div>
    @endif
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
    <summary class="ev-create-summary">Listening-party prep
      @if($prepLeft > 0)
        <span class="prep-badge todo" style="margin-left:8px;vertical-align:middle;">{{ $prepLeft }} of {{ $prepTotal }} left</span>
      @else
        <span class="prep-badge done" style="margin-left:8px;vertical-align:middle;">All done</span>
      @endif
    </summary>
    <form method="POST" action="{{ route('events.prep', ['id' => $event['id']]) }}" style="margin-top:14px;">
      {{ csrf_field() }}

      <div class="ev-row">
        @if($multiStore)
          @if(in_array('hollywood', $evLocs, true))
            <div class="ev-field" style="flex:1 1 200px;">
              <label>Event lead — Hollywood</label>
              <input type="text" name="details[eventHostHollywood]" value="{{ $hostHw }}" placeholder="Who's running Hollywood">
            </div>
          @endif
          @if(in_array('pico', $evLocs, true))
            <div class="ev-field" style="flex:1 1 200px;">
              <label>Event lead — Pico</label>
              <input type="text" name="details[eventHostPico]" value="{{ $hostPico }}" placeholder="Who's running Pico">
            </div>
          @endif
        @else
          <div class="ev-field" style="flex:1 1 220px;">
            <label>Event host</label>
            <input type="text" name="details[eventHost]" value="{{ $details['eventHost'] ?? '' }}" placeholder="Who is running it">
          </div>
        @endif
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
            if ($hostForLabel !== '' && in_array($pi['id'], ['rules_confirmed_with_host','link_shared_with_host','link_confirmed_working'], true)) {
              $label = str_replace(['the person hosting', 'the designated employee'], $hostForLabel, $label);
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

    {{-- Order plan (want vs. ordered, per store) — collapsed inside prep. --}}
    @include('events.partials._order_plan')
  </details>

  {{-- Versions ordered — read-only reference (the products we ordered for this
       release). Shown even when public preorders are off. --}}
  @php $versions = array_values((array) ($event['preorderProducts'] ?? [])); @endphp
  @if(!empty($versions))
    @php
      $skuLabels = \App\Http\Controllers\EventsController::orderSkus();
      // Ordered qty per format (summed across stores).
      $orderedByFmt = [];
      foreach ((array) ($event['ordered'] ?? []) as $storeRow) {
        foreach ((array) $storeRow as $k => $val) {
          if ($val !== null && $val !== '') { $orderedByFmt[$k] = ($orderedByFmt[$k] ?? 0) + (int) $val; }
        }
      }
      // Preorders claimed per version (matched by the product title chosen).
      $preByTitle = [];
      foreach ((array) ($bridge['preorders'] ?? []) as $p) {
        $t = trim((string) ($p['preorderTitle'] ?? ''));
        if ($t !== '') { $preByTitle[$t] = ($preByTitle[$t] ?? 0) + 1; }
      }
    @endphp
    @php
      // Sort versions by price, highest to lowest.
      usort($versions, function ($a, $b) {
        return (float) ($b['price'] ?? 0) <=> (float) ($a['price'] ?? 0);
      });
    @endphp
    <div class="ev-card">
      <h2 style="margin-top:0;">What We Ordered</h2>
      <p class="sub" style="margin:0 0 10px;">What we ordered for this release vs. preorders claimed, highest to lowest. "Left" is what's still available to preorder.</p>
      <table class="ev-tbl">
        <thead><tr>
          <th style="width:42%;">Product</th>
          <th style="width:16%;">Format</th>
          <th style="width:12%;">Price</th>
          <th style="width:10%;">Ordered</th>
          <th style="width:10%;">Preordered</th>
          <th style="width:10%;">Left</th>
        </tr></thead>
        @php $totOrdered = 0; $totPre = 0; $totLeft = 0; @endphp
        <tbody>
          @foreach($versions as $v)
            @php
              $qty = (int) ($orderedByFmt[$v['format'] ?? ''] ?? 0);
              $pre = (int) ($preByTitle[trim((string) ($v['title'] ?? ''))] ?? 0);
              $left = $qty - $pre;
              $totOrdered += $qty; $totPre += $pre; $totLeft += $left;
            @endphp
            <tr>
              <td class="ev-name">{{ $v['title'] ?? '' }}</td>
              <td>{{ $skuLabels[$v['format'] ?? ''] ?? ($v['format'] ?? '—') }}</td>
              <td>{{ isset($v['price']) && $v['price'] !== null && $v['price'] !== '' ? '$' . $v['price'] : '—' }}</td>
              <td>{{ $qty }}</td>
              <td>{{ $pre }}</td>
              <td style="{{ $left <= 0 ? 'color:#a23;font-weight:700;' : '' }}">{{ $left }}@if($left <= 0) (sold out)@endif</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr style="font-weight:800;border-top:2px solid var(--pos-line,#ECE3CF);">
            <td class="ev-name">Total</td>
            <td></td>
            <td></td>
            <td>{{ $totOrdered }}</td>
            <td>{{ $totPre }}</td>
            <td>{{ $totLeft }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  @endif

  {{-- RSVPs, giveaway spin, preorders (live from nivessa.com via the bridge) --}}
  @include('events.partials._bridge')
</div>
@endsection
