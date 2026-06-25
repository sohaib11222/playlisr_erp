@extends('layouts.app')
@section('title', 'Daily Checklist')

@section('content')
@php
    $checked = $checked ?? [];
    $links   = $links   ?? [];
    $today   = $today   ?? date('Y-m-d');
@endphp
{{-- Cream / pastel-yellow look to match /pos/create. Scoped under .open-shell. --}}
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
    display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
    margin-bottom: 4px;
}
.open-shell .progress-pill {
    flex: 0 0 auto; background: var(--d-surface-2); border: 1px solid var(--d-line);
    border-radius: 999px; padding: 8px 16px; font-weight: 700; font-size: 14px;
    white-space: nowrap;
}
.open-shell .saved-note { font-size: 13px; font-weight: 700; color: var(--d-good); opacity: 0; transition: opacity .2s; }
.open-shell .saved-note.show { opacity: 1; }
.open-shell .date-note { font-size: 13px; color: var(--d-ink-3); margin-left: auto; }

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
.open-shell .item .txt a { color: var(--d-accent-text); font-weight: 700; }

.open-shell table.hist { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.open-shell table.hist th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-3); padding: 6px 8px; border-bottom: 1px solid var(--d-line); }
.open-shell table.hist td { padding: 8px; border-bottom: 1px solid var(--d-line); vertical-align: top; color: var(--d-ink-2); }
.open-shell .tag { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.open-shell .tag.full { background: #E6F4E6; color: var(--d-good); }
.open-shell .tag.part { background: #FBEBD2; color: var(--d-warn); }
</style>

<div class="open-shell">
    @php
        // "Pin to my sidebar" — saves this page to the current user's personal
        // Favorites group (top of the left menu). Per-account.
        $pinUrl     = url('/daily-checklist');
        $pinLabel   = 'Daily Checklist';
        $pinAlready = \App\Http\Controllers\SidebarFavoriteController::isPinned(
            session()->get('user.business_id'),
            session()->get('user.id'),
            $pinUrl
        );
    @endphp
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Daily Checklist</h1>
            <p>Your tasks for the day. Tick each one as you finish — it saves automatically, and the list starts fresh tomorrow.</p>
        </div>
        <button type="button" class="pin-btn {{ $pinAlready ? 'is-on' : '' }}"
                data-pin-url="{{ $pinUrl }}" data-pin-label="{{ $pinLabel }}"
                title="{{ $pinAlready ? 'Pinned to your sidebar' : 'Pin this page to your sidebar' }}">
            <i class="fa {{ $pinAlready ? 'fa-star' : 'fa-star-o' }}"></i>
            <span class="pin-text">{{ $pinAlready ? 'Pinned' : 'Pin to my sidebar' }}</span>
        </button>
    </div>

    <style>
    .open-shell .pin-btn {
        flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px;
        white-space: nowrap; cursor: pointer; font: inherit; font-size: 13px;
        font-weight: 700; color: var(--d-accent-text);
        background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep);
        border-radius: 999px; padding: 8px 14px; line-height: 1;
        transition: background .12s ease, box-shadow .12s ease;
    }
    .open-shell .pin-btn:hover { background: var(--d-accent); }
    .open-shell .pin-btn.is-on { background: var(--d-accent); box-shadow: inset 0 0 0 1px var(--d-accent-deep); }
    .open-shell .pin-btn .fa { color: var(--d-accent-deep); }
    .open-shell .pin-btn.is-on .fa { color: #C99A12; }
    </style>
    <script>
    (function () {
        function ready(fn) {
            if (document.readyState !== 'loading') { fn(); }
            else { document.addEventListener('DOMContentLoaded', fn); }
        }
        ready(function () {
            var btn = document.querySelector('.pin-btn[data-pin-url]');
            if (!btn) { return; }
            var url = btn.getAttribute('data-pin-url');
            var label = btn.getAttribute('data-pin-label');

            function paint(on) {
                btn.classList.toggle('is-on', on);
                var ic = btn.querySelector('.fa');
                if (ic) { ic.className = 'fa ' + (on ? 'fa-star' : 'fa-star-o'); }
                var t = btn.querySelector('.pin-text');
                if (t) { t.textContent = on ? 'Pinned' : 'Pin to my sidebar'; }
                btn.title = on ? 'Pinned to your sidebar' : 'Pin this page to your sidebar';
            }

            btn.addEventListener('click', function () {
                if (window.NivessaSidebarFav && window.NivessaSidebarFav.toggle) {
                    var willBeOn = !window.NivessaSidebarFav.isPinned(url);
                    window.NivessaSidebarFav.toggle(url, label);
                    paint(willBeOn);
                    return;
                }
                var tokenEl = document.querySelector('meta[name="csrf-token"]');
                var body = new FormData();
                body.append('url', url);
                body.append('label', label);
                fetch('{{ url('/sidebar-favorites/toggle') }}', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : '',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.ok) { paint(d.starred); }
                }).catch(function () {});
            });
        });
    })();
    </script>

    <div class="callout">
        <i class="fa fa-phone"></i> Answer phone calls while you work — keep the phone with you all day.
    </div>

    <div class="card">
        <div class="topbar">
            <div class="progress-pill"><span id="progCount">0</span> / {{ $totalItems }} done</div>
            <span class="saved-note" id="savedNote">Saved</span>
            <span class="date-note">{{ \Carbon\Carbon::parse($today)->format('l, M j') }}</span>
        </div>

        @foreach ($groups as $groupName => $items)
            <div class="grp">
                <h3>{{ $groupName }}</h3>
                @foreach ($items as $key => $label)
                    <div class="item">
                        <label class="item-main">
                            <input type="checkbox" class="task-box" value="{{ $key }}" {{ in_array($key, $checked, true) ? 'checked' : '' }}>
                            <span class="txt">
                                {{ $label }}
                                @if(!empty($links[$key]))
                                    <a href="{{ url($links[$key]) }}" target="_blank" rel="noopener" onclick="event.stopPropagation();">Open &rarr;</a>
                                @endif
                            </span>
                        </label>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    @if(!empty($recent))
        <div class="card">
            <div class="grp" style="margin-top:0">
                <h3>Recent days</h3>
                <table class="hist">
                    <thead>
                        <tr><th>Day</th><th>Who</th><th>Done</th><th>Last update</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $r)
                            @php
                                $cnt = count($r['checked'] ?? []);
                                $tot = $r['total'] ?? $totalItems;
                                $full = $cnt >= $tot;
                            @endphp
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r['date'])->format('D, M j') }}</td>
                                <td>{{ $r['user_name'] ?? 'n/a' }}</td>
                                <td><span class="tag {{ $full ? 'full' : 'part' }}">{{ $cnt }}/{{ $tot }}</span></td>
                                <td>{{ !empty($r['updated_at']) ? \Carbon\Carbon::parse($r['updated_at'])->format('g:i A') : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script>
(function () {
    var toggleUrl = '{{ url('/daily-checklist/toggle') }}';
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.getAttribute('content') : '';
    var boxes = document.querySelectorAll('.open-shell .task-box');
    var savedNote = document.getElementById('savedNote');
    var savedTimer = null;

    function refreshCount() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) n++; });
        document.getElementById('progCount').textContent = n;
    }

    function flashSaved() {
        if (!savedNote) { return; }
        savedNote.classList.add('show');
        if (savedTimer) { clearTimeout(savedTimer); }
        savedTimer = setTimeout(function () { savedNote.classList.remove('show'); }, 1200);
    }

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            refreshCount();
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
                flashSaved();
            }).catch(function () {
                // Roll the box back so the screen matches what's actually saved.
                b.checked = !b.checked;
                refreshCount();
                alert('Could not save that — check your connection and try again.');
            });
        });
    });

    refreshCount();
})();
</script>
@endsection
