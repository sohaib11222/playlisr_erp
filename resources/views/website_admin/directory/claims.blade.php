@extends('layouts.app')

@section('title', 'Store Claims')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .sc-tabs { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:16px; }
  .sc-tab { position:relative; padding:10px 16px; font-size:14px; font-weight:600; color:#6b7280; text-decoration:none; }
  .sc-tab:hover { color:#111827; text-decoration:none; }
  .sc-tab.active { color:#1e40af; }
  .sc-tab.active::after { content:""; position:absolute; left:0; right:0; bottom:-2px; height:3px; background:#93c5fd; border-radius:2px 2px 0 0; }
  .sc-detail td { background:#f9fafb; }
  .sc-detail-grid { display:flex; flex-wrap:wrap; gap:26px; padding:6px 2px; }
  .sc-detail-grid > div { min-width:240px; flex:1; }
  .sc-detail-grid h5 { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin:0 0 8px; }
  .sc-proof { max-width:100%; max-height:220px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; object-fit:contain; }
</style>

@php
  $la = fn($d, $f = 'M j, Y g:ia') => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d, $f);
  $methods = ['email_domain' => ['Email domain', 'blue'], 'instagram' => ['Instagram', 'purple'], 'manual_proof' => ['Manual proof', 'orange']];
  $stClass = fn($s) => in_array($s, ['pending', 'approved', 'rejected'], true) ? $s : 'other';
  $allCount = $counts['pending'] + $counts['approved'] + $counts['rejected'];
  $claimsPages = (int) ceil($claimsTotal / $limit);
  $attemptsPages = (int) ceil($attemptsTotal / $limit);
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Store Claims</h1>
      <p class="sub">Store owners claiming their listing in the nivessa.com directory. Approving marks the store as verified.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="sc-tabs">
    <a href="{{ route('wa.directory.claims') }}" class="sc-tab {{ $view === 'claims' ? 'active' : '' }}">Claims</a>
    <a href="{{ route('wa.directory.claims', ['view' => 'attempts']) }}" class="sc-tab {{ $view === 'attempts' ? 'active' : '' }}">All attempts @if($attemptsTotal > 0)({{ number_format($attemptsTotal) }})@endif</a>
  </div>

  @if($view === 'claims')
    <div class="wd-pills">
      <a href="{{ route('wa.directory.claims') }}" class="wd-pill {{ $status === '' ? 'active' : '' }}">All <span class="n">{{ $allCount }}</span></a>
      <a href="{{ route('wa.directory.claims', ['status' => 'pending']) }}" class="wd-pill {{ $status === 'pending' ? 'active' : '' }}">Pending <span class="n">{{ $counts['pending'] }}</span></a>
      <a href="{{ route('wa.directory.claims', ['status' => 'approved']) }}" class="wd-pill {{ $status === 'approved' ? 'active' : '' }}">Approved <span class="n">{{ $counts['approved'] }}</span></a>
      <a href="{{ route('wa.directory.claims', ['status' => 'rejected']) }}" class="wd-pill {{ $status === 'rejected' ? 'active' : '' }}">Rejected <span class="n">{{ $counts['rejected'] }}</span></a>
    </div>

    <div class="wd-card">
      <div class="wd-scroll">
        <table class="wd-tbl" style="min-width:980px;">
          <thead><tr>
            <th style="width:40px;">#</th><th>Store</th><th>Claimant</th><th>Role</th><th>Verification</th><th>Status</th><th>Submitted</th><th style="width:330px;">Actions</th>
          </tr></thead>
          <tbody>
          @if($claimsError)
            <tr><td colspan="8" class="wd-empty" style="color:#b91c1c;">{{ $claimsError }}</td></tr>
          @elseif(count($claims) === 0)
            <tr><td colspan="8" class="wd-empty">No store claims found.</td></tr>
          @endif
          @foreach($claims as $i => $c)
            @php
              $id = $c['_id'] ?? '';
              $store = is_array($c['store'] ?? null) ? $c['store'] : [];
              $cl = is_array($c['claimant'] ?? null) ? $c['claimant'] : [];
              $m = $methods[$c['verificationMethod'] ?? ''] ?? [($c['verificationMethod'] ?? 'unknown'), ''];
              $st = (string) ($c['status'] ?? '');
            @endphp
            <tr>
              <td>{{ ($page - 1) * $limit + $i + 1 }}</td>
              <td>
                <div style="display:flex; gap:8px; align-items:center;">
                  @if(!empty($store['image_url']))<img class="wd-thumb" src="{{ $store['image_url'] }}" alt="">@endif
                  <div>
                    <div style="font-weight:600;">{{ $store['store_name'] ?? 'Unknown store' }}</div>
                    <div class="wd-muted">{{ implode(', ', array_filter([$store['city'] ?? '', $store['state'] ?? ''])) }}</div>
                  </div>
                </div>
              </td>
              <td><div style="font-weight:600;">{{ $cl['name'] ?? '' }}</div><div class="wd-muted">{{ $cl['email'] ?? '' }}</div></td>
              <td style="text-transform:capitalize;">{{ $cl['role'] ?? '' }}</td>
              <td>
                <span class="wd-tag {{ $m[1] }}">{{ $m[0] }}</span>
                @if(!empty($c['autoApproved']))<div style="font-size:11px; font-weight:700; color:#166534;">Auto-approved</div>@endif
              </td>
              <td><span class="wd-st {{ $stClass($st) }}">{{ $st }}</span></td>
              <td class="wd-muted">{{ $la($c['createdAt'] ?? '', 'M j, Y') }}<br>{{ $la($c['createdAt'] ?? '', 'g:i a') }}</td>
              <td>
                <div class="wd-actions">
                  <button type="button" class="wd-btn" onclick="var r=document.getElementById('sc-d-{{ $id }}'); r.style.display = r.style.display === 'none' ? '' : 'none';">Details</button>
                  @if($st !== 'approved')
                    <form method="POST" action="{{ route('wa.directory.claimApprove', ['id' => $id]) }}">
                      {{ csrf_field() }}
                      <button type="submit" class="wd-btn wd-btn-ok">Approve</button>
                    </form>
                  @endif
                  <form method="POST" action="{{ route('wa.directory.claimDelete', ['id' => $id]) }}"
                        onsubmit="return confirm('Delete this claim? It is removed for good.');">
                    {{ csrf_field() }}
                    <button type="submit" class="wd-btn wd-btn-danger">Delete</button>
                  </form>
                </div>
                @if($st !== 'rejected')
                  <form method="POST" action="{{ route('wa.directory.claimReject', ['id' => $id]) }}" class="wd-actions" style="margin-top:6px;">
                    {{ csrf_field() }}
                    <input type="text" name="reason" class="wd-input" placeholder="Reason (optional)" style="width:170px;">
                    <button type="submit" class="wd-btn wd-btn-danger">Reject</button>
                  </form>
                @endif
              </td>
            </tr>
            <tr id="sc-d-{{ $id }}" class="sc-detail" style="display:none;">
              <td colspan="8">
                <div class="sc-detail-grid">
                  <div>
                    <h5>Store being claimed</h5>
                    <div style="font-weight:700;">{{ $store['store_name'] ?? 'Unknown store' }}</div>
                    <div class="wd-muted">{{ implode(', ', array_filter([$store['city'] ?? '', $store['state'] ?? '', $store['country'] ?? ''])) }}</div>
                    @if(!empty($store['website_url']))<div><a href="{{ $store['website_url'] }}" target="_blank" rel="noopener noreferrer">{{ $store['website_url'] }}</a></div>@endif
                    <h5 style="margin-top:14px;">Claimant</h5>
                    <dl class="wd-kv">
                      <dt>Name</dt><dd>{{ $cl['name'] ?? '' }} @if(!empty($cl['role']))<span class="wd-tag">{{ $cl['role'] }}</span>@endif</dd>
                      <dt>Email</dt><dd>{{ $cl['email'] ?? '' }} @if(!empty($c['emailDomainMatch']))<span class="wd-tag green">Domain match</span>@endif</dd>
                      @if(!empty($cl['phone']))<dt>Phone</dt><dd>{{ $cl['phone'] }}</dd>@endif
                      @if(!empty($cl['instagram']))<dt>Instagram</dt><dd>{{ $cl['instagram'] }}</dd>@endif
                      @if(!empty($c['instagramHandle']))<dt>Store IG</dt><dd><strong>{{ $c['instagramHandle'] }}</strong></dd>@endif
                      @if(!empty($c['ip']))<dt>IP</dt><dd class="wd-mono">{{ $c['ip'] }}</dd>@endif
                      <dt>Submitted</dt><dd>{{ $la($c['createdAt'] ?? '') }}</dd>
                    </dl>
                  </div>
                  @if(!empty($c['proofDocument']))
                    @php $proof = (preg_match('#^https?://#i', $c['proofDocument']) ? '' : $siteRoot) . $c['proofDocument']; @endphp
                    <div>
                      <h5>Uploaded proof</h5>
                      <a href="{{ $proof }}" target="_blank" rel="noopener noreferrer">
                        @if(preg_match('/\.pdf($|\?)/i', $c['proofDocument']))
                          Open the PDF
                        @else
                          <img class="sc-proof" src="{{ $proof }}" alt="Proof document">
                        @endif
                      </a>
                    </div>
                  @endif
                  <div>
                    <h5>Moderation timeline</h5>
                    @if(empty($c['statusHistory']))
                      <div class="wd-muted">No history recorded.</div>
                    @else
                      <ul class="wd-timeline">
                        @foreach((array) $c['statusHistory'] as $h)
                          <li>
                            <span class="wd-st {{ $stClass($h['status'] ?? '') }}">{{ $h['status'] ?? '' }}</span>
                            <div class="t">{{ $la($h['changedAt'] ?? '') }} @if(!empty($h['changedBy'])), {{ $h['changedBy'] }} @endif</div>
                            @if(!empty($h['reason']))<div>{{ $h['reason'] }}</div>@endif
                          </li>
                        @endforeach
                      </ul>
                    @endif
                  </div>
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @if($claimsPages > 1)
        <div class="wd-pager">
          @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.claims', array_filter(['status' => $status, 'page' => $page - 1])) }}">Previous</a>@endif
          <span>Page {{ $page }} of {{ $claimsPages }}</span>
          @if($page < $claimsPages)<a class="wd-btn" href="{{ route('wa.directory.claims', array_filter(['status' => $status, 'page' => $page + 1])) }}">Next</a>@endif
        </div>
      @endif
    </div>
  @else
    <p class="wd-muted" style="margin:0 0 12px;">Every submit attempt, including ones that never became a claim (validation errors, stores already claimed, sign-in failures). Use this to see who tried even when nothing shows under Claims.</p>
    <div class="wd-card">
      <div class="wd-scroll">
        <table class="wd-tbl" style="min-width:820px;">
          <thead><tr><th style="width:40px;">#</th><th>Store</th><th>Claimant</th><th>Verification</th><th>Outcome</th><th>Attempted</th></tr></thead>
          <tbody>
          @if($attemptsError)
            <tr><td colspan="6" class="wd-empty" style="color:#b91c1c;">{{ $attemptsError }}</td></tr>
          @elseif(count($attempts) === 0)
            <tr><td colspan="6" class="wd-empty">No submit attempts recorded yet.</td></tr>
          @endif
          @foreach($attempts as $i => $a)
            @php
              $store = is_array($a['store'] ?? null) ? $a['store'] : [];
              $m = !empty($a['verificationMethod']) ? ($methods[$a['verificationMethod']] ?? [$a['verificationMethod'], '']) : null;
            @endphp
            <tr>
              <td>{{ ($page - 1) * $limit + $i + 1 }}</td>
              <td><div style="font-weight:600;">{{ $store['store_name'] ?? 'Unknown store' }}</div><div class="wd-muted">{{ implode(', ', array_filter([$store['city'] ?? '', $store['state'] ?? ''])) }}</div></td>
              <td><div style="font-weight:600;">{{ $a['name'] ?? '' ?: '-' }}</div><div class="wd-muted">{{ $a['email'] ?? '' ?: '-' }}</div></td>
              <td>@if($m)<span class="wd-tag {{ $m[1] }}">{{ $m[0] }}</span>@else - @endif</td>
              <td>
                <span class="wd-st {{ !empty($a['success']) ? 'success' : 'failed' }}">{{ !empty($a['success']) ? 'Success' : 'Failed' }}</span>
                @if(!empty($a['reason']))<div class="wd-muted" style="margin-top:4px;">{{ $a['reason'] }}</div>@endif
              </td>
              <td class="wd-muted">{{ $la($a['createdAt'] ?? '', 'M j, Y') }}<br>{{ $la($a['createdAt'] ?? '', 'g:i a') }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @if($attemptsPages > 1)
        <div class="wd-pager">
          @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.claims', ['view' => 'attempts', 'page' => $page - 1]) }}">Previous</a>@endif
          <span>Page {{ $page }} of {{ $attemptsPages }}</span>
          @if($page < $attemptsPages)<a class="wd-btn" href="{{ route('wa.directory.claims', ['view' => 'attempts', 'page' => $page + 1]) }}">Next</a>@endif
        </div>
      @endif
    </div>
  @endif
</div>
@endsection
