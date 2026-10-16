@extends('layouts.app')
@section('title', 'Payroll')

@section('content')

{{-- Inter Tight, loaded non-blocking (same approach as POS create). --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>

<style>
.content-wrapper, .payroll-v2 section.content { background:#FAF6EE !important; }
.payroll-v2 {
    --pos-bg:#FAF6EE; --pos-surface:#FFFFFF; --pos-surface-2:#F7F1E3;
    --pos-ink:#1F1B16; --pos-ink-2:#5A5045; --pos-ink-3:#8E8273;
    --pos-line:#ECE3CF; --pos-line-2:#DFD2B3;
    --pos-brand:#1F1B16; --pos-brand-ink:#FAF6EE;
    --pos-accent:#FFF2B3; --pos-accent-deep:#E8CF68; --pos-accent-soft:#FFF9DB; --pos-accent-text:#5A4410;
    --pos-radius:10px; --pos-radius-sm:8px;
    --pos-shadow-sm:0 1px 2px rgba(31,27,22,.06);
    font-family:"Inter Tight",system-ui,sans-serif; color:var(--pos-ink); -webkit-font-smoothing:antialiased;
}
.payroll-v2, .payroll-v2 .btn, .payroll-v2 input, .payroll-v2 select, .payroll-v2 textarea, .payroll-v2 button, .payroll-v2 .box, .payroll-v2 table, .payroll-v2 summary { font-family:inherit; }
.payroll-v2 .content-header { padding:18px 4px 6px; }
.payroll-v2 .content-header h1 { font-weight:800; letter-spacing:-.01em; }
.payroll-v2 .content-header h1 small { color:var(--pos-ink-3); font-weight:600; }
.payroll-v2 .box, .payroll-v2 .box.box-solid { background:var(--pos-surface); border:1px solid var(--pos-line); border-radius:var(--pos-radius); box-shadow:var(--pos-shadow-sm); margin-bottom:16px; }
.payroll-v2 .box > .box-header { border-bottom:1px solid var(--pos-line); padding:14px 18px 10px; }
.payroll-v2 .box > .box-header .box-title { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--pos-ink-3); }
.payroll-v2 .box-body { padding:16px 18px; }
.payroll-v2 .table { background:var(--pos-surface); margin-bottom:0; }
.payroll-v2 .table > thead > tr > th { background:var(--pos-accent-soft) !important; border-color:var(--pos-line) !important; color:var(--pos-ink-2); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
.payroll-v2 .table > tbody > tr > td, .payroll-v2 .table > tfoot > tr > td { border-color:var(--pos-line) !important; vertical-align:middle; }
.payroll-v2 .table > tfoot { background:var(--pos-surface-2); }
.payroll-v2 .table-bordered { border-color:var(--pos-line); }
.payroll-v2 .form-control, .payroll-v2 textarea, .payroll-v2 select, .payroll-v2 input { border:1px solid var(--pos-line-2); border-radius:var(--pos-radius-sm); box-shadow:none; color:var(--pos-ink); }
.payroll-v2 .form-control:focus { border-color:var(--pos-accent-deep); box-shadow:0 0 0 3px rgba(232,207,104,.25); }
.payroll-v2 label { color:var(--pos-ink-3); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.payroll-v2 .input-group-addon { background:var(--pos-surface-2); border:1px solid var(--pos-line-2); color:var(--pos-ink-2); }
.payroll-v2 .btn { border-radius:var(--pos-radius-sm); font-weight:700; }
.payroll-v2 .btn-primary { background:var(--pos-accent); border:1px solid var(--pos-accent-deep); color:var(--pos-accent-text); }
.payroll-v2 .btn-primary:hover { background:var(--pos-accent-deep); color:var(--pos-accent-text); }
.payroll-v2 .btn-success { background:var(--pos-brand); border:1px solid var(--pos-brand); color:var(--pos-brand-ink); }
.payroll-v2 .btn-success:hover { background:#000; color:#fff; }
.payroll-v2 .btn-default { background:var(--pos-surface); border:1px solid var(--pos-line-2); color:var(--pos-ink-2); }
.payroll-v2 .btn-default:hover { background:var(--pos-surface-2); }
.payroll-v2 .btn-danger { background:#fff; border:1px solid #E0B4AC; color:#8A3A2E; }
.payroll-v2 .btn-danger:hover { background:#8A3A2E; color:#fff; }
.payroll-v2 .label-warning { background:var(--pos-accent); color:var(--pos-accent-text); }
.payroll-v2 .label-success { background:#E7F1E9; color:#2F6B3E; }
.payroll-v2 .label-default { background:var(--pos-surface-2); color:var(--pos-ink-3); }
.payroll-v2 .alert-success { background:var(--pos-accent-soft); border:1px solid var(--pos-accent-deep); color:var(--pos-accent-text); border-radius:var(--pos-radius-sm); }
.payroll-v2 .alert-warning { background:#FFF9DB; border:1px solid var(--pos-accent-deep); color:#5A4410; border-radius:var(--pos-radius-sm); }
.payroll-v2 .alert-danger { border-radius:var(--pos-radius-sm); }
.payroll-v2 .text-muted { color:var(--pos-ink-3); }
.payroll-v2 hr { border-top:1px solid var(--pos-line); }
.payroll-v2 details.pr-more { background:var(--pos-surface); border:1px solid var(--pos-line); border-radius:var(--pos-radius); box-shadow:var(--pos-shadow-sm); margin-bottom:12px; }
.payroll-v2 details.pr-more > summary { cursor:pointer; padding:13px 18px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--pos-ink-3); list-style:none; }
.payroll-v2 details.pr-more > summary::-webkit-details-marker { display:none; }
.payroll-v2 details.pr-more > summary::before { content:'▸\00a0\00a0'; }
.payroll-v2 details.pr-more[open] > summary::before { content:'▾\00a0\00a0'; }
.payroll-v2 details.pr-more > .pr-body { padding:0 18px 16px; }
.pr-pin-btn { flex:0 0 auto; display:inline-flex; align-items:center; gap:7px; white-space:nowrap; cursor:pointer; font:inherit; font-size:13px; font-weight:700; color:#6b5a00; background:#FFF7CC; border:1px solid #E6CE5A; border-radius:999px; padding:8px 14px; line-height:1; }
.pr-pin-btn:hover { background:#FFF2B3; }
.pr-pin-btn.is-on { background:#FFF2B3; box-shadow:inset 0 0 0 1px #E6CE5A; }
.pr-pin-btn .fa { color:#C99A12; }
</style>

@php
    $fmt = function ($n) { return number_format((float) $n, 2); };
    $hh  = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
    $pinUrl     = url('/payroll');
    $pinAlready = \App\Http\Controllers\SidebarFavoriteController::isPinned(
        session()->get('user.business_id'), session()->get('user.id'), $pinUrl
    );
    // One "what I owe everyone" figure = staff wages+commission owed + freelancers.
    $owedAll = round($totals['grand_total'] + $freelancer_total, 2);
    $LC = \App\Http\Controllers\ListingCommissionController::class;
@endphp

<div class="payroll-v2">
<section class="content-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
    <div>
      <h1>Payroll <small>{{ \Carbon::parse($start)->format('M j') }} – {{ \Carbon::parse($end)->format('M j, Y') }}</small></h1>
      <p class="text-muted" style="margin-top:6px;max-width:900px;">
        What you owe everyone this period: hours pay (Clover, OT over {{ $hh($settings['daily_ot_after']) }}h/day at {{ $hh($settings['ot_multiplier']) }}x)
        plus sales &amp; listing commission owed. Everything else is tucked into the sections below.
      </p>
    </div>
    <button type="button" class="pr-pin-btn {{ $pinAlready ? 'is-on' : '' }}" data-pin-url="{{ $pinUrl }}" data-pin-label="Payroll"
            title="{{ $pinAlready ? 'Pinned to your sidebar' : 'Pin this page to your sidebar' }}">
        <i class="fa {{ $pinAlready ? 'fa-star' : 'fa-star-o' }}"></i>
        <span class="pin-text">{{ $pinAlready ? 'Pinned' : 'Star to sidebar' }}</span>
    </button>
</section>

<section class="content">

@if (session('status'))
  <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">{{ session('status')['msg'] }}</div>
@endif

{{-- Period + import (one compact row) --}}
<div class="box box-solid"><div class="box-body">
  <form method="GET" action="{{ url('/payroll') }}" class="form-inline" style="margin-bottom:0;">
    <label style="margin-right:6px;">Pay period</label>
    <input type="date" name="start" value="{{ $start }}" class="form-control input-sm" style="margin-right:6px;">
    <span style="margin-right:6px;">to</span>
    <input type="date" name="end" value="{{ $end }}" class="form-control input-sm" style="margin-right:12px;">
    <button class="btn btn-primary btn-sm" type="submit">Load</button>
    @php $ps = \Carbon::parse($start); @endphp
    <a class="btn btn-default btn-sm" style="margin-left:6px;" href="{{ url('/payroll?start=' . $ps->copy()->subDays(14)->toDateString() . '&end=' . $ps->copy()->subDay()->toDateString()) }}">&lsaquo; Previous period</a>
    <a class="btn btn-default btn-sm" href="{{ url('/payroll?start=' . $ps->copy()->addDays(14)->toDateString() . '&end=' . $ps->copy()->addDays(27)->toDateString()) }}">Next period &rsaquo;</a>
    <span class="pull-right text-muted" style="font-size:12px;">
      @if ($imported_at) {{ $row_count }} punches @if (($hours_source ?? '') === 'erp_time_clock') from the ERP time clock @endif loaded {{ \Carbon::parse($imported_at)->format('M j, g:ia') }} @else No hours loaded yet. Load them from the ERP time clock, or import from Clover below. @endif
    </span>
  </form>
  <form method="POST" action="{{ url('/payroll/load-erp-hours') }}" style="margin-top:10px;">
    {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
    <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-clock-o"></i> Load hours from ERP time clock</button>
    <span class="text-muted" style="font-size:12px;margin-left:6px;">Uses this period's punches from <a href="{{ url('/reports/timecards') . '?start=' . urlencode($start) . '&end=' . urlencode($end) }}" target="_blank">Timecards</a>. Replaces any hours loaded for this period. Clover import below still works.</span>
  </form>
</div></div>

@if ($can_see_rates && !empty($unmatched))
  <div class="alert alert-warning">
    <strong>Needs setup before these are correct:</strong>
    <ul style="margin:6px 0 0 18px;">
      @foreach ($unmatched as $u)
        <li>{{ $u['name'] }} —
          @if ($u['no_rate']) <span style="color:#a94442;">no hourly rate set</span>@endif
          @if ($u['no_rate'] && $u['no_user']) &middot; @endif
          @if ($u['no_user']) <span style="color:#8a6d3b;">not linked to an ERP user (commissions won't attach)</span>@endif
        </li>
      @endforeach
    </ul>
    Set rates &amp; links in <strong>Rates &amp; settings</strong> below.
  </div>
@endif

{{-- ============ COMMISSIONS THIS RUN ============ --}}
@php
    $commPeople = array_values(array_filter($people, function ($p) { return !empty($p['user_id']) && ($p['sales_comm'] + $p['listing_comm'] + $p['party_comm']) > 0.004; }));
    $incUids = array_flip(array_map('intval', $run['include_uids'] ?? []));
@endphp
<div class="box box-solid">
  <div class="box-header"><span class="box-title">Commissions this run</span></div>
  <div class="box-body">
    <form method="POST" action="{{ url('/payroll/commission-mode') }}">
      {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
      <label style="text-transform:none;letter-spacing:0;font-size:13px;color:var(--pos-ink);font-weight:700;">
        <input type="checkbox" name="include_commissions" value="1" {{ !empty($run['include_commissions']) ? 'checked' : '' }}> Pay everyone's commissions this run (month-end payroll)
      </label>
      @if (!empty($commPeople))
        <div style="margin:8px 0 4px;font-size:12.5px;color:var(--pos-ink-2);">Or only these people:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px 16px;">
          @foreach ($commPeople as $cp)
            <label style="text-transform:none;letter-spacing:0;font-size:13px;color:var(--pos-ink);font-weight:500;">
              <input type="checkbox" name="include_uids[]" value="{{ $cp['user_id'] }}" {{ isset($incUids[(int) $cp['user_id']]) ? 'checked' : '' }}>
              {{ $cp['first_name'] }} (${{ $fmt($cp['sales_comm'] + $cp['listing_comm'] + $cp['party_comm']) }})
            </label>
          @endforeach
        </div>
      @endif
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;">Save</button>
      <span style="font-size:12px;margin-left:6px;color:var(--pos-ink-2);">Commissions not ticked stay owed and show as "next run". After payroll, record exactly what was paid on <a href="{{ url('/admin/listing-commissions') }}" target="_blank">Commissions</a>.</span>
    </form>
  </div>
</div>

{{-- ============ SHEET VIEW: SUMMARY (like the payroll Google Sheet) ============ --}}
<style>
.pr-sheet { font-family: Calibri, "Inter Tight", Arial, sans-serif; font-size:13px; border-collapse:collapse; background:#fff; }
.pr-sheet th, .pr-sheet td { border:1px solid #c9c9c9; padding:3px 7px; white-space:nowrap; color:#1F1B16; }
.pr-sheet thead th { background:#000; color:#fff; font-weight:700; text-align:center; vertical-align:middle; white-space:normal; line-height:1.15; }
.pr-sheet td.num { text-align:right; }
.pr-sheet tr.pr-tot td { font-weight:700; border-top:2px solid #000; }
.pr-sheet.pr-log thead th { background:#4A86E8; color:#fff; }
.pr-sheet.pr-log td.pr-name { font-weight:700; }
.pr-sheet .pr-check { color:#8A3A2E; font-size:12px; white-space:normal; max-width:320px; }
.pr-sheet input.pr-note { border:0; width:100%; min-width:180px; font:inherit; background:transparent; }
.pr-sheet-title { font-family: Calibri, Arial, sans-serif; font-weight:700; font-size:15px; margin:4px 0 8px; }
</style>
<div class="box box-solid"><div class="box-body" style="overflow-x:auto;">
  <div class="pr-sheet-title">PAYROLL SUMMARY {{ \Carbon::parse($start)->format('m/d/Y') }} - {{ \Carbon::parse($end)->format('m/d/Y') }}</div>
  <table class="pr-sheet" id="owe-table">
    <thead><tr>
      <th>Listening<br>Parties</th>
      <th class="pr-sortable" onclick="payrollSort('first')" style="cursor:pointer;">SUMMARY</th>
      <th>Pico<br>Hours</th><th>HW<br>Hours</th><th>Total<br>Hours</th>
      <th>Sales<br>Commission</th><th>Listing<br>Commission</th><th>Listening Party<br>Commission</th><th>Total<br>commission owed</th>
      <th>Back pay /<br>adjustments</th>
      @if ($can_see_rates)<th>Total Owed<br>pre tax</th><th>Last Payroll<br>Amount</th>@endif
      <th>Store</th><th>Notes</th><th>Check</th><th></th>
    </tr></thead>
    <tbody>
    @forelse ($people as $p)
      @php $commAmt = $p['sales_comm'] + $p['listing_comm'] + $p['party_comm']; $muted = !$p['comm_included'] ? 'color:#8E8273;' : ''; @endphp
      <tr class="pr-person-row" data-first="{{ strtolower($p['first_name']) }}" data-last="{{ strtolower($p['last_name']) }}">
        <td style="white-space:normal;font-size:12px;">@foreach ($p['party_lines'] as $pl){{ preg_replace('/ (Advance )?Listening Party.*/i', '', $pl['label']) }}@if(!$loop->last), @endif @endforeach</td>
        <td><strong>{{ $p['first_name'] }} {{ mb_substr($p['last_name'], 0, 1) }}</strong>@if (($p['paid_by'] ?? 'qb') === 'zelle') <small>(Zelle)</small>@endif</td>
        <td class="num">{{ $p['one_hours'] ? number_format($p['one_hours'], 2) : '' }}</td>
        <td class="num">{{ $p['two_hours'] ? number_format($p['two_hours'], 2) : '' }}</td>
        <td class="num"><strong>{{ ($p['one_hours'] + $p['two_hours']) ? number_format($p['one_hours'] + $p['two_hours'], 2) : '' }}</strong>@if (($p['co']['one']['ot'] ?? 0) + ($p['co']['two']['ot'] ?? 0) > 0)<br><small>{{ number_format($p['co']['one']['ot'] + $p['co']['two']['ot'], 2) }} OT</small>@endif</td>
        <td class="num" style="{{ $muted }}">{{ $p['sales_comm'] ? '$' . $fmt($p['sales_comm']) : '' }}</td>
        <td class="num" style="{{ $muted }}">{{ $p['listing_comm'] ? '$' . $fmt($p['listing_comm']) : '' }}</td>
        <td class="num" style="{{ $muted }}">{{ $p['party_comm'] ? '$' . $fmt($p['party_comm']) : '' }}</td>
        <td class="num" style="{{ $muted }}">@if ($commAmt > 0.004)<strong>${{ $fmt($commAmt) }}</strong>@if (!$p['comm_included'])<br><small>next run</small>@endif @endif</td>
        <td class="num" style="font-size:12px;">@foreach ($p['adjustments'] as $a)<div>{{ $a['type'] === 'hours_offset' ? $hh($a['hours']) . ' hrs' : '$' . $fmt($a['amount']) }} <small>{{ $a['company'] === 'one' ? 'Pico' : 'HW' }}</small></div>@endforeach</td>
        @if ($can_see_rates)
          <td class="num"><strong>${{ $fmt($p['grand_total']) }}</strong></td>
          <td class="num"><em>{{ isset($p['last_paycheck']) && $p['last_paycheck'] !== null ? '$' . $fmt($p['last_paycheck']) : '' }}</em></td>
        @endif
        <td>{{ trim(($p['one_hours'] > 0 ? 'pico ' : '') . ($p['two_hours'] > 0 ? 'hw' : '')) ?: $p['store'] }}</td>
        <td><input form="notes-form" class="pr-note" name="notes[{{ $p['key'] }}]" value="{{ $notes[$p['key']] ?? '' }}" placeholder=""></td>
        <td class="pr-check">{{ implode('; ', array_merge($p['checks'] ?? [], $p['flags'] ?? [])) }}</td>
        <td>
          <form method="POST" action="{{ url('/payroll/hide') }}" style="display:inline;" onsubmit="return confirm('Hide {{ $p['name'] }} from payroll? (For people who no longer work here.)');">
            {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
            <input type="hidden" name="user_id" value="{{ $p['user_id'] }}"><input type="hidden" name="key" value="{{ $p['key'] }}"><input type="hidden" name="name" value="{{ $p['name'] }}">
            <button type="submit" class="btn btn-xs btn-default" title="Hide (left the company)">Hide</button>
          </form>
        </td>
      </tr>
    @empty
      <tr><td colspan="16" style="text-align:center;padding:20px;">No hours loaded for this period yet. Click "Load hours from ERP time clock" above.</td></tr>
    @endforelse
    </tbody>
    @if (!empty($people))
    <tfoot><tr class="pr-tot">
      <td></td><td>TOTAL</td>
      <td class="num">{{ number_format(array_sum(array_column($people, 'one_hours')), 2) }}</td>
      <td class="num">{{ number_format(array_sum(array_column($people, 'two_hours')), 2) }}</td>
      <td class="num">{{ number_format(array_sum(array_column($people, 'one_hours')) + array_sum(array_column($people, 'two_hours')), 2) }}</td>
      <td class="num">${{ $fmt($totals['sales_comm']) }}</td><td class="num">${{ $fmt($totals['listing_comm']) }}</td><td class="num">${{ $fmt($totals['party_comm']) }}</td>
      <td class="num">${{ $fmt($totals['sales_comm'] + $totals['listing_comm'] + $totals['party_comm']) }}</td>
      <td class="num">${{ $fmt($totals['adj_total']) }}</td>
      @if ($can_see_rates)<td class="num">${{ $fmt($totals['grand_total']) }}</td><td></td>@endif
      <td></td><td></td><td></td><td></td>
    </tr></tfoot>
    @endif
  </table>

  <form id="notes-form" method="POST" action="{{ url('/payroll/save-notes') }}" style="margin-top:10px;">
    {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-save"></i> Save notes</button>
    <a href="{{ url('/admin/listing-commissions') }}" target="_blank" class="btn btn-default btn-sm">Verify commissions</a>
    <a href="{{ url('/payroll?start=' . urlencode($start) . '&end=' . urlencode($end) . '&fresh=1') }}" class="btn btn-default btn-sm" title="Commissions are cached for 10 minutes">Refresh commissions</a>
    <a href="{{ url('/payroll/export.csv?start=' . urlencode($start) . '&end=' . urlencode($end)) }}" class="btn btn-success btn-sm"><i class="fa fa-download"></i> Export for QuickBooks</a>
  </form>
  @if ($can_see_rates)
    <form method="POST" action="{{ url('/payroll/save-run') }}" style="display:inline-block;margin-top:6px;" onsubmit="return confirm('Save this pay run as the last-paycheck reference for next period?');">
      {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
      <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-save"></i> Save pay run</button>
      <span style="font-size:12px;margin-left:6px;">@if($last_run_at)Saved {{ \Carbon::parse($last_run_at)->format('m/d/y g:ia') }}@endif</span>
    </form>
  @endif
</div></div>

{{-- ============ SHEET VIEW: SHIFT LOG (the sheet's bottom blocks) ============ --}}
<div class="box box-solid"><div class="box-body" style="overflow-x:auto;">
  <table class="pr-sheet pr-log">
    <thead><tr><th>Name</th><th>Total Hours</th><th>Clock In Date</th><th>Clock In Time</th><th>Clock Out Date</th><th>Clock Out Time</th><th>Elapsed Hours</th><th>Location</th><th>Comments</th></tr></thead>
    <tbody>
    @foreach ($people as $p)
      @if (!empty($p['log']))
        @foreach ($p['log'] as $lg)
          <tr>
            <td class="pr-name">{{ $loop->first ? $p['first_name'] : '' }}</td>
            <td class="num">@if ($loop->first)<strong>{{ number_format($p['one_hours'] + $p['two_hours'], 2) }}</strong>@endif</td>
            <td class="num">{{ \Carbon::parse($lg['day'])->format('d-M-y') }}</td>
            <td class="num">{{ $lg['in'] ? \Carbon::parse($lg['in'])->format('g:i:s A') : '' }}</td>
            <td class="num">{{ $lg['out'] ? \Carbon::parse($lg['out'])->format('d-M-y') : '' }}</td>
            <td class="num">{{ $lg['out'] ? \Carbon::parse($lg['out'])->format('g:i:s A') : '' }}</td>
            <td class="num">{{ number_format($lg['hours'], 2) }}</td>
            <td>{{ $lg['company'] === 'one' ? 'pico' : 'hw' }}</td>
            <td>{{ $lg['comment'] }}</td>
          </tr>
        @endforeach
        <tr><td colspan="9" style="border-left:0;border-right:0;height:10px;"></td></tr>
      @endif
    @endforeach
    </tbody>
  </table>
  <p style="font-size:12px;margin-top:6px;color:var(--pos-ink-2);">Fix a wrong or missing punch on <a href="{{ url('/reports/timecards') . '?start=' . urlencode($start) . '&end=' . urlencode($end) }}" target="_blank">Timecards</a>, then click "Load hours from ERP time clock" again.</p>
</div></div>

{{-- ============ EVERYTHING ELSE (collapsed) ============ --}}

<details class="pr-more"><summary>Import hours from Clover</summary><div class="pr-body">
  <form method="POST" action="{{ url('/payroll/import-hours') }}" enctype="multipart/form-data">
    {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
    <p class="text-muted" style="margin-bottom:8px;">Paste the Clover timecard export (or upload the CSV). Reads columns like <em>Name, Clock In Date, Clock In Time, Clock Out Date, Clock Out Time, Elapsed, Location</em>. Re-importing replaces this period's hours.</p>
    <textarea name="paste" class="form-control" rows="5" placeholder="Name&#9;Clock In Date&#9;Clock In Time&#9;Clock Out Date&#9;Clock Out Time&#9;Elapsed&#9;Location"></textarea>
    <div style="margin-top:10px;">
      <input type="file" name="file" accept=".csv,text/csv" style="display:inline-block;">
      <button type="submit" class="btn btn-primary btn-sm pull-right">Import hours</button>
    </div>
  </form>
</div></details>

<details class="pr-more" open><summary>What to enter in QuickBooks (one block per company)</summary><div class="pr-body">
  <div style="margin-bottom:10px;">
    <a href="{{ url('/payroll/export.csv?start=' . urlencode($start) . '&end=' . urlencode($end)) }}" class="btn btn-success btn-sm"><i class="fa fa-download"></i> Export CSV</a>
    <span style="margin-left:8px;font-size:12px;color:var(--pos-ink-2);">Columns follow QuickBooks' Run payroll order. Paste the numbers into each company's draft; check each person's Gross matches.</span>
  </div>
  @foreach ($qb as $co => $block)
    <h4 style="font-weight:800;margin:14px 0 6px;">{{ $block['label'] }}</h4>
    @if (empty($block['rows']) && empty($block['zelle']))
      <p style="color:var(--pos-ink-2);">Nothing for this company.</p>
    @else
    <div class="table-responsive">
    <table class="table table-condensed table-bordered" id="qb-table-{{ $co }}">
      <thead><tr><th>Name</th><th class="text-right">Regular hrs</th><th class="text-right">Manager hrs</th><th class="text-right">OT hrs</th><th class="text-right">Listening party</th><th class="text-right">Listing comm</th><th class="text-right">Sales comm</th><th class="text-right">Back pay</th><th class="text-right">Bonus</th><th class="text-right">Other</th>@if ($can_see_rates)<th class="text-right">Gross</th>@endif</tr></thead>
      <tbody>
        @foreach ($block['rows'] as $r)
          @php $mgr = $r['pay_type'] === 'manager'; @endphp
          <tr>
            <td>{{ $r['name'] }}</td>
            <td class="text-right">{{ !$mgr && $r['reg'] ? $hh($r['reg']) : '' }}</td>
            <td class="text-right">{{ $mgr && $r['reg'] ? $hh($r['reg']) : '' }}</td>
            <td class="text-right">{{ $r['ot'] ? $hh($r['ot']) : '' }}</td>
            <td class="text-right">{{ $r['party'] ? $fmt($r['party']) : '' }}</td>
            <td class="text-right">{{ $r['listing'] ? $fmt($r['listing']) : '' }}</td>
            <td class="text-right">{{ $r['sales'] ? $fmt($r['sales']) : '' }}</td>
            <td class="text-right">{{ $r['back_pay'] ? $fmt($r['back_pay']) : '' }}</td>
            <td class="text-right">{{ $r['bonus'] ? $fmt($r['bonus']) : '' }}</td>
            <td class="text-right">{{ $r['other'] ? $fmt($r['other']) : '' }}</td>
            @if ($can_see_rates)<td class="text-right"><strong>{{ $fmt($r['gross']) }}</strong></td>@endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <button type="button" class="btn btn-default btn-xs" onclick="payrollCopyTable('qb-table-{{ $co }}')"><i class="fa fa-copy"></i> Copy {{ $block['label'] }}</button>
    @if (!empty($block['zelle']))
      <div style="margin-top:8px;font-size:12.5px;"><strong>Paid outside QuickBooks (Zelle):</strong>
        @foreach ($block['zelle'] as $z){{ $z['name'] }} {{ $hh($z['reg'] + $z['ot']) }} hrs @if ($can_see_rates) (${{ $fmt($z['gross']) }})@endif @if(!$loop->last); @endif @endforeach
      </div>
    @endif
    @endif
  @endforeach
</div></details>

<details class="pr-more" {{ !empty(array_filter($people, function ($p) { return !empty($p['adjustments']); })) ? 'open' : '' }}><summary>Back pay, bonuses &amp; overpay offsets</summary><div class="pr-body">
  <p style="font-size:12.5px;color:var(--pos-ink-2);margin-bottom:8px;">One-off amounts for this pay run, booked to the company that owes them. To take back an overpay, use <strong>Hours offset</strong> with negative hours (fewer hours on this check, so taxes stay right); a negative back pay or bonus covers any cents left over.</p>
  @php $allAdj = []; foreach ($people as $p) { foreach ($p['adjustments'] as $a) { $allAdj[] = $a + ['who' => $p['name']]; } } @endphp
  @if (!empty($allAdj))
  <table class="table table-condensed table-bordered" style="margin-bottom:12px;">
    <thead><tr><th>Person</th><th>Company</th><th>Type</th><th class="text-right">Amount / hours</th><th>Note</th><th></th></tr></thead>
    <tbody>
      @foreach ($allAdj as $a)
        <tr>
          <td>{{ $a['who'] }}</td>
          <td>{{ $companies[$a['company']] ?? $a['company'] }}</td>
          <td>{{ \App\Http\Controllers\PayrollController::ADJ_TYPES[$a['type']] ?? $a['type'] }}</td>
          <td class="text-right">{{ $a['type'] === 'hours_offset' ? $hh($a['hours']) . ' hrs' : '$' . $fmt($a['amount']) }}</td>
          <td>{{ $a['note'] }}</td>
          <td class="text-right">
            <form method="POST" action="{{ url('/payroll/adjust/delete') }}" style="display:inline;">
              {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}"><input type="hidden" name="id" value="{{ $a['id'] }}">
              <button type="submit" class="btn btn-xs btn-danger">Remove</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @endif
  <form method="POST" action="{{ url('/payroll/adjust') }}" id="adj-form">
    {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}"><input type="hidden" name="name" id="adj-name" value="">
    <div class="row">
      <div class="col-sm-3"><label>Person</label>
        <select name="who" class="form-control input-sm" required onchange="document.getElementById('adj-name').value=this.options[this.selectedIndex].text">
          <option value="">Pick a person</option>
          @foreach ($people as $p)<option value="{{ !empty($p['user_id']) ? 'uid:' . $p['user_id'] : 'key:' . $p['key'] }}">{{ $p['name'] }}</option>@endforeach
          @foreach ($erp_users as $eu)<option value="uid:{{ $eu['id'] }}">{{ $eu['name'] }} (not on this run)</option>@endforeach
        </select>
      </div>
      <div class="col-sm-2"><label>Company</label><select name="company" class="form-control input-sm">@foreach ($companies as $ck => $cl)<option value="{{ $ck }}">{{ $cl }}</option>@endforeach</select></div>
      <div class="col-sm-2"><label>Type</label><select name="type" class="form-control input-sm">@foreach (\App\Http\Controllers\PayrollController::ADJ_TYPES as $tk => $tl)<option value="{{ $tk }}">{{ $tl }}</option>@endforeach</select></div>
      <div class="col-sm-1"><label>Amount $</label><input name="amount" type="number" step="0.01" class="form-control input-sm"></div>
      <div class="col-sm-1"><label>Hours</label><input name="hours" type="number" step="0.01" class="form-control input-sm"></div>
      <div class="col-sm-3"><label>Note (why)</label><input name="note" class="form-control input-sm" placeholder="e.g. 7/1 min wage back pay"></div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px;">Add</button>
  </form>
</div></details>

@php $memoPeople = array_filter($people, function ($p) { return !empty($p['memo']); }); @endphp
<details class="pr-more"><summary>Paycheck memos — commission explanation</summary><div class="pr-body">
  <div style="margin-bottom:10px;">
    <a href="{{ url('/admin/listing-commissions') }}" target="_blank" class="btn btn-default btn-sm"><i class="fa fa-external-link"></i> Verify in Commissions Owed report</a>
    <button type="button" class="btn btn-default btn-sm" onclick="payrollCopyMemos()"><i class="fa fa-copy"></i> Copy memos</button>
  </div>
  <div style="background:var(--pos-accent-soft);border:1px solid var(--pos-accent-deep);border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;">
    <strong>How commission works</strong>
    <ul style="margin:6px 0 0 18px;">
      <li><strong>Sales bonus</strong> — 2% of your register sales above your daily goal, or <strong>4% during peak hours</strong>, added up day by day (since {{ \Carbon::parse($LC::SALES_BONUS_FROM)->format('M j, Y') }}).</li>
      <li><strong>Listing commission</strong> — 2% of the sale price of each <strong>used</strong> item you listed/barcoded that has since sold (listings since {{ \Carbon::parse($LC::DEFAULT_FROM)->format('M j, Y') }}; excludes sealed/new stock, gear, apparel, etc.).</li>
    </ul>
  </div>
  @if (empty($memoPeople))
    <p class="text-muted">No commission owed this period.</p>
  @else
    <div id="memo-list">
    @foreach ($memoPeople as $p)
      <div style="padding:8px 0;border-bottom:1px solid var(--pos-line);">
        <strong>{{ $p['name'] }}</strong>@if ($can_see_rates)<span class="text-muted"> — owes ${{ $fmt($p['sales_comm'] + $p['listing_comm']) }} this period</span>@endif
        <div class="text-muted" style="font-size:13px;margin-top:2px;">{{ $p['memo'] }}</div>
      </div>
    @endforeach
    </div>
  @endif
</div></details>

@php $flagged = array_filter($people, function ($p) { return !empty($p['flags']); }); @endphp
@if (!empty($flagged))
<details class="pr-more"><summary>Late arrivals / late clock-outs ({{ $totals['flags'] }})</summary><div class="pr-body">
  <p class="text-muted" style="margin-bottom:8px;">Compared to the Sling schedule, past a {{ $settings['grace_minutes'] }}-minute grace. Soft warnings — they don't change pay.</p>
  @foreach ($flagged as $p)
    <div style="margin-bottom:6px;"><strong>{{ $p['name'] }}</strong>
      <ul style="margin:2px 0 6px 18px;">@foreach ($p['flags'] as $f)<li>{{ $f }}</li>@endforeach</ul>
    </div>
  @endforeach
</div></details>
@endif

<details class="pr-more"><summary>Freelancers / contractors — add &amp; edit</summary><div class="pr-body">
  <p class="text-muted" style="margin-bottom:8px;">Paid outside QuickBooks payroll. Hourly / per-unit compute automatically; flat is a fixed amount. They also show in the main table above.</p>
  <table class="table table-condensed table-bordered">
    <thead><tr><th>Name</th><th>Model</th><th class="text-right">Amount</th><th>Method</th><th>Paid</th><th></th></tr></thead>
    <tbody>
      @forelse ($freelancers as $f)
        <tr>
          <td><strong>{{ $f['name'] }}</strong></td>
          <td>{{ ucfirst($f['model'] ?? 'flat') }}</td>
          <td class="text-right"><strong>${{ $fmt($f['amount']) }}</strong></td>
          <td>{{ $f['method'] ?? '' }}</td>
          <td>@if (!empty($f['paid']))<span class="label label-success">paid</span>@else<span class="label label-default">unpaid</span>@endif</td>
          <td class="text-right" style="white-space:nowrap;">
            <button type="button" class="btn btn-xs btn-default" onclick='payrollEditFreelancer(@json($f, JSON_HEX_APOS | JSON_HEX_QUOT))'>Edit</button>
            <form method="POST" action="{{ url('/payroll/freelancer/delete') }}" style="display:inline;" onsubmit="return confirm('Remove {{ $f['name'] }}?');">
              {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}"><input type="hidden" name="id" value="{{ $f['id'] }}">
              <button type="submit" class="btn btn-xs btn-danger">Delete</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center text-muted" style="padding:14px;">No freelancers yet. Add one below.</td></tr>
      @endforelse
    </tbody>
  </table>
  <hr>
  <form method="POST" action="{{ url('/payroll/freelancer') }}" id="freelancer-form">
    {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}"><input type="hidden" name="id" id="f-id" value="">
    <div class="row">
      <div class="col-sm-2"><label>Name</label><input name="name" id="f-name" class="form-control input-sm" required></div>
      <div class="col-sm-2"><label>Model</label><select name="model" id="f-model" class="form-control input-sm"><option value="flat">Flat amount</option><option value="hourly">Hourly</option><option value="units">Per unit</option></select></div>
      <div class="col-sm-1"><label>Rate</label><input name="f_rate" id="f-rate" type="number" step="0.01" class="form-control input-sm"></div>
      <div class="col-sm-1"><label>Qty</label><input name="qty" id="f-qty" type="number" step="0.01" class="form-control input-sm"></div>
      <div class="col-sm-2"><label>Flat amount</label><input name="amount" id="f-amount" type="number" step="0.01" class="form-control input-sm"></div>
      <div class="col-sm-2"><label>Method</label><input name="method" id="f-method" class="form-control input-sm" placeholder="PayPal"></div>
      <div class="col-sm-2"><label>Note</label><input name="note" id="f-note" class="form-control input-sm"></div>
    </div>
    <div style="margin-top:8px;">
      <label style="font-weight:normal;text-transform:none;letter-spacing:0;"><input type="checkbox" name="paid" id="f-paid" value="1"> Paid</label>
      <button type="submit" class="btn btn-primary btn-sm pull-right">Save freelancer</button>
      <button type="button" class="btn btn-default btn-sm pull-right" style="margin-right:6px;" onclick="payrollResetFreelancer()">Clear</button>
    </div>
  </form>
</div></details>

@if ($can_see_rates)
<details class="pr-more"><summary>Rates &amp; settings</summary><div class="pr-body">
  <form method="POST" action="{{ url('/payroll/save-rates') }}">
    {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}">
    <div class="row" style="margin-bottom:12px;">
      <div class="col-sm-3"><label>Pay OT after (hours/day)</label><input name="daily_ot_after" type="number" step="0.25" value="{{ $settings['daily_ot_after'] }}" class="form-control input-sm"></div>
      <div class="col-sm-3"><label>OT multiplier</label><input name="ot_multiplier" type="number" step="0.1" value="{{ $settings['ot_multiplier'] }}" class="form-control input-sm"></div>
      <div class="col-sm-3"><label>Late grace (min)</label><input name="grace_minutes" type="number" step="1" value="{{ $settings['grace_minutes'] }}" class="form-control input-sm"></div>
    </div>
    <p class="text-muted" style="margin-bottom:6px;">Hourly rate per person. Leave ERP user blank to auto-match by first name; set it only to fix an ambiguous name.</p>
    <div class="table-responsive">
    <table class="table table-condensed table-bordered">
      <thead><tr><th>Person</th><th style="width:140px;">Hourly rate</th><th style="width:120px;">Store</th><th style="width:170px;">Company</th><th style="width:120px;">QB pay type</th><th style="width:110px;">Paid by</th><th style="width:220px;">Linked ERP user (for commissions)</th></tr></thead>
      <tbody>
        @foreach ($people as $p)
          @if ($p['has_hours'])
          <tr>
            <td><strong>{{ $p['name'] }}</strong></td>
            <td><div class="input-group input-group-sm"><span class="input-group-addon">$</span><input name="rate[{{ $p['key'] }}]" type="number" step="0.01" value="{{ $p['rate'] ?: '' }}" class="form-control"></div></td>
            <td><input name="store[{{ $p['key'] }}]" value="{{ $p['store'] }}" class="form-control input-sm"></td>
            @php $rc = $p['rate_cfg'] ?? []; @endphp
            <td><select name="company[{{ $p['key'] }}]" class="form-control input-sm"><option value="auto">By store worked</option>@foreach ($companies as $ck => $cl)<option value="{{ $ck }}" {{ ($rc['company'] ?? 'auto') === $ck ? 'selected' : '' }}>Always {{ $cl }}</option>@endforeach</select></td>
            <td><select name="pay_type[{{ $p['key'] }}]" class="form-control input-sm"><option value="regular">Regular</option><option value="manager" {{ ($p['pay_type'] ?? '') === 'manager' ? 'selected' : '' }}>Manager</option></select></td>
            <td><select name="paid_by[{{ $p['key'] }}]" class="form-control input-sm"><option value="qb">QuickBooks</option><option value="zelle" {{ ($p['paid_by'] ?? '') === 'zelle' ? 'selected' : '' }}>Zelle</option></select></td>
            <td>
              <select name="user_id[{{ $p['key'] }}]" class="form-control input-sm">
                <option value="">Auto-match by name{{ $p['user_id'] ? '' : ' (unmatched)' }}</option>
                @foreach ($erp_users as $eu)
                  <option value="{{ $eu['id'] }}" {{ (int) $p['user_id'] === (int) $eu['id'] ? 'selected' : '' }}>{{ $eu['name'] }} (#{{ $eu['id'] }})</option>
                @endforeach
              </select>
            </td>
          </tr>
          @endif
        @endforeach
      </tbody>
    </table>
    </div>
    <button type="submit" class="btn btn-primary btn-sm pull-right">Save rates &amp; settings</button>
    <div style="clear:both;"></div>
  </form>
</div></details>

@if (!empty($hidden))
<details class="pr-more"><summary>Hidden people (left the company)</summary><div class="pr-body">
  <p class="text-muted" style="margin-bottom:8px;">Excluded from every pay run. Un-hide if someone returns.</p>
  <table class="table table-condensed table-bordered"><tbody>
    @foreach ($hidden as $h)
      <tr>
        <td><strong>{{ $h['name'] ?? ($h['key'] ?? ('User #' . ($h['user_id'] ?? '?'))) }}</strong></td>
        <td class="text-right" style="width:120px;">
          <form method="POST" action="{{ url('/payroll/unhide') }}" style="display:inline;">
            {{ csrf_field() }}<input type="hidden" name="start" value="{{ $start }}"><input type="hidden" name="end" value="{{ $end }}"><input type="hidden" name="id" value="{{ $h['id'] ?? '' }}">
            <button type="submit" class="btn btn-xs btn-default">Un-hide</button>
          </form>
        </td>
      </tr>
    @endforeach
  </tbody></table>
</div></details>
@endif
@endif

</section>
</div>

<script>
function payrollCopyTable(id) {
  var t = document.getElementById(id); if (!t) { return; }
  var lines = [];
  t.querySelectorAll('tr').forEach(function (tr) {
    var cells = []; tr.querySelectorAll('th,td').forEach(function (c) { cells.push(c.innerText.trim()); });
    lines.push(cells.join('\t'));
  });
  copyText(lines.join('\n'));
}
function payrollCopyMemos() {
  var el = document.getElementById('memo-list'); if (!el) { return; }
  copyText(el.innerText.trim());
}
function copyText(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function () { alert('Copied.'); });
  } else {
    var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
    ta.select(); try { document.execCommand('copy'); alert('Copied.'); } catch (e) {} document.body.removeChild(ta);
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
  var form = document.getElementById('freelancer-form');
  var det = form.closest('details'); if (det) { det.open = true; }
  form.scrollIntoView({behavior:'smooth', block:'center'});
}
function payrollResetFreelancer() {
  document.getElementById('freelancer-form').reset();
  document.getElementById('f-id').value = '';
}
var payrollSortDir = {};
function payrollSort(col) {
  var tbody = document.querySelector('#owe-table tbody'); if (!tbody) { return; }
  var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.pr-person-row'));
  if (!rows.length) { return; }
  var dir = payrollSortDir[col] === 1 ? -1 : 1; payrollSortDir = {}; payrollSortDir[col] = dir;
  rows.sort(function (a, b) {
    var av = (a.getAttribute('data-' + col) || ''), bv = (b.getAttribute('data-' + col) || '');
    if (av === bv) { return 0; }
    if (av === '') { return 1; } if (bv === '') { return -1; }  // blanks last
    return av.localeCompare(bv) * dir;
  });
  var anchor = tbody.querySelector('tr.pr-freelancer-sep');
  rows.forEach(function (r) { tbody.insertBefore(r, anchor); });
  document.querySelectorAll('#owe-table th.pr-sortable .pr-arrow').forEach(function (s) { s.textContent = '⇅'; });
  var th = document.querySelector('#owe-table th.pr-sortable[onclick*="' + col + '"] .pr-arrow');
  if (th) { th.textContent = dir === 1 ? '▲' : '▼'; }
}
(function () {
  var btn = document.querySelector('.pr-pin-btn[data-pin-url]'); if (!btn) { return; }
  var url = btn.getAttribute('data-pin-url'), label = btn.getAttribute('data-pin-label');
  function paint(on) {
    btn.classList.toggle('is-on', on);
    var ic = btn.querySelector('.fa'); if (ic) { ic.className = 'fa ' + (on ? 'fa-star' : 'fa-star-o'); }
    var t = btn.querySelector('.pin-text'); if (t) { t.textContent = on ? 'Pinned' : 'Star to sidebar'; }
  }
  btn.addEventListener('click', function () {
    if (window.NivessaSidebarFav && window.NivessaSidebarFav.toggle) {
      var willBeOn = !window.NivessaSidebarFav.isPinned(url);
      window.NivessaSidebarFav.toggle(url, label); paint(willBeOn); return;
    }
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var body = new FormData(); body.append('url', url); body.append('label', label);
    fetch('{{ url('/sidebar-favorites/toggle') }}', { method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : '', 'X-Requested-With': 'XMLHttpRequest' }, body: body
    }).then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) { paint(d.starred); } }).catch(function () {});
  });
})();
</script>
@endsection
