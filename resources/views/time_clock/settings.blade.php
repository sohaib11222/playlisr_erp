@extends('layouts.app')
@section('title', 'Time clock settings')

@section('content')
<section class="content-header">
  <h1>Time clock settings <small>PINs, phones and stores</small></h1>
</section>
<section class="content">
  <p>
    Staff clock in on their phones at <a href="{{ url('/clock') }}" target="_blank">{{ url('/clock') }}</a>.
    <a href="{{ url('/reports/timecards') }}" class="btn btn-default btn-xs" style="margin-left:8px;">Timecards</a>
    <a href="{{ url('/time-clock/qr') }}" class="btn btn-default btn-xs" target="_blank">Print QR codes</a>
  </p>
  @if (!$ready)
    <div class="alert alert-warning">The time clock tables do not exist yet, so PINs cannot be set. Run the database migration (GitHub Actions, Run migrations), then reload this page. Store settings below can already be saved.</div>
  @endif

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Staff PINs</h3></div>
    <div class="box-body">
      <p class="text-muted">Staff clock in with their PIN only, like Clover: on the store computer they type it every time, and on a phone once, then the phone is remembered. Every PIN is different, so the PIN says who it is. Leave the box empty to make a random PIN. The PIN is shown once after saving, so tell the person in person. People at the top had Sling shifts in the last 30 days.</p>
      @if ($isOwner && $ready)
        <form method="POST" action="{{ url('/time-clock/settings/pins/bulk') }}" target="_blank" style="margin-bottom:12px;">
          {{ csrf_field() }}
          <button type="submit" class="btn btn-warning btn-sm">Give everyone without a PIN a new PIN</button>
          <span class="text-muted" style="font-size:12px;margin-left:6px;">Opens a page of slips to print and cut. The PINs are shown only that one time.</span>
        </form>
      @endif
      <div class="table-responsive">
      <table class="table table-condensed" style="max-width:900px;">
        <thead><tr><th>Name</th><th>Sling, last 30 days</th><th>PIN</th><th>Remembered phones</th><th>Set or reset PIN</th><th></th></tr></thead>
        <tbody>
        @php $__devCount = $devices->groupBy('user_id')->map->count(); @endphp
        @foreach ($people as $uid => $uname)
          <tr>
            <td>{{ $uname }}</td>
            <td>{{ isset($roster[$uid]) ? 'Yes' : 'No' }}</td>
            <td>
              @if (isset($pins[$uid]))
                Set
              @else
                <span class="text-muted">none</span>
              @endif
            </td>
            <td>{{ $__devCount[$uid] ?? 0 }}</td>
            <td>
              <form method="POST" action="{{ url('/time-clock/settings/pin') }}" class="form-inline">
                {{ csrf_field() }}
                <input type="hidden" name="user_id" value="{{ $uid }}">
                <input type="text" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" class="form-control input-sm" style="width:80px;" placeholder="random" autocomplete="off" @if (!$ready) disabled @endif>
                <button type="submit" class="btn btn-primary btn-xs" @if (!$ready) disabled @endif>{{ isset($pins[$uid]) ? 'Reset' : 'Set' }}</button>
              </form>
            </td>
            <td>
              @if (isset($pins[$uid]))
                <form method="POST" action="{{ url('/time-clock/settings/pin/clear') }}" style="display:inline;">
                  {{ csrf_field() }}<input type="hidden" name="user_id" value="{{ $uid }}">
                  <button type="submit" class="btn btn-default btn-xs">Remove PIN</button>
                </form>
              @endif
              @if (($__devCount[$uid] ?? 0) > 0)
                <form method="POST" action="{{ url('/time-clock/settings/revoke') }}" style="display:inline;">
                  {{ csrf_field() }}<input type="hidden" name="user_id" value="{{ $uid }}">
                  <button type="submit" class="btn btn-default btn-xs">Sign out all their phones</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Remembered phones</h3></div>
    <div class="box-body table-responsive">
      @if (count($devices))
      <table class="table table-condensed">
        <thead><tr><th>Person</th><th>Phone</th><th>Set up</th><th>Last used</th><th></th></tr></thead>
        <tbody>
        @foreach ($devices as $d)
          <tr>
            <td>{{ trim($d->first_name . ' ' . $d->last_name) }}</td>
            <td style="font-size:12px;max-width:380px;">{{ $d->user_agent }}</td>
            <td>{{ \Carbon::parse($d->created_at)->format('M j, g:i A') }}</td>
            <td>{{ $d->last_used_at ? \Carbon::parse($d->last_used_at)->format('M j, g:i A') : '' }}</td>
            <td>
              <form method="POST" action="{{ url('/time-clock/settings/revoke') }}">
                {{ csrf_field() }}<input type="hidden" name="id" value="{{ $d->id }}">
                <button type="submit" class="btn btn-default btn-xs">Sign out this phone</button>
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      @else
        <p class="text-muted">No phones set up yet.</p>
      @endif
    </div>
  </div>

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Store location and Wi-Fi</h3></div>
    <div class="box-body">
      <p class="text-muted">A punch counts as "at the store" when the device's location is within a store's radius (distance minus the reported accuracy), or when it comes from one of the store's IP addresses. Every time a punch is confirmed by location, its IP is learned for that store automatically (Auto); auto IPs not seen for 30 days drop off. IPs you type in (Manual) stay until you remove them. Hollywood and Pico start with coordinates looked up from their addresses.</p>
      <p>This computer or phone is on <strong>{{ $ip }}</strong>
        @if ($ipStore['verify'] === 'ip' && isset($stores[$ipStore['location_id']]))
          <span class="label label-success">counts as {{ $stores[$ipStore['location_id']]['name'] }}</span>
        @else
          <span class="label label-default">not a store network</span>
        @endif
        @if ($network !== $ip)<span class="text-muted" style="font-size:12px;"> (IPv6, so the whole network {{ $network }} is saved)</span> @endif
      </p>
      @foreach ($locations as $lid => $lname)
        <form method="POST" action="{{ url('/time-clock/settings/allow-ip') }}" style="display:inline-block;margin:0 6px 8px 0;">
          {{ csrf_field() }}<input type="hidden" name="location_id" value="{{ $lid }}">
          <button type="submit" class="btn btn-default btn-sm">Add this network to {{ $lname }}</button>
        </form>
      @endforeach

      <form method="POST" action="{{ url('/time-clock/settings/stores') }}" style="margin-top:10px;">
        {{ csrf_field() }}
        <div class="well well-sm" style="font-size:15px;">
          <label style="margin:0;"><input type="checkbox" name="require_at_store" value="1" @if ($requireAtStore) checked @endif> <strong>Require being at the store</strong></label>
          <div class="text-muted" style="font-size:13px;margin-top:4px;">On: clock-ins and clock-outs from anywhere else are refused (staff see "You need to be at the store"). Off: they go through and are flagged "not verified at store" on Timecards. End Shift and register close always clock people out. Admins can always add or fix punches on Timecards.</div>
          <label style="margin:10px 0 0;display:block;"><input type="checkbox" name="require_schedule" value="1" @if ($requireSchedule) checked @endif> <strong>Only allow clock-in near a scheduled shift</strong></label>
          <div style="margin-top:4px;">Window: <input type="number" name="schedule_window_min" value="{{ $scheduleWindow }}" min="1" max="240" class="form-control input-sm" style="display:inline-block;width:80px;"> minutes before or after the Sling shift start</div>
          <div class="text-muted" style="font-size:13px;margin-top:4px;">On: clock-in needs a Sling shift at that store starting within the window (back-to-back shifts count as one; someone already in for that shift can clock back in after a break). Otherwise staff see "You are not on the schedule right now". Clock-out is never limited by schedule. If Sling has no shifts at all for the day (sync broken), clock-ins go through and Timecards flags them "No schedule data". Add hours for unscheduled people on Timecards.</div>
        </div>
        <div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr><th>Location</th><th>Latitude</th><th>Longitude</th><th>Radius (m)</th><th>Manual IPs (one per line, ranges like 203.0.113.0/24 work)</th><th>Auto IPs (last seen)</th><th>Not a store</th></tr></thead>
          <tbody>
          @foreach ($locations as $lid => $lname)
            @php $__s = $stores[$lid] ?? null; $__sv = $saved[$lid] ?? ($saved[(string) $lid] ?? []); @endphp
            <tr>
              <td>{{ $lname }} @if ($__s && $__s['address'])<div class="text-muted" style="font-size:11px;">{{ $__s['address'] }}</div> @endif</td>
              <td><input type="text" name="stores[{{ $lid }}][lat]" value="{{ $__s['lat'] ?? '' }}" class="form-control input-sm" style="width:120px;"></td>
              <td><input type="text" name="stores[{{ $lid }}][lng]" value="{{ $__s['lng'] ?? '' }}" class="form-control input-sm" style="width:130px;"></td>
              <td><input type="number" name="stores[{{ $lid }}][radius_m]" value="{{ $__s['radius_m'] ?? 200 }}" min="50" max="2000" class="form-control input-sm" style="width:90px;"></td>
              <td><textarea name="stores[{{ $lid }}][ips]" rows="2" class="form-control input-sm" style="min-width:200px;">{{ implode("\n", $__s['ips'] ?? []) }}</textarea></td>
              <td style="font-size:12px;">
                @forelse (($__s['learned_ips'] ?? []) as $__rule => $__seen)
                  <div><label style="font-weight:400;margin:0;" title="Tick and save to forget this IP"><input type="checkbox" name="stores[{{ $lid }}][forget_learned][]" value="{{ $__rule }}"> {{ $__rule }}</label> <span class="text-muted">{{ \Carbon::parse($__seen)->format('M j') }}</span></div>
                @empty
                  <span class="text-muted">none yet</span>
                @endforelse
              </td>
              <td><input type="checkbox" name="stores[{{ $lid }}][disabled]" value="1" @if (!empty($__sv['disabled'])) checked @endif></td>
            </tr>
          @endforeach
          </tbody>
        </table>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Save store settings</button>
      </form>

      @if (count($suggest))
        <p style="margin-top:16px;margin-bottom:4px;"><strong>Likely store IPs</strong> <span class="text-muted">(add one only if you are sure it is the store)</span></p>
        <table class="table table-condensed" style="max-width:820px;">
          <thead><tr><th>IP</th><th>Seen</th><th>Add to</th></tr></thead>
          <tbody>
          @foreach ($suggest as $sg)
            <tr>
              <td>{{ $sg['ip'] }}</td>
              <td class="text-muted" style="font-size:12px;">{{ $sg['why'] }}</td>
              <td>
                @foreach ($stores as $sid => $st)
                  @if (!$sg['location_id'] || $sg['location_id'] === $sid)
                    <form method="POST" action="{{ url('/time-clock/settings/allow-ip') }}" style="display:inline;">
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
      @endif
    </div>
  </div>
</section>
@endsection
