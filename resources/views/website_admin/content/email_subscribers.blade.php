@extends('layouts.app')
@section('title', 'Email Subscribers')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\SubscribersController::class;
  $sourceColor = ['brevo_sync' => 'purple', 'events' => 'amber', 'website_signup' => 'blue'];
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Email Subscribers</h1>
      <p class="sub">The website's subscriber list and its Brevo sync. Brevo lists pulled into the ERP (LA Regulars and others) are on <a href="{{ url('/marketing/email-lists') }}">Email Lists</a>. The weekly newsletter is on <a href="{{ route('wa.content.newsletter') }}">Newsletters</a>.</p>
    </div>
    <div class="wc-actions">
      <form method="POST" action="{{ route('wa.content.emailSubscribersSync') }}" onsubmit="if(!confirm('Sync with Brevo? This will update your subscriber list.')) return false; this.querySelector('button').disabled=true; this.querySelector('button').textContent='Syncing...';">
        {{ csrf_field() }}
        <button type="submit" class="wc-btn">Sync with Brevo</button>
      </form>
      <button type="button" class="wc-btn" onclick="document.getElementById('wc-import').showModal()">Import CSV</button>
      <button type="button" class="wc-btn" onclick="document.getElementById('wc-send').showModal()">Send email</button>
      <a class="wc-btn" href="{{ route('wa.content.emailSubscribersExport') }}">Export active (CSV)</a>
      <a class="wc-btn" href="{{ route('wa.content.emailSubscribersExport', ['all' => 1]) }}">Export all (CSV)</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  @if($importResult)
  <div class="wc-card wc-card-pad">
    <h2>Import result</h2>
    <div class="wc-stats" style="margin-bottom:8px;">
      <div class="wc-stat"><div class="k">Imported</div><div class="v" style="color:#15803d;">{{ $importResult['imported'] ?? 0 }}</div></div>
      <div class="wc-stat"><div class="k">Skipped</div><div class="v" style="color:#a16207;">{{ $importResult['skipped'] ?? 0 }}</div></div>
      <div class="wc-stat"><div class="k">Errors</div><div class="v" style="color:#b91c1c;">{{ $importResult['errors'] ?? 0 }}</div></div>
    </div>
    @php $skipped = $importResult['details']['skipped'] ?? []; @endphp
    @if(count($skipped))
      <div class="wc-label" style="margin-bottom:4px;">Skipped emails</div>
      @foreach(array_slice($skipped, 0, 5) as $sk)
        <div class="wc-muted">{{ $sk['email'] ?? '' }}: {{ $sk['reason'] ?? '' }}</div>
      @endforeach
      @if(count($skipped) > 5)<div class="wc-muted">And {{ count($skipped) - 5 }} more.</div>@endif
    @endif
  </div>
  @endif

  <div class="wc-stats">
    <div class="wc-stat"><div class="k">Total subscribers</div><div class="v">{{ number_format($stats['total']) }}</div></div>
    <div class="wc-stat"><div class="k">Active</div><div class="v" style="color:#15803d;">{{ number_format($stats['active']) }}</div></div>
    <div class="wc-stat"><div class="k">Inactive</div><div class="v" style="color:#b91c1c;">{{ number_format($stats['inactive']) }}</div></div>
  </div>

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Search by name or email"></div>
    <div class="wc-field"><label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="" @if($status === '') selected @endif>All statuses</option>
        <option value="active" @if($status === 'active') selected @endif>Active</option>
        <option value="inactive" @if($status === 'inactive') selected @endif>Inactive</option>
      </select>
    </div>
    <div class="wc-field"><label>Per page</label>
      <select name="limit" onchange="this.form.submit()">
        @foreach([10, 20, 50, 100] as $n)<option value="{{ $n }}" @if($limit === $n) selected @endif>{{ $n }} per page</option>@endforeach
      </select>
    </div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
  </form>

  <div class="wc-card">
    @if(count($rows) === 0)
      <div class="wc-empty">No subscribers found.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Source</th><th>Added</th></tr></thead>
        <tbody>
          @foreach($rows as $s)
          <tr>
            <td>{{ $s['name'] ?? '' }}</td>
            <td>{{ $s['email'] ?? '' }}</td>
            <td><span class="wc-pill {{ ($s['status'] ?? '') === 'active' ? 'green' : 'red' }}">{{ $s['status'] ?? '' }}</span></td>
            <td><span class="wc-pill {{ $sourceColor[$s['source'] ?? ''] ?? 'green' }}">{{ $s['source'] ?? 'unknown' }}</span></td>
            <td class="wc-muted">{{ $H::fmtDate($s['createdAt'] ?? null) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif
    @include('website_admin.content._pager')
  </div>
</div>

<dialog class="wc-dialog" id="wc-import">
  <form method="POST" action="{{ route('wa.content.emailSubscribersImport') }}" enctype="multipart/form-data" class="wc-dialog-body" onsubmit="this.querySelector('[type=submit]').disabled=true; this.querySelector('[type=submit]').textContent='Importing...';">
    {{ csrf_field() }}
    <h3>Import subscribers</h3>
    <div class="wc-field">
      <label>CSV file</label>
      <input type="file" name="file" accept=".csv" required>
      <div class="wc-help">The CSV needs an "Email" column. A "Name" column is optional. Up to 50 MB.</div>
    </div>
    <p class="wc-muted" style="margin-top:10px;"><a href="data:text/csv;base64,RW1haWwKam9obkBleGFtcGxlLmNvbQpqYW5lQGV4YW1wbGUuY29t" download="subscribers_template.csv">Download the CSV template</a>. Only the Email column is required.</p>
    <div class="wc-dialog-actions">
      <button type="button" class="wc-btn" onclick="document.getElementById('wc-import').close()">Cancel</button>
      <button type="submit" class="wc-btn wc-btn-primary">Import CSV</button>
    </div>
  </form>
</dialog>

<dialog class="wc-dialog wide" id="wc-send">
  <form method="POST" action="{{ route('wa.content.emailSubscribersSend') }}" class="wc-dialog-body" id="wc-send-form">
    {{ csrf_field() }}
    <h3>Send email to subscribers</h3>
    <div id="wc-send-edit">
      <div class="wc-field" style="margin-bottom:10px;"><label>Subject</label><input type="text" name="subject" id="wc-send-subject" value="{{ old('subject') }}" placeholder="Email subject"></div>
      <div class="wc-field">
        <label>Email content (HTML)</label>
        <textarea name="htmlContent" id="wc-send-html" rows="10" class="wc-mono" placeholder="HTML content for the email">{{ old('htmlContent') }}</textarea>
        <div class="wc-help">Use HTML tags like &lt;p&gt;, &lt;strong&gt;, &lt;a href=""&gt;.</div>
      </div>
      <div class="wc-muted" style="margin-top:8px;">Quick templates:
        <a href="#" data-tpl="simple">Simple message</a>,
        <a href="#" data-tpl="styled">Styled newsletter</a>
      </div>
      <div class="wc-label" style="margin-top:12px;">Live preview</div>
      <iframe id="wc-send-live" style="width:100%; height:260px; border:1px solid #e5e7eb; border-radius:8px; background:#fff;"></iframe>
    </div>
    <div id="wc-send-preview" style="display:none;">
      <div class="wc-muted">Subject</div>
      <div id="wc-send-prev-subject" style="font-weight:700; font-size:16px; margin-bottom:10px;"></div>
      <iframe id="wc-send-prev-frame" style="width:100%; height:380px; border:1px solid #e5e7eb; border-radius:8px; background:#fff;"></iframe>
    </div>
    <div id="wc-send-msg"></div>
    <div class="wc-dialog-actions">
      <button type="button" class="wc-btn" onclick="document.getElementById('wc-send').close()">Cancel</button>
      <button type="button" class="wc-btn" id="wc-send-prev-btn">Preview</button>
      <button type="button" class="wc-btn" id="wc-send-back-btn" style="display:none;">Back</button>
      <button type="submit" class="wc-btn wc-btn-primary">Send</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var subj = document.getElementById('wc-send-subject'), html = document.getElementById('wc-send-html');
  var live = document.getElementById('wc-send-live');
  var TPL = {
    simple: '<p>Hello,</p>\n<p>This is a test email.</p>\n<p>Best regards,<br/>Nivessa Team</p>',
    styled: '<div style="font-family: Arial, sans-serif;">\n<h2 style="color: #333;">Important Update</h2>\n<p>Dear Customer,</p>\n<p style="margin: 20px 0;">We have great news!</p>\n<a href="https://nivessa.com" style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;">View More</a>\n<p style="margin-top: 20px; color: #666; font-size: 12px;">&copy; Nivessa</p>\n</div>'
  };
  function refresh() { live.srcdoc = html.value; }
  html.addEventListener('input', refresh); refresh();
  document.querySelectorAll('[data-tpl]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); html.value = TPL[a.getAttribute('data-tpl')]; refresh(); });
  });
  function msg(t) { document.getElementById('wc-send-msg').innerHTML = t ? '<div class="alert-err" style="margin-top:10px;">' + t + '</div>' : ''; }
  function need() {
    if (!subj.value.trim()) { msg('Enter a subject.'); return false; }
    if (!html.value.trim()) { msg('Enter the email content.'); return false; }
    msg(''); return true;
  }
  document.getElementById('wc-send-prev-btn').addEventListener('click', function () {
    if (!need()) return;
    fetch(@json(route('wa.content.emailSubscribersPreview')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
      body: JSON.stringify({ subject: subj.value, htmlContent: html.value })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.success) return msg(res.message || 'Failed to preview the email.');
      var d = res.data || {};
      document.getElementById('wc-send-prev-subject').textContent = d.subject || subj.value;
      document.getElementById('wc-send-prev-frame').srcdoc = d.htmlContent || html.value;
      document.getElementById('wc-send-edit').style.display = 'none';
      document.getElementById('wc-send-preview').style.display = '';
      document.getElementById('wc-send-prev-btn').style.display = 'none';
      document.getElementById('wc-send-back-btn').style.display = '';
    }).catch(function () { msg('Failed to preview the email.'); });
  });
  document.getElementById('wc-send-back-btn').addEventListener('click', function () {
    document.getElementById('wc-send-edit').style.display = '';
    document.getElementById('wc-send-preview').style.display = 'none';
    document.getElementById('wc-send-prev-btn').style.display = '';
    this.style.display = 'none';
  });
  document.getElementById('wc-send-form').addEventListener('submit', function (e) {
    if (!need() || !confirm('Send this email to the website subscriber list now? This cannot be undone.')) { e.preventDefault(); return; }
    var b = this.querySelector('[type=submit]'); b.disabled = true; b.textContent = 'Sending...';
  });
})();
</script>
@endsection
