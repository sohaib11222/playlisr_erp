@extends('layouts.app')
@section('title', 'Buy/Sell/Trade Searches')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\SearchInsightsController::class;
  $rankBg = ['#fef9c3', '#f3f4f6', '#ffedd5'];
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Buy/Sell/Trade Searches</h1>
      <p class="sub">What customers type into the "What do you want to sell?" search on nivessa.com/buy-sell-trade. Repeated searches with no matching category page point to demand we don't serve yet. Manage the suggestions on <a href="{{ route('wa.content.bstOfferItems') }}">BST Offer Items</a>.</p>
    </div>
    <div class="wc-actions"><span class="wc-muted">Loaded {{ $loadedAt }}.</span><a class="wc-btn" href="{{ request()->fullUrl() }}">Refresh</a></div>
  </div>

  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Top queries on this page</h2><div class="wc-muted">Counted from the {{ count($rows) }} searches loaded below. Page through to see more.</div></div></div>
    @if(count($top) === 0)
      <div class="wc-empty">No queries yet.</div>
    @else
      <table class="wc-tbl">
        <thead><tr><th style="width:60px;">#</th><th>Query</th><th class="num">Count</th><th>Last searched</th></tr></thead>
        <tbody>
        @foreach($top as $i => $t)
          <tr style="{{ isset($rankBg[$i]) ? 'background:' . $rankBg[$i] . ';' : '' }}{{ $i === 0 ? 'font-weight:700;' : '' }}">
            <td>{{ $i + 1 }}</td>
            <td>{{ $t['query'] }}</td>
            <td class="num">{{ $t['count'] }}</td>
            <td class="wc-muted">{{ $t['last'] ? $H::fmtDate('@' . $t['last'], 'M j, Y g:i A') : '' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>All searches</h2><div class="wc-muted">Newest first. The referrer shows which page the customer came from.</div></div></div>
    @if(count($rows) === 0)
      <div class="wc-empty">No searches recorded yet.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>Query</th><th>Source</th><th>Referrer</th><th>User agent</th><th>Time</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
          <tr>
            <td style="font-weight:600;">{{ $r['query'] ?? '' }}</td>
            <td>@if(!empty($r['source']))<span class="wc-pill purple">{{ $r['source'] }}</span>@endif</td>
            <td class="wc-muted" style="max-width:260px;">{{ $r['referrer'] ?? '' }}</td>
            <td class="wc-muted" style="max-width:260px;" title="{{ $r['userAgent'] ?? '' }}">{{ \Illuminate\Support\Str::limit((string) ($r['userAgent'] ?? ''), 70) }}</td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($r['createdAt'] ?? null, 'M j, Y g:i A') }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
    @include('website_admin.content._pager')
  </div>
</div>
@endsection
