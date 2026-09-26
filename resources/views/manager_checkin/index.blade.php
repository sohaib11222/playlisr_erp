@extends('layouts.app')
@section('title', 'Manager Check-ins')

@section('content')
@php
    $old = function ($k, $d = '') { return old($k, $d); };
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
.open-shell .empty { color: var(--d-ink-3); font-size: 14px; }
</style>

<div class="open-shell">
    <div class="open-header">
        <div>
            <h1>Manager Check-ins</h1>
            <p>This is their time, not a review. Ask what they are working on, listen to their ideas, and find out what would make their job easier. Write it down here so Jon sees what each person is contributing. Takes about 2 minutes.</p>
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

        <label class="q">How is their week going?</label>
        <div class="pills">
            @foreach($ratings as $key => $label)
                <input type="radio" name="rating" id="ci-r-{{ $key }}" value="{{ $key }}" {{ $old('rating') === $key ? 'checked' : '' }} required>
                <label for="ci-r-{{ $key }}">{{ $label }}</label>
            @endforeach
        </div>

        @foreach($questions as $key => $q)
            <label class="q" for="ci-{{ $key }}">{{ $q[0] }} <span class="hint">{{ $q[1] }}</span></label>
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
            @foreach($questions as $key => $q)
                @if(!empty($r[$key]))
                    <div class="ans"><b>{{ $q[0] }}</b>{{ $r[$key] }}</div>
                @endif
            @endforeach
        </div>
    @empty
        <p class="empty">No check-ins yet.</p>
    @endforelse
</div>
@endsection
