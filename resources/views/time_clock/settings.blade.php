@extends('layouts.app')
@section('title', 'Time clock settings')

@section('content')
@include('time_clock.partials.admin_style')
<style>
  .tcx .tcx-actions { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
  .tcx .tcx-actions form { display:inline-block; margin:0; }
  .tcx .tcx-row-forms form { display:inline-block; margin:0 4px 2px 0; }
  .tcx .tcx-pin-form { display:flex; gap:4px; align-items:center; }
  .tcx .tcx-pin-form .form-control { width:80px; }
  .tcx .tcx-slack-form .form-control { width:120px; }
  .tcx .tcx-switch { padding:8px 0; border-bottom:1px solid var(--line); }
  .tcx .tcx-switch:last-child { border-bottom:0; }
  .tcx .tcx-switch .tcx-hint { margin:2px 0 0 20px; }
  .tcx .tcx-store-table .form-control { min-width:90px; }
  .tcx .tcx-store-table textarea.form-control { min-width:160px; font-size:12px; }
  .tcx details.tcx-more > summary { cursor:pointer; font-size:12px; font-weight:700; color:var(--ink2); margin:8px 0 4px; }
</style>
@php $__srcLabel = ['pos' => 'POS', 'gps' => 'Clock location', 'manual' => 'Manual', 'setup' => 'Setup link']; @endphp
<div class="tcx">
<section class="content-header">
  <h1>Time clock settings <small>PINs, phones and stores</small></h1>
</section>
<section class="content">
  <div class="box box-solid"><div class="box-body tcx-bar">
    <span>Staff clock in at <a href="{{ url('/clock') }}" target="_blank">{{ url('/clock') }}</a></span>
    <span class="tcx-right">
      <a href="{{ url('/reports/timecards') }}" class="btn btn-default btn-sm">Timecards</a>
      <a href="{{ url('/time-clock/qr') }}" class="btn btn-default btn-sm" target="_blank">QR codes</a>
    </span>
  </div></div>

  @if (!$ready)
    <div class="alert alert-warning">The time clock tables are not set up yet. Run the migration, then reload.</div>
  @endif
  @if ($setupLink)
    <div class="alert alert-success">
      One-time setup link for <strong>{{ $setupLink['store'] }}</strong>, works once for 24 hours. Open it on the store computer and tap the button.
      <input type="text" readonly value="{{ $setupLink['url'] }}" class="form-control" style="margin-top:6px;" onclick="this.select();">
    </div>
  @endif

  {{-- Staff PINs --}}
  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Staff PINs</h3></div>
    <div class="box-body">
      <p class="tcx-hint">Each PIN is unique and says who it is. Leave the box empty for a random PIN.</p>
      @if ($isOwner && $ready)
        <div class="tcx-actions">
          <form method="POST" action="{{ url('/time-clock/settings/pins/slack') }}" onsubmit="return confirm('Give everyone without a PIN a new PIN and DM it to them on Slack?');">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-success btn-sm">Give everyone a PIN and DM it on Slack</button>
          </form>
          <form method="POST" action="{{ url('/time-clock/settings/pins/bulk') }}" target="_blank">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default btn-sm">Print PIN slips for everyone without a PIN</button>
          </form>
        </div>
      @endif
      <div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr><th>Name</th><th>Sling</th><th>PIN</th><th class="text-right">Phones</th><th>Set or reset PIN</th><th>Slack ID</th><th></th></tr></thead>
        <tbody>
        @php $__devCount = $devices->groupBy('user_id')->map->count(); @endphp
        @forelse ($people as $uid => $uname)
          <tr>
            <td class="tcx-nowrap">{{ $uname }}</td>
            <td>
              @if (isset($roster[$uid]))
                <span class="tcx-badge green">Yes</span>
              @else
                <span class="tcx-small">No</span>
              @endif
            </td>
            <td>
              @if (isset($pins[$uid]))
                <span class="tcx-badge green">Set</span>
              @else
                <span class="tcx-badge red">None</span>
              @endif
            </td>
            <td class="text-right">{{ $__devCount[$uid] ?? 0 }}</td>
            <td>
              <form method="POST" action="{{ url('/time-clock/settings/pin') }}" class="tcx-pin-form">
                {{ csrf_field() }}
                <input type="hidden" name="user_id" value="{{ $uid }}">
                <input type="text" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" class="form-control" placeholder="random" autocomplete="off" aria-label="New PIN" @if (!$ready) disabled @endif>
                <button type="submit" class="btn btn-primary btn-xs" @if (!$ready) disabled @endif>{{ isset($pins[$uid]) ? 'Reset' : 'Set' }}</button>
              </form>
            </td>
            <td>
              @if ($isOwner)
                <form method="POST" action="{{ url('/time-clock/settings/slack-id') }}" class="tcx-pin-form tcx-slack-form">
                  {{ csrf_field() }}<input type="hidden" name="user_id" value="{{ $uid }}">
                  <input type="text" name="slack_id" value="{{ $slackIds[$uid] ?? '' }}" maxlength="22" class="form-control" placeholder="U0..." autocomplete="off" aria-label="Slack ID">
                  <button type="submit" class="btn btn-default btn-xs">Save</button>
                </form>
              @else
                <span class="tcx-small">{{ $slackIds[$uid] ?? '' }}</span>
              @endif
            </td>
            <td class="tcx-row-forms tcx-nowrap">
              @if ($isOwner && $ready && isset($pins[$uid]) && !empty($slackIds[$uid]))
                <form method="POST" action="{{ url('/time-clock/settings/pins/slack-resend') }}" onsubmit="return confirm('Reset this person\'s PIN and DM the new one on Slack?');">
                  {{ csrf_field() }}<input type="hidden" name="user_id" value="{{ $uid }}">
                  <button type="submit" class="btn btn-default btn-xs">Re-send new PIN</button>
                </form>
              @endif
              @if (isset($pins[$uid]))
                <form method="POST" action="{{ url('/time-clock/settings/pin/clear') }}">
                  {{ csrf_field() }}<input type="hidden" name="user_id" value="{{ $uid }}">
                  <button type="submit" class="btn btn-default btn-xs">Remove PIN</button>
                </form>
              @endif
              @if (($__devCount[$uid] ?? 0) > 0)
                <form method="POST" action="{{ url('/time-clock/settings/revoke') }}">
                  {{ csrf_field() }}<input type="hidden" name="user_id" value="{{ $uid }}">
                  <button type="submit" class="btn btn-default btn-xs">Sign out phones</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="tcx-empty">No staff yet.</td></tr>
        @endforelse
        </tbody>
      </table>
      </div>
    </div>
  </div>

  {{-- Store networks --}}
  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Store networks</h3></div>
    <div class="box-body">
      <p class="tcx-hint">Punches from a trusted store IP count as at the store. IPs are learned from POS sales (2 staff on 2 days).</p>
      <p style="margin-bottom:10px;">This device is on <strong>{{ $ip }}</strong>
        @if ($ipStore['verify'] === 'ip' && isset($stores[$ipStore['location_id']]))
          <span class="tcx-badge green">{{ $stores[$ipStore['location_id']]['name'] }}</span>
        @else
          <span class="tcx-badge">Not a store network</span>
        @endif
        @if ($network !== $ip)<span class="tcx-small"> (saved as {{ $network }})</span> @endif
      </p>
      @if ($isOwner)
        <div class="tcx-actions">
          @foreach ($locations as $lid => $lname)
            <form method="POST" action="{{ url('/time-clock/settings/setup-link') }}">
              {{ csrf_field() }}<input type="hidden" name="location_id" value="{{ $lid }}">
              <button type="submit" class="btn btn-default btn-sm">Setup link for {{ \App\Services\TimeClockService::shortStoreName($lname) }}</button>
            </form>
          @endforeach
          @foreach ($locations as $lid => $lname)
            <form method="POST" action="{{ url('/time-clock/settings/allow-ip') }}">
              {{ csrf_field() }}<input type="hidden" name="location_id" value="{{ $lid }}">
              <button type="submit" class="btn btn-default btn-sm">Add this device's network to {{ \App\Services\TimeClockService::shortStoreName($lname) }}</button>
            </form>
          @endforeach
          <form method="POST" action="{{ url('/time-clock/settings/pos-backfill') }}">
            {{ csrf_field() }}
            <button type="submit" class="btn btn-default btn-sm">Scan POS history</button>
          </form>
        </div>
      @endif
      <div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr><th>Store</th><th>Trusted IP</th><th>Came from</th><th>Seen</th></tr></thead>
        <tbody>
        @php $__anyTrusted = false; @endphp
        @foreach ($stores as $sid => $st)
          @foreach ($st['ips'] as $__ip)
            @php $__anyTrusted = true; $__m = $st['manual_src'][$__ip] ?? null; @endphp
            <tr>
              <td>{{ $st['name'] }}</td>
              <td>{{ $__ip }}</td>
              <td><span class="tcx-badge">{{ $__srcLabel[is_array($__m) ? ($__m['src'] ?? 'manual') : 'manual'] ?? 'Manual' }}</span></td>
              <td>{{ is_array($__m) && !empty($__m['at']) ? \Carbon::parse($__m['at'])->format('M j') : '' }}</td>
            </tr>
          @endforeach
          @foreach ($st['learned_ips'] as $__ip => $__seen)
            @php $__anyTrusted = true; @endphp
            <tr>
              <td>{{ $st['name'] }}</td>
              <td>{{ $__ip }}</td>
              <td><span class="tcx-badge green">{{ $__srcLabel[$st['auto_src'][$__ip] ?? 'gps'] ?? 'Auto' }}</span> <span class="tcx-small">auto</span></td>
              <td>{{ \Carbon::parse($__seen)->format('M j') }}</td>
            </tr>
          @endforeach
        @endforeach
        @if (!$__anyTrusted)
          <tr><td colspan="4" class="tcx-empty">No trusted store IPs yet.</td></tr>
        @endif
        </tbody>
      </table>
      </div>
      @if (count($posSeen))
        <details class="tcx-more"><summary>Seen from the POS, not trusted yet</summary>
          <div class="table-responsive">
          <table class="table table-condensed">
            <thead><tr><th>Store</th><th>IP</th><th class="text-right">Staff</th><th class="text-right">Days</th><th>Last seen</th></tr></thead>
            <tbody>
            @foreach ($posSeen as $__lid => $__nets)
              @foreach ($__nets as $__net => $__r)
                @if (!isset($stores[$__lid]) || (!in_array($__net, $stores[$__lid]['ips'], true) && !isset($stores[$__lid]['learned_ips'][$__net])))
                  <tr><td>{{ $locations[$__lid] ?? ('#' . $__lid) }}</td><td>{{ $__net }}</td><td class="text-right">{{ $__r->people }}</td><td class="text-right">{{ $__r->days }}</td><td>{{ \Carbon::parse($__r->last_day)->format('M j') }}</td></tr>
                @endif
              @endforeach
            @endforeach
            </tbody>
          </table>
          </div>
        </details>
      @endif
      @if ($isOwner && count($suggest))
        <details class="tcx-more"><summary>Likely store IPs from logins</summary>
          <div class="table-responsive">
          <table class="table table-condensed">
            <thead><tr><th>IP</th><th>Seen</th><th>Add to</th></tr></thead>
            <tbody>
            @foreach ($suggest as $sg)
              <tr>
                <td>{{ $sg['ip'] }}</td>
                <td class="tcx-small">{{ $sg['why'] }}</td>
                <td class="tcx-row-forms">
                  @foreach ($stores as $sid => $st)
                    @if (!$sg['location_id'] || $sg['location_id'] === $sid)
                      <form method="POST" action="{{ url('/time-clock/settings/allow-ip') }}">
                        {{ csrf_field() }}<input type="hidden" name="location_id" value="{{ $sid }}"><input type="hidden" name="ip" value="{{ $sg['ip'] }}">
                        <button type="submit" class="btn btn-default btn-xs">{{ $st['name'] }}</button>
                      </form>
                    @endif
                  @endforeach
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
          </div>
        </details>
      @endif
    </div>
  </div>

  {{-- Rules and store locations --}}
  <form method="POST" action="{{ url('/time-clock/settings/stores') }}">
    {{ csrf_field() }}
    <fieldset @if (!$isOwner) disabled @endif>
    <div class="box box-solid">
      <div class="box-header"><h3 class="box-title">Rules and store locations</h3></div>
      <div class="box-body">
        @if (!$isOwner)
          <p class="tcx-hint">Only the owners can change these.</p>
        @endif
        <div class="tcx-switch">
          <label class="tcx-inline"><input type="checkbox" name="require_at_store" value="1" @if ($requireAtStore) checked @endif> Require being at the store</label>
          <p class="tcx-hint">Off: punches from anywhere go through, flagged on Timecards.</p>
        </div>
        <div class="tcx-switch">
          <label class="tcx-inline"><input type="checkbox" name="require_schedule" value="1" @if ($requireSchedule) checked @endif> Only allow clock-in during a Sling shift, opening</label>
          <input type="number" name="schedule_window_min" value="{{ $scheduleWindow }}" min="1" max="240" class="form-control" style="display:inline-block;width:70px;" aria-label="Minutes before the shift">
          <span>minutes before it starts</span>
          <p class="tcx-hint">Late and stayed past shift are flagged on Timecards; hours are paid as recorded.</p>
        </div>
        <p class="tcx-hint" style="margin-top:10px;">A location passes when distance plus accuracy is within the radius plus 50 m.</p>
        <div class="table-responsive">
        <table class="table table-condensed tcx-store-table">
          <thead><tr><th>Location</th><th>Latitude</th><th>Longitude</th><th>Radius (m)</th><th>Manual IPs</th><th>Auto IPs (tick to forget)</th><th>Not a store</th></tr></thead>
          <tbody>
          @foreach ($locations as $lid => $lname)
            @php $__s = $stores[$lid] ?? null; $__sv = $saved[$lid] ?? ($saved[(string) $lid] ?? []); @endphp
            <tr>
              <td class="tcx-nowrap">{{ $lname }} @if ($__s && $__s['address'])<div class="tcx-small">{{ $__s['address'] }}</div> @endif</td>
              <td><input type="text" name="stores[{{ $lid }}][lat]" value="{{ $__s['lat'] ?? '' }}" class="form-control" aria-label="Latitude"></td>
              <td><input type="text" name="stores[{{ $lid }}][lng]" value="{{ $__s['lng'] ?? '' }}" class="form-control" aria-label="Longitude"></td>
              <td><input type="number" name="stores[{{ $lid }}][radius_m]" value="{{ $__s['radius_m'] ?? 100 }}" min="50" max="2000" class="form-control" style="width:80px;" aria-label="Radius"></td>
              <td><textarea name="stores[{{ $lid }}][ips]" rows="2" class="form-control" placeholder="One per line" aria-label="Manual IPs">{{ implode("\n", $__s['ips'] ?? []) }}</textarea></td>
              <td>
                @forelse (($__s['learned_ips'] ?? []) as $__rule => $__seen)
                  <div><label class="tcx-inline" style="font-weight:400 !important;"><input type="checkbox" name="stores[{{ $lid }}][forget_learned][]" value="{{ $__rule }}"> {{ $__rule }}</label> <span class="tcx-small">{{ \Carbon::parse($__seen)->format('M j') }}</span></div>
                @empty
                  <span class="tcx-small">None yet</span>
                @endforelse
              </td>
              <td class="text-center"><input type="checkbox" name="stores[{{ $lid }}][disabled]" value="1" aria-label="Not a store" @if (!empty($__sv['disabled'])) checked @endif></td>
            </tr>
          @endforeach
          </tbody>
        </table>
        </div>
        @if ($isOwner)<button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px;">Save rules and locations</button> @endif
      </div>
    </div>
    </fieldset>
  </form>

  {{-- Remembered phones --}}
  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Remembered phones</h3></div>
    <div class="box-body table-responsive">
      <table class="table table-condensed">
        <thead><tr><th>Person</th><th>Phone</th><th>Set up</th><th>Last used</th><th></th></tr></thead>
        <tbody>
        @forelse ($devices as $d)
          <tr>
            <td class="tcx-nowrap">{{ trim($d->first_name . ' ' . $d->last_name) }}</td>
            <td class="tcx-small" style="max-width:360px;">{{ $d->user_agent }}</td>
            <td class="tcx-nowrap">{{ \Carbon::parse($d->created_at)->format('M j, g:i A') }}</td>
            <td class="tcx-nowrap">{{ $d->last_used_at ? \Carbon::parse($d->last_used_at)->format('M j, g:i A') : '' }}</td>
            <td>
              <form method="POST" action="{{ url('/time-clock/settings/revoke') }}" style="margin:0;">
                {{ csrf_field() }}<input type="hidden" name="id" value="{{ $d->id }}">
                <button type="submit" class="btn btn-default btn-xs">Sign out</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="tcx-empty">No phones set up yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</section>
</div>
@endsection
