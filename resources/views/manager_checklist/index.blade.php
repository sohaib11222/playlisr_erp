@extends('layouts.app')
@section('title', 'Manager Checklist')

@section('content')
@php
    $notReady     = $notReady ?? false;
    $tasks        = $tasks ?? [];
    $overdueCount = $overdueCount ?? 0;
    $startDate    = $startDate ?? null;
    $meta         = $meta ?? ['label' => '', 'store' => ''];
    $nav          = $nav ?? null;
    $weekUrl      = function ($w) { return url('/manager-checklist') . '?' . http_build_query(['week' => $w]); };
@endphp
{{-- Cream / pastel-yellow look to match /pos/create and /daily-checklist. --}}
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
    --d-bad: #B3261E;
    --d-bad-bg: #FBEAE8;
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

.open-shell .callout {
    background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep);
    color: var(--d-accent-text); border-radius: var(--d-radius-sm);
    padding: 12px 16px; margin-bottom: 16px; font-weight: 700; font-size: 14.5px;
    display: flex; align-items: center; gap: 9px;
}
.open-shell .callout.bad {
    background: var(--d-bad-bg); border-color: var(--d-bad); color: var(--d-bad);
}

.open-shell .topbar {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    margin-bottom: 4px;
}
.open-shell .progress-pill {
    flex: 0 0 auto; background: var(--d-surface-2); border: 1px solid var(--d-line);
    border-radius: 999px; padding: 8px 16px; font-weight: 700; font-size: 14px;
    white-space: nowrap;
}
.open-shell .saved-note { font-size: 13px; font-weight: 700; color: var(--d-good); opacity: 0; transition: opacity .2s; }
.open-shell .saved-note.show { opacity: 1; }

.open-shell .week-nav {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.open-shell .week-nav .btn {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--d-surface); border: 1px solid var(--d-line-2); color: var(--d-ink-2);
    border-radius: 999px; padding: 7px 14px; font-size: 13.5px; font-weight: 700;
    text-decoration: none; cursor: pointer;
}
.open-shell .week-nav .btn:hover { background: var(--d-surface-2); }
.open-shell .week-nav .week-label { font-weight: 800; font-size: 15px; color: var(--d-ink); }

.open-shell .list-head {
    display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
    margin: 4px 0 10px;
}
.open-shell .list-head h3 {
    font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--d-ink-2); margin: 0;
}
.open-shell .list-head .count { font-size: 12.5px; font-weight: 600; color: var(--d-ink-3); }

.open-shell .item {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    padding: 11px 12px; border: 1px solid var(--d-line); border-radius: var(--d-radius-sm);
    margin: 8px 0 0; transition: background .12s, border-color .12s;
}
.open-shell .item:hover { background: var(--d-surface-2); }
.open-shell .item.overdue { border-color: var(--d-bad); background: var(--d-bad-bg); }
.open-shell .item.overdue:hover { background: var(--d-bad-bg); }
.open-shell .item-main { display: flex; align-items: flex-start; gap: 12px; flex: 1 1 220px; cursor: pointer; user-select: none; min-width: 0; }
.open-shell .item input[type=checkbox] {
    appearance: none; -webkit-appearance: none; flex: 0 0 auto;
    width: 22px; height: 22px; margin-top: 1px; border: 2px solid var(--d-line-2);
    border-radius: 6px; background: #fff; cursor: pointer; position: relative;
}
.open-shell .item.overdue input[type=checkbox] { border-color: var(--d-bad); }
.open-shell .item input[type=checkbox]:checked { background: var(--d-accent); border-color: var(--d-accent-deep); }
.open-shell .item input[type=checkbox]:checked::after {
    content: ""; position: absolute; left: 6px; top: 2px; width: 6px; height: 11px;
    border: solid var(--d-accent-text); border-width: 0 2.5px 2.5px 0; transform: rotate(45deg);
}
.open-shell .item .txt-wrap { padding-top: 1px; min-width: 0; }
.open-shell .item .txt { font-size: 15px; line-height: 1.35; color: var(--d-ink); display: block; }
.open-shell .item .how { font-size: 13px; line-height: 1.4; color: var(--d-ink-3); margin-top: 3px; display: block; }
.open-shell .item .how a { color: var(--d-accent-text); font-weight: 700; text-decoration: underline; }
.open-shell .item input[type=checkbox]:checked ~ .txt-wrap .txt { color: var(--d-ink-3); text-decoration: line-through; }
.open-shell .item input[type=checkbox]:checked ~ .txt-wrap .how { opacity: .6; }

.open-shell .item .meta {
    display: flex; align-items: center; gap: 8px; flex: 0 0 auto; margin-left: auto;
}
.open-shell .freq-badge {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    padding: 3px 8px; border-radius: 999px; white-space: nowrap;
    background: var(--d-surface-2); color: var(--d-ink-3); border: 1px solid var(--d-line);
}
.open-shell .period-note { font-size: 12.5px; color: var(--d-ink-3); white-space: nowrap; }
.open-shell .due { font-size: 13px; font-weight: 700; color: var(--d-ink-2); white-space: nowrap; }
.open-shell .due.due-overdue { color: var(--d-bad); }
.open-shell .item.overdue .period-note { color: var(--d-bad); opacity: .85; }
</style>

<div class="open-shell">
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Manager Checklist</h1>
            <p>
                {{ $meta['label'] }} - {{ $meta['store'] }}.
                Tasks sorted by due date - soonest first. Tick each one off as you go, it saves automatically.
            </p>
        </div>
        @include('partials.pin_button', ['pinUrl' => url('/manager-checklist'), 'pinLabel' => 'Manager Checklist'])
    </div>

    @if($notReady)
        <div class="callout">
            This page isn't set up yet - the Manager Checklist database table hasn't been migrated.
            Ask Sarah or Jon to run the migration, then reload this page.
        </div>
    @else
        @if($overdueCount > 0)
            <div class="callout bad">
                {{ $overdueCount }} {{ $overdueCount === 1 ? 'task is' : 'tasks are' }} overdue - see below.
            </div>
        @endif

        @if($nav)
            <div class="week-nav">
                @if($nav['prev'])
                    <a href="{{ $weekUrl($nav['prev']) }}" class="btn"><i class="fa fa-chevron-left"></i> Prev week</a>
                @endif
                @if(!$nav['is_current'])
                    <a href="{{ $weekUrl($nav['this_week']) }}" class="btn">This week</a>
                @endif
                <a href="{{ $weekUrl($nav['next']) }}" class="btn">Next week <i class="fa fa-chevron-right"></i></a>
                <span class="week-label">{{ $nav['week_label'] }}</span>
            </div>
        @endif

        <div class="card">
            <div class="list-head">
                <h3>Tasks</h3>
                <span class="count">{{ count($tasks) }} shown</span>
            </div>

            @forelse ($tasks as $t)
                @php
                    $dueDate = \Carbon\Carbon::parse($t['due_date']);
                    $dueText = ($t['overdue'] ? 'Overdue - was due ' : 'Due ') . $dueDate->format('D, M j');
                @endphp
                <div class="item {{ $t['overdue'] ? 'overdue' : '' }}">
                    <label class="item-main">
                        <input type="checkbox" class="task-box"
                               data-key="{{ $t['key'] }}" data-period="{{ $t['period_key'] }}"
                               {{ $t['done'] ? 'checked' : '' }}>
                        <span class="txt-wrap">
                            <span class="txt">{{ $t['label'] }}</span>
                            @if(!empty($t['how']))
                                {{-- $t['how'] is a fixed constant from ManagerChecklistController, not user input - safe to render unescaped so the links work. --}}
                                <span class="how">{!! $t['how'] !!}</span>
                            @endif
                        </span>
                    </label>
                    <div class="meta">
                        <span class="freq-badge">{{ $t['freq'] }}</span>
                        @if($t['period_note'])
                            <span class="period-note">{{ $t['period_note'] }}</span>
                        @endif
                        <span class="due {{ $t['overdue'] ? 'due-overdue' : '' }}" data-due-for="{{ $t['key'] }}:{{ $t['period_key'] }}">
                            {{ $dueText }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="callout">Nothing due yet.</div>
            @endforelse
        </div>
    @endif
</div>

@if(!$notReady)
<script>
(function () {
    var toggleUrl = '{{ url('/manager-checklist/toggle') }}';
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.getAttribute('content') : '';
    var boxes = document.querySelectorAll('.open-shell .task-box');

    // Links inside the "how" text sit inside the <label>, which would
    // otherwise also toggle the checkbox when clicked. Let the link
    // navigate without touching the checkbox.
    document.querySelectorAll('.open-shell .how a').forEach(function (a) {
        a.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            var row = b.closest('.item');
            var body = new FormData();
            body.append('key', b.getAttribute('data-key'));
            body.append('period_key', b.getAttribute('data-period'));
            body.append('checked', b.checked ? '1' : '0');
            fetch(toggleUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (r) {
                if (!r.ok) { throw new Error('save failed'); }
                return r.json();
            }).then(function (data) {
                if (!data || !data.ok) { throw new Error('save failed'); }
                // Checking off an overdue task clears its overdue styling immediately.
                if (row) { row.classList.remove('overdue'); }
            }).catch(function () {
                b.checked = !b.checked;
                alert('Could not save that - check your connection and try again.');
            });
        });
    });
})();
</script>
@endif
@endsection
