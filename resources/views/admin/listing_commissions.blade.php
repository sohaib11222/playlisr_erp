@extends('layouts.app')
@section('title', 'Listing Commissions')

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
    <h1>Listing Commissions Owed</h1>
    <p class="text-muted">
        What we owe each person for items they listed that have <strong>sold</strong>.
        Commission = <strong>{{ rtrim(rtrim(number_format($rate_pct, 2), '0'), '.') }}%</strong>
        of the actual sale price of each item they listed/barcoded on/after the start
        date that has since sold and hasn't been paid for yet. These are the same
        numbers each person sees as earned commission on the Employee Leaderboard.
        Click <strong>Mark paid</strong> once you've paid someone — those sales drop
        off the owed list.
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
                    <span>Since <strong>{{ \Carbon::parse($from)->format('M j, Y') }}</strong> (program start). <strong>Owed</strong> = everything unpaid — the exact number each employee sees on My Earnings. <strong>Paid</strong> = total you've actually paid them. Earned = Paid + Owed.</span>
                    <span class="pull-right" style="font-size:16px;">
                        Earned <strong>${{ number_format($total_earned, 2) }}</strong> &nbsp;·&nbsp; Paid <strong>${{ number_format($total_paid_window, 2) }}</strong> &nbsp;·&nbsp; Owed <strong>${{ number_format($total_owed, 2) }}</strong>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'By person (listed since ' . $from . ')'])
            @if ($people->isEmpty())
                <p class="text-muted">No listing commission for items listed since {{ $from }} that have sold.</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th style="text-align:right;">Items listed</th>
                            <th style="text-align:right;">Listed value</th>
                            <th style="text-align:right;">Items sold</th>
                            <th style="text-align:right;">Sale total</th>
                            <th style="text-align:right;">Earned</th>
                            <th style="text-align:right;">Paid</th>
                            <th style="text-align:right;">Owed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $p)
                            <tr>
                                <td><a href="{{ url('/my-earnings') }}?user_id={{ $p->user_id }}" title="See {{ $p->name }}'s full earnings page (what they see)">{{ $p->name }}</a></td>
                                <td style="text-align:right;"><a href="{{ url('/my-earnings/items') }}?user_id={{ $p->user_id }}">{{ number_format($p->listed_count) }}</a></td>
                                <td style="text-align:right;">${{ number_format($p->listed_value, 2) }}</td>
                                <td style="text-align:right;">{{ number_format($p->sold_count) }}</td>
                                <td style="text-align:right;">${{ number_format($p->sale_total, 2) }}</td>
                                <td style="text-align:right;">${{ number_format($p->earned, 2) }}</td>
                                <td style="text-align:right;">${{ number_format($p->paid, 2) }}</td>
                                <td style="text-align:right;"><strong>${{ number_format($p->owed, 2) }}</strong></td>
                                <td style="text-align:right;">
                                    @if($p->owed > 0)
                                    <form method="POST" action="{{ url('/admin/listing-commissions/mark-paid') }}"
                                          onsubmit="return confirm('Mark {{ $p->count }} sold item(s) for {{ $p->name }} as paid (${{ number_format($p->owed, 2) }})?');"
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
        @component('components.widget', ['title' => 'Paid history'])
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

</section>
@endsection
