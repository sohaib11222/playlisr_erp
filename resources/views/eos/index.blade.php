@extends('layouts.app')
@section('title', 'EOS')

@section('content')
@php
    $C = \App\Http\Controllers\EosController::class;
    $md = function ($ymd) { return $ymd ? date('m/d/y', strtotime($ymd)) : ''; };
    $num = function ($v, $money) {
        if ($v === null || $v === '') { return ''; }
        return $money ? '$' . number_format($v, 0) : rtrim(rtrim(number_format($v, 2), '0'), '.');
    };
    // On track when the week's number meets the goal in the right direction.
    $onTrack = function ($m, $v) {
        if ($v === null || $v === '' || !is_numeric($m['goal'] ?? null)) { return null; }
        return ($m['dir'] ?? 'min') === 'max' ? $v <= (float) $m['goal'] : $v >= (float) $m['goal'];
    };
    $lastWeek = $weeks[1] ?? $weeks[0];
    $rocksNow = array_values(array_filter($d['rocks'], function ($r) use ($quarter) {
        return $r['quarter'] === $quarter && $r['status'] !== 'dropped';
    }));
    $openIssues = array_values(array_filter($d['issues'], function ($i) { return $i['status'] === 'open'; }));
    $openTodos = array_values(array_filter($d['todos'], function ($t) { return empty($t['done']); }));
    $recentDone = array_values(array_filter($d['todos'], function ($t) {
        return !empty($t['done']) && !empty($t['done_at']) && $t['done_at'] >= date('Y-m-d', strtotime('-7 days'));
    }));
    $rockLabel = ['on' => 'On track', 'off' => 'Off track', 'done' => 'Done', 'dropped' => 'Dropped'];
    $ownerSelect = function ($name, $selected = '') use ($people) {
        $h = '<select name="' . e($name) . '"><option value="">Owner</option>';
        foreach ($people as $p) {
            $h .= '<option value="' . e($p) . '"' . ($p === $selected ? ' selected' : '') . '>' . e($p) . '</option>';
        }
        return $h . '</select>';
    };
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">

<style>
.eos {
    --ink: #1F1B16; --ink-2: #2B2620; --line: #E1D7C4; --bg: #FAF6EE;
    --sel-bg: #E8F0F8; --sel-ink: #13507a; --sel-line: #1b6ca8;
    --good-bg: #E6F4EA; --good: #1E6B2E; --bad-bg: #FBEAE5; --bad: #9A2414;
    font-family: "Inter Tight", system-ui, sans-serif; color: var(--ink);
    background: var(--bg); max-width: 1100px; margin: 12px auto 48px; padding: 0 16px;
}
.eos *, .eos *::before, .eos *::after { box-sizing: border-box; }
.eos h1 { font-size: 26px; font-weight: 800; margin: 12px 0 4px; }
.eos .sub { font-size: 14px; color: var(--ink-2); margin: 0 0 14px; line-height: 1.5; }
.eos h2 { font-size: 18px; font-weight: 800; margin: 0 0 10px; }
.eos h3 { font-size: 15px; font-weight: 800; margin: 16px 0 8px; }
.eos .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 16px; }
.eos .tabs a {
    border: 1px solid var(--line); border-radius: 999px; padding: 8px 16px; font-weight: 700; font-size: 14px;
    background: #fff; color: var(--ink) !important; text-decoration: none !important;
}
.eos .tabs a.on { background: var(--sel-bg); border-color: var(--sel-line); color: var(--sel-ink) !important; }
.eos .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
.eos .flash { border-radius: 10px; padding: 12px 16px; margin-bottom: 14px; font-weight: 700; font-size: 14px; }
.eos .flash.ok { background: var(--good-bg); color: var(--good); border: 1px solid #A8D5B5; }
.eos .flash.warn { background: var(--bad-bg); color: var(--bad); border: 1px solid #E0A99B; }
.eos input[type=text], .eos input[type=date], .eos input[type=number], .eos select, .eos textarea {
    font: inherit; font-size: 14px; color: var(--ink); border: 1px solid var(--line); border-radius: 8px;
    padding: 7px 9px; background: #fff;
}
.eos textarea { width: 100%; min-height: 70px; resize: vertical; }
.eos .grow { flex: 1 1 260px; min-width: 0; }
.eos .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.eos .btn {
    background: var(--sel-line); color: #fff !important; border: 0; border-radius: 999px; padding: 8px 16px;
    font: inherit; font-weight: 800; font-size: 14px; cursor: pointer;
}
.eos .btn.light { background: #fff; color: var(--ink) !important; border: 1px solid var(--line); font-weight: 700; }
.eos .btn.small { padding: 4px 11px; font-size: 13px; }
.eos .hint { font-size: 13px; color: var(--ink-2); font-weight: 500; }
.eos table { width: 100%; border-collapse: collapse; font-size: 14px; }
.eos th, .eos td { border-bottom: 1px solid var(--line); padding: 7px 8px; text-align: left; vertical-align: top; }
.eos th { font-weight: 800; font-size: 13px; white-space: nowrap; }
.eos .eos-scroll { overflow-x: auto; }
.eos td.good { background: var(--good-bg); color: var(--good); font-weight: 700; }
.eos td.bad { background: var(--bad-bg); color: var(--bad); font-weight: 700; }
.eos .eos-sc input[type=text] { width: 86px; padding: 4px 6px; }
.eos .tag { display: inline-block; border-radius: 999px; padding: 2px 10px; font-size: 12.5px; font-weight: 800; }
.eos .tag.on, .eos .tag.done { background: var(--good-bg); color: var(--good); }
.eos .tag.off { background: var(--bad-bg); color: var(--bad); }
.eos .tag.dropped { background: #EFEAE0; color: var(--ink-2); }
.eos .agenda li { margin: 4px 0; font-size: 14px; }
.eos .seats { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
.eos .seat { border: 1px solid var(--line); border-radius: 12px; padding: 12px; background: #fff; }
.eos .seat input[type=text] { width: 100%; margin-bottom: 6px; }
.eos .seat textarea { min-height: 110px; }
.eos label.q { display: block; font-weight: 800; font-size: 14px; margin: 14px 0 4px; }
.eos .done-txt { text-decoration: line-through; }
.eos details summary { cursor: pointer; font-weight: 700; font-size: 13.5px; color: var(--sel-ink); }
</style>

<div class="eos">
    <h1>EOS</h1>
    <p class="sub">How we run the business: one Vision, the right people in the right seats, 90-day Rocks, a weekly Scorecard, and a weekly L10 meeting that solves Issues.</p>

    @if (session('eos_ok'))<div class="flash ok">{{ session('eos_ok') }}</div>@endif
    @if (session('eos_warn'))<div class="flash warn">{{ session('eos_warn') }}</div>@endif

    <div class="tabs">
        @foreach ($C::TABS as $k => $label)
            <a href="{{ url('/eos') }}?tab={{ $k }}" class="{{ $tab === $k ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- ============ WEEKLY L10 ============ --}}
    @if ($tab === 'l10')
        <div class="card">
            <h2>Weekly L10 meeting, 90 minutes</h2>
            <ol class="agenda">
                @foreach ($C::AGENDA as $k => $a)
                    <li><strong>{{ $a[1] }} min</strong>: {{ $a[0] }}</li>
                @endforeach
            </ol>
        </div>

        <div class="card">
            <h2>Scorecard, week of {{ $md($lastWeek) }}</h2>
            @if (empty($d['measurables']))
                <p class="hint">No numbers yet. Add them on the Scorecard tab.</p>
            @else
                <table>
                    <tr><th>Number</th><th>Owner</th><th>Goal</th><th>Last week</th></tr>
                    @foreach ($d['measurables'] as $m)
                        @php $v = $values[$m['id']][$lastWeek] ?? null; $ok = $onTrack($m, $v); @endphp
                        <tr>
                            <td>{{ $m['name'] }}</td>
                            <td>{{ $m['owner'] }}</td>
                            <td>{{ $m['goal'] !== '' ? (($m['dir'] ?? 'min') === 'max' ? 'at most ' : 'at least ') . $num((float) $m['goal'], $m['money'] ?? 0) : 'set a goal' }}</td>
                            <td class="{{ $ok === true ? 'good' : ($ok === false ? 'bad' : '') }}">{{ $v === null ? 'not entered' : $num($v, $m['money'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </table>
                <p class="hint" style="margin-top:8px">Anything off goal goes on the Issues list below.</p>
            @endif
        </div>

        <div class="card">
            <h2>Rocks, {{ $quarter }}</h2>
            @include('eos._rocks', ['rocks' => $rocksNow, 'back' => 'l10'])
        </div>

        <div class="card">
            <h2>To-dos from last week</h2>
            @include('eos._todos', ['todos' => array_merge($openTodos, $recentDone), 'back' => 'l10'])
        </div>

        <div class="card">
            <h2>Issues to IDS</h2>
            <p class="hint">Pick the top 3, then go one at a time: identify the real issue, discuss, solve. A solve usually ends in a to-do.</p>
            @include('eos._issues', ['issues' => $openIssues, 'back' => 'l10'])
        </div>

        <div class="card">
            <h2>Conclude</h2>
            <form method="POST" action="{{ url('/eos') }}">
                @csrf
                <input type="hidden" name="do" value="meeting">
                <div class="row"><label class="q" style="margin:0">Meeting date</label><input type="date" name="date" value="{{ date('Y-m-d') }}"></div>
                <label class="q">Customer and employee headlines</label>
                <textarea name="headlines"></textarea>
                <label class="q">Messages to send and other notes</label>
                <textarea name="notes"></textarea>
                <label class="q">Rate the meeting, 1 to 10 <span class="hint">Anything under 8, say why.</span></label>
                <div class="row">
                    @foreach ($people as $p)
                        <span>{{ $p }} <input type="number" name="rating[{{ $p }}]" min="1" max="10" style="width:64px"></span>
                    @endforeach
                </div>
                <button class="btn" style="margin-top:14px">Save meeting</button>
            </form>
            @if (!empty($d['meetings']))
                <h3>Past meetings</h3>
                <table>
                    <tr><th>Date</th><th>Average</th><th>Headlines</th><th>Notes</th></tr>
                    @foreach (array_slice(array_reverse($d['meetings']), 0, 10) as $mt)
                        @php $r = array_values($mt['ratings'] ?? []); @endphp
                        <tr>
                            <td>{{ $md($mt['date']) }}</td>
                            <td>{{ $r ? number_format(array_sum($r) / count($r), 1) : '' }}</td>
                            <td style="white-space:pre-line">{{ $mt['headlines'] }}</td>
                            <td style="white-space:pre-line">{{ $mt['notes'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endif

    {{-- ============ SCORECARD ============ --}}
    @if ($tab === 'scorecard')
        <div class="card">
            <h2>Scorecard</h2>
            <p class="hint">5 to 15 weekly numbers, each with one owner and a goal. Store sales fill in from the ERP. Type the rest each week before the L10.</p>
            <form method="POST" action="{{ url('/eos') }}" class="eos-sc">
                @csrf
                <input type="hidden" name="do" value="values">
                <div class="eos-scroll">
                <table>
                    <tr>
                        <th>Number</th><th>Owner</th><th>Goal</th>
                        @foreach ($weeks as $w)<th>{{ $md($w) }}</th>@endforeach
                    </tr>
                    @foreach ($d['measurables'] as $m)
                        <tr>
                            <td>{{ $m['name'] }}</td>
                            <td>{{ $m['owner'] }}</td>
                            <td style="white-space:nowrap">{{ $m['goal'] !== '' ? (($m['dir'] ?? 'min') === 'max' ? '≤ ' : '≥ ') . $num((float) $m['goal'], $m['money'] ?? 0) : '' }}</td>
                            @foreach ($weeks as $w)
                                @php $v = $values[$m['id']][$w] ?? null; $ok = $onTrack($m, $v); @endphp
                                <td class="{{ $ok === true ? 'good' : ($ok === false ? 'bad' : '') }}" style="white-space:nowrap">
                                    @if (!empty($m['auto']))
                                        {{ $num($v, 1) }}
                                    @else
                                        <input type="text" name="v[{{ $m['id'] }}][{{ $w }}]" value="{{ $v === null ? '' : $v }}">
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
                </div>
                <button class="btn" style="margin-top:12px">Save numbers</button>
            </form>
        </div>

        <div class="card">
            <h2>Numbers on the Scorecard</h2>
            @foreach ($d['measurables'] as $m)
                <form method="POST" action="{{ url('/eos') }}" class="row" style="margin-bottom:8px">
                    @csrf
                    <input type="hidden" name="do" value="measurable_save">
                    <input type="hidden" name="id" value="{{ $m['id'] }}">
                    <input type="text" name="name" value="{{ $m['name'] }}" class="grow" {{ !empty($m['auto']) ? 'readonly' : '' }}>
                    {!! $ownerSelect('owner', $m['owner']) !!}
                    <select name="dir">
                        <option value="min" {{ ($m['dir'] ?? 'min') === 'min' ? 'selected' : '' }}>at least</option>
                        <option value="max" {{ ($m['dir'] ?? '') === 'max' ? 'selected' : '' }}>at most</option>
                    </select>
                    <input type="text" name="goal" value="{{ $m['goal'] }}" placeholder="Goal" style="width:100px">
                    <label class="hint"><input type="checkbox" name="money" value="1" {{ !empty($m['money']) ? 'checked' : '' }}> dollars</label>
                    <button class="btn small">Save</button>
                    <button class="btn small light" name="do" value="measurable_remove">Remove</button>
                </form>
            @endforeach
            <h3>Add a number</h3>
            <form method="POST" action="{{ url('/eos') }}" class="row">
                @csrf
                <input type="hidden" name="do" value="measurable_save">
                <input type="text" name="name" placeholder="e.g. Used buys spent, Website orders shipped, New Google reviews" class="grow">
                {!! $ownerSelect('owner') !!}
                <select name="dir"><option value="min">at least</option><option value="max">at most</option></select>
                <input type="text" name="goal" placeholder="Goal" style="width:100px">
                <label class="hint"><input type="checkbox" name="money" value="1"> dollars</label>
                <button class="btn small">Add</button>
            </form>
        </div>
    @endif

    {{-- ============ ROCKS ============ --}}
    @if ($tab === 'rocks')
        <div class="card">
            <h2>Rocks, {{ $quarter }}</h2>
            <p class="hint">3 to 7 company Rocks a quarter, and 1 to 3 per person. A Rock is done or not done by the end of the quarter, no partial credit.</p>
            @include('eos._rocks', ['rocks' => $rocksNow, 'back' => 'rocks'])
            <h3>Add a Rock</h3>
            <form method="POST" action="{{ url('/eos') }}" class="row">
                @csrf
                <input type="hidden" name="do" value="rock_add">
                <input type="text" name="title" placeholder="What will be done by the end of the quarter" class="grow">
                {!! $ownerSelect('owner') !!}
                <input type="text" name="quarter" value="{{ $quarter }}" style="width:90px">
                <label class="hint"><input type="checkbox" name="company" value="1"> company Rock</label>
                <button class="btn small">Add</button>
            </form>
        </div>
        @php $past = array_values(array_filter($d['rocks'], function ($r) use ($quarter) { return $r['quarter'] !== $quarter || $r['status'] === 'dropped'; })); @endphp
        @if ($past)
            <div class="card">
                <h2>Other quarters and dropped</h2>
                <table>
                    <tr><th>Quarter</th><th>Rock</th><th>Owner</th><th>Result</th></tr>
                    @foreach (array_reverse($past) as $r)
                        <tr><td>{{ $r['quarter'] }}</td><td>{{ $r['title'] }}</td><td>{{ $r['owner'] }}</td><td><span class="tag {{ $r['status'] }}">{{ $rockLabel[$r['status']] }}</span></td></tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endif

    {{-- ============ ISSUES ============ --}}
    @if ($tab === 'issues')
        <div class="card">
            <h2>Issues List</h2>
            <p class="hint">Anyone on the team can add an issue. They get solved in the weekly L10.</p>
            @include('eos._issues', ['issues' => $openIssues, 'back' => 'issues'])
        </div>
        @php $solved = array_values(array_filter($d['issues'], function ($i) { return $i['status'] === 'solved'; })); @endphp
        @if ($solved)
            <div class="card">
                <h2>Solved</h2>
                <table>
                    <tr><th>Solved</th><th>Issue</th><th>Solution</th><th></th></tr>
                    @foreach (array_slice(array_reverse($solved), 0, 50) as $i)
                        <tr>
                            <td>{{ $md($i['solved_at'] ?? '') }}</td>
                            <td>{{ $i['title'] }}</td>
                            <td style="white-space:pre-line">{{ $i['solution'] ?? '' }}</td>
                            <td>
                                <form method="POST" action="{{ url('/eos') }}">@csrf
                                    <input type="hidden" name="do" value="issue_reopen"><input type="hidden" name="id" value="{{ $i['id'] }}">
                                    <button class="btn small light">Reopen</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endif

    {{-- ============ TO-DOS ============ --}}
    @if ($tab === 'todos')
        <div class="card">
            <h2>To-dos</h2>
            <p class="hint">7-day action items from the L10. Aim for 90% done each week.</p>
            @include('eos._todos', ['todos' => array_merge($openTodos, $recentDone), 'back' => 'todos'])
        </div>
    @endif

    {{-- ============ ACCOUNTABILITY CHART ============ --}}
    @if ($tab === 'seats')
        <div class="card">
            <h2>Accountability Chart</h2>
            <p class="hint">Structure first, then people. Each seat has one person and about 5 roles. This is a starting draft from who does what today, so edit it to match.</p>
        </div>
        <div class="seats">
            @foreach ($d['seats'] as $s)
                <form method="POST" action="{{ url('/eos') }}" class="seat">
                    @csrf
                    <input type="hidden" name="do" value="seat_save">
                    <input type="hidden" name="id" value="{{ $s['id'] }}">
                    <input type="text" name="seat" value="{{ $s['seat'] }}" style="font-weight:800">
                    <input type="text" name="person" value="{{ $s['person'] }}" placeholder="Who sits here">
                    <textarea name="roles" placeholder="One role per line">{{ $s['roles'] }}</textarea>
                    <div class="row" style="margin-top:8px">
                        <button class="btn small">Save</button>
                        <button class="btn small light" name="do" value="seat_remove">Remove seat</button>
                    </div>
                </form>
            @endforeach
            <form method="POST" action="{{ url('/eos') }}" class="seat">
                @csrf
                <input type="hidden" name="do" value="seat_save">
                <input type="text" name="seat" placeholder="New seat" style="font-weight:800">
                <input type="text" name="person" placeholder="Who sits here">
                <textarea name="roles" placeholder="One role per line"></textarea>
                <button class="btn small" style="margin-top:8px">Add seat</button>
            </form>
        </div>
    @endif

    {{-- ============ VISION ============ --}}
    @if ($tab === 'vision')
        <div class="card">
            <h2>Vision / Traction Organizer</h2>
            <p class="hint">Write it with Jon and the managers, then read it out loud to the team every quarter.
                @if (!empty($d['vto_updated'])) Last saved {{ $md(substr($d['vto_updated'], 0, 10)) }}{{ substr($d['vto_updated'], 10) }}. @endif
            </p>
            <form method="POST" action="{{ url('/eos') }}">
                @csrf
                <input type="hidden" name="do" value="vto">
                @foreach ($C::VTO as $k => $q)
                    <label class="q">{{ $q[0] }} <span class="hint">{{ $q[1] }}</span></label>
                    <textarea name="vto_{{ $k }}">{{ $d['vto'][$k] ?? '' }}</textarea>
                @endforeach
                <button class="btn" style="margin-top:14px">Save Vision</button>
            </form>
        </div>
    @endif
</div>
@endsection
