@extends('layouts.app')

@section('title', 'Listening Party Prep')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .lp-head { display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; padding:12px 16px; background:#f9fafb; border-bottom:1px solid #f0f0f0; border-radius:10px 10px 0 0; }
  .lp-head h2 { font-size:17px; font-weight:700; margin:0; }
  .lp-meta { display:flex; flex-wrap:wrap; gap:4px 14px; font-size:12.5px; color:#6b7280; margin-top:3px; }
  .lp-badge { padding:4px 12px; border-radius:999px; font-size:12px; font-weight:700; border:1px solid; white-space:nowrap; }
  .lp-badge.ready { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }
  .lp-badge.todo { background:#fef3c7; color:#92400e; border-color:#fde68a; }
  .lp-details { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; padding:12px 16px; border-bottom:1px solid #f0f0f0; }
  @media (max-width: 900px) { .lp-details { grid-template-columns:1fr 1fr; } }
  @media (max-width: 560px) { .lp-details { grid-template-columns:1fr; } }
  .lp-details label { display:block; font-size:12px; font-weight:600; color:#4b5563; margin-bottom:4px; }
  .lp-details input { width:100%; box-sizing:border-box; font-size:13px; border:1px solid #d1d5db; border-radius:7px; padding:6px 9px; font-family:inherit; }
  .lp-items { list-style:none; margin:0; padding:0; }
  .lp-items li { display:flex; gap:12px; align-items:flex-start; padding:11px 16px; border-bottom:1px solid #f3f4f6; flex-wrap:wrap; }
  .lp-items li:last-child { border-bottom:0; }
  .lp-items input[type=checkbox] { width:18px; height:18px; margin-top:2px; flex:0 0 auto; cursor:pointer; }
  .lp-main { flex:1 1 260px; min-width:0; }
  .lp-lbl { font-size:14px; color:#1f2937; }
  .lp-lbl.em { font-weight:700; }
  li.is-done .lp-lbl { color:#9ca3af; text-decoration:line-through; }
  .lp-due { margin-left:8px; font-size:11.5px; color:#9ca3af; white-space:nowrap; }
  .lp-due.over { color:#dc2626; font-weight:600; }
  .lp-sub { margin:4px 0 0; padding-left:16px; font-size:12px; color:#6b7280; }
  .lp-by { font-size:11.5px; color:#9ca3af; margin-top:3px; }
  .lp-note { flex:0 1 240px; font-size:12.5px; border:1px solid #e5e7eb; border-radius:7px; padding:5px 8px; font-family:inherit; min-width:0; }
  .lp-saving { font-size:12px; color:#6b7280; }
  .lp-foot { padding:10px 16px; display:flex; gap:10px; align-items:center; }
</style>

@php
  $hostItems = ['rules_confirmed_with_host', 'link_shared_with_host', 'link_confirmed_working'];
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Listening Party Prep</h1>
      <p class="sub">Run through this before each listening party. Checks and notes save as you go and are shared with the whole team. It is the same checklist as the prep box on each event page.</p>
    </div>
    <div class="wd-pills" style="margin:0;">
      <a href="{{ route('wa.directory.partyPrep') }}" class="wd-pill {{ !$showPast ? 'active' : '' }}">Upcoming</a>
      <a href="{{ route('wa.directory.partyPrep', ['past' => 1]) }}" class="wd-pill {{ $showPast ? 'active' : '' }}">Include past</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if(count($parties) === 0)
    <div class="wd-card"><div class="wd-empty">No {{ $showPast ? '' : 'upcoming ' }}listening parties.</div></div>
  @endif

  @foreach($parties as $ev)
    @php
      $pid = (string) ($ev['id'] ?? '');
      $items = \App\Http\Controllers\EventsController::prepItemsForEvent($ev);
      $checklist = (array) ($ev['prepChecklist'] ?? []);
      $details = (array) ($ev['prepDetails'] ?? []);
      $locs = array_values(array_filter((array) ($ev['location'] ?? [])));
      $multiStore = count($locs) > 1;
      $host = trim((string) ($details['eventHost'] ?? ''));
      $hostHw = trim((string) ($details['eventHostHollywood'] ?? ''));
      $hostPico = trim((string) ($details['eventHostPico'] ?? ''));
      $hostForLabel = $multiStore
        ? implode(' / ', array_filter([$hostHw !== '' ? 'HW: ' . $hostHw : '', $hostPico !== '' ? 'Pico: ' . $hostPico : '']))
        : $host;
      $done = 0;
      foreach ($items as $it) { if (!empty($checklist[$it['id']]['done'])) { $done++; } }
      $total = count($items);
      $eventDate = null;
      try { $eventDate = !empty($ev['date']) ? \Carbon\Carbon::parse($ev['date'], 'America/Los_Angeles')->startOfDay() : null; } catch (\Throwable $e) { $eventDate = null; }
      $headcount = $attending[$pid] ?? null;
    @endphp
    <div class="wd-card lp-card" data-save="{{ route('wa.directory.partyPrepSave', ['id' => $pid]) }}">
      <div class="lp-head">
        <div style="min-width:0;">
          <h2>{{ $ev['name'] ?? 'Untitled party' }}</h2>
          <div class="lp-meta">
            <span>{{ $eventDate ? $eventDate->format('D, M j, Y') : 'Date TBD' }}@if(!empty($ev['time'])), {{ \Carbon\Carbon::parse('2000-01-01 ' . $ev['time'])->format('g:ia') }}@endif</span>
            @if(count($locs))<span>{{ implode(' + ', array_map(fn($l) => $l === 'hollywood' ? 'Hollywood' : ($l === 'pico' ? 'Pico' : $l), $locs)) }}</span>@endif
            <span>{{ $headcount === null ? 'Headcount unavailable' : $headcount . ' attending' }}</span>
            <a href="{{ route('events.edit', ['id' => $pid]) }}">Open event and RSVPs</a>
          </div>
        </div>
        <span class="lp-badge {{ $done >= $total ? 'ready' : 'todo' }}" data-badge data-total="{{ $total }}">{{ $done >= $total ? 'Ready' : $done . ' / ' . $total . ' done' }}</span>
      </div>

      <form method="POST" action="{{ route('wa.directory.partyPrepSave', ['id' => $pid]) }}" class="lp-form">
        {{ csrf_field() }}
        <div class="lp-details">
          @if($multiStore)
            @if(in_array('hollywood', $locs, true))
              <div><label>Event lead, Hollywood</label><input type="text" name="details[eventHostHollywood]" value="{{ $hostHw }}" placeholder="Who is running Hollywood" data-detail="eventHostHollywood"></div>
            @endif
            @if(in_array('pico', $locs, true))
              <div><label>Event lead, Pico</label><input type="text" name="details[eventHostPico]" value="{{ $hostPico }}" placeholder="Who is running Pico" data-detail="eventHostPico"></div>
            @endif
          @else
            <div><label>Event host</label><input type="text" name="details[eventHost]" value="{{ $host }}" placeholder="Employee hosting this party" data-detail="eventHost"></div>
          @endif
          <div>
            <label>Listening party link</label>
            <input type="text" name="details[eventLink]" value="{{ $details['eventLink'] ?? '' }}" placeholder="https://" data-detail="eventLink">
            @if(preg_match('#^https?://#i', trim((string) ($details['eventLink'] ?? ''))))
              <a href="{{ trim($details['eventLink']) }}" target="_blank" rel="noopener noreferrer" style="font-size:11.5px;">Open link</a>
            @endif
          </div>
          <div><label>Incoming box tracking</label><input type="text" name="details[boxTracking]" value="{{ $details['boxTracking'] ?? '' }}" placeholder="Tracking number or link" data-detail="boxTracking"></div>
          <div><label>Where the box is</label><input type="text" name="details[boxLocation]" value="{{ $details['boxLocation'] ?? '' }}" placeholder="e.g. Hollywood back room, shelf B" data-detail="boxLocation"></div>
        </div>

        <ul class="lp-items">
          @foreach($items as $it)
            @php
              $state = (array) ($checklist[$it['id']] ?? []);
              $isDone = !empty($state['done']);
              $label = $it['label'];
              if ($hostForLabel !== '' && in_array($it['id'], $hostItems, true)) {
                  $label = str_replace(['the person hosting', 'the designated employee', 'Host tests'], [$hostForLabel, $hostForLabel, $hostForLabel . ' tests'], $label);
              }
              $due = $eventDate ? $eventDate->copy()->subDays((int) ($it['due'] ?? 0)) : null;
              $overdue = $due && !$isDone && $due->lt($today);
            @endphp
            <li class="{{ $isDone ? 'is-done' : '' }}" data-item="{{ $it['id'] }}">
              <input type="hidden" name="checklist[{{ $it['id'] }}][done]" value="0">
              <input type="checkbox" name="checklist[{{ $it['id'] }}][done]" value="1" {{ $isDone ? 'checked' : '' }} data-check>
              <div class="lp-main">
                <span class="lp-lbl {{ in_array($it['id'], $emphasis, true) ? 'em' : '' }}">{{ $label }}</span>
                @if($it['id'] === 'enough_staff' && $headcount !== null)<span style="font-size:12px; color:#4f46e5;">({{ $headcount }} attending)</span>@endif
                @if($due)<span class="lp-due {{ $overdue ? 'over' : '' }}">Due {{ $due->format('M j') }}{{ $overdue ? ', overdue' : '' }}</span>@endif
                @if(!empty($sub[$it['id']]))
                  <ul class="lp-sub">@foreach($sub[$it['id']] as $line)<li style="padding:0; border:0; display:list-item;">{{ $line }}</li>@endforeach</ul>
                @endif
                <div class="lp-by" data-by>@if(!empty($state['updatedBy'])){{ $state['updatedBy'] }}@if(!empty($state['updatedAt'])), {{ \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($state['updatedAt'], 'M j, g:ia') }}@endif @endif</div>
              </div>
              <input class="lp-note" type="text" name="checklist[{{ $it['id'] }}][note]" value="{{ $state['note'] ?? '' }}" placeholder="Add a note" data-note>
            </li>
          @endforeach
        </ul>
        <div class="lp-foot">
          <button type="submit" class="wd-btn">Save all</button>
          <span class="lp-saving" data-msg></span>
        </div>
      </form>
    </div>
  @endforeach
</div>

<script>
// Save each change right away (like the website's prep page); "Save all" is the no-JS fallback.
(function () {
  var token = '{{ csrf_token() }}';
  function post(card, body, msgText) {
    var msg = card.querySelector('[data-msg]');
    msg.textContent = 'Saving...';
    return fetch(card.getAttribute('data-save'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.success, j: j }; }); })
      .then(function (res) {
        msg.textContent = res.ok ? (msgText || 'Saved.') : (res.j.message || 'Could not save. Try again.');
        return res;
      })
      .catch(function () { msg.textContent = 'Could not save. Try again.'; return { ok: false }; });
  }
  function refreshBadge(card) {
    var badge = card.querySelector('[data-badge]');
    var total = parseInt(badge.getAttribute('data-total'), 10);
    var done = card.querySelectorAll('[data-check]:checked').length;
    badge.textContent = done >= total ? 'Ready' : (done + ' / ' + total + ' done');
    badge.className = 'lp-badge ' + (done >= total ? 'ready' : 'todo');
  }
  document.querySelectorAll('.lp-card').forEach(function (card) {
    card.querySelectorAll('[data-check]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var li = cb.closest('li');
        var id = li.getAttribute('data-item');
        var body = { checklist: {} };
        body.checklist[id] = { done: cb.checked };
        li.classList.toggle('is-done', cb.checked);
        refreshBadge(card);
        post(card, body).then(function (res) {
          if (res.ok) {
            li.querySelector('[data-by]').textContent = res.j.by + ', ' + res.j.at;
          } else {
            cb.checked = !cb.checked;
            li.classList.toggle('is-done', cb.checked);
            refreshBadge(card);
          }
        });
      });
    });
    card.querySelectorAll('[data-note]').forEach(function (inp) {
      inp.setAttribute('data-saved', inp.value);
      inp.addEventListener('blur', function () {
        if (inp.value.trim() === inp.getAttribute('data-saved').trim()) return;
        var id = inp.closest('li').getAttribute('data-item');
        var body = { checklist: {} };
        body.checklist[id] = { note: inp.value.trim() };
        post(card, body, 'Note saved.').then(function (res) { if (res.ok) inp.setAttribute('data-saved', inp.value); });
      });
    });
    card.querySelectorAll('[data-detail]').forEach(function (inp) {
      inp.setAttribute('data-saved', inp.value);
      inp.addEventListener('blur', function () {
        if (inp.value.trim() === inp.getAttribute('data-saved').trim()) return;
        var body = { details: {} };
        body.details[inp.getAttribute('data-detail')] = inp.value.trim();
        post(card, body).then(function (res) { if (res.ok) inp.setAttribute('data-saved', inp.value); });
      });
    });
  });
})();
</script>
@endsection
