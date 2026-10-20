@extends('layouts.app')
@section('title', 'Store Credit Audit')

@section('content')
@php
    $money = function ($v) { return '$' . number_format((float) $v, 2); };
    $date = function ($v) { $t = strtotime((string) $v); return $t ? date('m/d/y', $t) : ''; };
    $who = function ($row) {
        $c = $row['contact'] ?? null;
        $name = $c ? trim(($c->name ?? '') ?: (($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))) : ($row['name'] ?? '');
        $link = $c ? '<a href="/contacts/' . (int) $c->id . '">' . e($name ?: $row['email']) . '</a>' : e($name ?: '');
        return $link . '<br><span style="color:#333">' . e($row['email'] ?? '') . '</span>';
    };
@endphp
<section class="content-header"><h1>Store Credit Audit</h1></section>
<section class="content">
  <p style="font-size:15px;max-width:900px">This page only reads data and changes nothing. It shows who was affected by two website store credit bugs that were fixed on 10/20/26.</p>
  @if ($error)
    <div class="alert alert-danger">{{ $error }}</div>
  @else
  <div class="box box-solid"><div class="box-header"><h3 class="box-title">1. Store credit wrongly taken when they used a gift card ({{ count($wronglyTaken) }})</h3></div>
  <div class="box-body">
    <p>These customers used a gift card someone bought on nivessa.com, and their store credit here went down by the same amount. That credit should be given back.</p>
    <table class="table table-striped"><thead><tr><th>Customer</th><th>Web order</th><th>Date</th><th>Gift card</th><th>Taken from store credit</th></tr></thead><tbody>
    @forelse ($wronglyTaken as $r)
      <tr><td>{!! $who($r) !!}</td><td>#{{ $r['orderNumber'] }}</td><td>{{ $date($r['date']) }}</td><td>{{ $r['code'] }}</td><td><strong>{{ $money($r['amount']) }}</strong></td></tr>
    @empty
      <tr><td colspan="5">Nobody found.</td></tr>
    @endforelse
    </tbody></table>
    @if (count($wronglyTaken))<p><strong>Total to give back: {{ $money(array_sum(array_column($wronglyTaken, 'amount'))) }}</strong></p>@endif
  </div></div>

  <div class="box box-solid"><div class="box-header"><h3 class="box-title">2. Store credit code used online that the store credit here never covered ({{ count($maybeTwice) }})</h3></div>
  <div class="box-body">
    <p>These customers used a return or refund credit code online, but this system never took it off their store credit. That usually means the credit had already been spent in store, so it was spent twice.</p>
    <table class="table table-striped"><thead><tr><th>Customer</th><th>Web order</th><th>Date</th><th>Code</th><th>Used online</th><th>Store credit now</th></tr></thead><tbody>
    @forelse ($maybeTwice as $r)
      <tr><td>{!! $who($r) !!}</td><td>#{{ $r['orderNumber'] }}</td><td>{{ $date($r['date']) }}</td><td>{{ $r['code'] }}</td><td><strong>{{ $money($r['amount']) }}</strong></td><td>{{ $r['contact'] ? $money($r['contact']->balance) : 'No customer here' }}</td></tr>
    @empty
      <tr><td colspan="6">Nobody found.</td></tr>
    @endforelse
    </tbody></table>
  </div></div>

  <div class="box box-solid"><div class="box-header"><h3 class="box-title">3. Open credit codes worth more than their store credit ({{ count($exposure) }})</h3></div>
  <div class="box-body">
    <p>These codes could still be used online for more than the customer has left. The fix lowers them automatically the next time their store credit changes.</p>
    <table class="table table-striped"><thead><tr><th>Customer</th><th>Codes</th><th>Codes total</th><th>Store credit here</th><th>Over by</th></tr></thead><tbody>
    @forelse ($exposure as $r)
      <tr><td>{!! $who($r) !!}</td><td>@foreach ($r['codes'] as $c){{ $c['code'] }} ({{ $money($c['balance']) }})<br>@endforeach</td><td>{{ $money($r['total']) }}</td><td>{{ $r['erpBalance'] === null ? 'No customer here' : $money($r['erpBalance']) }}</td><td><strong>{{ $money($r['over']) }}</strong></td></tr>
    @empty
      <tr><td colspan="5">Nobody found.</td></tr>
    @endforelse
    </tbody></table>
  </div></div>
  <p>{{ $okCount }} other gift card and credit code uses checked out fine.</p>
  @endif
</section>
@endsection
