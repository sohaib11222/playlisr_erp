@extends('layouts.app')

@section('title', 'Abandoned Carts')

@section('content')
<div class="ev-wrap">
  @include('website_admin.tools._head', ['active' => 'wa.tools.abandonedCarts'])

  @php
    $money = function ($n, $cur = 'USD') {
        if (!is_numeric($n)) return '-';
        return ($cur && strtoupper($cur) !== 'USD' ? strtoupper($cur) . ' ' : '$') . number_format((float) $n, 2);
    };
    $ago = function ($d) {
        if (!$d) return '-';
        $ts = strtotime($d);
        if (!$ts) return '-';
        $mins = (int) floor((time() - $ts) / 60);
        if ($mins < 60) return $mins . 'm ago';
        $hrs = (int) floor($mins / 60);
        if ($hrs < 24) return $hrs . 'h ago';
        return ((int) floor($hrs / 24)) . 'd ago';
    };
    $anon = $tab === 'live';
    $qs = function ($extra) use ($tab, $stale) {
        return route('wa.tools.abandonedCarts', array_merge(['tab' => $tab, 'stale' => $stale], $extra));
    };
  @endphp

  <div class="ev-head">
    <div>
      <h1>Abandoned / Unfinished Carts</h1>
      <p class="sub">Carts that did not turn into a paid order.</p>
    </div>
  </div>

  <nav class="wt-nav">
    <a href="{{ route('wa.tools.abandonedCarts', ['tab' => 'checkouts', 'stale' => $stale]) }}" class="{{ $tab === 'checkouts' ? 'active' : '' }}">Unfinished checkouts</a>
    <a href="{{ route('wa.tools.abandonedCarts', ['tab' => 'live', 'stale' => $stale]) }}" class="{{ $tab === 'live' ? 'active' : '' }}">Live carts</a>
  </nav>

  <p class="wt-note">
    @if($anon)
      Carts that got items added but never reached checkout, including anonymous shoppers, who usually have no contact info. Best read as a demand and lost-value signal.
    @else
      Checkouts a customer started but never paid for (pending orders). We already have their email, so they are good candidates for a recovery follow-up.
    @endif
  </p>

  <div class="wt-grid" style="margin:14px 0;">
    <div class="wt-stat"><div class="l">Carts</div><div class="v">{{ number_format((int) ($summary['count'] ?? 0)) }}</div></div>
    <div class="wt-stat"><div class="l">{{ $anon ? 'Cart value' : 'Recoverable value' }}</div><div class="v">{{ $money($summary['totalValue'] ?? 0) }}</div></div>
    @if($anon)
      <div class="wt-stat"><div class="l">Contactable / anonymous</div><div class="v">{{ number_format((int) ($summary['knownCount'] ?? 0)) }} / {{ number_format((int) ($summary['anonymousCount'] ?? 0)) }}</div></div>
    @endif
  </div>

  <form method="GET" action="{{ route('wa.tools.abandonedCarts') }}" class="wt-row" style="justify-content:space-between;">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <label>Idle for at least
      <select name="stale" class="wt-select" onchange="this.form.submit()">
        @foreach($staleOptions as $val => $label)
          <option value="{{ $val }}" {{ $stale === (string) $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
    </label>
    <span class="wt-row" style="margin:0;">
      <span class="wt-note">Updated {{ $updatedAt->format('g:i:s A') }}</span>
      <button type="submit" class="wt-btn sm">Refresh</button>
    </span>
  </form>

  <div class="wt-card">
    @if($error)
      <div class="wt-box bad">{{ $error }}</div>
    @elseif($allCount === 0)
      <p class="muted" style="text-align:center; padding:24px 0;">No carts in this window.</p>
    @else
      <div class="wt-scroll">
        <table class="wt-tbl">
          <thead><tr><th>Customer</th><th>Contact</th><th>Items</th><th class="r">Value</th><th>Abandoned</th></tr></thead>
          <tbody>
            @foreach($rows as $i => $cart)
              @php
                $c = $cart['customer'] ?? [];
                $isAnon = !empty($c['isAnonymous']);
                $cur = $cart['currency'] ?? 'USD';
                $when = $cart[$timeField] ?? null;
                $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
                $n = (int) ($cart['itemCount'] ?? count($items));
              @endphp
              <tr>
                <td>
                  @if($isAnon)
                    <span class="muted">Anonymous</span> <span class="wt-badge">No account</span>
                  @else
                    <strong>{{ $c['name'] ?? 'Guest' }}</strong>
                    @if(!empty($c['isGuest'])) <span class="wt-badge">Guest</span> @endif
                  @endif
                </td>
                <td>
                  @if(!empty($c['email']))
                    <a href="mailto:{{ $c['email'] }}">{{ $c['email'] }}</a>
                  @else
                    <span class="muted">-</span>
                  @endif
                  @if(!empty($c['phone']))<div class="wt-note" style="margin:2px 0 0;">{{ $c['phone'] }}</div>@endif
                </td>
                <td>
                  <button type="button" class="wt-btn sm" onclick="var r=document.getElementById('wt-cart-{{ $i }}'); r.classList.toggle('wt-hide');">
                    {{ $n }} {{ $n === 1 ? 'item' : 'items' }}
                  </button>
                </td>
                <td class="r"><strong>{{ $money($cart['totalValue'] ?? null, $cur) }}</strong></td>
                <td style="white-space:nowrap;"><span title="{{ $when ? \Carbon\Carbon::parse($when)->setTimezone(config('app.timezone'))->format('M j, Y g:i A') : '' }}">{{ $ago($when) }}</span></td>
              </tr>
              <tr id="wt-cart-{{ $i }}" class="wt-hide">
                <td colspan="5" style="background:#f9fafb;">
                  @foreach($items as $it)
                    <div style="display:flex; align-items:center; gap:10px; padding:4px 0; font-size:13px;">
                      @if(!empty($it['image']))
                        <img src="{{ $it['image'] }}" alt="" style="width:40px; height:40px; border-radius:6px; object-fit:cover; border:1px solid #e5e7eb;">
                      @else
                        <div style="width:40px; height:40px; border-radius:6px; border:1px solid #e5e7eb; background:#f3f4f6;"></div>
                      @endif
                      <span style="flex:1;">{{ $it['name'] ?? '' }} @if(!empty($it['sku']))<span class="muted" style="font-size:12px; margin-left:6px;">{{ $it['sku'] }}</span>@endif</span>
                      <span class="muted">x{{ (int) ($it['quantity'] ?? 1) }}</span>
                      <span style="width:90px; text-align:right;">{{ $money($it['price'] ?? null, $cur) }}</span>
                    </div>
                  @endforeach
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if($totalPages > 1)
        <div class="wt-row" style="justify-content:center; margin-top:14px;">
          @if($page > 1)<a class="wt-btn sm" href="{{ $qs(['page' => $page - 1]) }}">Previous</a>@else<button class="wt-btn sm" disabled>Previous</button>@endif
          <span class="wt-note">Page {{ $page }} of {{ $totalPages }}, {{ number_format($allCount) }} total</span>
          @if($page < $totalPages)<a class="wt-btn sm" href="{{ $qs(['page' => $page + 1]) }}">Next</a>@else<button class="wt-btn sm" disabled>Next</button>@endif
        </div>
      @endif
    @endif
  </div>
</div>
@endsection
