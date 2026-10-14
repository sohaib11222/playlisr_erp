@extends('layouts.app')
@section('title', 'Timecards')

@section('content')
<style>
  .tc-wrap .tc-flag { display:inline-block; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:600; margin:1px 2px 1px 0; background:#fde68a; color:#5c3c10; }
  .tc-wrap .tc-flag.bad { background:#fee2e2; color:#991b1b; }
  .tc-wrap .tc-flag.muted { background:#e5e7eb; color:#374151; }
  .tc-wrap tr.tc-voided td { color:#9ca3af; text-decoration:line-through; }
  .tc-wrap tr.tc-voided td.tc-actions { text-decoration:none; }
  .tc-wrap details > summary { cursor:pointer; color:#2563eb; font-size:12px; }
  .tc-wrap .tc-edit { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin-top:6px; min-width:260px; }
  .tc-wrap .tc-edit .form-control { margin-bottom:6px; }
  .tc-wrap .tc-hist { font-size:12px; color:#4b5563; margin-top:4px; }
</style>
<section class="content-header">
  <h1>Timecards <small>clock-ins from the phone time clock</small></h1>
</section>
<section class="content tc-wrap">
  @if(!$ready)
    <div class="alert alert-warning">The time clock tables do not exist yet. Run the database migration (GitHub Actions, run-migrations) and this page will fill in as staff clock in.</div>
  @endif

  <div class="box box-solid"><div class="box-body">
    <form method="GET" action="{{ url('/reports/timecards') }}" class="form-inline">
      <input type="date" name="start" value="{{ $f['start'] }}" class="form-control input-sm">
      <span>to</span>
      <input type="date" name="end" value="{{ $f['end'] }}" class="form-control input-sm">
      <select name="user_id" class="form-control input-sm">
        <option value="">Everyone</option>
        @foreach($users as $id => $name)
          <option value="{{ $id }}" @if($f['user_id'] == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
      <select name="location_id" class="form-control input-sm">
        <option value="">All stores</option>
        @foreach($locations as $id => $name)
          <option value="{{ $id }}" @if($f['location_id'] == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
      <select name="flag" class="form-control input-sm">
        <option value="">All punches</option>
        <option value="missing" @if($f['flag'] === 'missing') selected @endif>Missing clock-out</option>
        <option value="long" @if($f['flag'] === 'long') selected @endif>Over 10 hours (forgot to clock out)</option>
        <option value="unverified" @if($f['flag'] === 'unverified') selected @endif>Not verified at store</option>
        <option value="edited" @if($f['flag'] === 'edited') selected @endif>Edited by an admin</option>
        <option value="voided" @if($f['flag'] === 'voided') selected @endif>Removed</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Show</button>
      <a class="btn btn-success btn-sm" href="{{ url('/reports/timecards/export.csv') . '?' . http_build_query($f) }}"><i class="fa fa-download"></i> CSV</a>
      <a class="btn btn-default btn-sm" href="{{ url('/payroll') . '?start=' . urlencode($f['start']) . '&end=' . urlencode($f['end']) }}">Payroll for these dates</a>
      <a class="btn btn-default btn-sm" href="{{ url('/time-clock/settings') }}">PINs, phones and stores</a>
      <a class="btn btn-default btn-sm" href="{{ url('/time-clock/qr') }}" target="_blank">Print QR codes</a>
    </form>
  </div></div>

  @if($ready)
  <div class="row">
    <div class="col-md-5">
      <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">Hours by person</h3></div>
        <div class="box-body table-responsive">
          <table class="table table-condensed">
            <thead><tr><th>Name</th><th class="text-right">Shifts</th><th class="text-right">Hours</th><th class="text-right">Flags</th></tr></thead>
            <tbody>
            @forelse($summary as $uid => $s)
              <tr>
                <td><a href="{{ url('/reports/timecards') . '?' . http_build_query(array_merge($f, ['user_id' => $uid])) }}">{{ $s['name'] }}</a></td>
                <td class="text-right">{{ $s['shifts'] }}</td>
                <td class="text-right">{{ number_format($s['hours'], 2) }}</td>
                <td class="text-right"> @if($s['flags'])<span class="tc-flag">{{ $s['flags'] }}</span> @endif</td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-muted">No punches in this range.</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-7">
      <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">Add a missed punch</h3></div>
        <div class="box-body">
          <form method="POST" action="{{ url('/reports/timecards/save') }}">
            {{ csrf_field() }}
            <div class="row">
              <div class="col-sm-6">
                <label>Person</label>
                <select name="user_id" class="form-control input-sm" required>
                  <option value="">Pick a person</option>
                  @foreach($users as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-6">
                <label>Store</label>
                <select name="location_id" class="form-control input-sm">
                  <option value="">None</option>
                  @foreach($locations as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-sm-6" style="margin-top:6px;">
                <label>Clock in</label>
                <input type="datetime-local" name="clock_in_at" class="form-control input-sm" required>
              </div>
              <div class="col-sm-6" style="margin-top:6px;">
                <label>Clock out</label>
                <input type="datetime-local" name="clock_out_at" class="form-control input-sm">
              </div>
              <div class="col-sm-12" style="margin-top:6px;">
                <label>Reason (required)</label>
                <input type="text" name="reason" class="form-control input-sm" maxlength="500" required placeholder="For example: forgot to clock in, confirmed with Luis">
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;">Add punch</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Punches ({{ count($punches) }})</h3></div>
    <div class="box-body table-responsive">
      <table class="table table-condensed table-hover">
        <thead><tr><th>Name</th><th>Store</th><th>Date</th><th>In</th><th>Out</th><th class="text-right">Hours</th><th>Flags</th><th>Where</th><th class="tc-actions">Fix</th></tr></thead>
        <tbody>
        @forelse($punches as $p)
          <tr class="{{ $p->voided_at ? 'tc-voided' : '' }}">
            <td>{{ $p->name }}</td>
            <td>{{ $p->store }}</td>
            <td>{{ \Carbon::parse($p->clock_in_at)->format('D M j') }}</td>
            <td>{{ \Carbon::parse($p->clock_in_at)->format('g:i A') }}</td>
            <td>
              @if($p->clock_out_at)
                {{ \Carbon::parse($p->clock_out_at)->format(substr($p->clock_out_at, 0, 10) === substr($p->clock_in_at, 0, 10) ? 'g:i A' : 'M j g:i A') }}
                @if($p->clock_out_source && $p->clock_out_source !== 'phone')<div class="text-muted" style="font-size:11px;">{{ \App\Services\TimeClockService::sourceLabel($p->clock_out_source) }}</div> @endif
              @else
                <span class="text-muted">none</span>
              @endif
            </td>
            <td class="text-right">{{ $p->hours === null ? '' : number_format($p->hours, 2) }}</td>
            <td>
              @foreach($p->flags as $fl)
                <span class="tc-flag {{ in_array($fl, ['Missing clock-out', 'Over 10 hours', 'Clocked in over 10 hours', 'Clock-in not verified at store', 'Clock-out not verified at store', 'No schedule data']) ? 'bad' : (in_array($fl, ['Edited', 'Removed', 'Still clocked in']) ? 'muted' : '') }}">{{ $fl }}</span>
              @endforeach
              @if($p->voided_at && $p->void_reason)<div class="tc-hist">Removed: {{ $p->void_reason }}</div> @endif
            </td>
            <td style="font-size:11px;color:#6b7280;">
              @if($p->in_verify)In: {{ \App\Services\TimeClockService::verifyLabel($p->in_verify) }} @endif
              @if($p->in_distance_m !== null) ({{ $p->in_distance_m }} m{{ $p->in_accuracy_m !== null ? ', +/- ' . $p->in_accuracy_m . ' m' : '' }}) @endif
              @if($p->in_geo_error) (location {{ $p->in_geo_error }}) @endif
              @if($p->clock_in_ip)<br>{{ $p->clock_in_ip }} @endif
              @if($p->out_verify)<br>Out: {{ \App\Services\TimeClockService::verifyLabel($p->out_verify) }} @endif
              @if($p->clock_out_ip && $p->clock_out_ip !== $p->clock_in_ip)<br>{{ $p->clock_out_ip }} @endif
              @if($p->in_user_agent)<div title="{{ $p->in_user_agent }}" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $p->in_user_agent }}</div> @endif
            </td>
            <td class="tc-actions">
              <details>
                <summary>Edit</summary>
                <div class="tc-edit">
                  <form method="POST" action="{{ url('/reports/timecards/save') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="id" value="{{ $p->id }}">
                    <label style="font-size:12px;">Clock in</label>
                    <input type="datetime-local" name="clock_in_at" class="form-control input-sm" value="{{ \Carbon::parse($p->clock_in_at)->format('Y-m-d\TH:i') }}" required>
                    <label style="font-size:12px;">Clock out</label>
                    <input type="datetime-local" name="clock_out_at" class="form-control input-sm" value="{{ $p->clock_out_at ? \Carbon::parse($p->clock_out_at)->format('Y-m-d\TH:i') : '' }}">
                    <label style="font-size:12px;">Store</label>
                    <select name="location_id" class="form-control input-sm">
                      <option value="">None</option>
                      @foreach($locations as $id => $name)
                        <option value="{{ $id }}" @if($p->location_id == $id) selected @endif>{{ $name }}</option>
                      @endforeach
                    </select>
                    <input type="text" name="reason" class="form-control input-sm" maxlength="500" required placeholder="Reason (required)">
                    <button type="submit" class="btn btn-primary btn-xs">Save</button>
                  </form>
                  <form method="POST" action="{{ url('/reports/timecards/void') }}" style="margin-top:8px;">
                    {{ csrf_field() }}
                    <input type="hidden" name="id" value="{{ $p->id }}">
                    <input type="hidden" name="restore" value="{{ $p->voided_at ? '1' : '0' }}">
                    <input type="text" name="reason" class="form-control input-sm" maxlength="500" required placeholder="Reason (required)">
                    <button type="submit" class="btn btn-default btn-xs">{{ $p->voided_at ? 'Restore this punch' : 'Remove this punch' }}</button>
                  </form>
                  @if(!empty($history[$p->id]))
                    <div class="tc-hist"><strong>History</strong>
                      @foreach($history[$p->id] as $h)
                        <div>{{ \Carbon::parse($h->created_at)->format('M j g:i A') }}, {{ $h->editor ?: 'someone' }}: {{ $h->action }}. {{ $h->reason }}
                          @php $__b = json_decode((string) $h->before, true); $__a = json_decode((string) $h->after, true); @endphp
                          @if(is_array($__b) && is_array($__a))
                            <span class="text-muted">(was {{ $__b['clock_in_at'] ?? '' }} to {{ $__b['clock_out_at'] ?? 'none' }})</span>
                          @endif
                        </div>
                      @endforeach
                    </div>
                  @endif
                </div>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="9" class="text-muted">No punches match.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
  @endif
</section>
@endsection
