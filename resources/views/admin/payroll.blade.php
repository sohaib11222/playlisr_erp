@extends('layouts.app')
@section('title', 'Payroll')

@section('content')
@php
    $fmt  = function ($n) { return number_format((float) $n, 2); };
    $hh   = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
    $back = '/payroll?start=' . urlencode($start) . '&end=' . urlencode($end);
@endphp

<section class="content-header">
    <h1>Payroll <small>{{ \Carbon::parse($start)->format('M j') }} – {{ \Carbon::parse($end)->format('M j, Y') }}</small></h1>
    <p class="text-muted" style="margin-top:6px;max-width:900px;">
        Hours come from your <strong>Clover</strong> clock in/out; late flags compare them to the <strong>Sling</strong> schedule.
        Overtime is California daily rule ({{ $hh($settings['daily_ot_after']) }}h = {{ $hh($settings['ot_multiplier']) }}x,
        {{ $hh($settings['daily_dt_after']) }}h = {{ $hh($settings['dt_multiplier']) }}x). Sales and listing commission are pulled
        live from Commissions Owed. The QuickBooks panel at the bottom is what you type into QB.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

{{-- Period picker --}}
<div class="row">
  <div class="col-md-12">
    <div class="box box-solid"><div class="box-body">
      <form method="GET" action="{{ url('/payroll') }}" class="form-inline">
        <label style="margin-right:6px;">Pay period</label>
        <input type="date" name="start" value="{{ $start }}" class="form-control input-sm" style="margin-right:6px;">
        <span style="margin-right:6px;">to</span>
        <input type="date" name="end" value="{{ $end }}" class="form-control input-sm" style="margin-right:12px;">
        <button class="btn btn-primary btn-sm" type="submit">Load</button>
        <span class="pull-right text-muted" style="font-size:12px;">
          @if ($imported_at)
            {{ $row_count }} punches imported {{ \Carbon::parse($imported_at)->format('M j, g:ia') }}
          @else
            No hours imported for this period yet
          @endif
        </span>
      </form>
    </div></div>
  </div>
</div>

{{-- Import hours --}}
<div class="row">
  <div class="col-md-12">
    @component('components.widget', ['title' => 'Import hours from Clover'])
      <form method="POST" action="{{ url('/payroll/import-hours') }}" enctype="multipart/form-data">
        {{ csrf_field() }}
        <input type="hidden" name="start" value="{{ $start }}">
        <input type="hidden" name="end" value="{{ $end }}">
        <p class="text-muted" style="margin-bottom:8px;">
          Paste the Clover timecard export (or upload the CSV). It reads columns like
          <em>Name, Clock In Date, Clock In Time, Clock Out Date, Clock Out Time, Elapsed, Location</em> — extra columns are ignored.
          Re-importing replaces this period's hours.
        </p>
        <textarea name="paste" class="form-control" rows="5"
                  placeholder="Name&#9;Clock In Date&#9;Clock In Time&#9;Clock Out Date&#9;Clock Out Time&#9;Elapsed&#9;Location&#10;Alec&#9;8-May-26&#9;4:04:21 PM&#9;8-May-26&#9;8:00:38 PM&#9;3.94&#9;pico"></textarea>
        <div style="margin-top:10px;">
          <input type="file" name="file" accept=".csv,text/csv" style="display:inline-block;">
          <button type="submit" class="btn btn-primary btn-sm pull-right">Import hours</button>
        </div>
      </form>
    @endcomponent
  </div>
</div>

{{-- Needs attention --}}
@if (!empty($unmatched))
  <div class="row"><div class="col-md-12">
    <div class="alert alert-warning" style="margin-bottom:15px;">
      <strong>Needs setup before these are correct:</strong>
      <ul style="margin:6px 0 0 18px;">
        @foreach ($unmatched as $u)
          <li>
            {{ $u['name'] }} —
            @if ($u['no_rate']) <span style="color:#a94442;">no hourly rate set</span>@endif
            @if ($u['no_rate'] && $u['no_user']) &middot; @endif
            @if ($u['no_user']) <span style="color:#8a6d3b;">not linked to an ERP user (commissions won't attach)</span>@endif
          </li>
        @endforeach
      </ul>
      Set rates &amp; links in <a href="#rates">Rates &amp; settings</a> below.
    </div>
  </div></div>
@endif

{{-- Summary --}}
<div class="row"><div class="col-md-12">
  @component('components.widget', ['title' => 'Pay run'])
    <div class="table-responsive">
    <table class="table table-condensed table-bordered" style="background:#fff;">
      <thead style="background:#FFF7CC;">
        <tr>
          <th>Name</th><th>Store</th><th class="text-right">Rate</th>
          <th class="text-right">Reg h</th><th class="text-right">OT h</th><th class="text-right">DT h</th>
          <th class="text-right">Wages</th><th class="text-right">Sales comm</th><th class="text-right">Listing comm</th>
          <th class="text-right">Total</th><th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($people as $p)
          <tr class="{{ $p['has_hours'] ? '' : 'text-muted' }}">
            <td><strong>{{ $p['name'] }}</strong>@unless($p['has_hours'])<br><small>commission only (no hours this period)</small>@endunless</td>
            <td>{{ $p['store'] }}</td>
            <td class="text-right">{{ $p['rate'] > 0 ? '$' . $fmt($p['rate']) : '—' }}</td>
            <td class="text-right">{{ $p['reg_hours'] ? $hh($p['reg_hours']) : '' }}</td>
            <td class="text-right">{{ $p['ot_hours'] ? $hh($p['ot_hours']) : '' }}</td>
            <td class="text-right">{{ $p['dt_hours'] ? $hh($p['dt_hours']) : '' }}</td>
            <td class="text-right">{{ $p['wages'] ? '$' . $fmt($p['wages']) : '' }}</td>
            <td class="text-right">{{ $p['sales_comm'] ? '$' . $fmt($p['sales_comm']) : '' }}</td>
            <td class="text-right">{{ $p['listing_comm'] ? '$' . $fmt($p['listing_comm']) : '' }}</td>
            <td class="text-right"><strong>${{ $fmt($p['grand_total']) }}</strong></td>
            <td class="text-center">
              @if (!empty($p['flags']))
                <span class="label label-warning" title="{{ implode(' | ', $p['flags']) }}" style="cursor:help;">
                  <i class="fa fa-clock-o"></i> {{ count($p['flags']) }}
                </span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="11" class="text-center text-muted" style="padding:24px;">No hours imported for this period. Paste the Clover export above.</td></tr>
        @endforelse
      </tbody>
      @if (!empty($people))
      <tfoot style="background:#FBFBF6;font-weight:700;">
        <tr>
          <td colspan="3">Totals</td>
          <td class="text-right">{{ $hh($totals['reg_hours']) }}</td>
          <td class="text-right">{{ $hh($totals['ot_hours']) }}</td>
          <td class="text-right">{{ $hh($totals['dt_hours']) }}</td>
          <td class="text-right">${{ $fmt($totals['wages']) }}</td>
          <td class="text-right">${{ $fmt($totals['sales_comm']) }}</td>
          <td class="text-right">${{ $fmt($totals['listing_comm']) }}</td>
          <td class="text-right">${{ $fmt($totals['grand_total']) }}</td>
          <td></td>
        </tr>
      </tfoot>
      @endif
    </table>
    </div>
  @endcomponent
</div></div>

{{-- Late flags --}}
@php
  $flagged = array_filter($people, function ($p) { return !empty($p['flags']); });
@endphp
@if (!empty($flagged))
  <div class="row"><div class="col-md-12">
    @component('components.widget', ['title' => 'Late arrivals / late clock-outs (' . $totals['flags'] . ')'])
      <p class="text-muted" style="margin-bottom:8px;">Compared to the Sling schedule, past a {{ $settings['grace_minutes'] }}-minute grace. Soft warnings — they don't change pay.</p>
      @foreach ($flagged as $p)
        <div style="margin-bottom:6px;">
          <strong>{{ $p['name'] }}</strong>
          <ul style="margin:2px 0 6px 18px;">
            @foreach ($p['flags'] as $f)<li>{{ $f }}</li>@endforeach
          </ul>
        </div>
      @endforeach
    @endcomponent
  </div></div>
@endif

{{-- QuickBooks panel --}}
<div class="row"><div class="col-md-12">
  @component('components.widget', ['title' => 'What to enter in QuickBooks'])
    <div style="margin-bottom:10px;">
      <a href="{{ url('/payroll/export.csv?start=' . urlencode($start) . '&end=' . urlencode($end)) }}" class="btn btn-success btn-sm">
        <i class="fa fa-download"></i> Export CSV
      </a>
      <button type="button" class="btn btn-default btn-sm" onclick="payrollCopyTable('qb-table')"><i class="fa fa-copy"></i> Copy table</button>
      <span class="text-muted" style="margin-left:8px;font-size:12px;">Enter hours as payroll; sales &amp; listing commission as separate pay items. Freelancers are paid outside QB (below).</span>
    </div>
    <div class="table-responsive">
    <table class="table table-condensed table-bordered" id="qb-table" style="background:#fff;">
      <thead style="background:#FFF7CC;">
        <tr><th>Name</th><th class="text-right">Regular hrs</th><th class="text-right">OT hrs</th><th class="text-right">DT hrs</th><th class="text-right">Sales commission</th><th class="text-right">Listing commission</th></tr>
      </thead>
      <tbody>
        @foreach ($people as $p)
          @if ($p['reg_hours'] || $p['ot_hours'] || $p['dt_hours'] || $p['sales_comm'] || $p['listing_comm'])
          <tr>
            <td>{{ $p['name'] }}</td>
            <td class="text-right">{{ $hh($p['reg_hours']) }}</td>
            <td class="text-right">{{ $hh($p['ot_hours']) }}</td>
            <td class="text-right">{{ $hh($p['dt_hours']) }}</td>
            <td class="text-right">{{ $p['sales_comm'] ? $fmt($p['sales_comm']) : '' }}</td>
            <td class="text-right">{{ $p['listing_comm'] ? $fmt($p['listing_comm']) : '' }}</td>
          </tr>
          @endif
        @endforeach
      </tbody>
    </table>
    </div>
  @endcomponent
</div></div>

{{-- Freelancers --}}
<div class="row"><div class="col-md-12">
  @component('components.widget', ['title' => 'Freelancers / contractors'])
    <p class="text-muted" style="margin-bottom:8px;">Paid outside QuickBooks payroll (PayPal, payment link, etc.). Hourly or per-unit compute automatically; flat is a fixed amount.</p>
    <div class="table-responsive">
    <table class="table table-condensed table-bordered" style="background:#fff;">
      <thead style="background:#FFF7CC;">
        <tr><th>Name</th><th>Model</th><th class="text-right">Rate</th><th class="text-right">Qty</th><th class="text-right">Amount</th><th>Method</th><th>Note</th><th>Paid</th><th></th></tr>
      </thead>
      <tbody>
        @forelse ($freelancers as $f)
          <tr>
            <td><strong>{{ $f['name'] }}</strong></td>
            <td>{{ ucfirst($f['model'] ?? 'flat') }}</td>
            <td class="text-right">{{ in_array(($f['model'] ?? ''), ['hourly','units']) && ($f['rate'] ?? 0) ? '$' . $fmt($f['rate']) : '' }}</td>
            <td class="text-right">{{ in_array(($f['model'] ?? ''), ['hourly','units']) && ($f['qty'] ?? 0) ? $hh($f['qty']) : '' }}</td>
            <td class="text-right"><strong>${{ $fmt($f['amount']) }}</strong></td>
            <td>{{ $f['method'] ?? '' }}</td>
            <td><small>{{ $f['note'] ?? '' }}</small></td>
            <td>
              @if (!empty($f['paid'])) <span class="label label-success">paid</span>
              @else <span class="label label-default">unpaid</span> @endif
            </td>
            <td class="text-right" style="white-space:nowrap;">
              <button type="button" class="btn btn-xs btn-default"
                onclick='payrollEditFreelancer(@json($f, JSON_HEX_APOS | JSON_HEX_QUOT))'>Edit</button>
              <form method="POST" action="{{ url('/payroll/freelancer/delete') }}" style="display:inline;"
                    onsubmit="return confirm('Remove {{ $f['name'] }}?');">
                {{ csrf_field() }}
                <input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
                <input type="hidden" name="id" value="{{ $f['id'] }}">
                <button type="submit" class="btn btn-xs btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="9" class="text-center text-muted" style="padding:16px;">No freelancers yet. Add one below.</td></tr>
        @endforelse
      </tbody>
      @if (!empty($freelancers))
      <tfoot style="background:#FBFBF6;font-weight:700;">
        <tr><td colspan="4">Total</td><td class="text-right">${{ $fmt($freelancer_total) }}</td><td colspan="4"></td></tr>
      </tfoot>
      @endif
    </table>
    </div>

    <hr>
    <form method="POST" action="{{ url('/payroll/freelancer') }}" id="freelancer-form">
      {{ csrf_field() }}
      <input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
      <input type="hidden" name="id" id="f-id" value="">
      <div class="row">
        <div class="col-sm-2"><label>Name</label><input name="name" id="f-name" class="form-control input-sm" required></div>
        <div class="col-sm-2"><label>Model</label>
          <select name="model" id="f-model" class="form-control input-sm">
            <option value="flat">Flat amount</option>
            <option value="hourly">Hourly</option>
            <option value="units">Per unit</option>
          </select>
        </div>
        <div class="col-sm-1"><label>Rate</label><input name="f_rate" id="f-rate" type="number" step="0.01" class="form-control input-sm"></div>
        <div class="col-sm-1"><label>Qty</label><input name="qty" id="f-qty" type="number" step="0.01" class="form-control input-sm"></div>
        <div class="col-sm-2"><label>Flat amount</label><input name="amount" id="f-amount" type="number" step="0.01" class="form-control input-sm"></div>
        <div class="col-sm-2"><label>Method</label><input name="method" id="f-method" class="form-control input-sm" placeholder="PayPal"></div>
        <div class="col-sm-2"><label>Note</label><input name="note" id="f-note" class="form-control input-sm"></div>
      </div>
      <div style="margin-top:8px;">
        <label style="font-weight:normal;"><input type="checkbox" name="paid" id="f-paid" value="1"> Paid</label>
        <button type="submit" class="btn btn-primary btn-sm pull-right">Save freelancer</button>
        <button type="button" class="btn btn-default btn-sm pull-right" style="margin-right:6px;" onclick="payrollResetFreelancer()">Clear</button>
      </div>
    </form>
  @endcomponent
</div></div>

{{-- Rates & settings --}}
<div class="row" id="rates"><div class="col-md-12">
  @component('components.widget', ['title' => 'Rates & settings'])
    <form method="POST" action="{{ url('/payroll/save-rates') }}">
      {{ csrf_field() }}
      <input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">

      <div class="row" style="margin-bottom:12px;">
        <div class="col-sm-2"><label>OT after (h/day)</label><input name="daily_ot_after" type="number" step="0.25" value="{{ $settings['daily_ot_after'] }}" class="form-control input-sm"></div>
        <div class="col-sm-2"><label>DT after (h/day)</label><input name="daily_dt_after" type="number" step="0.25" value="{{ $settings['daily_dt_after'] }}" class="form-control input-sm"></div>
        <div class="col-sm-2"><label>OT multiplier</label><input name="ot_multiplier" type="number" step="0.1" value="{{ $settings['ot_multiplier'] }}" class="form-control input-sm"></div>
        <div class="col-sm-2"><label>DT multiplier</label><input name="dt_multiplier" type="number" step="0.1" value="{{ $settings['dt_multiplier'] }}" class="form-control input-sm"></div>
        <div class="col-sm-2"><label>Late grace (min)</label><input name="grace_minutes" type="number" step="1" value="{{ $settings['grace_minutes'] }}" class="form-control input-sm"></div>
      </div>

      <p class="text-muted" style="margin-bottom:6px;">Hourly rate per person. Leave ERP user blank to auto-match by first name; set it only to override or fix an ambiguous name.</p>
      <div class="table-responsive">
      <table class="table table-condensed table-bordered" style="background:#fff;">
        <thead style="background:#FFF7CC;"><tr><th>Person</th><th style="width:160px;">Hourly rate</th><th style="width:160px;">Store</th><th style="width:180px;">ERP user id (optional)</th></tr></thead>
        <tbody>
          @foreach ($people as $p)
            @if ($p['has_hours'])
            <tr>
              <td><strong>{{ $p['name'] }}</strong></td>
              <td><div class="input-group input-group-sm"><span class="input-group-addon">$</span>
                <input name="rate[{{ $p['key'] }}]" type="number" step="0.01" value="{{ $p['rate'] ?: '' }}" class="form-control"></div></td>
              <td><input name="store[{{ $p['key'] }}]" value="{{ $p['store'] }}" class="form-control input-sm"></td>
              <td><input name="user_id[{{ $p['key'] }}]" type="number" value="{{ $p['user_id'] }}" placeholder="auto: {{ $p['user_id'] ?: 'unmatched' }}" class="form-control input-sm"></td>
            </tr>
            @endif
          @endforeach
        </tbody>
      </table>
      </div>
      <button type="submit" class="btn btn-primary btn-sm pull-right">Save rates &amp; settings</button>
      <div style="clear:both;"></div>
    </form>
  @endcomponent
</div></div>

</section>

<script>
function payrollCopyTable(id) {
  var t = document.getElementById(id);
  if (!t) { return; }
  var lines = [];
  t.querySelectorAll('tr').forEach(function (tr) {
    var cells = [];
    tr.querySelectorAll('th,td').forEach(function (c) { cells.push(c.innerText.trim()); });
    lines.push(cells.join('\t'));
  });
  var text = lines.join('\n');
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function () { alert('Copied — paste into a sheet or QB.'); });
  } else {
    var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
    ta.select(); try { document.execCommand('copy'); alert('Copied.'); } catch (e) {}
    document.body.removeChild(ta);
  }
}
function payrollEditFreelancer(f) {
  document.getElementById('f-id').value = f.id || '';
  document.getElementById('f-name').value = f.name || '';
  document.getElementById('f-model').value = f.model || 'flat';
  document.getElementById('f-rate').value = f.rate || '';
  document.getElementById('f-qty').value = f.qty || '';
  document.getElementById('f-amount').value = f.amount || '';
  document.getElementById('f-method').value = f.method || '';
  document.getElementById('f-note').value = f.note || '';
  document.getElementById('f-paid').checked = !!f.paid;
  document.getElementById('freelancer-form').scrollIntoView({behavior:'smooth', block:'center'});
}
function payrollResetFreelancer() {
  document.getElementById('freelancer-form').reset();
  document.getElementById('f-id').value = '';
}
</script>
@endsection
