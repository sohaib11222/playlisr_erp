@extends('layouts.app')
@section('title', 'Manager Checklist')

@section('content')
@php
    $notReady = $notReady ?? false;
    $groups   = $groups   ?? [];
    $checked  = $checked  ?? [];
    $periods  = $periods  ?? [];
    $meta     = $meta     ?? ['label' => '', 'store' => ''];
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

.open-shell .grp { margin-top: 18px; }
.open-shell .grp:first-child { margin-top: 4px; }
.open-shell .grp h3 {
    font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--d-ink-2); margin: 0 0 2px; padding-bottom: 6px;
    border-bottom: 2px solid var(--d-accent);
    display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
}
.open-shell .grp h3 .period-note {
    text-transform: none; letter-spacing: 0; font-weight: 600; font-size: 12.5px; color: var(--d-ink-3);
}

.open-shell .item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 12px; border: 1px solid var(--d-line); border-radius: var(--d-radius-sm);
    margin: 8px 0 0; transition: background .12s, border-color .12s;
}
.open-shell .item:hover { background: var(--d-surface-2); }
.open-shell .item-main { display: flex; align-items: flex-start; gap: 12px; flex: 1; cursor: pointer; user-select: none; }
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
</style>

<div class="open-shell">
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Manager Checklist</h1>
            <p>
                {{ $meta['label'] }} - {{ $meta['store'] }}.
                Tick each item off as you go - it saves automatically. Daily items reset every day,
                weekly items reset Monday, monthly items reset on the 1st.
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
        <div class="card">
            @foreach ($groups as $groupName => $items)
                <div class="grp">
                    <h3>
                        <span>{{ $groupName }}</span>
                        <span class="period-note">
                            {{ $periods[$groupName] ?? '' }}
                            <span class="saved-note" data-saved-for="{{ $groupName }}">Saved</span>
                        </span>
                    </h3>
                    @foreach ($items as $key => $label)
                        <div class="item">
                            <label class="item-main">
                                <input type="checkbox" class="task-box" data-group="{{ $groupName }}" value="{{ $key }}"
                                       {{ in_array($key, $checked[$groupName] ?? [], true) ? 'checked' : '' }}>
                                <span class="txt">{{ $label }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endforeach
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

    function flashSaved(group) {
        var note = document.querySelector('.saved-note[data-saved-for="' + group + '"]');
        if (!note) { return; }
        note.classList.add('show');
        setTimeout(function () { note.classList.remove('show'); }, 1200);
    }

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            var group = b.getAttribute('data-group');
            var body = new FormData();
            body.append('key', b.value);
            body.append('checked', b.checked ? '1' : '0');
            fetch(toggleUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (r) {
                if (!r.ok) { throw new Error('save failed'); }
                return r.json();
            }).then(function () {
                flashSaved(group);
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
