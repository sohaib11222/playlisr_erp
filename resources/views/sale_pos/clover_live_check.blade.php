@extends('layouts.app')
@section('title', 'Clover Live Check')

@section('content')
<style>
    .clc-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
    .clc-wrap h1 { font-size: 22px; font-weight: 700; color: #1F1B16; margin: 0 0 14px; }
    .clc-card { background:#FFFFFF; border:1px solid #ECE3CF; border-radius:10px; padding:14px 18px; margin-bottom:14px; box-shadow:0 1px 2px rgba(31,27,22,.06); }
    .clc-card h2 { font-size:14px; font-weight:700; color:#1F1B16; text-transform:uppercase; letter-spacing:.06em; margin:0 0 10px; }
    .clc-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .clc-form label { display:block; font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
    .clc-form input, .clc-form select { padding:6px 10px; border:1px solid #DFD2B3; border-radius:7px; font-size:13px; color:#1F1B16; }
    .clc-form button { padding:7px 16px; background:#1F1B16; color:#fff; border:none; border-radius:7px; font-weight:600; cursor:pointer; }
    .clc-meta { color:#5A5045; font-size:12px; margin:0 0 8px; }
    .clc-table { width:100%; border-collapse:collapse; font-size:12px; font-variant-numeric:tabular-nums; }
    .clc-table th { text-align:left; color:#8A7C6A; font-weight:600; padding:4px 8px; border-bottom:1px solid #ECE3CF; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
    .clc-table td { padding:6px 8px; border-bottom:1px solid #F2EBD8; color:#1F1B16; }
    .clc-table tr:hover { background:#FAF6EE; }
    .clc-empty { color:#8A7C6A; font-style:italic; padding:10px 0; }
    .clc-flag { display:inline-block; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:700; letter-spacing:.04em; }
    .clc-flag.voided { background:#D94B4B; color:#fff; }
    .clc-flag.refunded { background:#C99A2A; color:#fff; }
    .clc-conclusion { background:#FAF6EE; border:1px solid #DFD2B3; border-radius:8px; padding:12px 14px; font-size:13px; color:#3A3128; line-height:1.6; }
    .clc-conclusion strong { color:#1F1B16; }
    code { background:#F7F1E3; border:1px solid #DFD2B3; border-radius:3px; padding:0 4px; font-size:11px; }
</style>

<section class="content">
    <div class="clc-wrap">
        <h1>Clover Live Check</h1>

        <div class="clc-card">
            <form method="GET" action="{{ action('SellPosController@cloverLiveCheck') }}" class="clc-form">
                <div>
                    <label>Date</label>
                    <input type="date" name="date" value="{{ $date }}">
                </div>
                <div>
                    <label>Store</label>
                    <select name="location_id">
                        <option value="0">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" {{ (int) $id === (int) $locationId ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Amount ($)</label>
                    <input type="number" step="0.01" name="amount" value="{{ $amount !== null ? $amount : '' }}" placeholder="58.17">
                </div>
                <button type="submit">Check Clover</button>
            </form>
            <p class="clc-meta" style="margin-top:10px;">
                Searches our DB + the live Clover API for charges matching <strong>${{ $amount !== null ? number_format($amount, 2) : '?' }}</strong> ±${{ number_format($tolDollars, 2) }} at <strong>{{ $locationId ? $locName : 'any location' }}</strong> on <strong>{{ $date }}</strong>.
            </p>
        </div>

        <div class="clc-card">
            <h2>Our DB (clover_payments table)</h2>
            <p class="clc-meta">{{ count($dbRows) }} matching row{{ count($dbRows) === 1 ? '' : 's' }} in our local sync.</p>
            @if(empty($dbRows))
                <div class="clc-empty">No matching Clover rows in our DB for that filter.</div>
            @else
                <table class="clc-table">
                    <thead>
                        <tr>
                            <th>Clover ID</th>
                            <th>Amount</th>
                            <th>Paid At (LA)</th>
                            <th>Location</th>
                            <th>Result</th>
                            <th>Card</th>
                            <th>Employee</th>
                            <th>Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dbRows as $r)
                            <tr>
                                <td><code>{{ $r['clover_payment_id'] }}</code></td>
                                <td>${{ number_format($r['amount'], 2) }}</td>
                                <td>{{ $r['paid_at'] }}</td>
                                <td>{{ $r['location'] }}</td>
                                <td>{{ $r['result'] }}</td>
                                <td>{{ $r['card'] ?: '—' }}</td>
                                <td>{{ $r['employee'] ?: '—' }}</td>
                                <td><code>{{ $r['order_id'] ?: '—' }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="clc-card">
            <h2>Live Clover API</h2>
            <p class="clc-meta">
                Scopes queried: {{ empty($liveScopesQueried) ? '— (none)' : implode(', ', $liveScopesQueried) }}.
                Returned {{ count($liveRows) }} matching payment{{ count($liveRows) === 1 ? '' : 's' }} from Clover.
            </p>
            @if(!empty($liveError))
                <div style="background:#FDF1F1; color:#8B2C2C; padding:10px 12px; border-radius:6px; font-size:12px; margin-bottom:10px;">
                    <strong>Clover API error:</strong> {{ $liveError }}
                </div>
            @endif
            @if(empty($liveRows))
                <div class="clc-empty">No matching payments returned by Clover's live API.</div>
            @else
                <table class="clc-table">
                    <thead>
                        <tr>
                            <th>Clover ID</th>
                            <th>Amount</th>
                            <th>Created (LA)</th>
                            <th>Scope</th>
                            <th>Result</th>
                            <th>Flags</th>
                            <th>Tender</th>
                            <th>Card</th>
                            <th>Employee</th>
                            <th>Order</th>
                            <th>Tip</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($liveRows as $r)
                            <tr>
                                <td><code>{{ $r['clover_payment_id'] }}</code></td>
                                <td>${{ number_format($r['amount'], 2) }}</td>
                                <td>{{ $r['paid_at'] }}</td>
                                <td>{{ $r['scope'] }}</td>
                                <td>{{ $r['result'] }}</td>
                                <td>
                                    @if($r['voided'])<span class="clc-flag voided">VOIDED</span>@endif
                                    @if($r['refunded'])<span class="clc-flag refunded">REFUNDED</span>@endif
                                </td>
                                <td>{{ $r['tender'] ?: '—' }}</td>
                                <td>{{ $r['card'] ?: '—' }}</td>
                                <td>{{ $r['employee'] ?: '—' }}</td>
                                <td><code>{{ $r['order_id'] ?: '—' }}</code></td>
                                <td>{{ $r['tip_cents'] > 0 ? '$' . number_format($r['tip_cents'] / 100, 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="clc-conclusion">
            @php
                $dbCount = count($dbRows);
                $liveCount = count($liveRows);
            @endphp
            <strong>Interpretation:</strong>
            @if($dbCount === 0 && $liveCount === 0)
                Clover itself has nothing for ${{ $amount !== null ? number_format($amount, 2) : '?' }} ±${{ number_format($tolDollars, 2) }} at {{ $locationId ? $locName : 'any location' }} on {{ $date }}. <strong>The transaction was never run on a Clover terminal.</strong> If the ERP rang it, the cashier skipped entering it on Clover entirely.
            @elseif($dbCount === 0 && $liveCount > 0)
                Clover has the charge live but it's NOT in our DB. <strong>Sync gap — our nightly Clover sync didn't pull this row.</strong> Re-running the sync should pick it up. If this happens repeatedly, the sync needs inspection.
            @elseif($dbCount > 0 && $liveCount === 0)
                Our DB has the charge but Clover's live API doesn't return it now. Unusual — possibly the charge was voided/deleted on Clover after our sync stored it.
            @else
                Both DB and Clover have the charge. If the recent-feed shows "no Clover match" anyway, the matcher is failing on this pair specifically — check whether the Clover row's <code>location_id</code> matches the ERP sale's store, and whether the amounts differ by more than ±15¢.
            @endif
        </div>
    </div>
</section>
@endsection
