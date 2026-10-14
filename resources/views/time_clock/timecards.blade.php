@extends('layouts.app')
@section('title', 'Timecards')

@section('content')
@include('time_clock.partials.admin_style')
<style>
  .tcx tr.tc-voided td { color:#9ca3af; text-decoration:line-through; }
  .tcx tr.tc-voided td.tc-actions { text-decoration:none; }
  .tcx details > summary { cursor:pointer; color:#2563eb; font-size:12px; font-weight:600; }
  .tcx .tc-edit { background:#FAF6EE; border:1px solid var(--line); border-radius:8px; padding:10px; margin-top:6px; min-width:240px; }
  .tcx .tc-edit .form-control { margin-bottom:6px; }
  .tcx .tc-hist { font-size:12px; color:var(--ink2); margin-top:6px; }
  .tcx .tc-where { font-size:11px; color:var(--ink3); line-height:1.4; max-width:200px; }
  .tcx .tc-where .ua { max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .tcx table.tc-punches th.c-time, .tcx table.tc-punches td.c-time { width:90px; white-space:nowrap; }
  .tcx table.tc-punches th.c-hours, .tcx table.tc-punches td.c-hours { width:64px; }
  .tcx table.tc-punches th.c-date, .tcx table.tc-punches td.c-date { width:100px; white-space:nowrap; }
  .tcx table.tc-punches th.c-store, .tcx table.tc-punches td.c-store { width:90px; }
</style>
@php
  $__nowLocal = \App\Services\TimeClockService::now()->format('Y-m-d\TH:i');
  $__flagClass = function ($fl) {
      if (strpos($fl, 'Late ') === 0) { return 'amber'; }
      if (strpos($fl, 'Stayed ') === 0) { return 'blue'; }
      if (in_array($fl, ['Missing clock-out', 'Over 10 hours', 'Clocked in over 10 hours', 'Clock-in not verified at store', 'Clock-out not verified at store', 'No schedule data', 'PIN typed on a phone'], true)) { return 'red'; }
      return '';
  };
@endphp
<div class="tcx">
<section class="content-header">
  <h1>Timecards <small>clock-ins from the time clock</small></h1>
</section>
<section class="content">
  @if(!$ready)
    <div class="alert alert-warning">The time clock tables are not set up yet. Run the migration, then reload.</div>
  @endif

  <div class="box box-solid"><div class="box-body">
    <form method="GET" action="{{ url('/reports/timecards') }}" class="tcx-bar">
      <input type="date" name="start" value="{{ $f['start'] }}" class="form-control" aria-label="From">
      <span class="tcx-to">to</span>
      <input type="date" name="end" value="{{ $f['end'] }}" class="form-control" aria-label="To">
      <select name="user_id" class="form-control" aria-label="Person">
        <option value="">Everyone</option>
        @foreach($users as $id => $name)
          <option value="{{ $id }}" @if($f['user_id'] == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
      <select name="location_id" class="form-control" aria-label="Store">
        <option value="">All stores</option>
        @foreach($locations as $id => $name)
          <option value="{{ $id }}" @if($f['location_id'] == $id) selected @endif>{{ $name }}</option>
        @endforeach
      </select>
      <select name="flag" class="form-control" aria-label="Flag">
        <option value="">All punches</option>
        <option value="missing" @if($f['flag'] === 'missing') selected @endif>Missing clock-out</option>
        <option value="long" @if($f['flag'] === 'long') selected @endif>Over 10 hours</option>
        <option value="unverified" @if($f['flag'] === 'unverified') selected @endif>Not verified at store</option>
        <option value="late" @if($f['flag'] === 'late') selected @endif>Late</option>
        <option value="stayed" @if($f['flag'] === 'stayed') selected @endif>Stayed past shift</option>
        <option value="edited" @if($f['flag'] === 'edited') selected @endif>Edited</option>
        <option value="voided" @if($f['flag'] === 'voided') selected @endif>Removed</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Show</button>
      <a class="btn btn-default btn-sm" href="{{ url('/reports/timecards/export.csv') . '?' . http_build_query($f) }}"><i class="fa fa-download"></i> CSV</a>
      <span class="tcx-right">
        <a class="btn btn-default btn-sm" href="{{ url('/payroll') . '?start=' . urlencode($f['start']) . '&end=' . urlencode($f['end']) }}">Payroll</a>
        <a class="btn btn-default btn-sm" href="{{ url('/time-clock/settings') }}">PINs and stores</a>
        <a class="btn btn-default btn-sm" href="{{ url('/time-clock/qr') }}" target="_blank">QR codes</a>
      </span>
    </form>
  </div></div>

  @if($ready)
  <div class="row">
    <div class="{{ $canEdit ? 'col-md-6' : 'col-md-12' }}">
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
                <td class="text-right"> @if($s['flags'])<span class="tcx-badge amber">{{ $s['flags'] }}</span> @endif</td>
              </tr>
            @empty
              <tr><td colspan="4" class="tcx-empty">No punches in this range.</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </div>
      @if(count($stayedWeeks))
      <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">Stayed past shift, by week</h3></div>
        <div class="box-body table-responsive">
          <p class="tcx-hint">Minutes past the scheduled end. Hours are still paid as recorded.</p>
          <table class="table table-condensed">
            <thead><tr><th>Name</th><th>Week of</th><th class="text-right">Extra minutes</th></tr></thead>
            <tbody>
            @foreach($stayedWeeks as $uid => $sw)
              @foreach($sw['weeks'] as $wk => $min)
                <tr>
                  <td>{{ $sw['name'] }}</td>
                  <td>{{ \Carbon::parse($wk)->format('M j') }}</td>
                  <td class="text-right"><span class="tcx-badge blue">{{ $min }} min</span></td>
                </tr>
              @endforeach
            @endforeach
            </tbody>
          </table>
        </div>
      </div>
      @endif
    </div>
    @if($canEdit)
    <div class="col-md-6">
      <div class="box box-solid">
        <div class="box-header"><h3 class="box-title">Add a missed punch</h3></div>
        <div class="box-body">
          <form method="POST" action="{{ url('/reports/timecards/save') }}">
            {{ csrf_field() }}
            <div class="tcx-grid">
              <div>
                <label for="tcAddUser">Person</label>
                <select id="tcAddUser" name="user_id" class="form-control" required>
                  <option value="">Pick a person</option>
                  @foreach($users as $id => $name)
                    @if((int) $id !== $me)
                      <option value="{{ $id }}">{{ $name }}</option>
                    @endif
                  @endforeach
                </select>
              </div>
              <div>
                <label for="tcAddLoc">Store</label>
                <select id="tcAddLoc" name="location_id" class="form-control">
                  <option value="">None</option>
                  @foreach($locations as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="tcAddIn">Clock in</label>
                <input id="tcAddIn" type="datetime-local" name="clock_in_at" class="form-control" value="{{ $__nowLocal }}" max="{{ $__nowLocal }}" required>
              </div>
              <div>
                <label for="tcAddOut">Clock out</label>
                <input id="tcAddOut" type="datetime-local" name="clock_out_at" class="form-control" max="{{ $__nowLocal }}">
              </div>
              <div class="tcx-full">
                <label for="tcAddWhy">Reason</label>
                <input id="tcAddWhy" type="text" name="reason" class="form-control" maxlength="500" required placeholder="For example: forgot to clock in, confirmed with Luis">
              </div>
              <div class="tcx-full"><button type="submit" class="btn btn-primary btn-sm">Add punch</button></div>
            </div>
          </form>
        </div>
      </div>
    </div>
    @endif
  </div>

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Punches ({{ count($punches) }})</h3></div>
    <div class="box-body table-responsive">
      <table class="table table-condensed table-hover tc-punches">
        <thead><tr><th>Name</th><th class="c-store">Store</th><th class="c-date">Date</th><th class="c-time">In</th><th class="c-time">Out</th><th class="text-right c-hours">Hours</th><th>Flags</th><th>Where</th> @if($canEdit)<th class="tc-actions">Fix</th> @endif</tr></thead>
        <tbody>
        @forelse($punches as $p)
          <tr class="{{ $p->voided_at ? 'tc-voided' : '' }}">
            <td class="tcx-nowrap">{{ $p->name }}</td>
            <td class="c-store">{{ $p->store }}</td>
            <td class="c-date">{{ \Carbon::parse($p->clock_in_at)->format('D M j') }}</td>
            <td class="c-time">{{ \Carbon::parse($p->clock_in_at)->format('g:i A') }}</td>
            <td class="c-time">
              @if($p->clock_out_at)
                {{ \Carbon::parse($p->clock_out_at)->format(substr($p->clock_out_at, 0, 10) === substr($p->clock_in_at, 0, 10) ? 'g:i A' : 'M j g:i A') }}
                @if($p->clock_out_source && $p->clock_out_source !== 'phone')<div class="tcx-small">{{ \App\Services\TimeClockService::sourceLabel($p->clock_out_source) }}</div> @endif
              @else
                <span class="tcx-small">none</span>
              @endif
            </td>
            <td class="text-right c-hours">{{ $p->hours === null ? '' : number_format($p->hours, 2) }}</td>
            <td>
              @foreach($p->flags as $fl)
                <span class="tcx-badge {{ $__flagClass($fl) }}">{{ $fl }}</span>
              @endforeach
              @if($p->voided_at && $p->void_reason)<div class="tc-hist">Removed: {{ $p->void_reason }}</div> @endif
            </td>
            <td class="tc-where">
              @if($p->in_verify)In: {{ \App\Services\TimeClockService::verifyLabel($p->in_verify) }} @endif
              @if($p->in_distance_m !== null) ({{ $p->in_distance_m }} m{{ $p->in_accuracy_m !== null ? ', +/- ' . $p->in_accuracy_m . ' m' : '' }}) @endif
              @if($p->in_geo_error) (location {{ $p->in_geo_error }}) @endif
              @if($p->clock_in_ip)<br>{{ $p->clock_in_ip }} @endif
              @if($p->out_verify)<br>Out: {{ \App\Services\TimeClockService::verifyLabel($p->out_verify) }} @endif
              @if($p->clock_out_ip && $p->clock_out_ip !== $p->clock_in_ip)<br>{{ $p->clock_out_ip }} @endif
              @if($p->in_user_agent)<div class="ua" title="{{ $p->in_user_agent }}">{{ $p->in_user_agent }}</div> @endif
            </td>
            @if($canEdit)
            <td class="tc-actions">
              @if((int) $p->user_id === $me)
                <span class="tcx-small">Your punch</span>
              @else
              <details>
                <summary>Edit</summary>
                <div class="tc-edit">
                  <form method="POST" action="{{ url('/reports/timecards/save') }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="id" value="{{ $p->id }}">
                    <label>Clock in</label>
                    <input type="datetime-local" name="clock_in_at" class="form-control" value="{{ \Carbon::parse($p->clock_in_at)->format('Y-m-d\TH:i') }}" max="{{ $__nowLocal }}" required>
                    <label>Clock out</label>
                    <input type="datetime-local" name="clock_out_at" class="form-control" value="{{ $p->clock_out_at ? \Carbon::parse($p->clock_out_at)->format('Y-m-d\TH:i') : '' }}" max="{{ $__nowLocal }}">
                    <label>Store</label>
                    <select name="location_id" class="form-control">
                      <option value="">None</option>
                      @foreach($locations as $id => $name)
                        <option value="{{ $id }}" @if($p->location_id == $id) selected @endif>{{ $name }}</option>
                      @endforeach
                    </select>
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" maxlength="500" required>
                    <button type="submit" class="btn btn-primary btn-xs">Save</button>
                  </form>
                  <form method="POST" action="{{ url('/reports/timecards/void') }}" style="margin-top:10px;">
                    {{ csrf_field() }}
                    <input type="hidden" name="id" value="{{ $p->id }}">
                    <input type="hidden" name="restore" value="{{ $p->voided_at ? '1' : '0' }}">
                    <label>Reason</label>
                    <input type="text" name="reason" class="form-control" maxlength="500" required>
                    <button type="submit" class="btn btn-default btn-xs">{{ $p->voided_at ? 'Restore this punch' : 'Remove this punch' }}</button>
                  </form>
                  @if(!empty($history[$p->id]))
                    <div class="tc-hist"><strong>History</strong>
                      @foreach($history[$p->id] as $h)
                        <div>{{ \Carbon::parse($h->created_at)->format('M j g:i A') }}, {{ $h->editor ?: 'someone' }}: {{ $h->action }}. {{ $h->reason }}
                          @php $__b = json_decode((string) $h->before, true); $__a = json_decode((string) $h->after, true); @endphp
                          @if(is_array($__b) && is_array($__a))
                            <span class="tcx-small">(was {{ $__b['clock_in_at'] ?? '' }} to {{ $__b['clock_out_at'] ?? 'none' }})</span>
                          @endif
                        </div>
                      @endforeach
                    </div>
                  @endif
                </div>
              </details>
              @endif
            </td>
            @endif
          </tr>
        @empty
          <tr><td colspan="{{ $canEdit ? 9 : 8 }}" class="tcx-empty">No punches match.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
  @endif
</section>
</div>
@endsection
