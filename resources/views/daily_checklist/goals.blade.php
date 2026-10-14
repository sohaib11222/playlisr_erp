@extends('layouts.app')
@section('title', 'Fatteen Goals')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
@include('daily_checklist.partials.styles')

<div class="open-shell">
    @php
        $pinAlready = \App\Http\Controllers\SidebarFavoriteController::isPinned(
            session()->get('user.business_id'),
            session()->get('user.id'),
            url('/fatteen-goals')
        );
    @endphp
    <div class="open-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div>
            <h1>Fatteen Goals</h1>
            <p>Monthly goals, bonus and review. Numbers update from the ERP as the month goes. <a href="{{ url('/daily-checklist') }}" style="color:var(--d-accent-text);font-weight:700">Daily checklist &rarr;</a></p>
        </div>
        <button type="button" class="gbtn" id="pinGoals">{{ $pinAlready ? 'Pinned' : 'Pin to my sidebar' }}</button>
    </div>

    @if(session('status'))
        <div class="callout" style="font-weight:600">{{ session('status')['msg'] ?? '' }}</div>
    @endif

    @if(!$goals)
        <div class="card">The goals could not load right now. Reload the page in a minute.</div>
    @endif

    @include('daily_checklist.partials.goals')
</div>

<script>
(function () {
    var btn = document.getElementById('pinGoals');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var tokenEl = document.querySelector('meta[name="csrf-token"]');
        var body = new FormData();
        body.append('url', '{{ url('/fatteen-goals') }}');
        body.append('label', 'Fatteen Goals');
        fetch('{{ url('/sidebar-favorites/toggle') }}', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : '', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d && d.ok) { btn.textContent = d.starred ? 'Pinned' : 'Pin to my sidebar'; }
        }).catch(function () {});
    });
})();
</script>
@endsection
