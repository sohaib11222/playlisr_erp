<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Buy approval</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f4f4; color: #222; margin: 0; padding: 16px; }
        .card { background: #fff; border-radius: 10px; padding: 16px; max-width: 560px; margin: 0 auto 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0 0 6px; }
        .muted { color: #666; font-size: 14px; }
        .amounts { display: flex; gap: 10px; margin: 14px 0 4px; }
        .amounts div { flex: 1; border-radius: 8px; padding: 10px; text-align: center; }
        .amounts .paid { background: #fdecea; color: #a12622; }
        .amounts .sys { background: #eaf4ea; color: #2b6a2b; }
        .amounts b { display: block; font-size: 24px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td { padding: 6px 4px; border-bottom: 1px solid #eee; vertical-align: top; }
        td.num { text-align: right; white-space: nowrap; }
        .photos img { width: 100%; border-radius: 8px; margin-top: 8px; }
        .btns { display: flex; gap: 10px; }
        .btns button { flex: 1; font-size: 18px; padding: 14px; border: 0; border-radius: 8px; color: #fff; cursor: pointer; }
        .approve { background: #2e7d32; }
        .deny { background: #c62828; }
        .status { font-size: 18px; font-weight: 600; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Buy approval{{ $rec['location_name'] ? ' at ' . $rec['location_name'] : '' }}</h1>
        <div class="muted">{{ $rec['cashier_name'] }} is buying from {{ $rec['seller'] ?: 'a seller' }}, {{ \Carbon\Carbon::parse($rec['created_at'])->format('M j, g:ia') }}</div>
        <div class="amounts">
            <div class="paid">Wants to pay<b>${{ number_format($rec['paid'], 2) }}</b>{{ $rec['pm_label'] }}</div>
            <div class="sys">System says<b>${{ number_format($rec['auto'], 2) }}</b>{{ $rec['pm_label'] }}</div>
        </div>
    </div>

    <div class="card">
        @if($rec['status'] === 'pending' && !$expired)
            <div class="status">Reply YES {{ $rec['code'] ?? '' }} to the text to approve, or NO {{ $rec['code'] ?? '' }} to deny.</div>
        @elseif($rec['status'] === 'approved' || $rec['status'] === 'used')
            <div class="status" style="color:#2e7d32;">Approved. {{ $rec['cashier_name'] }} can finish the buy.</div>
        @elseif($rec['status'] === 'denied')
            <div class="status" style="color:#c62828;">Denied. {{ $rec['cashier_name'] }} cannot pay this amount.</div>
        @else
            <div class="status muted">This request has expired.</div>
        @endif
    </div>

    @if(!empty($rec['photos']))
        <div class="card photos">
            <strong>Photos</strong>
            @foreach($rec['photos'] as $i => $p)
                <a href="{{ route('buy-approval.photo', ['token' => $token, 'n' => $i]) }}" target="_blank"><img src="{{ route('buy-approval.photo', ['token' => $token, 'n' => $i]) }}" alt="Photo {{ $i + 1 }}"></a>
            @endforeach
        </div>
    @endif

    <div class="card">
        <strong>What they entered</strong>
        <table>
            @foreach($rec['lines'] as $l)
                <tr>
                    <td class="num">{{ rtrim(rtrim(number_format($l['qty'], 2), '0'), '.') }}x</td>
                    <td>{{ $l['type'] }}@if(!empty($l['title'])), {{ $l['title'] }}@endif @if(!empty($l['grade'])) ({{ $l['grade'] }})@endif @if(!empty($l['value'])) <span class="muted">value ${{ number_format($l['value'], 2) }}</span>@endif</td>
                    <td class="num">${{ number_format($l['line_cash'], 2) }}</td>
                </tr>
            @endforeach
        </table>
        @if(trim($rec['notes'] ?? '') !== '')
            <p class="muted">Notes: {{ $rec['notes'] }}</p>
        @endif
    </div>
</body>
</html>
