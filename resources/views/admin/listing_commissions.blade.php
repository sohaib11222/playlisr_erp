@extends('layouts.app')
@section('title', 'Commissions')

@section('content')
@php
    // "Pin to my sidebar" — saves this page to the current user's personal
    // Favorites group (top of the left menu). Per-account; nobody else sees it.
    // See SidebarFavoriteController. (Fatteen pins this so payouts are one click.)
    $pinUrl     = url('/admin/listing-commissions');
    $pinLabel   = 'Commissions Owed';
    $pinAlready = \App\Http\Controllers\SidebarFavoriteController::isPinned(
        session()->get('user.business_id'),
        session()->get('user.id'),
        $pinUrl
    );
@endphp
<section class="content-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
    <div>
    <h1>Commissions Owed</h1>
    <p class="text-muted">
        One row per person. For payroll you only need the <strong>Pay now</strong> column — it's their
        unpaid listing commission ({{ rtrim(rtrim(number_format($rate_pct, 2), '0'), '.') }}% of items they
        listed that have since sold) <strong>plus</strong> their unpaid sales bonus, added up since the
        program started and minus everything you've already paid. Pay that amount, then hit
        <strong>Mark paid</strong> and it drops off so you can never pay it twice. Everything else is just
        showing the math — tick <strong>Show full breakdown</strong> if you want to see it.
    </p>
    </div>
    <button type="button" class="lc-pin-btn {{ $pinAlready ? 'is-on' : '' }}"
            data-pin-url="{{ $pinUrl }}" data-pin-label="{{ $pinLabel }}"
            title="{{ $pinAlready ? 'Pinned to your sidebar' : 'Pin this page to your sidebar' }}">
        <i class="fa {{ $pinAlready ? 'fa-star' : 'fa-star-o' }}"></i>
        <span class="pin-text">{{ $pinAlready ? 'Pinned' : 'Pin to my sidebar' }}</span>
    </button>
</section>

<style>
.lc-pin-btn {
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px;
    white-space: nowrap; cursor: pointer; font: inherit; font-size: 13px;
    font-weight: 700; color: #6b5a00; background: #FFF7CC;
    border: 1px solid #E6CE5A; border-radius: 999px; padding: 8px 14px; line-height: 1;
    transition: background .12s ease, box-shadow .12s ease;
}
.lc-pin-btn:hover { background: #FFF2B3; }
.lc-pin-btn.is-on { background: #FFF2B3; box-shadow: inset 0 0 0 1px #E6CE5A; }
.lc-pin-btn .fa { color: #C99A12; }
.lc-detail { display: none; }
#lc-people.show-detail .lc-detail { display: table-cell; }
</style>
<script>
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function () {
        var btn = document.querySelector('.lc-pin-btn[data-pin-url]');
        if (!btn) { return; }
        var url = btn.getAttribute('data-pin-url');
        var label = btn.getAttribute('data-pin-label');

        function paint(on) {
            btn.classList.toggle('is-on', on);
            var ic = btn.querySelector('.fa');
            if (ic) { ic.className = 'fa ' + (on ? 'fa-star' : 'fa-star-o'); }
            var t = btn.querySelector('.pin-text');
            if (t) { t.textContent = on ? 'Pinned' : 'Pin to my sidebar'; }
            btn.title = on ? 'Pinned to your sidebar' : 'Pin this page to your sidebar';
        }

        btn.addEventListener('click', function () {
            // Reuse the sidebar helper so the left-menu Favorites group updates live.
            if (window.NivessaSidebarFav && window.NivessaSidebarFav.toggle) {
                var willBeOn = !window.NivessaSidebarFav.isPinned(url);
                window.NivessaSidebarFav.toggle(url, label);
                paint(willBeOn);
                return;
            }
            // Fallback: post directly if the sidebar script isn't present.
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var body = new FormData();
            body.append('url', url);
            body.append('label', label);
            fetch('{{ url('/sidebar-favorites/toggle') }}', {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : '',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.ok) { paint(d.starred); }
            }).catch(function () {});
        });
    });
})();
</script>

<section class="content">

@if (session('status'))
    <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <div class="form-inline">
                    <span><strong>Total owed now</strong> = unpaid listing commission + unpaid sales commission, across everyone.</span>
                    <span class="pull-right" style="font-size:16px;">
                        Total commission <strong>${{ number_format($total_commission, 2) }}</strong> &nbsp;·&nbsp; Paid <strong>${{ number_format($total_paid_all, 2) }}</strong> &nbsp;·&nbsp; Total owed now <strong>${{ number_format($total_owed_now, 2) }}</strong>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'Spot-check: one day\'s sales bonus (not payroll)'])
            <p style="margin:0 0 12px; padding:9px 12px; background:#FFF7CC; border:1px solid #E6CE5A; border-radius:6px; color:#6b5a00;">
                <strong>This is just a lookup.</strong> Changing the date here does <strong>not</strong> change
                what anyone gets paid — it only shows one single day's sales bonus for one person. To actually
                pay people, use the <strong>Pay now</strong> column in the table below.
            </p>
            <details>
                <summary style="cursor:pointer; font-weight:600; color:#6b5a00; margin-bottom:10px;">Open the day lookup</summary>
            <form method="GET" action="{{ url('/admin/listing-commissions') }}" class="form-inline" style="margin-bottom:12px;">
                <label style="margin-right:6px;">Day</label>
                <input type="date" name="day" value="{{ $bonus_day }}" class="form-control input-sm" max="{{ \Carbon::now()->toDateString() }}" style="margin-right:12px;">
                <label style="margin-right:6px;">Person</label>
                <input type="text" name="person" value="{{ $bonus_person }}" placeholder="e.g. Andy" class="form-control input-sm" style="margin-right:12px;">
                <button type="submit" class="btn btn-primary btn-sm">Show</button>
                @if ($bonus_person !== '' || $bonus_day !== \Carbon::now()->toDateString())
                    <a href="{{ url('/admin/listing-commissions') }}" class="btn btn-default btn-sm" style="margin-left:6px;">Reset to today</a>
                @endif
            </form>
            <p class="text-muted" style="margin-bottom:10px;">
                Sales-goal bonus earned on <strong>{{ \Carbon::parse($bonus_day)->format('D, M j, Y') }}</strong>{!! $bonus_person !== '' ? ', filtered to "<strong>' . e($bonus_person) . '</strong>"' : '' !!}.
                Same math as the Employee Leaderboard, scoped to that single day.
            </p>
            @if ($day_rows->isEmpty())
                <p class="text-muted">No sales bonus for that day{{ $bonus_person !== '' ? ' matching "' . $bonus_person . '"' : '' }}.</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th style="text-align:right;" title="Sales rung that day (Whatnot excluded)">Sales achieved</th>
                            <th style="text-align:right;" title="Sales target for that day">Sales goal</th>
                            <th style="text-align:right;" title="Sales-goal bonus earned that day">Bonus</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($day_rows as $r)
                            <tr>
                                <td><a href="{{ url('/my-earnings') }}?user_id={{ $r->user_id }}" target="_blank">{{ $r->name }}</a></td>
                                <td style="text-align:right;">@if($r->achieved > 0)${{ number_format($r->achieved, 0) }}@else <span class="text-muted">-</span>@endif</td>
                                <td style="text-align:right;"><span class="text-muted">@if($r->goal > 0)${{ number_format($r->goal, 0) }}@else - @endif</span></td>
                                <td style="text-align:right;">@if($r->bonus > 0)<strong>${{ number_format($r->bonus, 2) }}</strong>@else <span class="text-muted">$0.00</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            </details>
        @endcomponent
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'By person — what to pay'])
            <label style="display:inline-flex; align-items:center; gap:7px; cursor:pointer; margin-bottom:10px; font-weight:600; color:#5A5045;">
                <input type="checkbox" id="lc-detail-toggle"> Show full breakdown (earned, paid, listings, goals)
            </label>
            @if ($people->isEmpty())
                <p class="text-muted">No commission to show yet.</p>
            @else
                <table class="table table-striped" id="lc-people">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th class="lc-detail" style="text-align:right;" title="Listing earned + sales earned (total commission earned)">Total commission</th>
                            <th class="lc-detail" style="text-align:right;" title="Items this person has listed since {{ $from }}"># Listings</th>
                            <th class="lc-detail" style="text-align:right;" title="Listing commission earned since {{ $from }}">Listing earned</th>
                            <th class="lc-detail" style="text-align:right;" title="Listing commission already paid out">Listing paid</th>
                            <th class="lc-detail" style="text-align:right; border-left:1px solid #ddd;" title="Actual sales rung by this person since {{ $sales_bonus_from }} (Whatnot excluded)">Sales achieved</th>
                            <th class="lc-detail" style="text-align:right;" title="Sales target for this person since {{ $sales_bonus_from }}">Sales goal</th>
                            <th class="lc-detail" style="text-align:right;" title="Sales-goal bonus earned since {{ $sales_bonus_from }} (same as the leaderboard)">Sales earned</th>
                            <th class="lc-detail" style="text-align:right;" title="Sales commission already paid out">Sales paid</th>
                            <th style="text-align:right; background:#FFF3C4; border-left:2px solid #E6CE5A;" title="Unpaid sales bonus">Sales owed</th>
                            <th style="text-align:right; background:#FFF3C4;" title="Unpaid listing commission">Listing owed</th>
                            <th style="text-align:right; background:#FFE9A8; border-left:2px solid #E6CE5A; font-size:15px;" title="Unpaid listing + unpaid sales = what you hand this person this run">Pay now</th>
                            <th style="min-width:240px;" title="What this payout is for — for the pay stub">What it's for</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $p)
                            <tr>
                                <td><a href="{{ url('/my-earnings') }}?user_id={{ $p->user_id }}" title="See {{ $p->name }}'s full earnings page (what they see)">{{ $p->name }}</a></td>
                                <td class="lc-detail" style="text-align:right;">@if($p->total_comm > 0)${{ number_format($p->total_comm, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right;">@if($p->listed_count > 0)<a href="{{ url('/my-earnings/items') }}?user_id={{ $p->user_id }}">{{ number_format($p->listed_count) }}</a>@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right;">@if($p->earned > 0)<a href="{{ url('/my-earnings/items') }}?user_id={{ $p->user_id }}" target="_blank" title="Breakdown: items {{ $p->name }} listed that sold">${{ number_format($p->earned, 2) }}</a>@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right;">@if($p->paid > 0)<span class="text-muted">${{ number_format($p->paid, 2) }}</span>@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right; border-left:1px solid #ddd;">@if($p->sales_achieved > 0)${{ number_format($p->sales_achieved, 0) }}@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right;">@if($p->sales_goal > 0)<span class="text-muted">${{ number_format($p->sales_goal, 0) }}</span>@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right;">@if($p->sales_earned > 0)<a href="{{ url('/my-earnings') }}?user_id={{ $p->user_id }}" target="_blank" title="Breakdown: {{ $p->name }}'s day-by-day sales vs goal">${{ number_format($p->sales_earned, 2) }}</a>@else <span class="text-muted">—</span>@endif</td>
                                <td class="lc-detail" style="text-align:right;">@if($p->sales_paid > 0)<span class="text-muted">${{ number_format($p->sales_paid, 2) }}</span>@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; background:#FFF3C4; border-left:2px solid #E6CE5A;">@if($p->sales_owed > 0)${{ number_format($p->sales_owed, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; background:#FFF3C4;">@if($p->owed > 0)${{ number_format($p->owed, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; background:#FFE9A8; border-left:2px solid #E6CE5A;">@if($p->total_owed_now > 0)<strong style="font-size:15px;">${{ number_format($p->total_owed_now, 2) }}</strong>@else <span class="text-muted">—</span>@endif</td>
                                <td style="font-size:12px; color:#5A5045;">@if($p->payroll_memo){{ $p->payroll_memo }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; white-space:nowrap;">
                                    @if($p->total_owed_now > 0)
                                    <form method="POST" action="{{ url('/admin/listing-commissions/mark-all-paid') }}"
                                          onsubmit="return confirm('Mark all commission paid for {{ $p->name }} (${{ number_format($p->total_owed_now, 2) }})?');"
                                          style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $p->user_id }}">
                                        <input type="hidden" name="from" value="{{ $from }}">
                                        <button type="submit" class="btn btn-success btn-xs">Mark paid</button>
                                    </form>
                                    @else
                                        <span class="text-muted">paid up</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endcomponent
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'Listing commission — paid history'])
            @if ($history->isEmpty())
                <p class="text-muted">No payouts recorded yet. Total paid: $0.00</p>
            @else
                <p class="text-muted">Total paid to date: <strong>${{ number_format($total_paid, 2) }}</strong></p>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Paid on</th>
                            <th>Person</th>
                            <th style="text-align:right;">Items</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Covered</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $h)
                            <tr>
                                <td>{{ $h['marked_at'] ?? '—' }}</td>
                                <td>{{ $h['name'] ?? ('User #' . ($h['user_id'] ?? '?')) }}</td>
                                <td style="text-align:right;">{{ number_format($h['count'] ?? 0) }}</td>
                                <td style="text-align:right;">${{ number_format($h['amount'] ?? 0, 2) }}</td>
                                <td>{{ $h['from_date'] ?? '?' }} → {{ $h['to_date'] ?? '?' }}</td>
                                <td style="text-align:right;">
                                    <form method="POST" action="{{ url('/admin/listing-commissions/undo-payout') }}"
                                          onsubmit="return confirm('Undo this payout? Those listings will be owed again.');"
                                          style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $h['id'] ?? '' }}">
                                        <input type="hidden" name="from" value="{{ $from }}">
                                        <button type="submit" class="btn btn-warning btn-xs">Undo</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endcomponent
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'Sales commission — paid history'])
            @if ($sales_history->isEmpty())
                <p class="text-muted">No sales commission payouts recorded yet. Total paid: $0.00</p>
            @else
                <p class="text-muted">Total paid to date: <strong>${{ number_format($total_sales_paid_all, 2) }}</strong></p>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Paid on</th>
                            <th>Person</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Covered</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales_history as $h)
                            <tr>
                                <td>{{ $h['marked_at'] ?? '—' }}</td>
                                <td>{{ $h['name'] ?? ('User #' . ($h['user_id'] ?? '?')) }}</td>
                                <td style="text-align:right;">${{ number_format($h['amount'] ?? 0, 2) }}</td>
                                <td>{{ $h['from_date'] ?? '?' }} → {{ $h['to_date'] ?? '?' }}</td>
                                <td style="text-align:right;">
                                    <form method="POST" action="{{ url('/admin/listing-commissions/undo-sales-payout') }}"
                                          onsubmit="return confirm('Undo this sales payout? That commission will be owed again.');"
                                          style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $h['id'] ?? '' }}">
                                        <button type="submit" class="btn btn-warning btn-xs">Undo</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endcomponent
    </div>
</div>

<script>
(function () {
    var table = document.getElementById('lc-people');
    if (!table || !table.tHead) { return; }
    var ths = table.tHead.rows[0].cells;

    function val(cell) {
        var t = (cell.textContent || '').trim();
        if (t === '' || t === '—') { return { n: null, s: '' }; }
        var num = parseFloat(t.replace(/[^0-9.\-]/g, ''));
        return { n: isNaN(num) ? null : num, s: t.toLowerCase() };
    }
    function clearArrows() {
        for (var k = 0; k < ths.length; k++) {
            ths[k].textContent = ths[k].textContent.replace(/[ ▲▼]+$/, '');
        }
    }
    for (var i = 0; i < ths.length; i++) {
        (function (idx) {
            var th = ths[idx];
            if ((th.textContent || '').trim() === '') { return; } // skip the actions column
            th.style.cursor = 'pointer';
            th.title = (th.title ? th.title + ' — ' : '') + 'click to sort';
            var dir = 0;
            th.addEventListener('click', function () {
                dir = dir === 1 ? -1 : 1;
                var tb = table.tBodies[0];
                var rows = Array.prototype.slice.call(tb.rows);
                rows.sort(function (a, b) {
                    var va = val(a.cells[idx]), vb = val(b.cells[idx]);
                    if (va.n !== null && vb.n !== null) { return (va.n - vb.n) * dir; }
                    if (va.n !== null) { return -1; }   // numbers ahead of blanks
                    if (vb.n !== null) { return 1; }
                    return va.s.localeCompare(vb.s) * dir;
                });
                rows.forEach(function (r) { tb.appendChild(r); });
                clearArrows();
                th.textContent = th.textContent.replace(/[ ▲▼]+$/, '') + (dir === 1 ? ' ▲' : ' ▼');
            });
        })(i);
    }
})();
</script>

<script>
(function () {
    var t = document.getElementById('lc-detail-toggle');
    var tbl = document.getElementById('lc-people');
    if (t && tbl) {
        t.addEventListener('change', function () {
            tbl.classList.toggle('show-detail', t.checked);
        });
    }
})();
</script>

</section>
@endsection
