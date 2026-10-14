{{-- Fatteen's monthly goals, bonus and review. Data from FatteenGoalsUtil::summary(). --}}
<style>
.open-shell .goal-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.open-shell .goal-head h3 { margin: 0; border: 0; padding: 0; }
.open-shell .goal-head .mnav { margin-left: auto; display: flex; gap: 6px; font-size: 13px; }
.open-shell .goal-head .mnav a { color: var(--d-accent-text); font-weight: 700; }
.open-shell .sumrow { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
.open-shell .sumrow div { background: var(--d-surface-2); border: 1px solid var(--d-line); border-radius: var(--d-radius-sm); padding: 10px 12px; }
.open-shell .sumrow b { display: block; font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
.open-shell .sumrow span { font-size: 12px; color: var(--d-ink-3); }
.open-shell table.goals { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.open-shell table.goals th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-3); padding: 6px 8px; border-bottom: 1px solid var(--d-line); }
.open-shell table.goals td { padding: 9px 8px; border-bottom: 1px solid var(--d-line); vertical-align: top; color: var(--d-ink-2); }
.open-shell table.goals td.num { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 700; color: var(--d-ink); }
.open-shell table.goals .lbl { font-weight: 700; color: var(--d-ink); }
.open-shell table.goals .sub { display: block; font-size: 12px; color: var(--d-ink-3); margin-top: 3px; line-height: 1.4; }
.open-shell .tag.miss { background: #FBE3E0; color: #B42318; }
.open-shell .tag.none { background: var(--d-surface-2); color: var(--d-ink-3); }
.open-shell .goals-wrap { overflow-x: auto; }
.open-shell .review h4 { font-size: 13px; font-weight: 800; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .05em; color: var(--d-ink-2); }
.open-shell .review ul { margin: 0; padding-left: 18px; font-size: 14px; line-height: 1.5; color: var(--d-ink-2); }
.open-shell .review li { margin-bottom: 4px; }
.open-shell .gform { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; }
.open-shell .gform label { font-size: 12px; color: var(--d-ink-3); display: flex; flex-direction: column; gap: 4px; }
.open-shell .gform input, .open-shell .gform select, .open-shell .wrap-box {
    font: inherit; font-size: 14px; border: 1px solid var(--d-line-2); border-radius: 8px; padding: 7px 9px; background: #fff; color: var(--d-ink);
}
.open-shell .gform input.wide { min-width: 240px; }
.open-shell .gbtn { font: inherit; font-size: 13px; font-weight: 700; border: 1px solid var(--d-accent-deep); background: var(--d-accent); color: var(--d-accent-text); border-radius: 8px; padding: 8px 14px; cursor: pointer; }
.open-shell .gbtn.ghost { background: transparent; }
.open-shell .wrap-box { width: 100%; min-height: 110px; line-height: 1.5; }
@media (max-width: 560px) { .open-shell .sumrow { grid-template-columns: 1fr; } }
</style>

@php
    $g = $goals;
    $prevMonth = \Carbon\Carbon::parse($goalMonth . '-01')->subMonth()->format('Y-m');
    $nextMonth = \Carbon\Carbon::parse($goalMonth . '-01')->addMonth()->format('Y-m');
    $fmtNum = function ($n, $unit) {
        return rtrim(rtrim(number_format((float) $n, 1), '0'), '.') . $unit;
    };
    $fmtVal = function ($row) {
        if ($row['value'] === null) { return 'Not entered'; }
        return rtrim(rtrim(number_format((float) $row['value'], 1), '0'), '.') . ($row['unit'] ?? '');
    };
    $fmtTarget = function ($row) {
        return ($row['cmp'] === 'gte' ? 'At least ' : 'At most ') . $row['target'] . ($row['unit'] ?? '');
    };
@endphp

@if($g)
<div class="card" id="goals">
    <div class="goal-head">
        <h3 class="grp" style="margin:0"><span style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--d-ink-2)">Goals and bonus: {{ \Carbon\Carbon::parse($goalMonth . '-01')->format('F Y') }}</span></h3>
        <div class="mnav">
            <a href="{{ url('/fatteen-goals?month=' . $prevMonth) }}">&larr; {{ \Carbon\Carbon::parse($prevMonth . '-01')->format('M') }}</a>
            @if($goalMonth < date('Y-m'))
                <a href="{{ url('/fatteen-goals?month=' . $nextMonth) }}">{{ \Carbon\Carbon::parse($nextMonth . '-01')->format('M') }} &rarr;</a>
            @endif
        </div>
    </div>

    @if(!$g['active'])
        <div class="callout" style="font-weight:600">Bonus starts November 1. This month shows where things stand now.</div>
    @endif

    <div class="sumrow">
        <div><b>${{ $g['active'] ? $g['bonus'] : $g['bonus_if_gate'] }}</b><span>{{ $g['active'] ? 'Bonus earned so far' : 'Would have earned' }} (max ${{ $g['max_bonus'] }})</span></div>
        <div><b>{{ $g['check_days'] }} / {{ \App\Utils\FatteenGoalsUtil::GATE_DAYS }}</b><span>Checklist days (80%+ ticked)</span></div>
        <div><b>{{ $g['wrap_days'] }} / {{ \App\Utils\FatteenGoalsUtil::GATE_DAYS }}</b><span>End-of-day wrap-ups posted</span></div>
    </div>
    <p style="font-size:13px;color:var(--d-ink-3);margin:0 0 12px;line-height:1.5">
        Each goal pays $5 when its target is hit. Nothing pays unless both the checklist and the wrap-up are done on {{ \App\Utils\FatteenGoalsUtil::GATE_DAYS }}+ days that month.
        Goals hit: <b style="color:var(--d-ink)">{{ $g['hit_count'] }} of {{ $g['goal_count'] }}</b>.
        Rate review: {{ \Carbon\Carbon::parse(\App\Utils\FatteenGoalsUtil::REVIEW_DATE)->format('M j, Y') }}, based on November and December.
    </p>

    <div class="goals-wrap">
    <table class="goals">
        <thead><tr><th>Goal</th><th>Last month</th><th>This month</th><th>Target</th><th>Bonus</th></tr></thead>
        <tbody>
        @foreach($g['rows'] as $key => $row)
            <tr>
                <td><span class="lbl">{{ $row['label'] }}</span><span class="sub">{{ $row['src'] }}</span></td>
                <td class="num" style="font-weight:500;color:var(--d-ink-2)">{{ $row['prev'] === null ? '-' : $fmtNum($row['prev'], $row['unit']) }}</td>
                <td class="num">
                    <span class="tag {{ $row['hit'] === null ? 'none' : ($row['hit'] ? 'full' : 'miss') }}">{{ $row['value'] === null ? '-' : $fmtNum($row['value'], $row['unit']) }}</span>
                    @if(!empty($row['note']))<span class="sub" style="font-weight:400">{{ $row['note'] }}</span>@endif
                </td>
                <td class="num">{{ $row['target'] === null ? '-' : ($row['cmp'] === 'gte' ? $row['target'] . $row['unit'] . '+' : $row['target'] . $row['unit'] . ' or less') }}</td>
                <td class="num">${{ $row['earn'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <div class="grp" style="margin-top:0">
        <h3>Log a win</h3>
        <p style="font-size:13px;color:var(--d-ink-3);margin:0 0 10px;line-height:1.5">
            Collection buys count on their own when a ${{ \App\Utils\FatteenGoalsUtil::GOOD_BUY_MIN }}+ buy is accepted for the phone number you log. Bookings, collabs and problems count once Sarah or Jon approve them.
        </p>
        <form method="POST" action="{{ url('/daily-checklist/goals/log') }}" class="gform">
            @csrf
            <label>Type
                <select name="type" id="goal_type">
                    @foreach($winTypes as $k => $lbl)
                        <option value="{{ $k }}">{{ $lbl }}</option>
                    @endforeach
                    @if($isOwner)
                        <option value="chase">I had to ask Fatteen for an update</option>
                    @endif
                </select>
            </label>
            <label>What happened
                <input type="text" name="desc" id="goal_desc" class="wide" maxlength="500" placeholder="e.g. Seller with 600 jazz LPs, booked for Sat 2pm at Pico">
            </label>
            <label>Phone (for buys)
                <input type="text" name="phone" id="goal_phone" maxlength="40" placeholder="(310) 555-0100">
            </label>
            <button type="submit" class="gbtn">Log it</button>
        </form>

        @if(!empty($g['entries']))
            <table class="hist" style="margin-top:14px">
                <thead><tr><th>When</th><th>What</th><th>Status</th>@if($isOwner)<th></th>@endif</tr></thead>
                <tbody>
                @foreach($g['entries'] as $e)
                    @php
                        $type = $e['type'] ?? '';
                        $buy = $e['buy'] ?? null;
                        if ($type === 'chase') {
                            $status = 'Chase'; $cls = 'miss';
                        } elseif ($type === 'collection_buy' && $buy && $buy['paid'] >= \App\Utils\FatteenGoalsUtil::GOOD_BUY_MIN && ($e['approved'] ?? null) !== false) {
                            $status = 'Buy $' . number_format($buy['paid'], 2) . ' on ' . \Carbon\Carbon::parse($buy['date'])->format('n/j'); $cls = 'full';
                        } elseif (($e['approved'] ?? null) === true) {
                            $status = 'Approved'; $cls = 'full';
                        } elseif (($e['approved'] ?? null) === false) {
                            $status = 'Not counted'; $cls = 'miss';
                        } elseif ($type === 'collection_buy' && $buy) {
                            $status = 'Buy $' . number_format($buy['paid'], 2) . ', under $' . \App\Utils\FatteenGoalsUtil::GOOD_BUY_MIN; $cls = 'part';
                        } else {
                            $status = $type === 'collection_buy' ? 'Waiting for the buy' : 'Waiting for approval'; $cls = 'part';
                        }
                    @endphp
                    <tr>
                        <td style="white-space:nowrap">{{ \Carbon\Carbon::parse($e['at'])->format('M j') }}</td>
                        <td><b style="color:var(--d-ink)">{{ $type === 'chase' ? 'Update requested' : ($winTypes[$type] ?? $type) }}</b>: {{ $e['desc'] }}@if(!empty($e['phone'])) <span style="color:var(--d-ink-3)">{{ $e['phone'] }}</span>@endif</td>
                        <td><span class="tag {{ $cls }}">{{ $status }}</span></td>
                        @if($isOwner)
                            <td style="white-space:nowrap">
                                <form method="POST" action="{{ url('/daily-checklist/goals/review') }}" style="display:inline">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $e['id'] }}">
                                    @if($type !== 'chase')
                                        <button class="gbtn" name="action" value="approve" style="padding:4px 9px">Count</button>
                                        <button class="gbtn ghost" name="action" value="reject" style="padding:4px 9px">Don't count</button>
                                    @endif
                                    <button class="gbtn ghost" name="action" value="delete" style="padding:4px 9px">Delete</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($isOwner)
        <div class="grp">
            <h3>Google reviews (owner only)</h3>
            <form method="POST" action="{{ url('/daily-checklist/goals/metrics') }}" class="gform">
                @csrf
                <input type="hidden" name="month" value="{{ $goalMonth }}">
                <label>New Google reviews this month (both stores)<input type="number" name="reviews_new" id="m_reviews" value="{{ $g['metrics']['reviews_new'] ?? '' }}" style="width:110px"></label>
                <button type="submit" class="gbtn">Save</button>
            </form>
        </div>
    @endif
</div>

<div class="card review">
    <div class="grp" style="margin-top:0">
        <h3>Review, October 2026</h3>
        <h4>What you cover</h4>
        <ul>
            <li>Payroll prep from Clockify, Clover and the ERP, and QuickBooks entry.</li>
            <li>Sling schedules for both stores, time-off requests, onboarding and offboarding.</li>
            <li>Store phones and texts through Quo, plus WhatsApp and hello@ email.</li>
            <li>Daily register recon follow-ups with cashiers.</li>
            <li>Discogs orders and messages, website refunds when a cancel fails, AMS purchases.</li>
            <li>Events: listening party applications for both stores, the events calendar, event checklists and social posts.</li>
            <li>Camera checks, quarterly PCI test, QuickBooks categorizing, help with purchasing.</li>
        </ul>
        <h4>Doing well</h4>
        <ul>
            <li>Payroll: you caught the Clockify time-zone undercount (3h24m on 9/17) and checked the 9/30 shifts against Clover yourself.</li>
            <li>Scheduling: the monthly Sling schedules for both stores get built on time.</li>
            <li>Training: Insha answered 13 of 14 calls on her first day (10/12).</li>
            <li>Register recon: 10/13 was the first fully reconciled day, and the daily gaps got smaller in early October.</li>
            <li>Listening party applications to RSD for both stores.</li>
        </ul>
        <h4>Needs to improve</h4>
        <ul>
            <li>Finish the loop. When someone doesn't reply, call them, try someone else, or propose a fix. Recon lines stayed open from 9/27 because cashiers didn't answer.</li>
            <li>Close out with detail. "Already sorted" or "no" doesn't say what was done. Say what happened, what's next, and who owns it.</li>
            <li>Make your work visible. The checklist went unticked from 7/15 to 10/14. Tick it every day and post the end-of-day wrap-up so we can see what got done.</li>
            <li>Be reachable during your hours and reply in Slack quickly, so problems don't wait until we find them.</li>
            <li>Customers: 38 people are still waiting on a reply, including sellers. Warm replies that turn into buys and visits.</li>
            <li>Catch problems early. Staff unable to clock in, bank feeds down for 7 weeks, and Discogs orders not syncing for 5 weeks were all found by us.</li>
            <li>Get it right the first time: refunds and cancels need Jon's OK before telling a customer yes.</li>
        </ul>
    </div>
</div>
@endif
