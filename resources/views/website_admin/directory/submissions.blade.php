@extends('layouts.app')

@section('title', 'Store Submissions')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .ss-card { display:flex; gap:18px; padding:16px; }
  .ss-img { width:150px; height:150px; border-radius:10px; object-fit:cover; background:#f3f4f6; border:1px solid #e5e7eb; flex-shrink:0; }
  .ss-noimg { display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:12px; }
  .ss-body { flex:1; min-width:0; }
  .ss-name { font-size:19px; font-weight:700; margin:0 0 2px; }
  .ss-sec { border-top:1px solid #f0f0f0; padding-top:10px; margin-top:10px; }
  .ss-sec h4 { font-size:13px; font-weight:700; margin:0 0 6px; color:#374151; }
  .ss-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:4px 18px; font-size:13px; color:#374151; }
  @media (max-width: 640px) { .ss-card { flex-direction:column; } .ss-img { width:100%; height:180px; } .ss-grid2 { grid-template-columns:1fr; } }
</style>

@php
  $la = fn($d) => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d);
  $tags = function ($list) {
      $out = [];
      foreach ((array) $list as $c) {
          $j = is_string($c) ? json_decode($c, true) : null;
          if (is_array($j)) { foreach ($j as $x) { $out[] = (string) $x; } } elseif ($c !== null && $c !== '') { $out[] = (string) $c; }
      }
      return $out;
  };
  $stClass = fn($s) => in_array($s, ['pending', 'approved', 'rejected'], true) ? $s : 'other';
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Store Submissions</h1>
      <p class="sub">Record stores people submitted to the nivessa.com directory. Approve to list them, or reject with a reason.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wd-pills">
    @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label)
      <a href="{{ route('wa.directory.submissions', ['status' => $k]) }}" class="wd-pill {{ $status === $k ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
  </div>

  @if($error)
    <div class="wd-card"><div class="wd-empty" style="color:#b91c1c;">{{ $error }}</div></div>
  @elseif(count($stores) === 0)
    <div class="wd-card"><div class="wd-empty">{{ $status === 'pending' ? 'No pending store submissions right now.' : 'No ' . ($status !== 'all' ? $status . ' ' : '') . 'store submissions.' }}</div></div>
  @endif

  @foreach($stores as $s)
    @php
      $id = $s['_id'] ?? '';
      $st = (string) ($s['status'] ?? '');
      $sb = is_array($s['submitted_by'] ?? null) ? $s['submitted_by'] : [];
      $sbName = $sb['name'] ?? ($s['guest_name'] ?? 'unknown');
      $sbEmail = $sb['email'] ?? ($s['guest_email'] ?? '');
    @endphp
    <div class="wd-card">
      <div class="ss-card">
        @if(!empty($s['image_url']))
          <a href="{{ $s['image_url'] }}" target="_blank" rel="noopener noreferrer"><img class="ss-img" src="{{ $s['image_url'] }}" alt=""></a>
        @else
          <div class="ss-img ss-noimg">No image</div>
        @endif
        <div class="ss-body">
          <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
              <h3 class="ss-name">{{ $s['store_name'] ?? 'Unnamed store' }}</h3>
              <div class="wd-muted" style="margin-bottom:6px;">{{ implode(', ', array_filter([$s['city'] ?? '', $s['state'] ?? '', $s['country'] ?? ''])) }}</div>
              <span class="wd-st {{ $stClass($st) }}">{{ $st ?: 'unknown' }}</span>
            </div>
          </div>

          @if(count($tags($s['categories'] ?? [])))
            <div style="margin-top:10px;">@foreach($tags($s['categories'] ?? []) as $c)<span class="wd-tag">{{ $c }}</span>@endforeach</div>
          @endif

          @if(!empty($s['description']))
            <p style="margin:8px 0 0; font-size:13.5px; color:#374151; white-space:pre-wrap;">{{ $s['description'] }}</p>
          @endif

          <div class="ss-grid2" style="margin-top:10px;">
            @if(!empty($s['address']))<div><span class="wd-muted">Address:</span> {{ $s['address'] }}@if(!empty($s['postal_code'])), {{ $s['postal_code'] }}@endif</div>@endif
            @if(!empty($s['phone']))<div><span class="wd-muted">Phone:</span> {{ $s['phone'] }}</div>@endif
            @if(!empty($s['email']))<div><span class="wd-muted">Email:</span> <a href="mailto:{{ $s['email'] }}">{{ $s['email'] }}</a></div>@endif
            @if(!empty($s['website_url']))<div><span class="wd-muted">Website:</span> <a href="{{ $s['website_url'] }}" target="_blank" rel="noopener noreferrer">{{ $s['website_url'] }}</a></div>@endif
            @if(!empty($s['opening_hours']))<div><span class="wd-muted">Hours:</span> {{ $s['opening_hours'] }}</div>@endif
            @if(!empty($s['instagram_url']))<div><a href="{{ $s['instagram_url'] }}" target="_blank" rel="noopener noreferrer">Instagram</a></div>@endif
            @if(!empty($s['facebook_url']))<div><a href="{{ $s['facebook_url'] }}" target="_blank" rel="noopener noreferrer">Facebook</a></div>@endif
            @if(!empty($s['twitter_url']))<div><a href="{{ $s['twitter_url'] }}" target="_blank" rel="noopener noreferrer">Twitter</a></div>@endif
          </div>

          @if(!empty($s['formats_buy']) || !empty($s['conditions_buy']) || !empty($s['genres_buy']) || !empty($s['special_items']))
            <div class="ss-sec">
              <h4>Buying profile</h4>
              @if(!empty($s['formats_buy']))<div class="wd-muted">Formats they buy</div><div>@foreach((array) $s['formats_buy'] as $x)<span class="wd-tag green">{{ $x }}</span>@endforeach</div>@endif
              @if(!empty($s['conditions_buy']))<div class="wd-muted">Conditions accepted</div><div>@foreach((array) $s['conditions_buy'] as $x)<span class="wd-tag yellow">{{ $x }}</span>@endforeach</div>@endif
              @if(!empty($s['genres_buy']))<div class="wd-muted">Genres they buy</div><div>@foreach((array) $s['genres_buy'] as $x)<span class="wd-tag purple">{{ $x }}</span>@endforeach @if(!empty($s['other_genres']))<span class="wd-tag purple">{{ $s['other_genres'] }}</span>@endif</div>@endif
              @if(!empty($s['special_items']))<div class="wd-muted">Special items</div><div>@foreach((array) $s['special_items'] as $x)<span class="wd-tag orange">{{ $x }}</span>@endforeach</div>@endif
            </div>
          @endif

          @if(!empty($s['location_count']) || !empty($s['buyer_name']) || !empty($s['buyer_role']))
            <div class="ss-sec">
              <h4>Store information</h4>
              <div class="ss-grid2">
                @if(!empty($s['location_count']))
                  <div><span class="wd-muted">Locations:</span> {{ $s['location_count'] }}
                    @if(!empty($s['other_locations']))<div class="wd-muted">Also in: {{ $s['other_locations'] }}</div>@endif
                  </div>
                @endif
                @if(!empty($s['buyer_name']) || !empty($s['buyer_role']))
                  <div><span class="wd-muted">Primary buyer:</span> {{ $s['buyer_name'] ?? '' }}
                    @if(!empty($s['buyer_role']))<span class="wd-muted">({{ $s['buyer_role'] }})</span>@endif
                    @if(!empty($s['ok_to_contact_buyer']))<div style="color:#166534; font-size:12px;">OK to contact directly.</div>@endif
                  </div>
                @endif
              </div>
            </div>
          @endif

          @if(!empty($s['preferred_contact']) || !empty($s['response_time']))
            <div class="ss-sec">
              <h4>Contact preferences for sellers</h4>
              @if(!empty($s['preferred_contact']))<div>@foreach((array) $s['preferred_contact'] as $x)<span class="wd-tag blue">{{ $x }}</span>@endforeach</div>@endif
              @if(!empty($s['response_time']))<div style="font-size:13px;"><span class="wd-muted">Response time:</span> {{ $s['response_time'] }}</div>@endif
            </div>
          @endif

          <div class="ss-sec wd-muted">
            <div>Submitted by {{ $sbName }} @if($sbEmail !== '') ({{ $sbEmail }}) @endif on {{ $la($s['submitted_at'] ?? ($s['createdAt'] ?? '')) }}</div>
            @if(!empty($s['reviewed_at']))<div>Reviewed {{ $la($s['reviewed_at']) }}</div>@endif
            @if(!empty($s['rejection_reason']))<div style="color:#b91c1c;">Rejection reason: {{ $s['rejection_reason'] }}</div>@endif
          </div>

          <div class="wd-actions" style="margin-top:12px;">
            @if($st === 'pending')
              <form method="POST" action="{{ route('wa.directory.submissionApprove', ['id' => $id]) }}" onsubmit="return confirm('Approve this store?');">
                {{ csrf_field() }}
                <button type="submit" class="wd-btn wd-btn-ok">Approve</button>
              </form>
              <form method="POST" action="{{ route('wa.directory.submissionReject', ['id' => $id]) }}">
                {{ csrf_field() }}
                <input type="text" name="reason" class="wd-input" placeholder="Rejection reason" required style="width:220px;">
                <button type="submit" class="wd-btn wd-btn-danger">Reject</button>
              </form>
            @endif
            <form method="POST" action="{{ route('wa.directory.submissionDelete', ['id' => $id]) }}"
                  onsubmit="return confirm('Delete this store submission? This cannot be undone.');">
              {{ csrf_field() }}
              <button type="submit" class="wd-btn">Delete</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  @endforeach
</div>
@endsection
