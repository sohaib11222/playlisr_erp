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
        Both commission types per person, in one place.
        <strong>Listing</strong> = <strong>{{ rtrim(rtrim(number_format($rate_pct, 2), '0'), '.') }}%</strong>
        of the sale price of each item they listed/barcoded (since {{ \Carbon::parse($from)->format('M j, Y') }})
        that has since sold and isn't paid yet.
        <strong>Sales</strong> = the sales-goal bonus they've earned since {{ \Carbon::parse($sales_bonus_from)->format('M j, Y') }}
        (same number as the Employee Leaderboard).
        Click <strong>Mark paid</strong> once you've paid someone's listing commission — those sales drop
        off the owed list. The sales bonus is paid out manually (no ledger yet).
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
        @component('components.widget', ['title' => 'By person — listing + sales commission'])
            @if ($people->isEmpty())
                <p class="text-muted">No commission to show yet.</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th style="text-align:right;" title="Items this person has listed since {{ $from }}"># Listings</th>
                            <th style="text-align:right;" title="Listing commission earned since {{ $from }}">Listing earned</th>
                            <th style="text-align:right;" title="Listing commission already paid out">Listing paid</th>
                            <th style="text-align:right; border-left:1px solid #ddd;" title="Sales-goal bonus earned since {{ $sales_bonus_from }} (same as the leaderboard)">Sales earned</th>
                            <th style="text-align:right;" title="Sales commission already paid out">Sales paid</th>
                            <th style="text-align:right; background:#FFF3C4; border-left:2px solid #E6CE5A;" title="Listing commission still owed">Listing owed</th>
                            <th style="text-align:right; background:#FFF3C4;" title="Sales commission still owed">Sales owed</th>
                            <th style="text-align:right; background:#FFF3C4;" title="Listing earned + sales earned">Total commission</th>
                            <th style="text-align:right;" title="Unpaid listing + unpaid sales = what you owe this person now">Total owed now</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $p)
                            <tr>
                                <td><a href="{{ url('/my-earnings') }}?user_id={{ $p->user_id }}" title="See {{ $p->name }}'s full earnings page (what they see)">{{ $p->name }}</a></td>
                                <td style="text-align:right;">@if($p->listed_count > 0)<a href="{{ url('/my-earnings/items') }}?user_id={{ $p->user_id }}">{{ number_format($p->listed_count) }}</a>@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right;">@if($p->earned > 0)${{ number_format($p->earned, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right;">@if($p->paid > 0)<span class="text-muted">${{ number_format($p->paid, 2) }}</span>@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; border-left:1px solid #ddd;">@if($p->sales_earned > 0)${{ number_format($p->sales_earned, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right;">@if($p->sales_paid > 0)<span class="text-muted">${{ number_format($p->sales_paid, 2) }}</span>@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; background:#FFF3C4; border-left:2px solid #E6CE5A;">@if($p->owed > 0)${{ number_format($p->owed, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; background:#FFF3C4;">@if($p->sales_owed > 0)${{ number_format($p->sales_owed, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; background:#FFF3C4;">@if($p->total_comm > 0)${{ number_format($p->total_comm, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right;">@if($p->total_owed_now > 0)<strong>${{ number_format($p->total_owed_now, 2) }}</strong>@else <span class="text-muted">—</span>@endif</td>
                                <td style="text-align:right; white-space:nowrap;">
                                    @if($p->owed > 0)
                                    <form method="POST" action="{{ url('/admin/listing-commissions/mark-paid') }}"
                                          onsubmit="return confirm('Mark {{ $p->count }} sold item(s) for {{ $p->name }} listing-paid (${{ number_format($p->owed, 2) }})?');"
                                          style="margin:0 0 3px;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $p->user_id }}">
                                        <input type="hidden" name="from" value="{{ $from }}">
                                        <button type="submit" class="btn btn-success btn-xs">Mark listing paid</button>
                                    </form>
                                    @endif
                                    @if($p->sales_owed > 0)
                                    <form method="POST" action="{{ url('/admin/listing-commissions/mark-sales-paid') }}"
                                          onsubmit="return confirm('Mark {{ $p->name }} sales commission paid (${{ number_format($p->sales_owed, 2) }})?');"
                                          style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $p->user_id }}">
                                        <button type="submit" class="btn btn-primary btn-xs">Mark sales paid</button>
                                    </form>
                                    @endif
                                    @if($p->owed <= 0 && $p->sales_owed <= 0)
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

</section>
@endsection
