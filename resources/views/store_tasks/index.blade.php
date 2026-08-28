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
    $storeUrl = function ($s) { return url('/employee-tasks') . '?' . http_build_query(['store' => $s]); };
    $initials = function ($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        $parts = array_filter($parts);
        if (empty($parts)) return '?';
        $first = mb_substr(reset($parts), 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    };
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
    --d-warn: #B26A00;
    --d-bad: #B3261E;
    --d-bad-bg: #FBEAE8;
    --d-radius: 12px;
    --d-radius-sm: 10px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 860px;
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

.et-shell .add-card {
    background: var(--d-surface); border: 1px dashed var(--d-line-2); border-radius: var(--d-radius);
    padding: 14px 16px; margin-bottom: 20px;
}
.et-shell .add-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.et-shell .add-row input[type=text], .et-shell .add-row select, .et-shell .add-row input[type=date] {
    font-family: inherit; font-size: 14px; padding: 9px 12px; border-radius: var(--d-radius-sm);
    border: 1px solid var(--d-line-2); background: var(--d-surface); color: var(--d-ink);
}
.et-shell .add-row input[type=text] { flex: 1 1 220px; min-width: 160px; }
.et-shell .add-row .kind-toggle { display: flex; border: 1px solid var(--d-line-2); border-radius: var(--d-radius-sm); overflow: hidden; }
.et-shell .add-row .kind-toggle button {
    font-family: inherit; font-size: 13px; font-weight: 700; padding: 9px 12px; border: none; background: var(--d-surface);
    color: var(--d-ink-3); cursor: pointer;
}
.et-shell .add-row .kind-toggle button.active { background: var(--d-accent); color: var(--d-accent-text); }
.et-shell .add-row button.submit {
    font-family: inherit; font-size: 14px; font-weight: 800; padding: 9px 18px; border: none; border-radius: var(--d-radius-sm);
    background: var(--d-ink); color: #fff; cursor: pointer;
}
.et-shell .add-row button.submit:hover { opacity: .9; }
.et-shell .add-notes { margin-top: 8px; }
.et-shell .add-notes input[type=text] { width: 100%; }

.et-shell .section-head {
    display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
    margin: 20px 4px 8px;
}
.et-shell .section-head h3 {
    font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--d-ink-2); margin: 0;
}
.et-shell .section-head .count { font-size: 12.5px; font-weight: 600; color: var(--d-ink-3); }

.et-shell .task-row {
    display: flex; align-items: center; gap: 12px; background: var(--d-surface);
    border: 1px solid var(--d-line); border-radius: var(--d-radius-sm);
    padding: 11px 12px; margin-bottom: 8px; transition: background .12s, border-color .12s;
}
.et-shell .task-row:hover { background: var(--d-surface-2); }
.et-shell .task-row.overdue { border-color: var(--d-bad); background: var(--d-bad-bg); }
.et-shell .task-row.done { opacity: .68; }

.et-shell .task-row input[type=checkbox] {
    appearance: none; -webkit-appearance: none; flex: 0 0 auto;
    width: 22px; height: 22px; border: 2px solid var(--d-line-2);
    border-radius: 50%; background: #fff; cursor: pointer; position: relative;
}
.et-shell .task-row.overdue input[type=checkbox] { border-color: var(--d-bad); }
.et-shell .task-row input[type=checkbox]:checked { background: var(--d-accent); border-color: var(--d-accent-deep); }
.et-shell .task-row input[type=checkbox]:checked::after {
    content: ""; position: absolute; left: 6px; top: 2px; width: 6px; height: 11px;
    border: solid var(--d-accent-text); border-width: 0 2.5px 2.5px 0; transform: rotate(45deg);
}

.et-shell .task-main { flex: 1 1 auto; min-width: 0; }
.et-shell .task-title { font-size: 15px; color: var(--d-ink); display: block; }
.et-shell .task-row.done .task-title { text-decoration: line-through; color: var(--d-ink-3); }
.et-shell .task-notes { font-size: 12.5px; color: var(--d-ink-3); margin-top: 2px; display: block; }
.et-shell .task-done-by { font-size: 12px; color: var(--d-good); margin-top: 2px; display: block; font-weight: 600; }

.et-shell .task-meta { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.et-shell .chip {
    font-size: 11.5px; font-weight: 700; padding: 4px 9px; border-radius: 999px; white-space: nowrap;
    background: var(--d-surface-2); color: var(--d-ink-3); border: 1px solid var(--d-line);
}
.et-shell .chip.repeat { color: var(--d-accent-text); background: var(--d-accent-soft); border-color: var(--d-accent-deep); }
.et-shell .chip.due-overdue { color: var(--d-bad); background: var(--d-bad-bg); border-color: var(--d-bad); }
.et-shell .avatar {
    width: 26px; height: 26px; border-radius: 50%; flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; background: var(--d-accent); color: var(--d-accent-text);
    border: 1px solid var(--d-accent-deep);
}
.et-shell .avatar.unassigned { background: var(--d-surface-2); color: var(--d-ink-3); border-color: var(--d-line); }
.et-shell select.assignee-select {
    font-family: inherit; font-size: 12px; font-weight: 700; padding: 5px 8px; border-radius: 999px;
    border: 1px solid var(--d-line-2); background: var(--d-surface); color: var(--d-ink-2); max-width: 130px;
}
.et-shell .del-btn {
    flex: 0 0 auto; width: 26px; height: 26px; border-radius: 50%; border: 1px solid var(--d-line-2);
    background: var(--d-surface); color: var(--d-ink-3); cursor: pointer; font-size: 13px; line-height: 1;
}
.et-shell .del-btn:hover { background: var(--d-bad-bg); border-color: var(--d-bad); color: var(--d-bad); }
.et-shell .empty-note { font-size: 13.5px; color: var(--d-ink-3); padding: 10px 12px; }
</style>

<div class="et-shell">
    <div class="et-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Employee Tasks</h1>
            <p>
                @if($canManage)
                    Add today's tasks and weekly routines, assign them or leave them open for whoever's on shift.
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

        @if($canManage)
            <div class="add-card">
                <form id="add-task-form">
                    <input type="hidden" name="store" value="{{ $store }}">
                    <input type="hidden" name="recurrence" id="recurrence-field" value="once">
                    <div class="add-row">
                        <div class="kind-toggle">
                            <button type="button" class="kind-btn active" data-kind="once">Today</button>
                            <button type="button" class="kind-btn" data-kind="weekly">Weekly routine</button>
                        </div>
                        <input type="text" name="title" placeholder="Task - e.g. Clean floors" maxlength="200" required>
                        <select name="assigned_to_user_id">
                            <option value="">Anyone on shift</option>
                            @foreach($employees as $e)
                                <option value="{{ $e['id'] }}">{{ $e['name'] }}</option>
                            @endforeach
                        </select>
                        <select name="weekday" id="weekday-field" style="display:none;">
                            @foreach($weekdayNames as $num => $name)
                                <option value="{{ $num }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <input type="date" name="due_date" id="due-date-field" value="{{ date('Y-m-d') }}">
                        <button type="submit" class="submit">Add task</button>
                    </div>
                    <div class="add-notes">
                        <input type="text" name="notes" placeholder="Notes (optional) - any instructions" maxlength="2000">
                    </div>
                </form>
            </div>
        @endif

        @php
            $todo = array_values(array_filter($tasks, function ($t) { return !$t['done']; }));
            $done = array_values(array_filter($tasks, function ($t) { return $t['done']; }));
        @endphp

        <div class="section-head">
            <h3>To do</h3>
            <span class="count">{{ count($todo) }}</span>
        </div>
        @forelse ($todo as $t)
            @include('store_tasks._row', ['t' => $t, 'canManage' => $canManage, 'employees' => $employees, 'weekdayNames' => $weekdayNames, 'initials' => $initials])
        @empty
            <div class="empty-note">Nothing to do{{ $canManage ? ' - add a task above.' : ' right now.' }}</div>
        @endforelse

        @if(count($done) > 0)
            <div class="section-head">
                <h3>Done</h3>
                <span class="count">{{ count($done) }}</span>
            </div>
            @foreach ($done as $t)
                @include('store_tasks._row', ['t' => $t, 'canManage' => $canManage, 'employees' => $employees, 'weekdayNames' => $weekdayNames, 'initials' => $initials])
            @endforeach
        @endif
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

    var form = document.getElementById('add-task-form');
    if (form) {
        var kindField = document.getElementById('recurrence-field');
        var weekdayField = document.getElementById('weekday-field');
        var dueDateField = document.getElementById('due-date-field');
        document.querySelectorAll('.kind-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.kind-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                var kind = btn.getAttribute('data-kind');
                kindField.value = kind;
                weekdayField.style.display = kind === 'weekly' ? '' : 'none';
                dueDateField.style.display = kind === 'weekly' ? 'none' : '';
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            var data = {};
            fd.forEach(function (v, k) { data[k] = v; });
            post(urls.store, data)
                .then(function () { window.location.reload(); })
                .catch(function (e) { alert('Could not add that task.\n' + e.message); });
        });
    }
})();
</script>
@endif
@endsection
