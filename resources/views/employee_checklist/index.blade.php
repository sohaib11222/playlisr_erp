@extends('layouts.app')
@section('title', 'Onboarding / Offboarding')

@section('content')
@php
    $type        = $type        ?? 'onboarding';
    $typeOptions = $typeOptions ?? [];
    $baseUrl     = $baseUrl     ?? '';
    $intro       = $intro       ?? '';
    $isOff       = $type === 'offboarding';
    $editing     = $editing ?? null;
    $editChecked = $editing ? ($editing['checked'] ?? []) : [];
    $heading     = $isOff ? 'Offboarding Checklist' : 'Onboarding Checklist';
    $submitLabel = $editing
        ? 'Save changes'
        : ($isOff ? 'Log offboarding' : 'Log onboarding');
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

.open-shell .flash {
    border-radius: var(--d-radius-sm); padding: 12px 16px; margin-bottom: 16px;
    font-weight: 600; font-size: 14px;
}
.open-shell .flash.ok { background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep); color: var(--d-accent-text); }
.open-shell .flash.warn { background: #FBEAE5; border: 1px solid #E0A99B; color: #8A2C12; }

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
.open-shell .store-toggle { display: inline-flex; gap: 6px; background: var(--d-surface-2); border: 1px solid var(--d-line); border-radius: 999px; padding: 4px; }
.open-shell .store-pill {
    display: inline-block; padding: 7px 18px; border-radius: 999px; font-weight: 700; font-size: 14px;
    color: var(--d-ink-2); text-decoration: none;
}
.open-shell .store-pill.active { background: var(--d-accent); color: var(--d-accent-text); box-shadow: 0 1px 2px rgba(31,27,22,.08); }

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
    @php
        // "Pin to my sidebar" — saves this page to the current user's personal
        // Favorites group (top of the left menu). Per-account; nobody else sees
        // it, and it stays out of the shared menu. See SidebarFavoriteController.
        $pinUrl     = url('/employee-checklist');
        $pinLabel   = 'Onboarding / Offboarding';
        $pinAlready = \App\Http\Controllers\SidebarFavoriteController::isPinned(
            session()->get('user.business_id'),
            session()->get('user.id'),
            $pinUrl
        );
    @endphp
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>{{ $heading }}</h1>
            <p>{{ $intro }}</p>
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
                // Reuse the sidebar helper so the left-menu Favorites group updates live.
                if (window.NivessaSidebarFav && window.NivessaSidebarFav.toggle) {
                    var willBeOn = !window.NivessaSidebarFav.isPinned(url);
                    window.NivessaSidebarFav.toggle(url, label);
                    paint(willBeOn);
                    return;
                }
                // Fallback: post directly if the sidebar script isn't present.
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

    @if(session('status') && !empty(session('status')['msg']))
        <div class="flash {{ (session('status')['success'] ?? 1) ? 'ok' : 'warn' }}">{{ session('status')['msg'] }}</div>
    @endif

    <div class="topbar" style="margin-bottom:16px;">
        <div class="store-toggle">
            @foreach ($typeOptions as $tkey => $tlabel)
                <a href="{{ $baseUrl }}?type={{ $tkey }}" class="store-pill {{ $type === $tkey ? 'active' : '' }}">{{ $tlabel }}</a>
            @endforeach
        </div>
    </div>

    @if($editing)
        <div class="flash ok" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <span>Editing <strong>{{ $editing['employee_name'] ?? '' }}</strong>'s {{ $type }} (logged {{ \Carbon\Carbon::parse($editing['completed_at'])->format('M j, g:i A') }}). Check off what's now done and save.</span>
            <a href="{{ $baseUrl }}?type={{ $type }}" class="store-pill">Cancel</a>
        </div>
    @endif

    @if(!$isOff)
        <div class="card">
            <h3 style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--d-ink-2);margin:0 0 4px;">Compile &amp; Send Offer</h3>
            <p style="font-size:13px;color:var(--d-ink-3);margin:0 0 14px;">Fills the standard offer letter with the job title below, compiles it to a PDF, and emails it for signature. The Job Responsibilities section still uses the cashier duties regardless of title — ask if a role needs its own.</p>
            <form method="POST" action="{{ route('employee-checklist.send-offer') }}">
                @csrf
                <div class="topbar" style="margin-bottom:0;">
                    <div class="fld">
                        <label class="lbl">Full name</label>
                        <input type="text" name="full_name" class="input" placeholder="First Last" required>
                    </div>
                    <div class="fld">
                        <label class="lbl">Email</label>
                        <input type="email" name="email" class="input" placeholder="name@email.com" required>
                    </div>
                    <div class="fld">
                        <label class="lbl">Job title</label>
                        <input type="text" name="job_title" class="input" placeholder="e.g. Sales Cashier" value="Sales Cashier" required>
                    </div>
                    <div class="fld">
                        <label class="lbl">Start date</label>
                        <input type="text" name="start_date" class="input" placeholder="e.g. September 22, 2026" required>
                    </div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Compile &amp; Send Offer</button>
                </div>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ action('EmployeeChecklistController@complete') }}">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        @if($editing)<input type="hidden" name="edit_id" value="{{ $editing['id'] }}">@endif
        <div class="card">
            <div class="topbar">
                <div class="fld" style="flex:1 1 260px;">
                    <label class="lbl">Employee name</label>
                    <input type="text" name="employee_name" class="input" placeholder="Who is this {{ $type }} for?" value="{{ $editing['employee_name'] ?? '' }}" required>
                </div>
                <div class="progress-pill" style="margin-left:auto;"><span id="progCount">0</span> / {{ $totalItems }} done</div>
            </div>

            @foreach ($groups as $groupName => $items)
                <div class="grp">
                    <h3>{{ $groupName }}</h3>
                    @foreach ($items as $key => $label)
                        <div class="item">
                            <label class="item-main">
                                <input type="checkbox" name="items[]" value="{{ $key }}" onchange="openTick()" {{ in_array($key, $editChecked, true) ? 'checked' : '' }}>
                                <span class="txt">{{ $label }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endforeach

            <div class="grp">
                <label class="lbl">Notes <span style="font-weight:500;text-transform:none;letter-spacing:0">(optional)</span></label>
                <textarea name="note" class="input" rows="2" placeholder="e.g. waiting on signed handbook, ERP account pending role assignment">{{ $editing['note'] ?? '' }}</textarea>
            </div>

            <div id="allDoneMsg" style="display:none;margin:4px 0 16px;background:#FFF9DB;border:1px solid #E8CF68;border-radius:10px;padding:13px 16px;font-weight:800;color:#5A4410;font-size:15px;">
                Everything checked. Hit "{{ $submitLabel }}" to record it.
            </div>

            <div class="actions">
                <button type="submit" class="btn">{{ $submitLabel }}</button>
                <button type="button" class="btn-link" onclick="openCheckAll()">Check all</button>
            </div>
        </div>
    </form>

    @if(!empty($recent))
        <div class="card">
            <div class="grp" style="margin-top:0">
                <h3>Recent {{ strtolower($type) }}</h3>
                <table class="hist">
                    <thead>
                        <tr><th>When</th><th>Employee</th><th>By</th><th>Done</th><th>Skipped</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $r)
                            @php $full = ($r['checked_count'] ?? 0) >= ($r['total'] ?? 0); @endphp
                            <tr>
                                <td>
                                    {{ \Carbon\Carbon::parse($r['completed_at'])->format('M j, g:i A') }}
                                    @if(!empty($r['updated_at']))<div class="missed" style="color:var(--d-ink-3)">edited {{ \Carbon\Carbon::parse($r['updated_at'])->format('M j, g:i A') }}{{ !empty($r['updated_by']) ? ' by '.$r['updated_by'] : '' }}</div>@endif
                                </td>
                                <td>{{ $r['employee_name'] ?? 'n/a' }}</td>
                                <td>{{ $r['user_name'] ?? 'n/a' }}</td>
                                <td>
                                    <span class="tag {{ $full ? 'full' : 'part' }}">{{ $r['checked_count'] ?? 0 }}/{{ $r['total'] ?? 0 }}</span>
                                </td>
                                <td>
                                    @if($full)
                                        <span class="missed" style="color:var(--d-good)">Nothing skipped</span>
                                    @else
                                        <ul style="margin:0;padding-left:16px;">
                                            @foreach (($r['missed'] ?? []) as $mk)
                                                <li class="missed">{{ $itemLabels[$mk] ?? $mk }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if(!empty($r['note']))<div class="missed" style="color:var(--d-ink-3)">Note: {{ $r['note'] }}</div>@endif
                                </td>
                                <td><a href="{{ $baseUrl }}?type={{ $type }}&edit={{ $r['id'] }}" class="store-pill" style="padding:5px 14px;font-size:13px;">Edit</a></td>
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
