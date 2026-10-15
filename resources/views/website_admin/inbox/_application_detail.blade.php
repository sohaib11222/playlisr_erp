@php
  $AC = \App\Http\Controllers\WebsiteAdmin\ApplicationsController::class;
  $d = $a;
  $dScore = (int) ($d['score'] ?? 0);
  $dBand = $AC::band($dScore);
  $links = [
    'Resume link' => $AC::externalUrl($d['resumeLink'] ?? null),
    'LinkedIn profile' => $AC::externalUrl($d['linkedinUrl'] ?? null),
    'Instagram profile' => $AC::externalUrl($d['instagramUrl'] ?? null),
  ];
  $vmLink = $AC::externalUrl($d['vmWorkLink'] ?? null);
  $longs = [
    'retailExperience' => 'Retail / sales experience',
    'vinylKnowledge' => 'Vinyl knowledge',
    'vmExperience' => 'VM experience',
  ];
@endphp
<dialog class="wi-dialog" id="wi-detail-{{ $d['_id'] }}">
  <div class="wi-dialog-body">
    <h3><span>{{ $d['name'] ?? '' }}</span> @include('website_admin.inbox._application_status', ['st' => $d['status'] ?? 'new'])</h3>

    <div class="wi-kv" style="align-items:center;">
      <b>Score</b>
      <span>
        @if($dBand)<span class="wi-pill {{ $dBand[1] }}">{{ $dScore }}/{{ $maxScore }} &middot; {{ $dBand[0] }}</span>@else<span class="wi-muted">Unscored</span>@endif
        <button type="button" class="wi-btn" style="height:26px;margin-left:6px;" onclick="this.closest('dialog').close(); wiOpenScore('{{ $d['_id'] }}');">Score</button>
      </span>
    </div>

    @if(isset($d['aiScore']) && $d['aiScore'] !== null)
      <div class="wi-box">
        <div class="wi-kv" style="margin-top:0;"><b>AI screen</b><span>@include('website_admin.inbox._application_ai', ['x' => $d])</span></div>
        @if(empty($d['aiResumeRead']))
          <p style="font-size:12px;color:#b45309;margin:4px 0;">The resume link could not be opened, so this score is based on limited info. Review it manually.</p>
        @endif
        @if(!empty($d['aiSummary']))<p style="margin:6px 0;color:#374151;font-size:13.5px;">{{ $d['aiSummary'] }}</p>@endif
        @if(!empty($d['aiStrengths']))
          <div style="font-weight:700;color:#15803d;font-size:13px;margin-top:6px;">Strengths</div>
          <ul style="margin:2px 0 0 18px;padding:0;font-size:13px;color:#374151;">
            @foreach($d['aiStrengths'] as $s)<li>{{ $s }}</li>@endforeach
          </ul>
        @endif
        @if(!empty($d['aiConcerns']))
          <div style="font-weight:700;color:#b91c1c;font-size:13px;margin-top:6px;">Concerns</div>
          <ul style="margin:2px 0 0 18px;padding:0;font-size:13px;color:#374151;">
            @foreach($d['aiConcerns'] as $s)<li>{{ $s }}</li>@endforeach
          </ul>
        @endif
      </div>
    @endif

    <div class="wi-kv"><b>Email</b><span><a href="mailto:{{ $d['email'] ?? '' }}">{{ $d['email'] ?? '' }}</a></span></div>
    @if(!empty($d['phone']))<div class="wi-kv"><b>Phone</b><span>{{ $d['phone'] }}</span></div>@endif
    <div class="wi-kv"><b>Role</b><span>{{ $d['role'] ?? '' }}</span></div>
    <div class="wi-kv"><b>Preferred store</b><span>{{ $d['location'] ?? '' }}</span></div>
    @foreach($links as $label => $href)
      @if($href)<div class="wi-kv"><b>{{ explode(' ', $label)[0] }}</b><span><a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ $label }}</a></span></div>@endif
    @endforeach
    <div class="wi-kv"><b>Available days</b><span>{{ !empty($d['availableDays']) ? implode(', ', (array) $d['availableDays']) : 'None given' }}</span></div>
    <div class="wi-kv"><b>Availability notes</b><span>{{ ($d['availabilityNotes'] ?? '') !== '' ? $d['availabilityNotes'] : 'None given' }}</span></div>
    <div class="wi-kv"><b>Notice period</b><span>{{ ($d['noticePeriod'] ?? '') !== '' ? $d['noticePeriod'] : 'None given' }}</span></div>

    <div class="wi-sec">Prerequisite questions</div>
    <div class="wi-kv"><b>Into vinyl (1 to 5)</b><span>{{ !empty($d['vinylInterest']) ? $d['vinylInterest'] . '/5' : 'None given' }}</span></div>
    <div class="wi-kv"><b>Favorite genres</b><span>{{ ($d['favoriteGenres'] ?? '') !== '' ? $d['favoriteGenres'] : 'None given' }}</span></div>
    <div class="wi-kv"><b>Other record stores</b><span>{{ ($d['otherRecordStores'] ?? '') !== '' ? $d['otherRecordStores'] : 'None given' }}</span></div>
    @foreach($longs as $field => $label)
      @if(!empty($d[$field]))<div class="wi-block"><b>{{ $label }}</b><p>{{ $d[$field] }}</p></div>@endif
    @endforeach
    @if($vmLink)<div class="wi-kv"><b>Recent VM work</b><span><a href="{{ $vmLink }}" target="_blank" rel="noopener noreferrer">Open link</a></span></div>@endif
    <div class="wi-kv"><b>Authorized to work in US</b><span>{{ !empty($d['rightToWork']) ? 'Yes' : 'No' }}</span></div>
    @if(!empty($d['coverNote']))<div class="wi-block"><b>Why Nivessa</b><p>{{ $d['coverNote'] }}</p></div>@endif

    <div class="wi-dialog-actions">
      <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Close</button>
    </div>
  </div>
</dialog>
