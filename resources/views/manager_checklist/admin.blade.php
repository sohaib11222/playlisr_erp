@extends('layouts.app')
@section('title', 'Manager Checklists')

@section('content')
@php
    $notReady = $notReady ?? false;
    $managers = $managers ?? [];
    $startDate = $startDate ?? null;
@endphp
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
    max-width: 1200px;
    margin: 12px auto 48px;
    padding: 0 16px;
}
.open-shell *, .open-shell *::before, .open-shell *::after { box-sizing: border-box; }

.open-shell .open-header { margin: 12px 4px 16px; }
.open-shell .open-header h1 { font-size: 26px; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; }
.open-shell .open-header p { font-size: 14px; color: var(--d-ink-3); margin: 6px 0 0; line-height: 1.5; }

.open-shell .callout {
    background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep);
    color: var(--d-accent-text); border-radius: var(--d-radius-sm);
    padding: 12px 16px; margin-bottom: 16px; font-weight: 700; font-size: 14.5px;
}

.open-shell .managers-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 16px; margin-bottom: 16px; align-items: start;
}

.open-shell .card {
    background: var(--d-surface); border: 1px solid var(--d-line);
    border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06);
    padding: 18px 20px; margin-bottom: 16px;
}

.open-shell .mgr-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
.open-shell .mgr-head h2 { font-size: 18px; font-weight: 800; margin: 0; }
.open-shell .mgr-head .store { font-size: 12.5px; font-weight: 700; color: var(--d-ink-3); text-transform: uppercase; letter-spacing: .04em; }
.open-shell .mgr-missing { font-size: 13px; color: var(--d-bad); font-weight: 700; margin-bottom: 8px; }

.open-shell .stat-pills { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0 14px; }
.open-shell .stat-pill {
    display: inline-flex; align-items: baseline; gap: 5px; padding: 6px 12px; border-radius: 999px;
    font-size: 13px; font-weight: 700; background: var(--d-surface-2); border: 1px solid var(--d-line); color: var(--d-ink-2);
}
.open-shell .stat-pill.bad { background: var(--d-bad-bg); border-color: var(--d-bad); color: var(--d-bad); }
.open-shell .stat-pill.good { background: #E6F4E6; border-color: var(--d-good); color: var(--d-good); }
.open-shell .stat-pill b { font-size: 15px; }

.open-shell .task-list { max-height: 560px; overflow-y: auto; padding-right: 2px; }
.open-shell .row {
    display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
    padding: 9px 10px; border: 1px solid var(--d-line); border-radius: var(--d-radius-sm);
    margin-top: 6px; font-size: 13.5px;
}
.open-shell .row.overdue { border-color: var(--d-bad); background: var(--d-bad-bg); }
.open-shell .row .main { display: flex; align-items: center; gap: 8px; flex: 1 1 200px; min-width: 0; }
.open-shell .row .dot { flex: 0 0 auto; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
.open-shell .row .dot.on { background: #E6F4E6; color: var(--d-good); }
.open-shell .row .dot.off { background: var(--d-surface-2); color: var(--d-ink-3); }
.open-shell .row.overdue .dot.off { background: var(--d-bad-bg); color: var(--d-bad); }
.open-shell .row .dot .fa { font-size: 10px; }
.open-shell .row .label { color: var(--d-ink); }
.open-shell .row.overdue .label { color: var(--d-bad); font-weight: 600; }
.open-shell .row .done .label { color: var(--d-ink-3); text-decoration: line-through; font-weight: 400; }
.open-shell .row .meta { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; margin-left: auto; }
.open-shell .freq-badge {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    padding: 2px 7px; border-radius: 999px; white-space: nowrap;
    background: var(--d-surface-2); color: var(--d-ink-3); border: 1px solid var(--d-line);
}
.open-shell .due { font-size: 12.5px; font-weight: 700; color: var(--d-ink-2); white-space: nowrap; }
.open-shell .due.due-overdue { color: var(--d-bad); }
</style>

<div class="open-shell">
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Manager Checklists</h1>
            <p>Zakary (Pico) and Luis (Hollywood) - full task list, soonest due date first. Overdue items are flagged red.</p>
        </div>
        @include('partials.pin_button', ['pinUrl' => url('/admin/manager-checklists'), 'pinLabel' => 'Manager Checklists'])
    </div>

    @if($notReady)
        <div class="callout">
            This page isn't set up yet - the Manager Checklist database table hasn't been migrated.
            Dispatch the "Run migrations" workflow, then reload this page.
        </div>
    @else
        <div class="managers-grid">
            @foreach ($managers as $key => $m)
                <div class="card">
                    <div class="mgr-head">
                        <h2>{{ $m['label'] }}</h2>
                        <span class="store">{{ $m['store'] }}</span>
                    </div>
                    @if(!$m['found'])
                        <div class="mgr-missing">No matching user account found (looked for first name "{{ ucfirst($key) }}").</div>
                    @endif

                    <div class="stat-pills">
                        <span class="stat-pill {{ $m['overdueCount'] > 0 ? 'bad' : 'good' }}">
                            <b>{{ $m['overdueCount'] }}</b> overdue
                        </span>
                        <span class="stat-pill good"><b>{{ $m['doneCount'] }}</b> done</span>
                        <span class="stat-pill"><b>{{ $m['totalCount'] }}</b> total in list</span>
                    </div>

                    <div class="task-list">
                        @forelse ($m['tasks'] as $t)
                            @php
                                $dueDate = \Carbon\Carbon::parse($t['due_date']);
                                $dueText = ($t['overdue'] ? 'Overdue - ' : 'Due ') . $dueDate->format('D, M j');
                            @endphp
                            <div class="row {{ $t['overdue'] ? 'overdue' : '' }} {{ $t['done'] ? 'done' : '' }}">
                                <div class="main">
                                    <span class="dot {{ $t['done'] ? 'on' : 'off' }}"><i class="fa {{ $t['done'] ? 'fa-check' : 'fa-times' }}"></i></span>
                                    <span class="label">{{ $t['label'] }}</span>
                                </div>
                                <div class="meta">
                                    <span class="freq-badge">{{ $t['freq'] }}</span>
                                    <span class="due {{ $t['overdue'] ? 'due-overdue' : '' }}">{{ $dueText }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="callout">Nothing in the list yet.</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
