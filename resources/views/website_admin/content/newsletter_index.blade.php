@extends('layouts.app')
@section('title', 'Newsletters')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\NewsletterController::class;
  $s = $settings ?: [];
  $statusColor = ['draft' => 'amber', 'approved' => 'blue', 'sent' => 'green', 'skipped' => '', 'failed' => 'red'];
  $selectedLists = array_map('strval', $s['brevoListIds'] ?? []);
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Newsletters</h1>
      <p class="sub">Weekly auto-built drafts. Review, edit, and send through Brevo. The subscriber list itself lives on <a href="{{ route('wa.content.emailSubscribers') }}">Email Subscribers</a> and <a href="{{ url('/marketing/email-lists') }}">Email Lists</a>.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <div class="wc-card wc-card-pad" style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
    <div>
      <h2 style="margin:0;">This week</h2>
      <div class="wc-muted">Drafts build automatically on Mondays at 9am Pacific. You can also build one now.</div>
    </div>
    <form method="POST" action="{{ route('wa.content.newsletterBuild') }}" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Building...';">
      {{ csrf_field() }}
      <button type="submit" class="wc-btn wc-btn-primary">Build draft now</button>
    </form>
  </div>

  @if($settings)
  <form method="POST" action="{{ route('wa.content.newsletterSettings') }}" class="wc-card wc-card-pad">
    {{ csrf_field() }}
    <h2>Settings</h2>
    <div class="wc-grid">
      <div class="wc-field">
        <label>Brevo audience list</label>
        <select name="brevoListIds[]" multiple style="height:140px;">
          @foreach($brevoLists as $l)
            <option value="{{ $l['id'] }}" @if(in_array((string) $l['id'], $selectedLists, true)) selected @endif>{{ $l['name'] ?? $l['id'] }} ({{ $l['totalSubscribers'] ?? 0 }})</option>
          @endforeach
        </select>
        <div class="wc-help">Hold Cmd or Ctrl to select more than one.@if($brevoError) {{ $brevoError }}@endif</div>
      </div>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <div class="wc-field"><label>Sender name</label><input type="text" name="senderName" value="{{ $s['senderName'] ?? '' }}"></div>
        <div class="wc-field"><label>Sender email</label><input type="email" name="senderEmail" value="{{ $s['senderEmail'] ?? '' }}"></div>
        <div class="wc-field"><label>Reply-to email</label><input type="email" name="replyToEmail" value="{{ $s['replyToEmail'] ?? '' }}"></div>
      </div>
      <div class="wc-field"><label>Products per store</label><input type="number" name="productsPerStore" min="2" max="12" value="{{ $s['productsPerStore'] ?? 6 }}"></div>
      <div class="wc-field"><label>Events lookahead (days)</label><input type="number" name="eventsLookaheadDays" min="7" max="60" value="{{ $s['eventsLookaheadDays'] ?? 21 }}"></div>
      <div class="wc-field wc-span2">
        <label>Cool New Releases: exclude genres</label>
        <input type="text" name="sealedReleasesExcludeGenres" value="{{ implode(', ', $s['sealedReleasesExcludeGenres'] ?? []) }}" placeholder="Classical, Easy Listening, Children's, Spoken Word">
        <div class="wc-help">Comma separated. Matches any part of the genre, ignoring case.</div>
      </div>
      <div class="wc-field wc-span2">
        <label>Cool New Releases: exclude artists</label>
        <input type="text" name="sealedReleasesExcludeArtists" value="{{ implode(', ', $s['sealedReleasesExcludeArtists'] ?? []) }}" placeholder="Bach, Mozart, Beethoven">
      </div>
      <label class="wc-check wc-span2"><input type="checkbox" name="sealedReleasesRequireSales" value="1" @if(($s['sealedReleasesRequireSales'] ?? true) !== false) checked @endif> Cool New Releases: only include records with at least 1 web sale in the last 60 days</label>
      <div class="wc-field">
        <label>Featured record SKU</label>
        <input type="text" name="featuredSku" value="{{ $s['featuredSku'] ?? '' }}" placeholder="e.g. 0093624946021">
        <div class="wc-help">Shown as the spotlight at the top. Leave blank for no spotlight. The SKU is on the product's page.</div>
      </div>
      <div class="wc-field">
        <label>Featured blurb</label>
        <textarea name="featuredBlurb" rows="3" placeholder="One or two sentences about why this record is worth featuring.">{{ $s['featuredBlurb'] ?? '' }}</textarea>
      </div>
      <div class="wc-field wc-span2">
        <label>New Releases: SKUs to highlight</label>
        <input type="text" name="newReleasesSkus" value="{{ $s['newReleasesSkus'] ?? '' }}" placeholder="e.g. 0093624946021, 10065, 5667">
        <div class="wc-help">Comma separated, in order. Hand-pick the titles to show off. Leave blank to fill with recent arrivals over $15.</div>
      </div>
      <label class="wc-check"><input type="checkbox" name="bargainBinEnabled" value="1" @if(($s['bargainBinEnabled'] ?? true) !== false) checked @endif> Show Bargain Bin (titles under $10)</label>
      <div class="wc-field"><label>Bargain Bin: how many</label><input type="number" name="bargainBinCount" min="0" max="12" value="{{ $s['bargainBinCount'] ?? 6 }}"></div>
      <div class="wc-field wc-span2"><label>Default intro paragraph</label><textarea name="introTemplate" rows="2">{{ $s['introTemplate'] ?? '' }}</textarea></div>
      <div class="wc-field"><label>Notify email (when a draft is ready)</label><input type="email" name="notifyEmail" value="{{ $s['notifyEmail'] ?? '' }}"></div>
      <label class="wc-check"><input type="checkbox" name="autoBuildEnabled" value="1" @if(!empty($s['autoBuildEnabled'])) checked @endif> Build a draft automatically every Monday</label>
    </div>
    <div style="display:flex; justify-content:flex-end; margin-top:14px;">
      <button type="submit" class="wc-btn wc-btn-primary">Save settings</button>
    </div>
  </form>
  @elseif($settingsError)
    <div class="alert-err">Newsletter settings: {{ $settingsError }}</div>
  @endif

  <div class="wc-card">
    <div class="wc-card-head"><h2>History</h2></div>
    @if(count($items) === 0)
      <div class="wc-empty">No newsletters yet. Click "Build draft now" to make one.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>Week of</th><th>Subject</th><th>Status</th><th>Sent</th><th></th></tr></thead>
        <tbody>
        @foreach($items as $n)
          <tr>
            <td style="white-space:nowrap;">{{ $H::fmtDate($n['weekOf'] ?? null) }}</td>
            <td>{{ ($n['subject'] ?? '') !== '' ? $n['subject'] : 'No subject' }}</td>
            <td><span class="wc-pill {{ $statusColor[$n['status'] ?? ''] ?? '' }}">{{ $n['status'] ?? '' }}</span></td>
            <td class="wc-muted">{{ !empty($n['sentAt']) ? $H::fmtDate($n['sentAt']) : '' }}</td>
            <td>
              <div class="wc-actions" style="justify-content:flex-end;">
                <a class="wc-btn wc-btn-sm" href="{{ route('wa.content.newsletterShow', $n['_id']) }}">Open</a>
                @if(($n['status'] ?? '') !== 'sent')
                  <form method="POST" action="{{ route('wa.content.newsletterDelete', $n['_id']) }}" onsubmit="return confirm('Delete this draft (week of {{ $H::fmtDate($n['weekOf'] ?? null) }})? This can\'t be undone.');">
                    {{ csrf_field() }}
                    <button type="submit" class="wc-btn wc-btn-sm wc-btn-danger">Delete</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
  </div>
</div>
@endsection
