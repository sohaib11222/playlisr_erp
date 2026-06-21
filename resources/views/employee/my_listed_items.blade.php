@extends('layouts.app')
@section('title', 'Listed Items')

@section('content')
<script>document.body.classList.add('role-picker');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.role-picker { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.role-picker .content-wrapper { background: #FAF6EE !important; }
body.role-picker .content-header { background: transparent; padding: 28px 16px 8px; }
body.role-picker .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; max-width: 820px; }
body.role-picker .li-wrap { max-width: 1040px; padding: 0 16px 60px; }
body.role-picker .li-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 8px 4px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker table.li-table { width: 100%; border-collapse: collapse; }
body.role-picker table.li-table th, body.role-picker table.li-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; }
body.role-picker table.li-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker table.li-table code { background: #F7F1E3; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
body.role-picker .li-muted { color:#5A5045; font-size:13px; }
body.role-picker .li-sold { color:#2F6B3E; font-weight:700; }
body.role-picker .li-unsold { color:#8E8273; }
body.role-picker .li-inelig { color:#8A3A2E; font-size:12px; }
body.role-picker .li-pager { display:flex; gap:10px; align-items:center; margin:8px 2px 0; }
body.role-picker .li-btn { display:inline-flex; align-items:center; min-height:38px; padding:8px 16px; border:1px solid #D7CDB6; border-radius:8px; font-family:inherit; font-weight:700; font-size:13px; text-decoration:none; color:#1F1B16; background:#FFFCF5; }
body.role-picker a.li-back { color:#5A5045; font-size:13px; text-decoration:none; }
</style>

<section class="content-header">
    <h1>{{ $is_self ? 'Items I listed' : $target_name . "'s listed items" }}</h1>
    <p>Every item {{ $is_self ? 'you' : $target_name }} listed since {{ \Carbon::parse($from)->format('M j, Y') }} — {{ number_format($total) }} total. "Sold" items earn {{ rtrim(rtrim(number_format($rate_pct,2),'0'),'.') }}% commission; items in non-commission categories (sealed/new/gear/etc.) are marked.</p>
    @if($is_self)
        <p style="margin-top:8px;"><a class="li-back" href="{{ url('/my-earnings') }}">&larr; Back to My Earnings</a></p>
    @endif
</section>

<section class="content">
    <div class="li-wrap">
        <div class="li-card">
            <table class="li-table">
                <thead>
                    @php
                        $cols = ['listed'=>'Listed','item'=>'Item','sku'=>'SKU','category'=>'Category','sold'=>'Sold','sale'=>'Sale value','commission'=>'Commission'];
                        $sortBase = url('/my-earnings/items').'?user_id='.$user_id;
                    @endphp
                    <tr>
                        @foreach($cols as $key => $label)
                            @php
                                $active = $sort === $key;
                                $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : (in_array($key, ['sold','sale','commission','listed']) ? 'desc' : 'asc');
                                $arrow = $active ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
                            @endphp
                            <th><a href="{{ $sortBase.'&sort='.$key.'&dir='.$next }}" style="color:inherit;text-decoration:none;white-space:nowrap;">{{ $label }}{{ $arrow }}</a></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td class="li-muted">{{ \Carbon::parse($r->listed_at)->format('M j, Y') }}</td>
                            <td>{{ $r->name }}</td>
                            <td><code>{{ $r->sku }}</code></td>
                            <td class="li-muted">{{ $r->category }}@unless($r->eligible)<br><span class="li-inelig">not commission-eligible</span>@endunless</td>
                            <td>@if($r->units > 0)<span class="li-sold">{{ rtrim(rtrim(number_format($r->units,2),'0'),'.') }} sold</span>@else<span class="li-unsold">—</span>@endif</td>
                            <td>@if($r->sale_value > 0)${{ number_format($r->sale_value, 2) }}@else<span class="li-unsold">—</span>@endif</td>
                            <td>@if($r->commission > 0)<span class="li-sold">${{ number_format($r->commission, 2) }}</span>@else<span class="li-unsold">$0.00</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="li-muted" style="padding:18px;">No items listed in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($page > 1 || $has_more)
            <div class="li-pager">
                @if($page > 1)
                    <a class="li-btn" href="{{ url('/my-earnings/items') }}?user_id={{ $user_id }}&sort={{ $sort }}&dir={{ $dir }}&page={{ $page - 1 }}">&larr; Prev</a>
                @endif
                <span class="li-muted">Page {{ $page }}</span>
                @if($has_more)
                    <a class="li-btn" href="{{ url('/my-earnings/items') }}?user_id={{ $user_id }}&sort={{ $sort }}&dir={{ $dir }}&page={{ $page + 1 }}">Next &rarr;</a>
                @endif
            </div>
        @endif
    </div>
</section>
@endsection
