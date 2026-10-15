@extends('layouts.app')

@section('title', 'Job Applications')

@section('content')
@include('website_admin.inbox._styles')

@php
  $AC = \App\Http\Controllers\WebsiteAdmin\ApplicationsController::class;
  $fmtDate = function ($iso) {
      if (empty($iso)) return '';
      try { return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format('M j, Y'); } catch (\Throwable $e) { return ''; }
  };
  $shortRole = function ($r) { return trim(preg_replace('/\s*\(.*\)\s*$/', '', (string) $r)); };
  $tabs = [
    '' => ['All', $status === '' ? $totalCount : ($counts['new'] + $counts['rejected'])],
    'new' => ['New', $counts['new']],
    'accepted' => ['Accepted', $counts['accepted']],
    'hold' => ['Hold', $counts['hold']],
    'maybe' => ['Maybe', $counts['maybe']],
    'rejected' => ['Rejected', $counts['rejected']],
    'archived' => ['Archived', $counts['archived']],
  ];
  $sortUrl = function ($field) use ($sortBy, $sortDir, $status, $role) {
      if ($sortBy === $field) {
          $dir = $sortDir === 'asc' ? 'desc' : 'asc';
      } else {
          $dir = in_array($field, ['aiScore', 'score', 'createdAt'], true) ? 'desc' : 'asc';
      }
      return route('wa.inbox.applications', ['status' => $status, 'role' => $role, 'sort' => $field, 'dir' => $dir]);
  };
  $arrow = function ($field) use ($sortBy, $sortDir) {
      return $sortBy === $field ? ($sortDir === 'asc' ? ' &uarr;' : ' &darr;') : '';
  };
  $pageUrl = function ($p) use ($status, $role, $sortBy, $sortDir) {
      return route('wa.inbox.applications', ['status' => $status, 'role' => $role, 'sort' => $sortBy, 'dir' => $sortDir, 'page' => $p]);
  };
  $jsApps = [];
  foreach (array_merge($applications, $findHit ? [$findHit] : []) as $a) {
      $jsApps[$a['_id']] = ['name' => $a['name'] ?? '', 'scorecard' => $a['scorecard'] ?? new \stdClass()];
  }
  $listedIds = array_column($applications, '_id');
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Job Applications</h1>
      <p class="sub">Review applicants from the careers page and accept, hold, or reject them.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($findMissed)<div class="alert-err">No application found for "{{ $find }}".</div>@endif

  <div class="wi-tabs">
    @foreach($tabs as $key => $t)
      <a class="wi-tab {{ $status === $key ? 'active' : '' }}" href="{{ route('wa.inbox.applications', ['status' => $key, 'role' => $role]) }}">{{ $t[0] }} <span class="n">{{ $t[1] }}</span></a>
    @endforeach
  </div>

  <div class="wi-bar">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <form method="GET" action="{{ route('wa.inbox.applications') }}">
        <input type="hidden" name="status" value="{{ $status }}">
        <label for="wi-role">Hiring for</label>
        <select id="wi-role" name="role" onchange="this.form.submit()">
          <option value="">All roles</option>
          @foreach($roleOptions as $r)
            <option value="{{ $r }}" {{ $role === $r ? 'selected' : '' }}>{{ $r }}</option>
          @endforeach
        </select>
      </form>
      <button type="button" class="wi-btn" id="wi-archive-btn" title="Archive every application scoring under {{ $threshold }}/100 on the AI screen">Archive under {{ $threshold }}%</button>
    </div>
    <div class="wi-hint">Click a column header to sort.</div>
  </div>

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Could not load applications</h2>
      <p class="sub" style="margin:0;">{{ $bridgeError }}</p>
    </div>
  @elseif(empty($applications))
    <div class="wi-card"><div class="wi-empty">No applications here yet.</div></div>
  @else
    <div class="wi-card wi-scroll">
      <table class="wi-tbl" style="min-width:1100px;">
        <thead>
          <tr>
            <th><a class="{{ $sortBy === 'name' ? 'on' : '' }}" href="{{ $sortUrl('name') }}">Applicant{!! $arrow('name') !!}</a></th>
            <th><a class="{{ $sortBy === 'role' ? 'on' : '' }}" href="{{ $sortUrl('role') }}">Role{!! $arrow('role') !!}</a></th>
            <th><a class="{{ $sortBy === 'location' ? 'on' : '' }}" href="{{ $sortUrl('location') }}">Store{!! $arrow('location') !!}</a></th>
            <th>Links</th>
            <th><a class="{{ $sortBy === 'aiScore' ? 'on' : '' }}" href="{{ $sortUrl('aiScore') }}">AI screen{!! $arrow('aiScore') !!}</a></th>
            <th><a class="{{ $sortBy === 'score' ? 'on' : '' }}" href="{{ $sortUrl('score') }}">Score{!! $arrow('score') !!}</a></th>
            <th><a class="{{ $sortBy === 'createdAt' ? 'on' : '' }}" href="{{ $sortUrl('createdAt') }}">Applied{!! $arrow('createdAt') !!}</a></th>
            <th><a class="{{ $sortBy === 'status' ? 'on' : '' }}" href="{{ $sortUrl('status') }}">Status{!! $arrow('status') !!}</a></th>
            <th class="r">Actions</th>
          </tr>
        </thead>
        <tbody>
          @foreach($applications as $a)
            @php
              $id = $a['_id'];
              $resume = $AC::externalUrl($a['resumeLink'] ?? null);
              $linkedin = $AC::externalUrl($a['linkedinUrl'] ?? null);
              $insta = $AC::externalUrl($a['instagramUrl'] ?? null);
              $score = (int) ($a['score'] ?? 0);
              $band = $AC::band($score);
            @endphp
            <tr>
              <td>
                <div style="display:flex;gap:6px;align-items:flex-start;">
                  <form method="POST" action="{{ route('wa.inbox.applications.status', $id) }}">
                    {{ csrf_field() }}<input type="hidden" name="status" value="accepted">
                    <button class="wi-btn ok" title="Accept" style="width:30px;padding:0;">&#10003;</button>
                  </form>
                  <button type="button" class="wi-btn bad" title="Reject" style="width:30px;padding:0;" onclick="wiOpenReject('{{ $id }}')">&#10005;</button>
                  <div>
                    <div class="wi-name">{{ $a['name'] ?? '' }}</div>
                    <div class="wi-muted">{{ $a['email'] ?? '' }}</div>
                    @foreach(($a['otherApplications'] ?? []) as $o)
                      <div class="wi-also" title="Same applicant, a separate application">Also applied: {{ $shortRole($o['role'] ?? '') }}@if(isset($o['aiScore']) && $o['aiScore'] !== null) ({{ $o['aiScore'] }} {{ $o['aiVerdict'] ?? '' }})@endif @if(($o['status'] ?? 'new') !== 'new') &middot; {{ $o['status'] }}@endif</div>
                    @endforeach
                  </div>
                </div>
              </td>
              <td>{{ $a['role'] ?? '' }}</td>
              <td>{{ $a['location'] ?? '' }}</td>
              <td class="wi-links">
                @if($resume)<a href="{{ $resume }}" target="_blank" rel="noopener noreferrer">Resume</a>@endif
                @if($linkedin)<a href="{{ $linkedin }}" target="_blank" rel="noopener noreferrer">LinkedIn</a>@endif
                @if($insta)<a href="{{ $insta }}" target="_blank" rel="noopener noreferrer">Instagram</a>@endif
                @if(!$resume && !$linkedin && !$insta)<span class="wi-muted">None</span>@endif
              </td>
              <td>@include('website_admin.inbox._application_ai', ['x' => $a])</td>
              <td>
                <button type="button" style="background:none;border:0;padding:0;cursor:pointer;" title="Score against the rubric" onclick="wiOpenScore('{{ $id }}')">
                  @if($band)<span class="wi-pill {{ $band[1] }}">{{ $score }}/{{ $maxScore }} &middot; {{ $band[0] }}</span>@else<span class="wi-muted" style="text-decoration:underline;">Unscored</span>@endif
                </button>
              </td>
              <td style="white-space:nowrap;">{{ $fmtDate($a['createdAt'] ?? null) }}</td>
              <td>
                @include('website_admin.inbox._application_status', ['st' => $a['status'] ?? 'new'])
                @if(($a['status'] ?? '') === 'accepted')
                  <form method="POST" action="{{ route('wa.inbox.applications.interview', $id) }}">
                    {{ csrf_field() }}<input type="hidden" name="scheduled" value="{{ !empty($a['interviewScheduled']) ? 0 : 1 }}">
                    <button class="wi-pill wi-pill-btn {{ !empty($a['interviewScheduled']) ? 'purple' : 'gray' }}" title="{{ !empty($a['interviewScheduled']) ? 'Interview scheduled. Click to clear.' : 'Mark interview as scheduled' }}">{{ !empty($a['interviewScheduled']) ? 'Interview set' : 'Set interview' }}</button>
                  </form>
                @endif
              </td>
              <td>
                <div class="wi-actions">
                  <button type="button" class="wi-btn" onclick="document.getElementById('wi-detail-{{ $id }}').showModal()">View</button>
                  <form method="POST" action="{{ route('wa.inbox.applications.status', $id) }}">
                    {{ csrf_field() }}<input type="hidden" name="status" value="hold">
                    <button class="wi-btn warn" title="Hold">Hold</button>
                  </form>
                  <form method="POST" action="{{ route('wa.inbox.applications.status', $id) }}">
                    {{ csrf_field() }}<input type="hidden" name="status" value="maybe">
                    <button class="wi-btn" title="Maybe">Maybe</button>
                  </form>
                  <button type="button" class="wi-btn danger" onclick="wiOpenDelete('{{ $id }}')">Delete</button>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    @if($totalPages > 1)
      <div class="wi-pager">
        @if($page > 1)<a class="wi-btn" href="{{ $pageUrl($page - 1) }}">Previous</a>@else<button class="wi-btn" disabled>Previous</button>@endif
        <span>Page {{ $page }} of {{ $totalPages }}</span>
        @if($page < $totalPages)<a class="wi-btn" href="{{ $pageUrl($page + 1) }}">Next</a>@else<button class="wi-btn" disabled>Next</button>@endif
      </div>
    @endif
  @endif
</div>

{{-- One detail dialog per applicant on the page, plus the ?find= hit. --}}
@foreach($applications as $a)
  @include('website_admin.inbox._application_detail', ['a' => $a])
@endforeach
@if($findHit && !in_array($findHit['_id'], $listedIds, true))
  @include('website_admin.inbox._application_detail', ['a' => $findHit])
@endif

<dialog class="wi-dialog wide" id="wi-score">
  <form method="POST" id="wi-score-form" class="wi-dialog-body">
    {{ csrf_field() }}
    <h3><span id="wi-score-title">Score</span> <span class="wi-pill gray" id="wi-score-total">0/{{ $maxScore }}</span></h3>
    <p class="sub">Rate each criterion 0 to 3. 0 is no relevant examples, 1 is some, 2 is good, 3 is excellent. The total out of {{ $maxScore }} sets the band: under 25 regret, 25 to 31 hold, 32 or more offer.</p>
    @foreach($scoreSections as $title => $items)
      <div class="wi-sec">{{ $title }}</div>
      @foreach($items as $key => $label)
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid #f3f4f6;padding:6px 0;">
          <span style="font-size:13.5px;color:#111827;">{{ $label }}</span>
          <span style="display:flex;gap:4px;flex-shrink:0;">
            @foreach([0 => 'None. No relevant examples, vague or generic.', 1 => 'Some. Aware, but lacks depth, specifics, or outcome.', 2 => 'Good. Clear, specific experience with a result.', 3 => 'Excellent. Full depth, specificity, and genuine insight.'] as $n => $desc)
              <label title="{{ $desc }}" class="wi-lvl"><input type="radio" name="card[{{ $key }}]" value="{{ $n }}" data-key="{{ $key }}"><span>{{ $n }}</span></label>
            @endforeach
          </span>
        </div>
      @endforeach
    @endforeach
    <div class="wi-dialog-actions">
      <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Cancel</button>
      <button class="wi-btn primary" id="wi-score-save">Save score</button>
    </div>
  </form>
</dialog>

<dialog class="wi-dialog" id="wi-reject">
  <form method="POST" id="wi-reject-form" class="wi-dialog-body">
    {{ csrf_field() }}
    <input type="hidden" name="status" value="archived">
    <input type="hidden" name="reject" value="1">
    <h3>Reject application</h3>
    <p class="sub">Optionally add a private note. It is not sent to the applicant.</p>
    <textarea name="reason" rows="3" placeholder="Reason or note (optional)"></textarea>
    <div class="wi-dialog-actions">
      <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Cancel</button>
      <button class="wi-btn bad">Reject</button>
    </div>
  </form>
</dialog>

<dialog class="wi-dialog" id="wi-delete">
  <form method="POST" id="wi-delete-form" class="wi-dialog-body">
    {{ csrf_field() }}
    <h3>Delete application?</h3>
    <p class="sub">This permanently removes the application. It cannot be undone.</p>
    <div class="wi-dialog-actions">
      <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Cancel</button>
      <button class="wi-btn bad">Delete</button>
    </div>
  </form>
</dialog>

<dialog class="wi-dialog" id="wi-archive">
  <form method="POST" action="{{ route('wa.inbox.applications.archive_low') }}" class="wi-dialog-body">
    {{ csrf_field() }}
    <h3>Archive low AI scores?</h3>
    <p class="sub" id="wi-archive-text">Checking...</p>
    <div class="wi-dialog-actions">
      <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Cancel</button>
      <button class="wi-btn primary" id="wi-archive-go" style="display:none;">Archive</button>
    </div>
  </form>
</dialog>

<style>
  .wi-lvl { margin:0; cursor:pointer; }
  .wi-lvl input { position:absolute; opacity:0; pointer-events:none; }
  .wi-lvl span { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:6px; border:1px solid #d1d5db; background:#fff; font-size:13.5px; font-weight:600; color:#111827; }
  .wi-lvl input:checked + span { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
</style>

<script>
(function () {
  var apps = {!! json_encode($jsApps, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  var maxScore = {{ (int) $maxScore }};
  var scoreUrl = @json(route('wa.inbox.applications.score', '__ID__'));
  var statusUrl = @json(route('wa.inbox.applications.status', '__ID__'));
  var deleteUrl = @json(route('wa.inbox.applications.delete', '__ID__'));

  function band(t) {
    if (!t) return ['Unscored', 'gray'];
    if (t >= 32) return [t + '/' + maxScore + ' · Offer', 'green'];
    if (t >= 25) return [t + '/' + maxScore + ' · Talent pool / Hold', 'amber'];
    return [t + '/' + maxScore + ' · Regret', 'red'];
  }
  function recalc() {
    var t = 0;
    document.querySelectorAll('#wi-score input[type=radio]:checked').forEach(function (r) { t += parseInt(r.value, 10) || 0; });
    var b = band(t), pill = document.getElementById('wi-score-total');
    pill.textContent = t ? b[0] : '0/' + maxScore;
    pill.className = 'wi-pill ' + b[1];
    document.getElementById('wi-score-save').textContent = 'Save score (' + t + '/' + maxScore + ')';
  }
  window.wiOpenScore = function (id) {
    var a = apps[id] || { name: '', scorecard: {} };
    var card = a.scorecard || {};
    document.getElementById('wi-score-form').action = scoreUrl.replace('__ID__', id);
    document.getElementById('wi-score-title').textContent = 'Score: ' + a.name;
    document.querySelectorAll('#wi-score input[type=radio]').forEach(function (r) {
      r.checked = parseInt(r.value, 10) === (parseInt(card[r.dataset.key], 10) || 0);
    });
    recalc();
    document.getElementById('wi-score').showModal();
  };
  document.querySelectorAll('#wi-score input[type=radio]').forEach(function (r) { r.addEventListener('change', recalc); });

  window.wiOpenReject = function (id) {
    var f = document.getElementById('wi-reject-form');
    f.action = statusUrl.replace('__ID__', id);
    f.reason.value = '';
    document.getElementById('wi-reject').showModal();
  };
  window.wiOpenDelete = function (id) {
    document.getElementById('wi-delete-form').action = deleteUrl.replace('__ID__', id);
    document.getElementById('wi-delete').showModal();
  };

  document.getElementById('wi-archive-btn').addEventListener('click', function () {
    var text = document.getElementById('wi-archive-text'), go = document.getElementById('wi-archive-go');
    text.textContent = 'Checking...';
    go.style.display = 'none';
    document.getElementById('wi-archive').showModal();
    fetch(@json(route('wa.inbox.applications.archive_preview')), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.success) { text.textContent = j.message || 'Could not check applications.'; return; }
      var n = j.count || 0;
      if (!n) { text.textContent = 'No graded applications are scoring under {{ $threshold }}/100 right now, so there is nothing to archive.'; return; }
      text.textContent = 'This moves ' + n + ' application' + (n === 1 ? '' : 's') + ' scoring under {{ $threshold }}/100 on the AI screen into the Archived tab. They are hidden from the main list but not deleted, and you can restore any of them from the Archived tab. Applications with no AI score yet are left alone.';
      go.textContent = 'Archive ' + n;
      go.style.display = '';
    }).catch(function () { text.textContent = 'Could not check applications.'; });
  });

  @if($findHit)
    var hit = document.getElementById('wi-detail-{{ $findHit['_id'] }}');
    if (hit) hit.showModal();
  @endif
})();
</script>
@endsection
