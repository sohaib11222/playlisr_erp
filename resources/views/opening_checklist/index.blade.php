@extends('layouts.app')
@section('title', 'Opening Checklist')

@section('content')
{{-- Morning opening checklist, Hollywood. Reskinned to match /pos/create
     (cream surface, pastel yellow accent, Inter Tight). Scoped under
     .open-shell so it doesn't bleed into the rest of the app. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
.open-shell {
    --d-bg: #FAF6EE;
    --d-surface: #FFFFFF;
    --d-surface-2: #F7F1E3;
    --d-ink: #1F1B16;
    --d-ink-2: #5A5045;
    --d-ink-3: #8E8273;
    --d-line: #ECE3CF;
    --d-line-2: #DFD2B3;
    --d-accent: #FFF2B3;
    --d-accent-deep: #E8CF68;
    --d-accent-soft: #FFF9DB;
    --d-accent-text: #5A4410;
    --d-good: #2E7D32;
    --d-warn: #B26A00;
    --d-radius: 12px;
    --d-radius-sm: 10px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 820px;
    margin: 12px auto 48px;
    padding: 0 16px;
}
.open-shell *, .open-shell *::before, .open-shell *::after { box-sizing: border-box; }

.open-shell .open-header { margin: 12px 4px 16px; }
.open-shell .open-header h1 {
    font-size: 26px; font-weight: 800; letter-spacing: -.01em;
    margin: 0; line-height: 1.2;
}
.open-shell .open-header p {
    font-size: 14px; color: var(--d-ink-3); margin: 6px 0 0; line-height: 1.5;
}

.open-shell .card {
    background: var(--d-surface); border: 1px solid var(--d-line);
    border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06);
    padding: 18px 20px; margin-bottom: 16px;
}

.open-shell .flash {
    border-radius: var(--d-radius-sm); padding: 12px 16px; margin-bottom: 16px;
    font-weight: 600; font-size: 14px;
}
.open-shell .flash.ok { background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep); color: var(--d-accent-text); }

.open-shell .done-banner {
    background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep);
    border-radius: var(--d-radius-sm); padding: 12px 16px; margin-bottom: 16px;
    font-size: 14px; color: var(--d-accent-text);
}

.open-shell .topbar {
    display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;
    margin-bottom: 8px;
}
.open-shell .topbar .fld { flex: 1 1 220px; }
.open-shell label.lbl { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-3); margin: 0 0 5px; }
.open-shell select.input, .open-shell input.input, .open-shell textarea.input {
    width: 100%; border: 1px solid var(--d-line-2); border-radius: var(--d-radius-sm);
    padding: 9px 11px; font: inherit; color: var(--d-ink); background: #fff;
}
.open-shell .progress-pill {
    flex: 0 0 auto; background: var(--d-surface-2); border: 1px solid var(--d-line);
    border-radius: 999px; padding: 8px 16px; font-weight: 700; font-size: 14px;
    white-space: nowrap;
}

.open-shell .grp { margin-top: 18px; }
.open-shell .grp:first-child { margin-top: 4px; }
.open-shell .grp h3 {
    font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--d-ink-2); margin: 0 0 8px; padding-bottom: 6px;
    border-bottom: 2px solid var(--d-accent);
}

.open-shell .item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 12px; border: 1px solid var(--d-line); border-radius: var(--d-radius-sm);
    margin-bottom: 7px; transition: background .12s, border-color .12s;
}
.open-shell .item:hover { background: var(--d-surface-2); }
.open-shell .item-main { display: flex; align-items: flex-start; gap: 12px; flex: 1; cursor: pointer; user-select: none; }
.open-shell .item-link {
    flex: 0 0 auto; font-size: 12.5px; font-weight: 700; white-space: nowrap;
    color: var(--d-accent-text); background: var(--d-accent-soft);
    border: 1px solid var(--d-accent-deep); border-radius: 999px; padding: 5px 12px;
    text-decoration: none;
}
.open-shell .item-link:hover { background: var(--d-accent); }
.open-shell .item input[type=checkbox] {
    appearance: none; -webkit-appearance: none; flex: 0 0 auto;
    width: 22px; height: 22px; margin-top: 1px; border: 2px solid var(--d-line-2);
    border-radius: 6px; background: #fff; cursor: pointer; position: relative;
}
.open-shell .item input[type=checkbox]:checked { background: var(--d-accent); border-color: var(--d-accent-deep); }
.open-shell .item input[type=checkbox]:checked::after {
    content: ""; position: absolute; left: 6px; top: 2px; width: 6px; height: 11px;
    border: solid var(--d-accent-text); border-width: 0 2.5px 2.5px 0; transform: rotate(45deg);
}
.open-shell .item .txt { font-size: 15px; line-height: 1.35; color: var(--d-ink); padding-top: 1px; }
.open-shell .item input[type=checkbox]:checked + .txt { color: var(--d-ink-3); text-decoration: line-through; }

.open-shell .actions { margin-top: 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.open-shell .btn {
    background: var(--d-accent); border: 1px solid var(--d-accent-deep); color: var(--d-accent-text);
    font: inherit; font-weight: 800; font-size: 15px; padding: 11px 22px;
    border-radius: var(--d-radius-sm); cursor: pointer;
}
.open-shell .btn:hover { background: var(--d-accent-deep); }
.open-shell .btn-link { background: none; border: none; color: var(--d-ink-3); font: inherit; font-weight: 600; cursor: pointer; text-decoration: underline; }

.open-shell table.hist { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.open-shell table.hist th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-3); padding: 6px 8px; border-bottom: 1px solid var(--d-line); }
.open-shell table.hist td { padding: 8px; border-bottom: 1px solid var(--d-line); vertical-align: top; color: var(--d-ink-2); }
.open-shell .tag { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.open-shell .tag.full { background: #E6F4E6; color: var(--d-good); }
.open-shell .tag.part { background: #FBEBD2; color: var(--d-warn); }
.open-shell .missed { font-size: 12px; color: var(--d-warn); }
</style>

<div class="open-shell">
    <div class="open-header">
        <h1>Morning Opening Checklist</h1>
        <p>Hollywood. This follows the store front to back — just walk it in order and check each box as you go. Whatever you can't get to, leave it unchecked and let a manager know. Thank you!</p>
    </div>

    @if(session('status') && !empty(session('status')['msg']))
        <div class="flash ok">{{ session('status')['msg'] }}</div>
    @endif

    @if(!empty($doneToday))
        @php $d = $doneToday[0]; @endphp
        <div class="done-banner">
            Today's opening was already logged by <strong>{{ $d['user_name'] }}</strong> at {{ \Carbon\Carbon::parse($d['completed_at'])->format('g:i A') }}
            ({{ $d['checked_count'] }}/{{ $d['total'] }} done).
            You can still submit again if you're re-checking the floor.
        </div>
    @endif

    <form method="POST" action="{{ action('OpeningChecklistController@complete') }}">
        @csrf
        <div class="card">
            <div class="topbar">
                <div class="fld">
                    <label class="lbl">Store</label>
                    <select name="location_id" class="input">
                        @foreach ($locations as $lid => $lname)
                            <option value="{{ $lid }}" @if(stripos($lname, 'holly') !== false) selected @endif>{{ $lname }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="progress-pill"><span id="progCount">0</span> / {{ $totalItems }} done</div>
            </div>

            @foreach ($groups as $groupName => $items)
                <div class="grp">
                    <h3>{{ $groupName }}</h3>
                    @foreach ($items as $key => $label)
                        <div class="item">
                            <label class="item-main">
                                <input type="checkbox" name="items[]" value="{{ $key }}" onchange="openTick()">
                                <span class="txt">{{ $label }}</span>
                            </label>
                            @if(!empty($links[$key]))
                                <a class="item-link" href="{{ url($links[$key]['url']) }}">{{ $links[$key]['text'] }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="grp">
                <label class="lbl">Anything to flag? <span style="font-weight:500;text-transform:none;letter-spacing:0">(optional)</span></label>
                <textarea name="note" class="input" rows="2" placeholder="e.g. rock bins low on stock, neon sign behind the listening station is flickering"></textarea>
            </div>

            <div id="allDoneMsg" style="display:none;margin:4px 0 16px;background:#FFF9DB;border:1px solid #E8CF68;border-radius:10px;padding:13px 16px;font-weight:800;color:#5A4410;font-size:15px;">
                You rock! Thank you, and have a great day! Hit "Complete opening" to log it.
            </div>

            <div class="actions">
                <button type="submit" class="btn">Complete opening</button>
                <button type="button" class="btn-link" onclick="openCheckAll()">Check all</button>
            </div>
        </div>
    </form>

    @if(!empty($recent))
        <div class="card">
            <div class="grp" style="margin-top:0">
                <h3>Recent openings</h3>
                <table class="hist">
                    <thead>
                        <tr><th>When</th><th>Store</th><th>Opened by</th><th>Done</th><th>Left undone</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $r)
                            @php $full = ($r['checked_count'] ?? 0) >= ($r['total'] ?? 0); @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r['completed_at'])->format('M j, g:i A') }}</td>
                                <td>{{ $r['location_name'] ?: '—' }}</td>
                                <td>{{ $r['user_name'] ?? '—' }}</td>
                                <td>
                                    <span class="tag {{ $full ? 'full' : 'part' }}">{{ $r['checked_count'] ?? 0 }}/{{ $r['total'] ?? 0 }}</span>
                                </td>
                                <td>
                                    @if($full)
                                        <span class="missed" style="color:var(--d-good)">All done</span>
                                    @else
                                        <span class="missed">{{ count($r['missed'] ?? []) }} skipped</span>
                                    @endif
                                    @if(!empty($r['note']))<div class="missed" style="color:var(--d-ink-3)">Note: {{ $r['note'] }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script>
function openTick() {
    var boxes = document.querySelectorAll('.open-shell input[name="items[]"]');
    var n = 0;
    boxes.forEach(function (b) { if (b.checked) n++; });
    document.getElementById('progCount').textContent = n;
    var msg = document.getElementById('allDoneMsg');
    if (msg) { msg.style.display = (boxes.length > 0 && n === boxes.length) ? 'block' : 'none'; }
}
function openCheckAll() {
    var boxes = document.querySelectorAll('.open-shell input[name="items[]"]');
    var anyUnchecked = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
    boxes.forEach(function (b) { b.checked = anyUnchecked; });
    openTick();
}
</script>
@endsection
