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
    <h1>My Earnings</h1>
    <p>Hi {{ $name }} — here's your listing commission since {{ \Carbon::parse($from)->format('M j, Y') }}. You earn {{ rtrim(rtrim(number_format($rate_pct,2),'0'),'.') }}% of the sale value of every item you listed that has since sold.</p>
</section>

<section class="content">
    <div class="me-wrap">
        @php
            $total_earned = round($earned + (float) $sales_bonus['bonus'], 2);
            $total_owed = round($owed + (float) $sales_bonus['bonus'], 2);
        @endphp
        <div class="me-hero">
            <div class="lbl">Total commission earned</div>
            <div class="val">${{ number_format($total_earned, 2) }}</div>
            <div class="sub">${{ number_format($earned, 2) }} listing pay + ${{ number_format($sales_bonus['bonus'], 2) }} sales bonus{{ $sales_bonus['live'] ? '' : ' (projected)' }}</div>
        </div>

        <div class="me-card" style="margin-bottom:14px;">
            <h3 style="margin-bottom:10px;">Listing commission</h3>
        <div class="me-stats" style="margin-bottom:0;">
            <div class="me-stat">
                <div class="lbl">Earned to date</div>
                <div class="val">${{ number_format($earned, 2) }}</div>
                <div class="sub">{{ $sold_count }} of your listed items have sold</div>
            </div>
            <div class="me-stat paid">
                <div class="lbl">Paid out to you</div>
                <div class="val">${{ number_format($paid_out, 2) }}</div>
                <div class="sub">{{ $payouts->count() }} payout(s)</div>
            </div>
            <div class="me-stat owed">
                <div class="lbl">Still owed</div>
                <div class="val">${{ number_format($owed, 2) }}</div>
                <div class="sub">{{ $owed_count }} sold item(s) not yet paid</div>
            </div>
        </div>
        </div>

        <div class="me-card">
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
                    <div class="sub">non-Whatnot, counted toward target</div>
                </div>
            </div>
            <p class="me-note" style="margin-top:14px;">You earn 2% of every dollar you ring above your daily sales target (the target comes from the store's own hourly history). It's added up day by day.@unless($sales_bonus['live']) This bonus isn't live yet — the figure above is a projection.@endunless</p>
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
            <h3>See your items</h3>
            <p class="me-muted" style="margin:-4px 0 12px;">Every item you listed, with which ones sold and what each earned.</p>
            <a class="li-link" href="{{ url('/my-earnings/items') }}" style="display:inline-flex;align-items:center;min-height:42px;padding:9px 18px;border:0;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;background:#1F1B16;color:#FAF6EE;">View all {{ number_format($listed_count) }} items I listed &rarr;</a>
        </div>

        <div class="me-card">
            <h3>Payout history</h3>
            @if($payouts->isEmpty())
                <p class="me-muted">No payouts recorded yet. Anything in "Still owed" will show here once it's paid.</p>
            @else
                <table class="me-table">
                    <thead>
                        <tr><th>Date paid</th><th>Period</th><th>Items</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach($payouts as $p)
                            <tr>
                                <td>{{ isset($p['marked_at']) ? \Carbon::parse($p['marked_at'])->format('M j, Y') : '—' }}</td>
                                <td class="me-muted">{{ $p['from_date'] ?? '—' }} → {{ $p['to_date'] ?? '—' }}</td>
                                <td>{{ $p['count'] ?? 0 }}</td>
                                <td>${{ number_format((float)($p['amount'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
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
