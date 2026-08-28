@extends('layouts.app')

@section('title', 'Email Templates')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<style>
  .et-layout { display:flex; gap:20px; align-items:flex-start; }
  .et-sidebar { width:280px; flex:0 0 280px; position:sticky; top:12px; max-height:calc(100vh - 100px); overflow-y:auto; }
  .et-group-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9c927e; margin:18px 0 6px; padding:0 4px; }
  .et-group-title:first-child { margin-top:0; }
  .et-nav-item { display:block; padding:8px 10px; border-radius:8px; font-size:13px; color:#4a4636; text-decoration:none; margin-bottom:2px; }
  .et-nav-item:hover { background:#f4efe0; }
  .et-nav-item.active { background:#2a2620; color:#fff; }
  .et-nav-item .et-nav-desc { display:block; font-size:11px; color:#9c927e; margin-top:1px; }
  .et-nav-item.active .et-nav-desc { color:#c9c1ac; }
  .et-detail { flex:1 1 auto; min-width:0; }
  .et-panel { display:none; }
  .et-panel.active { display:block; }
  .et-field { margin-bottom:14px; }
  .et-field label { display:block; font-size:12px; font-weight:600; color:#5a5346; margin-bottom:4px; }
  .et-field input[type=text], .et-field textarea { width:100%; font-size:13px; padding:8px 10px; border:1px solid var(--pos-line,#ECE3CF); border-radius:8px; font-family:inherit; box-sizing:border-box; }
  .et-field textarea { resize:vertical; }
  .et-status-grid { display:grid; grid-template-columns:1fr; gap:10px; }
  .et-status-row { border:1px solid var(--pos-line,#ECE3CF); border-radius:8px; padding:10px; }
  .et-status-row h6 { margin:0 0 8px; font-size:12px; font-weight:700; color:#5a5346; text-transform:capitalize; }
  .et-status-row .et-status-inline { display:flex; gap:8px; }
  .et-status-row .et-status-inline input:first-child { max-width:60px; text-align:center; }
  .et-status-row input { margin-bottom:6px; }
  .et-actions { display:flex; gap:8px; align-items:center; margin-top:18px; padding-top:14px; border-top:1px solid var(--pos-line,#ECE3CF); flex-wrap:wrap; }
  .et-last-edited { font-size:11px; color:#9c927e; margin-left:auto; }
  .et-preview-wrap { margin-top:20px; padding-top:16px; border-top:1px solid var(--pos-line,#ECE3CF); }
  .et-preview-head { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#5a5346; margin-bottom:8px; }
  .et-preview-subject { font-weight:400; text-transform:none; letter-spacing:normal; color:#9c927e; margin-left:6px; }
  .et-preview-frame { display:block; width:100%; min-height:220px; border:1px solid var(--pos-line,#ECE3CF); border-radius:10px; background:#fff; }
  .et-test-row { display:flex; gap:8px; align-items:center; }
  .et-test-row input { font-size:12px; padding:6px 8px; border:1px solid var(--pos-line,#ECE3CF); border-radius:8px; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Email Templates</h1>
      <p class="sub">Edit the copy customers actually receive — the ERP's replacement for the website's own email-template editor.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Couldn't reach the website</h2>
      <p class="sub" style="margin:0;">{{ $bridgeErrorMessage }}</p>
    </div>
  @else
    <div class="et-layout">
      <div class="et-sidebar">
        @foreach($groupOrder as $group)
          @php $entries = collect($meta)->filter(fn($m) => $m['group'] === $group); @endphp
          @if($entries->isNotEmpty())
            <div class="et-group-title">{{ $group }}</div>
            @foreach($entries as $key => $m)
              <a href="#{{ $key }}" class="et-nav-item" data-et-nav="{{ $key }}" onclick="etShow('{{ $key }}'); return false;">
                {{ $m['label'] }}
                <span class="et-nav-desc">{{ $m['description'] }}</span>
              </a>
            @endforeach
          @endif
        @endforeach
      </div>

      <div class="et-detail">
        @foreach($meta as $key => $m)
          @php
            $tmpl = $templates[$key] ?? [];
            $bf = $tmpl['bodyFields'] ?? [];
            $subject = $tmpl['subject'] ?? '';
            $lastEditedBy = $tmpl['lastEditedBy'] ?? null;
          @endphp
          <div class="ev-card et-panel" id="panel-{{ $key }}" data-et-panel="{{ $key }}">
            <h2 style="margin-top:0;">{{ $m['label'] }}</h2>
            <p class="sub" style="margin-top:-6px;">{{ $m['description'] }}</p>

            <form method="POST" action="{{ route('email-templates.update', ['key' => $key]) }}" data-et-form="{{ $key }}">
              @csrf

              @foreach($m['fields'] as $field)
                @if($field === 'statusMessages')
                  <div class="et-field">
                    <label>{{ $fieldLabels['statusMessages'] }}</label>
                    <div class="et-status-grid">
                      @foreach($statusKeys as $sk)
                        @php $sm = $bf['statusMessages'][$sk] ?? []; @endphp
                        <div class="et-status-row">
                          <h6>{{ $sk }}</h6>
                          <div class="et-status-inline">
                            <input type="text" name="statusMessages[{{ $sk }}][icon]" value="{{ $sm['icon'] ?? '' }}" placeholder="icon">
                            <input type="text" name="statusMessages[{{ $sk }}][headline]" value="{{ $sm['headline'] ?? '' }}" placeholder="headline" style="flex:1;">
                          </div>
                          <input type="text" name="statusMessages[{{ $sk }}][message]" value="{{ $sm['message'] ?? '' }}" placeholder="message">
                        </div>
                      @endforeach
                    </div>
                  </div>
                @elseif($field === 'whatNextItems')
                  <div class="et-field">
                    <label>{{ $fieldLabels['whatNextItems'] }}</label>
                    <textarea name="whatNextItems" rows="4">{{ implode("\n", $bf['whatNextItems'] ?? []) }}</textarea>
                  </div>
                @elseif($field === 'subject')
                  <div class="et-field">
                    <label>{{ $fieldLabels['subject'] }}</label>
                    <input type="text" name="subject" value="{{ $subject }}">
                  </div>
                @elseif(strpos($field, 'extra.') === 0)
                  @php $subKey = substr($field, 6); @endphp
                  <div class="et-field">
                    <label>{{ $fieldLabels[$field] ?? $subKey }}</label>
                    <input type="text" name="extra[{{ $subKey }}]" value="{{ $bf['extra'][$subKey] ?? '' }}">
                  </div>
                @else
                  <div class="et-field">
                    <label>{{ $fieldLabels[$field] ?? $field }}</label>
                    @if(in_array($field, $textareaFields, true))
                      <textarea name="{{ $field }}" rows="3">{{ $bf[$field] ?? '' }}</textarea>
                    @else
                      <input type="text" name="{{ $field }}" value="{{ $bf[$field] ?? '' }}">
                    @endif
                  </div>
                @endif
              @endforeach

              <div class="et-actions">
                <button type="submit" class="btn-ghost" style="padding:7px 16px;font-weight:700;">Save</button>
                <button type="button" class="btn-ghost" style="padding:7px 14px;" onclick="etPreview('{{ $key }}')">Preview</button>
                <button type="button" class="btn-ghost" style="padding:7px 14px;color:#a23;border-color:#f3cccc;" onclick="if(confirm('Reset {{ $m['label'] }} to the default copy? This discards any edits.')) document.getElementById('reset-form-{{ $key }}').submit();">Reset to Default</button>
                @if($lastEditedBy)
                  <span class="et-last-edited">Last edited by {{ $lastEditedBy }}</span>
                @endif
              </div>
            </form>

            <form id="reset-form-{{ $key }}" method="POST" action="{{ route('email-templates.reset', ['key' => $key]) }}" style="display:none;">
              @csrf
            </form>

            <div class="et-test-row" style="margin-top:10px;">
              <input type="email" id="test-email-{{ $key }}" placeholder="you@nivessa.com" value="{{ auth()->user()->email ?? '' }}">
              <button type="button" class="btn-ghost" style="padding:6px 12px;font-size:12px;" onclick="etSendTest('{{ $key }}')">Send Test Email</button>
              <span id="test-status-{{ $key }}" style="font-size:12px;color:#5a8a4a;"></span>
            </div>

            <div class="et-preview-wrap">
              <div class="et-preview-head">Live Preview — this is the actual email, full length <span class="et-preview-subject" id="preview-subject-{{ $key }}"></span></div>
              <iframe id="preview-frame-{{ $key }}" class="et-preview-frame" title="Email preview for {{ $m['label'] }}"></iframe>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endif
</div>

<script>
function etShow(key) {
  document.querySelectorAll('[data-et-panel]').forEach(function(p) { p.classList.toggle('active', p.getAttribute('data-et-panel') === key); });
  document.querySelectorAll('[data-et-nav]').forEach(function(n) { n.classList.toggle('active', n.getAttribute('data-et-nav') === key); });
  window.location.hash = key;
  if (!document.getElementById('preview-frame-' + key).dataset.loaded) {
    etPreview(key);
  }
}

function etResizeFrame(frame) {
  try {
    var doc = frame.contentWindow.document;
    frame.style.height = Math.max(200, doc.documentElement.scrollHeight, doc.body.scrollHeight) + 'px';
  } catch (e) { /* ignore — cross-origin or not yet ready */ }
}

function etCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="_token"]')?.value
    || '';
}

function etFormBody(key) {
  var form = document.querySelector('[data-et-form="' + key + '"]');
  return new URLSearchParams(new FormData(form));
}

function etPreview(key) {
  var frame = document.getElementById('preview-frame-' + key);
  var subjectEl = document.getElementById('preview-subject-' + key);
  subjectEl.textContent = '— loading…';
  fetch('/email-templates/' + key + '/preview', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': etCsrf(), 'Accept': 'application/json' },
    body: etFormBody(key),
  })
    .then(function(r) { return r.json(); })
    .then(function(json) {
      if (!json.success) { subjectEl.textContent = ''; alert(json.message || 'Could not build preview.'); return; }
      frame.dataset.loaded = '1';
      subjectEl.textContent = '— ' + (json.data.subject || '');
      frame.onload = function() { etResizeFrame(frame); };
      frame.srcdoc = json.data.html || '';
    })
    .catch(function() { subjectEl.textContent = ''; alert('Could not reach the website.'); });
}

function etSendTest(key) {
  var email = document.getElementById('test-email-' + key).value.trim();
  if (!email) { alert('Enter an email address.'); return; }
  var statusEl = document.getElementById('test-status-' + key);
  statusEl.textContent = 'Sending…';
  statusEl.style.color = '#9c927e';
  fetch('/email-templates/' + key + '/send-test', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': etCsrf(), 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ toEmail: email }),
  })
    .then(function(r) { return r.json(); })
    .then(function(json) {
      statusEl.textContent = json.success ? 'Sent!' : (json.message || 'Failed.');
      statusEl.style.color = json.success ? '#5a8a4a' : '#a23';
    })
    .catch(function() { statusEl.textContent = 'Could not reach the website.'; statusEl.style.color = '#a23'; });
}

document.addEventListener('DOMContentLoaded', function() {
  var first = Object.keys(@json($meta))[0];
  var fromHash = window.location.hash ? window.location.hash.slice(1) : null;
  etShow(fromHash && document.querySelector('[data-et-panel="' + fromHash + '"]') ? fromHash : first);
});
</script>
@endsection
