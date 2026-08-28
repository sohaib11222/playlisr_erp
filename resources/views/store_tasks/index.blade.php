@extends('layouts.app')
@section('title', 'Employee Tasks')

@section('content')
@php
    $notReady   = $notReady ?? false;
    $store      = $store ?? 'pico';
    $storeLabel = $storeLabel ?? '';
    $stores     = $stores ?? [];
    $canManage  = $canManage ?? false;
    $tasks      = $tasks ?? [];
    $employees  = $employees ?? [];
    $userId     = $userId ?? null;
    $weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $weekdayFull  = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $storeUrl = function ($s) { return url('/employee-tasks') . '?' . http_build_query(['store' => $s]); };
    $initials = function ($name) {
        $parts = array_filter(preg_split('/\s+/', trim((string) $name)));
        if (empty($parts)) return '?';
        $first = mb_substr(reset($parts), 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    };
    // A consistent color per person (hash of their id into a fixed palette),
    // matching Asana's per-assignee avatar coloring.
    $avatarPalette = ['#E8CF68', '#8FB9A8', '#E3A0A0', '#A6B7D4', '#C9A6D4', '#D9B27C', '#8FC1D4', '#B7C98F'];
    $avatarColor = function ($id) use ($avatarPalette) {
        return $avatarPalette[$id % count($avatarPalette)];
    };
    $once  = array_values(array_filter($tasks, function ($t) { return $t['recurrence'] === 'once'; }));
    $weekly = array_values(array_filter($tasks, function ($t) { return $t['recurrence'] === 'weekly'; }));
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
.et-shell {
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
    --d-bad: #B3261E;
    --d-radius: 12px;
    --d-radius-sm: 8px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 920px;
    margin: 12px auto 48px;
    padding: 0 16px;
}
.et-shell *, .et-shell *::before, .et-shell *::after { box-sizing: border-box; }

.et-shell .et-header { margin: 12px 4px 16px; }
.et-shell .et-header h1 { font-size: 26px; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; }
.et-shell .et-header p { font-size: 14px; color: var(--d-ink-3); margin: 6px 0 0; line-height: 1.5; }

.et-shell .store-tabs { display: flex; gap: 6px; margin-bottom: 14px; }
.et-shell .store-tabs a {
    padding: 7px 16px; border-radius: 999px; font-size: 13.5px; font-weight: 700;
    text-decoration: none; color: var(--d-ink-2); background: var(--d-surface); border: 1px solid var(--d-line-2);
}
.et-shell .store-tabs a.active { background: var(--d-accent); border-color: var(--d-accent-deep); color: var(--d-accent-text); }

.et-shell .callout {
    background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep);
    color: var(--d-accent-text); border-radius: var(--d-radius-sm);
    padding: 12px 16px; margin-bottom: 16px; font-weight: 700; font-size: 14.5px;
}

.et-shell .board {
    background: var(--d-surface); border: 1px solid var(--d-line); border-radius: var(--d-radius);
    overflow: hidden;
}

.et-shell .col-head {
    display: grid; grid-template-columns: 30px 1fr 170px 130px 30px; gap: 10px; align-items: center;
    padding: 10px 16px; border-bottom: 1px solid var(--d-line);
    font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--d-ink-3);
}

.et-shell .section { border-bottom: 1px solid var(--d-line); }
.et-shell .section:last-child { border-bottom: none; }
.et-shell .section-head {
    display: flex; align-items: center; gap: 8px; padding: 12px 16px 6px; cursor: pointer; user-select: none;
}
.et-shell .section-head .caret { font-size: 10px; color: var(--d-ink-3); transition: transform .12s; display: inline-block; }
.et-shell .section-head.collapsed .caret { transform: rotate(-90deg); }
.et-shell .section-head h3 { font-size: 14.5px; font-weight: 800; margin: 0; color: var(--d-ink); }
.et-shell .section-head .count {
    font-size: 11.5px; font-weight: 700; color: var(--d-ink-3); background: var(--d-surface-2);
    border-radius: 999px; padding: 1px 8px;
}
.et-shell .section-body.collapsed { display: none; }

.et-shell .task-row {
    display: grid; grid-template-columns: 30px 1fr 170px 130px 30px; gap: 10px; align-items: center;
    padding: 8px 16px; border-top: 1px solid var(--d-line); transition: background .1s;
}
.et-shell .task-row:hover { background: var(--d-surface-2); }
.et-shell .task-row .del-btn { visibility: hidden; }
.et-shell .task-row:hover .del-btn { visibility: visible; }
.et-shell .task-row.overdue .due-cell { color: var(--d-bad); font-weight: 700; }

.et-shell .task-row input[type=checkbox] {
    appearance: none; -webkit-appearance: none; width: 20px; height: 20px; border: 2px solid var(--d-line-2);
    border-radius: 50%; background: #fff; cursor: pointer; position: relative; flex: 0 0 auto;
}
.et-shell .task-row.overdue input[type=checkbox] { border-color: var(--d-bad); }
.et-shell .task-row input[type=checkbox]:checked { background: var(--d-accent-deep); border-color: var(--d-accent-deep); }
.et-shell .task-row input[type=checkbox]:checked::after {
    content: ""; position: absolute; left: 5px; top: 1px; width: 5px; height: 10px;
    border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
}

.et-shell .task-main { min-width: 0; }
.et-shell .task-title { font-size: 14.5px; color: var(--d-ink); display: block; }
.et-shell .task-row.done .task-title { text-decoration: line-through; color: var(--d-ink-3); }
.et-shell .task-notes { font-size: 12px; color: var(--d-ink-3); margin-top: 1px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.et-shell .task-done-by { font-size: 11.5px; color: var(--d-good); margin-top: 1px; display: block; font-weight: 600; }

.et-shell .assignee-cell { display: flex; align-items: center; gap: 7px; min-width: 0; }
.et-shell .avatar {
    width: 24px; height: 24px; border-radius: 50%; flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 10.5px; font-weight: 800; color: var(--d-accent-text);
    border: 1px solid rgba(0,0,0,.08);
}
.et-shell .avatar.unassigned {
    background: transparent; border: 1.5px dashed var(--d-line-2); color: var(--d-ink-3);
}
.et-shell .assignee-name { font-size: 12.5px; color: var(--d-ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.et-shell select.assignee-select {
    font-family: inherit; font-size: 12.5px; padding: 4px 6px; border-radius: var(--d-radius-sm);
    border: 1px solid transparent; background: transparent; color: var(--d-ink-2); max-width: 150px; cursor: pointer;
}
.et-shell select.assignee-select:hover { border-color: var(--d-line-2); background: var(--d-surface); }

.et-shell .due-cell { font-size: 12.5px; color: var(--d-ink-2); white-space: nowrap; }
.et-shell .due-cell .repeat-mark { color: var(--d-ink-3); margin-right: 3px; }

.et-shell .del-btn {
    width: 22px; height: 22px; border-radius: 50%; border: none; background: transparent;
    color: var(--d-ink-3); cursor: pointer; font-size: 15px; line-height: 1;
}
.et-shell .del-btn:hover { background: var(--d-line); color: var(--d-bad); }

.et-shell .add-row {
    display: grid; grid-template-columns: 30px 1fr 170px 130px 30px; gap: 10px; align-items: center;
    padding: 8px 16px; border-top: 1px solid var(--d-line);
}
.et-shell .add-row .add-plus { color: var(--d-ink-3); font-size: 16px; text-align: center; }
.et-shell .add-row input[type=text] {
    font-family: inherit; font-size: 14px; padding: 6px 8px; border: 1px solid transparent; border-radius: var(--d-radius-sm);
    background: transparent; color: var(--d-ink); width: 100%;
}
.et-shell .add-row input[type=text]:hover, .et-shell .add-row input[type=text]:focus { border-color: var(--d-line-2); background: var(--d-surface); outline: none; }
.et-shell .add-row input[type=text]::placeholder { color: var(--d-ink-3); font-weight: 600; }
.et-shell .add-row select, .et-shell .add-row input[type=date] {
    font-family: inherit; font-size: 12.5px; padding: 5px 6px; border: 1px solid var(--d-line-2); border-radius: var(--d-radius-sm);
    background: var(--d-surface); color: var(--d-ink-2);
}
.et-shell .empty-note { padding: 4px 16px 14px; font-size: 13px; color: var(--d-ink-3); }
</style>

<div class="et-shell">
    <div class="et-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Employee Tasks</h1>
            <p>
                @if($canManage)
                    {{ $storeLabel }} - add today's tasks and weekly routines, assign them or leave open for whoever's on shift.
                @else
                    Your tasks for {{ $storeLabel }} - assigned to you, or open for anyone on shift.
                @endif
            </p>
        </div>
        @if(!$notReady)
            @include('partials.pin_button', ['pinUrl' => url('/employee-tasks'), 'pinLabel' => 'Employee Tasks'])
        @endif
    </div>

    @if($notReady)
        <div class="callout">
            This page isn't set up yet - the Employee Tasks database tables haven't been migrated.
            Dispatch the "Run migrations" workflow, then reload this page.
        </div>
    @else
        @if(count($stores) > 1)
            <div class="store-tabs">
                @foreach($stores as $key => $label)
                    <a href="{{ $storeUrl($key) }}" class="{{ $key === $store ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        @endif

        <div class="board">
            <div class="col-head">
                <span></span>
                <span>Name</span>
                <span>Assignee</span>
                <span>Due date</span>
                <span></span>
            </div>

            <div class="section" data-section="once">
                <div class="section-head" data-toggle="once-body">
                    <span class="caret">&#9660;</span>
                    <h3>Today</h3>
                    <span class="count">{{ count($once) }}</span>
                </div>
                <div class="section-body" id="once-body">
                    @foreach ($once as $t)
                        @include('store_tasks._row', ['t' => $t, 'canManage' => $canManage, 'employees' => $employees, 'weekdayNames' => $weekdayNames, 'initials' => $initials, 'avatarColor' => $avatarColor])
                    @endforeach
                    @if(count($once) === 0)
                        <div class="empty-note">Nothing for today{{ $canManage ? '' : ' yet' }}.</div>
                    @endif
                    @if($canManage)
                        <form class="add-row add-once" data-recurrence="once">
                            <span class="add-plus">+</span>
                            <input type="text" name="title" placeholder="Add task" maxlength="200">
                            <select name="assigned_to_user_id">
                                <option value="">Anyone</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e['id'] }}">{{ $e['name'] }}</option>
                                @endforeach
                            </select>
                            <input type="date" name="due_date" value="{{ date('Y-m-d') }}">
                            <span></span>
                        </form>
                    @endif
                </div>
            </div>

            <div class="section" data-section="weekly">
                <div class="section-head" data-toggle="weekly-body">
                    <span class="caret">&#9660;</span>
                    <h3>Weekly Routine</h3>
                    <span class="count">{{ count($weekly) }}</span>
                </div>
                <div class="section-body" id="weekly-body">
                    @foreach ($weekly as $t)
                        @include('store_tasks._row', ['t' => $t, 'canManage' => $canManage, 'employees' => $employees, 'weekdayNames' => $weekdayNames, 'initials' => $initials, 'avatarColor' => $avatarColor])
                    @endforeach
                    @if(count($weekly) === 0)
                        <div class="empty-note">No weekly routine tasks{{ $canManage ? '' : '' }}.</div>
                    @endif
                    @if($canManage)
                        <form class="add-row add-weekly" data-recurrence="weekly">
                            <span class="add-plus">+</span>
                            <input type="text" name="title" placeholder="Add weekly task" maxlength="200">
                            <select name="assigned_to_user_id">
                                <option value="">Anyone</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e['id'] }}">{{ $e['name'] }}</option>
                                @endforeach
                            </select>
                            <select name="weekday">
                                @foreach($weekdayFull as $num => $name)
                                    <option value="{{ $num }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <span></span>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>

@if(!$notReady)
<script>
(function () {
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.getAttribute('content') : '';
    var urls = {
        toggle: '{{ url('/employee-tasks/toggle') }}',
        store:  '{{ url('/employee-tasks/store') }}',
        update: '{{ url('/employee-tasks/update') }}',
        destroy:'{{ url('/employee-tasks/destroy') }}'
    };

    function post(url, data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) { body.append(k, data[k] == null ? '' : data[k]); });
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) {
            if (!r.ok) { throw new Error('failed'); }
            return r.json();
        }).then(function (data) {
            if (!data || !data.ok) { throw new Error(data && data.msg || 'failed'); }
            return data;
        });
    }

    document.querySelectorAll('.et-shell .section-head').forEach(function (h) {
        h.addEventListener('click', function () {
            var body = document.getElementById(h.getAttribute('data-toggle'));
            h.classList.toggle('collapsed');
            if (body) { body.classList.toggle('collapsed'); }
        });
    });

    document.querySelectorAll('.et-shell .task-box').forEach(function (b) {
        b.addEventListener('change', function () {
            post(urls.toggle, { id: b.getAttribute('data-id'), period_key: b.getAttribute('data-period'), checked: b.checked ? '1' : '0' })
                .then(function () { window.location.reload(); })
                .catch(function (e) {
                    b.checked = !b.checked;
                    alert('Could not save that - reload and try again.\n' + e.message);
                });
        });
    });

    document.querySelectorAll('.et-shell .assignee-select').forEach(function (s) {
        s.addEventListener('change', function () {
            post(urls.update, { id: s.getAttribute('data-id'), assigned_to_user_id: s.value })
                .then(function () { window.location.reload(); })
                .catch(function (e) { alert('Could not save that.\n' + e.message); window.location.reload(); });
        });
    });

    document.querySelectorAll('.et-shell .del-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Remove this task?')) { return; }
            post(urls.destroy, { id: btn.getAttribute('data-id') })
                .then(function () { window.location.reload(); })
                .catch(function (e) { alert('Could not remove that.\n' + e.message); });
        });
    });

    document.querySelectorAll('.et-shell .add-row').forEach(function (form) {
        form.addEventListener('submit', function (e) { e.preventDefault(); submitAdd(form); });
        var titleInput = form.querySelector('input[name=title]');
        if (titleInput) {
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); submitAdd(form); }
            });
        }
    });

    function submitAdd(form) {
        var titleInput = form.querySelector('input[name=title]');
        if (!titleInput || !titleInput.value.trim()) { return; }
        var fd = new FormData(form);
        var data = { store: '{{ $store }}', recurrence: form.getAttribute('data-recurrence') };
        fd.forEach(function (v, k) { data[k] = v; });
        post(urls.store, data)
            .then(function () { window.location.reload(); })
            .catch(function (e) { alert('Could not add that task.\n' + e.message); });
    }
})();
</script>
@endif
@endsection
