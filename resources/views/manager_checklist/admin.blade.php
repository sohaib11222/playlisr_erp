@extends('layouts.app')
@section('title', 'Manager Checklists')

@section('content')
@php
    $notReady   = $notReady ?? false;
    $managers   = $managers ?? [];
    $groups     = $groups ?? [];
    $periods    = $periods ?? [];
    $dailyTotal = $dailyTotal ?? 0;
    $history    = $history ?? [];
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
    --d-radius: 12px;
    --d-radius-sm: 10px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 1080px;
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
    display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 16px; margin-bottom: 16px;
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

.open-shell .grp { margin-top: 14px; }
.open-shell .grp:first-child { margin-top: 8px; }
.open-shell .grp h3 {
    font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--d-ink-2); margin: 0 0 6px; padding-bottom: 5px; border-bottom: 2px solid var(--d-accent);
    display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
}
.open-shell .grp h3 .period-note { text-transform: none; letter-spacing: 0; font-weight: 600; font-size: 12px; color: var(--d-ink-3); }

.open-shell .chk-row { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 13.5px; }
.open-shell .chk-row .dot { flex: 0 0 auto; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
.open-shell .chk-row .dot.on { background: #E6F4E6; color: var(--d-good); }
.open-shell .chk-row .dot.off { background: #FBEAE8; color: var(--d-bad); }
.open-shell .chk-row .dot .fa { font-size: 10px; }
.open-shell .chk-row .done { color: var(--d-ink-3); text-decoration: line-through; }

.open-shell table.hist { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.open-shell table.hist th { text-align: center; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-3); padding: 6px 8px; border-bottom: 1px solid var(--d-line); }
.open-shell table.hist th:first-child { text-align: left; }
.open-shell table.hist td { padding: 8px; border-bottom: 1px solid var(--d-line); vertical-align: middle; color: var(--d-ink-2); text-align: center; }
.open-shell table.hist td:first-child { text-align: left; font-weight: 600; color: var(--d-ink); }
.open-shell .tag { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.open-shell .tag.full { background: #E6F4E6; color: var(--d-good); }
.open-shell .tag.part { background: #FBEBD2; color: var(--d-warn); }
.open-shell .tag.none { background: #FBEAE8; color: var(--d-bad); }
</style>

<div class="open-shell">
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Manager Checklists</h1>
            <p>Zakary (Pico) and Luis (Hollywood) - current period status and recent daily history.</p>
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
                    @foreach ($groups as $groupName => $items)
                        <div class="grp">
                            <h3>
                                <span>{{ $groupName }}</span>
                                <span class="period-note">{{ $periods[$groupName] ?? '' }}</span>
                            </h3>
                            @foreach ($items as $itemKey => $label)
                                @php $done = in_array($itemKey, $m['checked'][$groupName] ?? [], true); @endphp
                                <div class="chk-row">
                                    <span class="dot {{ $done ? 'on' : 'off' }}"><i class="fa {{ $done ? 'fa-check' : 'fa-times' }}"></i></span>
                                    <span class="{{ $done ? 'done' : '' }}">{{ $label }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="grp" style="margin-top:0">
                <h3><span>Daily - last 7 days</span></h3>
                <table class="hist">
                    <thead>
                        <tr>
                            <th>Day</th>
                            @foreach ($managers as $m)
                                <th>{{ $m['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                @foreach ($managers as $key => $m)
                                    @php
                                        $cnt = $row[$key] ?? 0;
                                        $tag = $cnt >= $dailyTotal ? 'full' : ($cnt > 0 ? 'part' : 'none');
                                    @endphp
                                    <td><span class="tag {{ $tag }}">{{ $cnt }}/{{ $dailyTotal }}</span></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
