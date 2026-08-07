@extends('layouts.app')
@section('title', 'My Earnings')

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
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; max-width: 760px; }
body.role-picker .me-wrap { max-width: 880px; padding: 0 16px 60px; }
body.role-picker .me-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .me-card h3 { margin: 0 0 12px; font-size: 16px; font-weight: 700; color: #1F1B16; }
body.role-picker .me-stats { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px; }
body.role-picker .me-stat { flex:1; min-width:200px; background:#FFFFFF; border:1px solid #ECE3CF; border-radius:12px; padding:18px 20px; box-shadow:0 1px 2px rgba(31,27,22,.06); }
body.role-picker .me-stat .lbl { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#8E8273; margin-bottom:6px; }
body.role-picker .me-stat .val { font-size:30px; font-weight:800; letter-spacing:-0.5px; }
body.role-picker .me-stat .sub { font-size:13px; color:#5A5045; margin-top:4px; }
body.role-picker .me-stat.owed .val { color:#2F6B3E; }
body.role-picker .me-stat.paid .val { color:#1F1B16; }
body.role-picker table.me-table { width: 100%; border-collapse: collapse; }
body.role-picker table.me-table th, body.role-picker table.me-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ECE3CF; font-size: 14px; }
body.role-picker table.me-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; }
body.role-picker .me-muted { color:#5A5045; font-size:13px; }
body.role-picker .me-note { background:#FBF6E6; border:1px solid #EADFBE; border-radius:10px; padding:12px 16px; font-size:13.5px; color:#5A5045; }
body.role-picker .me-chips { display:flex; gap:14px; flex-wrap:wrap; }
body.role-picker .me-chip { background:#F7F1E3; border-radius:10px; padding:12px 16px; min-width:150px; }
body.role-picker .me-chip .n { font-size:22px; font-weight:800; }
body.role-picker .me-chip .l { font-size:12px; color:#8E8273; text-transform:uppercase; letter-spacing:.4px; }
body.role-picker .me-hero { background:#FFF2B3; border:1px solid #EADFAE; border-radius:14px; padding:22px 24px; margin-bottom:16px; box-shadow:0 1px 2px rgba(31,27,22,.06); }
body.role-picker .me-hero .lbl { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6B5E2E; margin-bottom:4px; }
body.role-picker .me-hero .val { font-size:44px; font-weight:800; letter-spacing:-1px; color:#1F1B16; line-height:1.05; }
body.role-picker .me-hero .sub { font-size:14px; color:#6B5E2E; margin-top:8px; font-weight:600; }
</style>

<section class="content-header">
    <h1>{{ $viewing_other ? $name . "'s Earnings" : 'My Earnings' }}</h1>
    @if($viewing_other)
        <p style="background:#FBF6E6;border:1px solid #EADFBE;border-radius:10px;padding:10px 14px;color:#6B5E2E;font-weight:600;">Admin preview — this is exactly what {{ $name }} sees on their own My Earnings page.</p>
    @else
        <p>Hi {{ $name }} — here's your listing commission since {{ \Carbon::parse($from)->format('M j, Y') }}. You earn {{ rtrim(rtrim(number_format($rate_pct,2),'0'),'.') }}% of the sale value of every item you listed that has since sold.</p>
    @endif
</section>

<section class="content">
    <div class="me-wrap">
        @if(!empty($is_admin) && $staff->isNotEmpty())
            <div class="me-card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                <label for="emp-pick" style="font-weight:700;font-size:14px;">View employee</label>
                <select id="emp-pick" onchange="if(this.value){window.location.href='{{ url('/my-earnings') }}?user_id='+this.value;}" style="min-height:40px;padding:8px 12px;border:1px solid #ECE3CF;border-radius:8px;background:#FFFFFF;font:inherit;min-width:220px;">
                    @foreach($staff as $s)
                        <option value="{{ $s->id }}" {{ (int) $target_id === (int) $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
                <a href="{{ url('/my-earnings/daily') }}" style="margin-left:auto;font-size:13px;font-weight:700;color:#6B5E2E;text-decoration:none;">All employees, day by day &rarr;</a>
            </div>
        @endif
        @php
            $total_earned = round($earned + (float) $sales_bonus['bonus'], 2);
            $total_owed = round($owed + (float) $sales_bonus['bonus'], 2);
        @endphp
        <div class="me-hero">
            <div class="lbl">Total commission earned (all-time)</div>
            <div class="val">${{ number_format($total_earned, 2) }}</div>
            <div class="sub">${{ number_format($sales_bonus['bonus'], 2) }} sales bonus{{ $sales_bonus['live'] ? '' : ' (projected)' }} + ${{ number_format($earned, 2) }} listing pay</div>
            <div class="sub" style="margin-top:6px;">
                Paid to you <strong>${{ number_format($total_paid_all, 2) }}</strong> &nbsp;·&nbsp;
                <strong>{{ $total_owed_now > 0.004 ? 'Still owed $' . number_format($total_owed_now, 2) : ($total_owed_now < -0.004 ? 'Overpaid $' . number_format(abs($total_owed_now), 2) . ' (credit)' : 'Fully paid up') }}</strong>
            </div>
        </div>

        @if($total_owed_now < -0.004)
            <div class="me-card" style="background:#FDECEA; border-color:#E7B7AE; margin-bottom:14px;">
                <strong style="color:#b3402e; font-size:16px;">Heads up — you were overpaid ${{ number_format(abs($total_owed_now), 2) }} by mistake.</strong>
                <div style="margin-top:4px; color:#7a3b31;">This isn't money owed to you. It's a credit that will be applied against your future commission until it's used up.</div>
            </div>
        @endif

        <div class="me-card" style="margin-bottom:14px;">
            <h3>Sales bonus</h3>
            <div class="me-stats" style="margin-bottom:0;">
                <div class="me-stat owed">
                    <div class="lbl">Bonus earned{{ $sales_bonus['live'] ? '' : ' (projected)' }}</div>
                    <div class="val">${{ number_format($sales_bonus['bonus'], 2) }}</div>
                    <div class="sub">since {{ \Carbon::parse($bonus_from)->format('M j, Y') }}</div>
                </div>
                <div class="me-stat">
                    <div class="lbl">Your sales (this bonus)</div>
                    <div class="val" style="font-size:24px;">${{ number_format($sales_bonus['revenue'], 2) }}</div>
                    <div class="sub">non-Whatnot; your sales feed the store goal</div>
                </div>
                @if(abs($sales_bonus['party_split'] ?? 0) > 0.004)
                <div class="me-stat">
                    <div class="lbl">Listening party commission</div>
                    <div class="val" style="font-size:24px; color:{{ $sales_bonus['party_split'] > 0 ? '#2F6B3E' : '#B3402E' }};">{{ $sales_bonus['party_split'] > 0 ? '+' : '-' }}${{ number_format(abs($sales_bonus['party_split']), 2) }}</div>
                    <div class="sub">{{ $sales_bonus['party_split'] > 0 ? 'your share of a party bonus while sharing the floor' : "your helper's share of a party bonus, moved to them" }} — included above</div>
                </div>
                @endif
                @php $salesOwed = round((float) $sales_bonus['bonus'] - (float) $sales_paid, 2); @endphp
                <div class="me-stat">
                    <div class="lbl">Paid out to you</div>
                    <div class="val" style="font-size:24px;">${{ number_format($sales_paid, 2) }}</div>
                    <div class="sub">{{ $salesOwed < -0.004 ? 'overpaid $' . number_format(abs($salesOwed), 2) . ' (credit)' : ($salesOwed > 0.004 ? 'still owed $' . number_format($salesOwed, 2) : 'fully paid') }}</div>
                </div>
            </div>
            <p class="me-note" style="margin-top:14px;"><strong>How it works:</strong> you earn <strong>2% of your register sales above your daily goal</strong> (4% during peak hours). On a listening party, that day's bonus is split evenly with whoever worked the floor alongside you.@unless($sales_bonus['live']) This bonus isn't live yet — the figure above is a projection.@endunless</p>
            @if(count($sales_bonus['per_location']) > 1)
                <table class="me-table" style="margin-top:12px;">
                    <thead><tr><th>Store</th><th>Sales</th><th>Bonus</th></tr></thead>
                    <tbody>
                        @foreach($sales_bonus['per_location'] as $loc)
                            <tr><td>{{ $loc['location'] }}</td><td>${{ number_format($loc['revenue'], 2) }}</td><td>${{ number_format($loc['bonus'], 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="me-card">
            <h3 style="margin-bottom:10px;">Listing commission — items you listed that sold</h3>
        <div class="me-stats" style="margin-bottom:0;">
            <div class="me-stat">
                <div class="lbl">Earned all-time</div>
                <div class="val">${{ number_format($earned, 2) }}</div>
                <div class="sub">2% of {{ $sold_count }} of your listed items that have sold</div>
            </div>
            <div class="me-stat paid">
                <div class="lbl">Paid out to you</div>
                <div class="val">${{ number_format($paid_out, 2) }}</div>
                <div class="sub">{{ $payouts->count() }} payout(s)</div>
            </div>
            @php $owedNeg = $owed < -0.004; @endphp
            <div class="me-stat owed">
                <div class="lbl">Listing still owed</div>
                <div class="val">{{ $owedNeg ? '-$' . number_format(abs($owed), 2) : '$' . number_format($owed, 2) }}</div>
                <div class="sub">{{ $owedNeg ? 'overpaid — credit' : 'earned minus paid' }}</div>
            </div>
        </div>
        </div>

        <div class="me-card">
            <h3 style="margin-bottom:10px;">Your payments</h3>
            @if(empty($payment_history))
                <p class="me-muted" style="margin:0;">No payments recorded yet.</p>
            @else
                <table class="me-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Paid on</th>
                            <th style="text-align:right;">Sales</th>
                            <th style="text-align:right;">Listing</th>
                            <th style="text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payment_history as $ph)
                            <tr>
                                <td>{{ \Carbon::parse($ph->date)->format('m/d/y') }}</td>
                                <td style="text-align:right;">@if($ph->sales != 0)${{ number_format($ph->sales, 2) }}@else<span class="me-muted">—</span>@endif</td>
                                <td style="text-align:right;">@if($ph->listing != 0)${{ number_format($ph->listing, 2) }}@else<span class="me-muted">—</span>@endif</td>
                                <td style="text-align:right;"><strong>${{ number_format($ph->total, 2) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="me-card">
            <h3 style="margin-bottom:2px;">Weekly commission statement</h3>
            <p class="me-muted" style="margin:2px 0 12px;">A running ledger, newest week first, of what {{ $viewing_other ? 'they' : 'you' }} earned vs. got paid. The <strong>top row's balance is the current one</strong> &mdash; that's what's still owed today. Each row: last week's balance + earned &minus; paid. A mid-list negative isn't an overpay &mdash; it just means a payment landed before that week's earnings caught up.</p>
            @if(empty($weekly))
                <p class="me-muted">No commission activity yet.</p>
            @else
                @php
                    $money = function ($n) { return ($n < 0 ? '-$' : '$') . number_format(abs($n), 2); };
                @endphp
                <div style="overflow-x:auto;">
                <table class="me-table">
                    <thead>
                        <tr>
                            <th>Week of</th>
                            <th style="text-align:right;">Earned</th>
                            <th style="text-align:right;">Paid</th>
                            <th style="text-align:right;border-left:1px solid #E5D9BC;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($weekly as $i => $w)
                            <tr @if($i === 0)style="background:#FBF7E9;"@endif>
                                <td style="white-space:nowrap;">{{ \Carbon::parse($w->week_start)->format('M j, Y') }}@if($i === 0)<div style="font-size:11px;color:#B07A00;font-weight:700;">current</div>@endif</td>
                                <td style="text-align:right;">
                                    <strong>${{ number_format($w->earned, 2) }}</strong>
                                    @if($w->listing != 0 || $w->sales != 0)
                                        <div style="font-size:11px;color:#8E8273;">@if($w->listing != 0)list ${{ number_format($w->listing, 2) }}@endif@if($w->sales != 0){{ $w->listing != 0 ? ' · ' : '' }}sales ${{ number_format($w->sales, 2) }}@endif</div>
                                    @endif
                                </td>
                                <td style="text-align:right;">
                                    @if($w->paid != 0)
                                        <span style="color:#2F6B3E;font-weight:600;">${{ number_format($w->paid, 2) }}</span>
                                        <div style="font-size:11px;color:#8E8273;">paid {{ implode(', ', array_map(function ($d) { return \Carbon::parse($d)->format('n/j'); }, $w->pay_dates)) }}</div>
                                    @else <span class="me-muted">—</span>@endif
                                </td>
                                <td style="text-align:right;border-left:1px solid #ECE3CF;font-weight:700;{{ $w->balance < -0.004 ? ($i === 0 ? 'color:#b3402e;' : 'color:#B07A00;') : ($w->balance > 0.004 ? 'color:#2F6B3E;' : 'color:#8E8273;') }}">
                                    {{ $money($w->balance) }}
                                    <div style="font-size:11px;color:#A79A80;font-weight:400;white-space:nowrap;">{{ $money($w->prev_balance) }} + ${{ number_format($w->earned, 2) }} &minus; ${{ number_format($w->paid, 2) }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>

        <div class="me-card">
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h3 style="margin-bottom:2px;">Daily breakdown — last {{ $daily_days }} days</h3>
                @if(!empty($is_admin))
                    <a href="{{ url('/my-earnings/daily') }}" style="font-size:13px;font-weight:700;text-decoration:none;color:#6B5E2E;">All employees, day by day &rarr;</a>
                @endif
            </div>
            <p class="me-muted" style="margin:2px 0 12px;">Each day's sales and what you earned. Sales bonus = 2% of your sales over goal (4% peak), split with your floor partner on party days. Listing = 2% of items you listed that sold.</p>
            @if(empty($daily))
                <p class="me-muted">No sales or listed-item sales in this window yet.</p>
            @else
                <div style="overflow-x:auto;">
                <table class="me-table">
                    <thead>
                        <tr>
                            <th rowspan="2">Day</th>
                            <th colspan="3" style="text-align:center;border-left:1px solid #E5D9BC;">Sales (register)</th>
                            <th colspan="2" style="text-align:center;border-left:1px solid #E5D9BC;">Listing</th>
                            <th rowspan="2" style="text-align:right;border-left:1px solid #E5D9BC;">Total earned</th>
                        </tr>
                        <tr>
                            <th style="text-align:right;border-left:1px solid #E5D9BC;">You rang</th>
                            <th style="text-align:right;">Daily goal</th>
                            <th style="text-align:right;">Sales bonus{{ $sales_bonus_live ? '' : '*' }}</th>
                            <th style="text-align:right;border-left:1px solid #E5D9BC;">Listed sold</th>
                            <th style="text-align:right;">Listing pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $t_rang = 0; $t_goal = 0; $t_bonus = 0; $t_listed = 0; $t_lpay = 0; $t_total = 0;
                        @endphp
                        @foreach($daily as $d)
                            @php
                                $t_rang += $d['register_sales']; $t_goal += $d['sales_target']; $t_bonus += $d['sales_bonus'];
                                $t_listed += $d['listed_sales']; $t_lpay += $d['listing_comm']; $t_total += $d['total_comm'];
                                $met_goal = $d['sales_target'] > 0 && $d['register_sales'] >= $d['sales_target'];
                            @endphp
                            <tr>
                                <td>{{ \Carbon::parse($d['date'])->format('D, M j') }}</td>
                                <td style="text-align:right;border-left:1px solid #ECE3CF;{{ $met_goal ? 'color:#2F6B3E;font-weight:700;' : '' }}">${{ number_format($d['register_sales'], 2) }}</td>
                                <td style="text-align:right;color:#5A5045;">{{ $d['sales_target'] > 0 ? '$'.number_format($d['sales_target'], 2) : '—' }}</td>
                                <td style="text-align:right;{{ $d['sales_bonus'] > 0 ? 'color:#2F6B3E;font-weight:600;' : 'color:#8E8273;' }}">${{ number_format($d['sales_bonus'], 2) }}</td>
                                <td style="text-align:right;border-left:1px solid #ECE3CF;">${{ number_format($d['listed_sales'], 2) }}</td>
                                <td style="text-align:right;">${{ number_format($d['listing_comm'], 2) }}</td>
                                <td style="text-align:right;border-left:1px solid #ECE3CF;font-weight:700;">${{ number_format($d['total_comm'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #ECE3CF;font-weight:700;">
                            <td>Total</td>
                            <td style="text-align:right;border-left:1px solid #ECE3CF;">${{ number_format($t_rang, 2) }}</td>
                            <td style="text-align:right;color:#5A5045;">${{ number_format($t_goal, 2) }}</td>
                            <td style="text-align:right;">${{ number_format($t_bonus, 2) }}</td>
                            <td style="text-align:right;border-left:1px solid #ECE3CF;">${{ number_format($t_listed, 2) }}</td>
                            <td style="text-align:right;">${{ number_format($t_lpay, 2) }}</td>
                            <td style="text-align:right;border-left:1px solid #ECE3CF;">${{ number_format($t_total, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
                </div>
                @unless($sales_bonus_live)
                    <p class="me-muted" style="margin-top:10px;">*Sales bonus isn't live yet — those figures are projections and aren't in "Total earned" until it goes live.</p>
                @endunless
            @endif
        </div>

        <div class="me-card">
            <h3>See your items</h3>
            <p class="me-muted" style="margin:-4px 0 12px;">Every item you listed, with which ones sold and what each earned.</p>
            <a class="li-link" href="{{ url('/my-earnings/items') }}{{ $viewing_other ? '?user_id='.$target_id : '' }}" style="display:inline-flex;align-items:center;min-height:42px;padding:9px 18px;border:0;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;background:#1F1B16;color:#FAF6EE;">View all {{ number_format($listed_count) }} items {{ $viewing_other ? 'they' : 'I' }} listed &rarr;</a>
        </div>

        <div class="me-card">
            <h3>Your activity (for reference — not pay)</h3>
            <div class="me-chips">
                <div class="me-chip"><div class="n">{{ number_format($listed_count) }}</div><div class="l">Items listed</div></div>
                <div class="me-chip"><div class="n">{{ number_format($sold_count) }}</div><div class="l">Of those, sold</div></div>
                <div class="me-chip"><div class="n">{{ number_format($labeled_count) }}</div><div class="l">Items put out (labeled)</div></div>
            </div>
            <p class="me-note" style="margin-top:14px;">These counts are just to track your work — your pay is the {{ rtrim(rtrim(number_format($rate_pct,2),'0'),'.') }}% commission above, based on items you listed that sold. The "items put out" number is a productivity stat and is not paid on its own.</p>
        </div>
    </div>
</section>
@endsection
