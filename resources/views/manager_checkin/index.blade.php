@extends('layouts.app')
@section('title', 'Manager Check-ins')

@section('content')
@php
    $old = function ($k, $d = '') { return old($k, $d); };
    // Answers that can turn into a task, and the "Make a task" link for them.
    $taskable = ['projects'];
    $taskLink = function ($r, $key, $label) use ($erpOwnerId) {
        $text  = trim((string) ($r[$key] ?? ''));
        $first = trim(strtok($text, "\n"));
        $owner = ($key === 'erp' && $erpOwnerId) ? $erpOwnerId : $r['employee_id'];
        return url('/tasks/create') . '?' . http_build_query([
            'prefill'     => 1,
            'title'       => mb_substr($first, 0, 150),
            'description' => $text . "\n\nFrom " . $r['manager_name'] . "'s check-in with " . $r['employee_name'] . ' on ' . $r['date'] . ' (' . $label . ').',
            'store'       => ($r['store'] ?? 'hw') === 'pico' ? 'pico' : 'hollywood',
            'assignees'   => [$owner],
        ]);
    };
@endphp
{{-- Cream / pastel-yellow look to match /pos/create and /manager-checklist. --}}
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
    --d-bad: #B3261E;
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
.open-shell .open-header { margin: 12px 4px 16px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.open-shell .open-header h1 { font-size: 26px; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; }
.open-shell .open-header p { font-size: 14px; color: var(--d-ink-3); margin: 6px 0 0; line-height: 1.5; }
.open-shell .card {
    background: var(--d-surface); border: 1px solid var(--d-line);
    border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06);
    padding: 18px 20px; margin-bottom: 16px;
}
.open-shell .flash { border-radius: var(--d-radius-sm); padding: 12px 16px; margin-bottom: 16px; font-weight: 600; font-size: 14px; }
.open-shell .flash.ok { background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep); color: var(--d-accent-text); }
.open-shell .flash.warn { background: #FBEAE5; border: 1px solid #E0A99B; color: #8A2C12; }
.open-shell .row2 { display: flex; gap: 12px; flex-wrap: wrap; }
.open-shell .row2 > div { flex: 1 1 220px; }
.open-shell label.q { display: block; font-weight: 800; font-size: 14px; margin: 14px 0 4px; }
.open-shell .hint { font-size: 12.5px; color: var(--d-ink-3); font-weight: 500; margin-left: 4px; }
.open-shell select, .open-shell input[type=date], .open-shell textarea {
    width: 100%; font: inherit; font-size: 14.5px; color: var(--d-ink);
    border: 1px solid var(--d-line-2); border-radius: var(--d-radius-sm);
    padding: 9px 11px; background: #fff;
}
.open-shell textarea { min-height: 64px; resize: vertical; }
.open-shell .pills { display: flex; gap: 8px; flex-wrap: wrap; }
.open-shell .pills input { position: absolute; opacity: 0; pointer-events: none; }
.open-shell .pills label {
    border: 1px solid var(--d-line-2); border-radius: 999px; padding: 8px 16px;
    font-weight: 700; font-size: 14px; cursor: pointer; background: #fff; margin: 0;
}
.open-shell .pills input:checked + label { background: var(--d-accent); border-color: var(--d-accent-deep); color: var(--d-accent-text); }
.open-shell .btn-save {
    margin-top: 18px; background: var(--d-ink); color: #fff; border: 0;
    border-radius: 999px; padding: 11px 22px; font: inherit; font-weight: 800; font-size: 15px; cursor: pointer;
}
.open-shell .list-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin: 24px 0 10px; }
.open-shell .list-head h3 { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--d-ink-2); margin: 0; }
.open-shell .list-head select { width: auto; }
.open-shell .entry-top { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: baseline; }
.open-shell .entry-name { font-weight: 800; font-size: 16px; }
.open-shell .entry-meta { font-size: 12.5px; color: var(--d-ink-3); font-weight: 600; }
.open-shell .rating { border-radius: 999px; padding: 3px 10px; font-size: 12.5px; font-weight: 800; background: var(--d-surface-2); }
.open-shell .rating.great { background: #E6F2E7; color: var(--d-good); }
.open-shell .rating.needs_work { background: #FBEAE8; color: var(--d-bad); }
.open-shell .ans { margin-top: 10px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; }
.open-shell .ans b { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-2); }
.open-shell .store-toggle { display: inline-flex; background: var(--d-surface-2); border: 1px solid var(--d-line-2); border-radius: 999px; padding: 4px; margin-bottom: 16px; }
.open-shell .store-toggle a {
    padding: 8px 22px; border-radius: 999px; font-weight: 800; font-size: 15px;
    color: var(--d-ink-2); text-decoration: none;
}
.open-shell .store-toggle a.on { background: var(--d-accent); color: var(--d-accent-text); box-shadow: 0 1px 2px rgba(31,27,22,.12); }
.open-shell .work { display: none; margin-top: 14px; background: var(--d-surface-2); border: 1px solid var(--d-line); border-radius: var(--d-radius-sm); padding: 12px 14px; font-size: 14px; }
.open-shell .work.show { display: block; }
.open-shell .work h4 { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-2); margin: 8px 0 4px; }
.open-shell .work h4:first-child { margin-top: 0; }
.open-shell .work ul { margin: 0; padding-left: 18px; }
.open-shell .work li { margin: 2px 0; }
.open-shell .work a { color: var(--d-ink); }
.open-shell .work .st { font-size: 12px; color: var(--d-ink-3); font-weight: 600; }
.open-shell .work .none { color: var(--d-ink-3); }
.open-shell .mk-task { font-size: 12.5px; font-weight: 700; color: var(--d-accent-text); background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep); border-radius: 999px; padding: 2px 10px; text-decoration: none; margin-left: 6px; white-space: nowrap; }
.open-shell .empty { color: var(--d-ink-3); font-size: 14px; }
.open-shell .jon-notes { border: 1px solid var(--d-accent-deep); background: var(--d-accent-soft); padding: 20px 22px; }
.open-shell .jon-notes .jn-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 6px; }
.open-shell .jon-notes h2 { font-size: 18px; font-weight: 800; margin: 0 0 4px; }
.open-shell .jon-notes .sub { font-size: 13px; color: var(--d-ink-2); margin: 0; }
.open-shell .jn-edit-btn, .open-shell .jn-cancel { background: #fff; border: 1px solid var(--d-line-2); color: var(--d-ink); border-radius: 999px; padding: 7px 16px; font: inherit; font-weight: 700; font-size: 13.5px; cursor: pointer; white-space: nowrap; }
.open-shell .jon-notes .body { font-size: 14.5px; line-height: 1.55; }
.open-shell .jon-notes .body h4 { font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--d-accent-text); margin: 16px 0 6px; padding-top: 12px; border-top: 1px solid var(--d-accent-deep); }
.open-shell .jon-notes .body h4:first-child { margin-top: 10px; }
.open-shell .jon-notes .body ul { margin: 0; padding-left: 20px; }
.open-shell .jon-notes .body li { margin: 4px 0; }
.open-shell .jon-notes .body p { margin: 6px 0; }
.open-shell .jon-notes .body a { color: var(--d-accent-text); font-weight: 700; text-decoration: underline; word-break: break-word; }
.open-shell .jon-notes .jn-help { font-size: 13px; color: var(--d-ink-2); margin: 12px 0 6px; }
.open-shell .jon-notes textarea { min-height: 340px; font-size: 14px; line-height: 1.5; }
.open-shell .jon-notes .jn-actions { display: flex; gap: 10px; align-items: center; margin-top: 10px; }
.open-shell .jon-notes .jn-actions .btn-save { margin-top: 0; }
.open-shell .upd-card h2 { font-size: 18px; font-weight: 800; margin: 0 0 4px; }
.open-shell .upd-card .sub { font-size: 13px; color: var(--d-ink-2); margin: 0 0 12px; }
.open-shell .upd-person { border-top: 1px solid var(--d-line); padding: 10px 0; }
.open-shell .upd-person:first-of-type { border-top: 0; }
.open-shell .upd-person .who { font-weight: 800; font-size: 14.5px; margin-bottom: 4px; }
.open-shell .upd-item { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; line-height: 1.5; padding: 3px 0; }
.open-shell .upd-item .mark { flex: 0 0 20px; height: 20px; border-radius: 6px; border: 2px solid var(--d-line-2); background: #fff; margin-top: 1px; display: flex; align-items: center; justify-content: center; }
.open-shell .upd-item.done .mark { background: var(--d-good); border-color: var(--d-good); }
.open-shell .upd-item .txt { flex: 1; }
.open-shell .upd-item .when { font-size: 12.5px; color: var(--d-ink-2); font-weight: 600; }
.open-shell .upd-item.done .txt > span:first-child { color: var(--d-ink-2); }
.open-shell .upd-item a { color: var(--d-accent-text); font-weight: 700; word-break: break-word; }
.open-shell .upd-del { background: none; border: 0; color: var(--d-ink-2); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; text-decoration: underline; padding: 0; }
.open-shell .upd-add { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--d-line); }
.open-shell .upd-add select { flex: 0 1 200px; }
.open-shell .upd-add input[type=text] { flex: 1 1 260px; font: inherit; font-size: 14.5px; border: 1px solid var(--d-line-2); border-radius: var(--d-radius-sm); padding: 9px 11px; }
.open-shell .upd-add .btn-save { margin-top: 0; padding: 9px 18px; font-size: 14px; }
.open-shell .share-box { margin-top: 14px; border: 2px solid var(--d-accent-deep); background: var(--d-accent-soft); border-radius: var(--d-radius-sm); padding: 12px 14px; display: none; }
.open-shell .share-box.show { display: block; }
.open-shell .share-box h4 { font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--d-accent-text); margin: 0 0 4px; }
.open-shell .share-box .hint2 { font-size: 13px; color: var(--d-ink-2); margin: 0 0 8px; }
.open-shell .share-box label { display: flex; gap: 10px; align-items: flex-start; font-size: 14.5px; line-height: 1.45; font-weight: 500; margin: 6px 0; cursor: pointer; }
.open-shell .share-box input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; flex: 0 0 18px; }
.open-shell .share-box a { color: var(--d-accent-text); font-weight: 700; }
.open-shell .shared-list { margin-top: 10px; font-size: 14px; }
.open-shell .shared-list b { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-2); }
</style>

<div class="open-shell">
    <div class="open-header">
        <div>
            <h1>Manager Check-ins</h1>
            <p>This is their time, not a review. Ask what they are working on, listen to their ideas, and find out what would make their job easier. Write it down here so Jon sees what each person is contributing. Takes about 2 minutes.</p>
            <p>You can approve shift swaps and change or assign tasks yourself, no need to check with Jon.</p>
            <p>Last week's sales and hours for each person: <a href="{{ url('/reports/employee-leaderboard') }}?period=last_week" target="_blank">Daily Store Dashboard</a></p>
        </div>
        @include('partials.pin_button', ['pinUrl' => url('/manager-checkins'), 'pinLabel' => 'Manager Check-ins'])
    </div>

    @if(session('status') && !empty(session('status')['msg']))
        <div class="flash {{ (session('status')['success'] ?? 1) ? 'ok' : 'warn' }}">{{ session('status')['msg'] }}</div>
    @endif

    <div class="store-toggle">
        @foreach($stores as $key => $label)
            <a href="{{ url('/manager-checkins') . '?store=' . $key }}" class="{{ $store === $key ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if($showNotes)
        @php
            $notesText = $jonNotes['text'] ?? '';
            // Render the plain text: ALL CAPS lines = section headings,
            // "- " lines = bullets, bare links clickable. Escaped first.
            $linkify = function ($t) {
                return preg_replace('~(https?://[^\s<]*[^\s<.,;:)!?])~', '<a href="$1" target="_blank" rel="noopener">$1</a>', e($t));
            };
            $notesHtml = '';
            $inList = false;
            foreach (preg_split('/\r?\n/', $notesText) as $line) {
                $line = trim($line);
                $isItem = strpos($line, '- ') === 0;
                if ($inList && !$isItem) { $notesHtml .= '</ul>'; $inList = false; }
                if ($line === '') { continue; }
                if ($isItem) {
                    if (!$inList) { $notesHtml .= '<ul>'; $inList = true; }
                    $notesHtml .= '<li>' . $linkify(substr($line, 2)) . '</li>';
                } elseif (preg_match('/[A-Z]/', $line) && strtoupper($line) === $line) {
                    $notesHtml .= '<h4>' . e(ucfirst(strtolower(rtrim($line, ':')))) . '</h4>';
                } else {
                    $notesHtml .= '<p>' . $linkify($line) . '</p>';
                }
            }
            if ($inList) { $notesHtml .= '</ul>'; }
            $hasNotes = trim($notesText) !== '';
        @endphp
        <div class="card jon-notes" id="jon-notes">
            <div class="jn-top">
                <div>
                    <h2>Notes for {{ $notesManager }} from Jon</h2>
                    <p class="sub">What got fixed from the check-ins and what to follow up on. Jon goes over these at the weekly check-in.@if(!empty($jonNotes['updated_at'])) Updated {{ date('m/d/y', strtotime($jonNotes['updated_at'])) }}.@endif</p>
                </div>
                @if($isAdmin)
                    <button type="button" class="jn-edit-btn" id="jn-edit-btn">{{ $hasNotes ? 'Edit notes' : 'Add notes' }}</button>
                @endif
            </div>
            <div class="body" id="jn-view">
                @if($hasNotes)
                    {!! $notesHtml !!}
                @else
                    <p class="empty">No notes yet.</p>
                @endif
            </div>
            @if($isAdmin)
                <form method="POST" action="{{ url('/manager-checkins/notes') }}" id="jn-form" style="display:none;">
                    {{ csrf_field() }}
                    <input type="hidden" name="store" value="{{ $store }}">
                    <p class="jn-help">Write a heading in CAPITALS, start a bullet with a dash and a space, and paste links as they are.</p>
                    <textarea name="notes" placeholder="Notes for {{ $notesManager }}">{{ $notesText }}</textarea>
                    <div class="jn-actions">
                        <button type="submit" class="btn-save">Save notes</button>
                        <button type="button" class="jn-cancel" id="jn-cancel">Cancel</button>
                    </div>
                </form>
                <script>
                (function () {
                    var btn = document.getElementById('jn-edit-btn'),
                        form = document.getElementById('jn-form'),
                        view = document.getElementById('jn-view'),
                        cancel = document.getElementById('jn-cancel');
                    if (!btn || !form) return;
                    function show(edit) {
                        form.style.display = edit ? '' : 'none';
                        view.style.display = edit ? 'none' : '';
                        btn.style.display = edit ? 'none' : '';
                        if (edit) form.querySelector('textarea').focus();
                    }
                    btn.addEventListener('click', function () { show(true); });
                    cancel.addEventListener('click', function () { show(false); });
                })();
                </script>
            @endif
        </div>
    @endif

    @php
        $linkifyUpd = function ($t) {
            return preg_replace('~(https?://[^\s<]*[^\s<.,;:)!?])~', '<a href="$1" target="_blank" rel="noopener">$1</a>', e($t));
        };
        $updByPerson = [];
        foreach ($updates as $u) { $updByPerson[$u['employee_name']][] = $u; }
        ksort($updByPerson);
    @endphp
    @if($isAdmin || !empty($updates))
    <div class="card upd-card" id="ci-updates">
        <h2>Updates to share with each person</h2>
        <p class="sub">At each person's next check-in, tell them their updates and tick them off in the form below. A green check means it was shared.</p>
        @forelse($updByPerson as $name => $list)
            <div class="upd-person">
                <div class="who">{{ $name }}</div>
                @foreach($list as $u)
                    <div class="upd-item {{ !empty($u['shared_at']) ? 'done' : '' }}">
                        <span class="mark">@if(!empty($u['shared_at']))<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2 6.5l2.5 2.5L10 3.5" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif</span>
                        <span class="txt">
                            <span>{!! $linkifyUpd($u['text']) !!}</span>
                            <br><span class="when">@if(!empty($u['shared_at']))Shared {{ date('m/d/y', strtotime($u['shared_at'])) }} by {{ $u['shared_by'] }}@else Not shared yet @endif</span>
                            @if($isAdmin)
                                <form method="POST" action="{{ url('/manager-checkins/updates/delete') }}" style="display:inline;margin-left:8px;">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="store" value="{{ $store }}">
                                    <input type="hidden" name="id" value="{{ $u['id'] }}">
                                    <button type="submit" class="upd-del">Remove</button>
                                </form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="empty">No updates yet.</p>
        @endforelse
        @if($isAdmin)
            <form method="POST" action="{{ url('/manager-checkins/updates') }}" class="upd-add">
                {{ csrf_field() }}
                <input type="hidden" name="store" value="{{ $store }}">
                <select name="employee_id" required>
                    <option value="">Pick someone</option>
                    @foreach($employees as $e)
                        <option value="{{ $e->id }}">{{ trim($e->first_name . ' ' . $e->last_name) }}</option>
                    @endforeach
                </select>
                <input type="text" name="text" maxlength="1000" placeholder="Update to share, for example: The bag fee glitch is fixed" required>
                <button type="submit" class="btn-save">Add</button>
            </form>
        @endif
    </div>
    @endif

    <form method="POST" action="{{ url('/manager-checkins') }}" class="card">
        {{ csrf_field() }}
        <input type="hidden" name="store" value="{{ $store }}">
        <div class="row2">
            <div>
                <label class="q" for="ci-employee">Employee</label>
                <select name="employee_id" id="ci-employee" required>
                    <option value="">Pick someone</option>
                    @foreach($employees as $e)
                        <option value="{{ $e->id }}" {{ (string) $old('employee_id') === (string) $e->id ? 'selected' : '' }}>{{ trim($e->first_name . ' ' . $e->last_name) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="q" for="ci-date">Date</label>
                <input type="date" name="date" id="ci-date" value="{{ $old('date', date('Y-m-d')) }}" required>
            </div>
        </div>

        <div class="work" id="ci-work"></div>

        <div class="share-box" id="ci-share">
            <h4>Updates to share</h4>
            <p class="hint2">Tell them these, then tick each one you shared.</p>
            <div id="ci-share-list"></div>
        </div>

        <label class="q">How is their week going?</label>
        <div class="pills">
            @foreach($ratings as $key => $label)
                <input type="radio" name="rating" id="ci-r-{{ $key }}" value="{{ $key }}" {{ $old('rating') === $key ? 'checked' : '' }} required>
                <label for="ci-r-{{ $key }}">{{ $label }}</label>
            @endforeach
        </div>

        @foreach($questions as $key => $q)
            <label class="q" for="ci-{{ $key }}">{{ $q[0] }} <span class="hint">{{ $q[1] }}</span>
                @if(in_array($key, $taskable, true))
                    <a href="#" class="mk-task js-mk-task" data-key="{{ $key }}" data-label="{{ $q[0] }}">Make a task</a>
                @endif
            </label>
            <textarea name="{{ $key }}" id="ci-{{ $key }}" maxlength="2000">{{ $old($key) }}</textarea>
        @endforeach

        <button type="submit" class="btn-save">Save check-in</button>
    </form>

    <div class="list-head">
        <h3>{{ $stores[$store] }} - {{ $isAdmin ? 'all check-ins' : 'your check-ins' }} ({{ count($rows) }})</h3>
        <form method="GET" action="{{ url('/manager-checkins') }}">
            <input type="hidden" name="store" value="{{ $store }}">
            <select name="employee_id" onchange="this.form.submit()">
                <option value="">Everyone</option>
                @foreach($employees as $e)
                    <option value="{{ $e->id }}" {{ $filterEmployee === (int) $e->id ? 'selected' : '' }}>{{ trim($e->first_name . ' ' . $e->last_name) }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @forelse($rows as $r)
        <div class="card">
            <div class="entry-top">
                <div>
                    <span class="entry-name">{{ $r['employee_name'] }}</span>
                    <span class="rating {{ $r['rating'] }}">{{ $ratings[$r['rating']] ?? $r['rating'] }}</span>
                </div>
                <div class="entry-meta">{{ \Carbon\Carbon::parse($r['date'])->format('D M j, Y') }} - by {{ $r['manager_name'] }}</div>
            </div>
            {{-- 'notes' was dropped from the form 2026-09-28; still shown on older entries. --}}
            @if(!empty($sharedByCheckin[$r['id']]))
                <div class="shared-list"><b>Updates shared</b>
                    @foreach($sharedByCheckin[$r['id']] as $t)
                        <div class="upd-item done"><span class="mark"><svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2 6.5l2.5 2.5L10 3.5" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="txt">{!! $linkifyUpd($t) !!}</span></div>
                    @endforeach
                </div>
            @endif
            @foreach($questions + ['notes' => ['Anything else for Jon', '']] as $key => $q)
                @if(!empty($r[$key]))
                    <div class="ans"><b>{{ $q[0] }} @if(in_array($key, $taskable, true))<a class="mk-task" href="{{ $taskLink($r, $key, $q[0]) }}" target="_blank" rel="noopener">Make a task</a>@endif</b>{{ $r[$key] }}</div>
                @endif
            @endforeach
        </div>
    @empty
        <p class="empty">No check-ins yet.</p>
    @endforelse
</div>
<script>
(function () {
    var work = @json($work);
    var sel = document.getElementById('ci-employee');
    var box = document.getElementById('ci-work');
    var labels = { not_started: 'not started', in_progress: 'in progress', complete: 'done' };
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function list(items) {
        if (!items.length) { return '<div class="none">None</div>'; }
        return '<ul>' + items.map(function (i) {
            return '<li><a href="' + i.url + '" target="_blank" rel="noopener">' + esc(i.title) + '</a> <span class="st">' + (i.project ? 'project, ' : '') + (labels[i.status] || i.status) + '</span></li>';
        }).join('') + '</ul>';
    }
    function render() {
        var w = work[sel.value];
        if (!w) { box.className = 'work'; box.innerHTML = ''; return; }
        box.innerHTML = '<h4>Their projects</h4>' + list(w.projects)
            + '<h4>Open tasks</h4>' + list(w.tasks)
            + '<h4>Done in the last 2 weeks</h4>' + list(w.done);
        box.className = 'work show';
    }
    sel.addEventListener('change', render);
    render();

    // Updates to share with the picked person, as checkboxes (told[]).
    var openUpdates = @json($openUpdates);
    var shareBox = document.getElementById('ci-share');
    var shareList = document.getElementById('ci-share-list');
    function linkify(s) { return esc(s).replace(/(https?:\/\/[^\s<]*[^\s<.,;:)!?])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>'); }
    function renderShare() {
        var list = openUpdates[sel.value] || [];
        if (!list.length) { shareBox.className = 'share-box'; shareList.innerHTML = ''; return; }
        shareList.innerHTML = list.map(function (u) {
            return '<label><input type="checkbox" name="told[]" value="' + esc(u.id) + '"><span>' + linkify(u.text) + '</span></label>';
        }).join('');
        shareBox.className = 'share-box show';
    }
    sel.addEventListener('change', renderShare);
    renderShare();

    // "Make a task" on the form: open a new task pre-filled from what's typed.
    var erpOwner = @json($erpOwnerId);
    var store = @json($store === 'pico' ? 'pico' : 'hollywood');
    var me = @json(trim(auth()->user()->first_name . ' ' . auth()->user()->last_name));
    document.querySelectorAll('.js-mk-task').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var key = a.getAttribute('data-key');
            var text = (document.getElementById('ci-' + key).value || '').trim();
            if (!text) { alert('Type something in this box first.'); return; }
            var who = sel.value ? sel.options[sel.selectedIndex].text : '';
            var owner = (key === 'erp' && erpOwner) ? erpOwner : sel.value;
            var p = new URLSearchParams();
            p.set('prefill', '1');
            p.set('title', text.split('\n')[0].slice(0, 150));
            p.set('description', text + '\n\nFrom ' + me + "'s check-in" + (who ? ' with ' + who : '') + ' on ' + document.getElementById('ci-date').value + ' (' + a.getAttribute('data-label') + ').');
            p.set('store', store);
            if (owner) { p.append('assignees[]', owner); }
            window.open(@json(url('/tasks/create')) + '?' + p.toString(), '_blank');
        });
    });
})();
</script>
@endsection
