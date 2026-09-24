@extends('layouts.app')
@section('title', 'My Tasks')

@section('content')
@include('tasks.partials.asana_styles')

<section class="content">
<div class="as-wrap">

    <div class="as-head">
        {!! \App\Http\Controllers\TeamProgressController::avatar(auth()->id(), trim(auth()->user()->first_name . ' ' . auth()->user()->last_name), 'lg') !!}
        <div>
            <h1 class="as-title">My Tasks</h1>
            <div class="as-sub">
                @if($completedCount > 0)
                    {{ $completedCount }} {{ $completedCount == 1 ? 'task' : 'tasks' }} completed in the last 7 days.
                @else
                    Your tasks, sorted by when they're due.
                @endif
                @if($shiftStores)
                    On shift at {{ implode(' and ', $shiftStores) }} today.
                @endif
            </div>
        </div>
    </div>

    @include('tasks.partials.asana_nav')

    <div class="as-toolbar">
        <a href="{{ action('TaskController@create') }}" class="as-btn"><i class="fa fa-plus"></i> Add task</a>
        @include('tasks.partials.asana_view_toggle', [
            'viewUrls' => ['incomplete' => route('tasks.my'), 'completed' => route('tasks.my', ['show' => 'completed']), 'all' => route('tasks.my', ['show' => 'all'])],
            'viewCurrent' => $show,
        ])
    </div>

    <div class="as-table">
        <div class="as-cols">
            <div>Task name</div><div>Due date</div><div>Priority</div><div>Store</div><div>Assignees</div>
        </div>

        @php
            $sectionTitles = ['past_due' => 'Past due', 'today' => 'Today', 'upcoming' => 'Upcoming', 'later' => 'Later'];
            $emptyText = ['past_due' => 'Nothing past due. Nice.', 'today' => 'Nothing else due today.', 'upcoming' => 'Nothing coming up this week.', 'later' => 'Nothing scheduled further out.'];
        @endphp

        @if($show !== 'completed')
        @foreach($sectionTitles as $key => $title)
            @if($key !== 'past_due' || count($sections[$key]))
            <div class="as-section {{ $key === 'later' && count($sections[$key]) ? 'collapsed' : '' }}">
                <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> {{ $title }} <span class="as-count">{{ count($sections[$key]) }}</span></div>
                <div class="as-rows">
                    @forelse($sections[$key] as $row)
                        @include('tasks.partials.asana_row', ['t' => $row['task'], 'due' => $row['due'], 'viaShift' => $row['via_shift'], 'storeLabels' => $storeLabels])
                    @empty
                        <div class="as-empty">{{ $emptyText[$key] }}</div>
                    @endforelse
                </div>
            </div>
            @endif
        @endforeach
        @endif

        @if($show !== 'incomplete')
        <div class="as-section">
            <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> Completed{{ ' ' }}<span class="as-meta" style="font-weight:400;">last 30 days</span> <span class="as-count">{{ $completed->count() }}</span></div>
            <div class="as-rows">
                @forelse($completed as $t)
                    <div class="as-row done">
                        <div>
                            <span class="as-check done">{!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}</span>
                            <a class="as-name" href="{{ action('TaskController@edit', $t->id) }}">{{ $t->title }}</a>
                        </div>
                        <div class="as-hide-sm"><span class="as-meta">{{ $t->completed_at->format('M j, g:i A') }}</span></div>
                        <div><span class="as-pill as-p-{{ $t->priority }}">{{ ucfirst($t->priority) }}</span></div>
                        <div class="as-hide-sm"><span class="as-pill as-tag">{{ $storeLabels[$t->store] ?? 'Both' }}</span></div>
                        <div class="as-hide-sm"></div>
                    </div>
                @empty
                    <div class="as-empty">Tasks you complete will show up here.</div>
                @endforelse
            </div>
        </div>
        @endif
    </div>

    <p class="as-note">Shows tasks assigned to you, plus unassigned tasks at the store you're on shift at today.</p>

</div>
</section>

@include('tasks.partials.asana_script')
@endsection
