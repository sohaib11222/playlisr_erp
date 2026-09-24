@extends('layouts.app')
@section('title', 'Team Progress')

@section('content')
@include('tasks.partials.asana_styles')

<section class="content">
<div class="as-wrap">

    <div class="as-head">
        <div>
            <h1 class="as-title">Team Progress</h1>
            <div class="as-sub">What's getting done and who's on it, {{ $windowStart->format('M j') }} to {{ $today->format('M j') }}.</div>
        </div>
    </div>

    @include('tasks.partials.asana_nav')

    <div class="as-cards">
        <div class="as-card good"><div class="n">{{ array_sum(array_column($score, 'done')) }}</div><div class="l">Completed in the last {{ $days }} days</div></div>
        <div class="as-card info"><div class="n">{{ count($openToday) }}</div><div class="l">Open now</div></div>
        <div class="as-card warn"><div class="n">{{ count($overdue) }}</div><div class="l">Past due, ready to pick up</div></div>
        <div class="as-card"><div class="n">{{ $noOwnerCount }}</div><div class="l">Open tasks waiting for an owner</div></div>
    </div>

    <div class="as-table" style="margin-bottom:18px;">
        <div class="as-section">
            <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> Team wins <span class="as-count">{{ count($score) }}</span></div>
            <div class="as-rows">
                @forelse($score as $uid => $s)
                    <div class="as-row as-row-win">
                        <div>
                            {!! \App\Http\Controllers\TeamProgressController::avatar($uid, $s['name']) !!}
                            <span class="as-name">{{ $s['name'] }}</span>
                        </div>
                        <div><strong>{{ $s['done'] }}</strong>&nbsp;<span class="as-meta">completed</span></div>
                        <div class="as-hide-sm" style="gap:8px;">
                            <div class="as-bar"><span style="width:{{ $s['rate'] === null ? 0 : max(0, $s['rate']) }}%"></span></div>
                            <span class="as-meta">{{ $s['rate'] === null ? '-' : $s['rate'] . '%' }}</span>
                        </div>
                        <div class="as-hide-sm">
                            @if($s['missed'] + $s['missed_shift'] > 0)
                                <span class="as-meta" title="Assigned to them: {{ $s['missed'] }}, closed the store: {{ $s['missed_shift'] }}">{{ $s['missed'] + $s['missed_shift'] }} not done</span>
                            @elseif($s['late'] > 0)
                                <span class="as-meta">{{ $s['late'] }} finished late</span>
                            @else
                                <span class="as-pill as-tag-shift">All done</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="as-empty">No task activity yet this week.</div>
                @endforelse
            </div>
        </div>
    </div>

    @php
        $ownerCell = function ($owner) use ($names) {
            if (empty($owner['ids'])) {
                return '<span class="as-pill as-tag">Unassigned</span>';
            }
            $html = '<span class="as-avatars">';
            foreach (array_slice($owner['ids'], 0, 3) as $id) {
                $html .= \App\Http\Controllers\TeamProgressController::avatar($id, $names[$id] ?? ('User #' . $id), 'sm');
            }
            $html .= '</span>';
            if ($owner['via'] === 'closer') {
                $html .= '<span class="as-meta" style="margin-left:6px;" title="Nobody assigned. The cashier who closed that store that day.">closer</span>';
            }
            if ($owner['via'] === 'sling') {
                $html .= '<span class="as-meta" style="margin-left:6px;" title="Nobody assigned. Whoever Sling had on shift at this store.">on shift</span>';
            }
            return $html;
        };
        $groups = [
            ['Past due', $overdue, 'All caught up.', false],
            ['Open now', $openToday, 'All done for today.', false],
        ];
    @endphp

    <div class="as-table" style="margin-bottom:18px;">
        <div class="as-cols"><div>Task name</div><div>Due date</div><div>Priority</div><div>Store</div><div>Owner</div></div>
        @foreach($groups as $g)
            <div class="as-section">
                <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> {{ $g[0] }} <span class="as-count">{{ count($g[1]) }}</span></div>
                <div class="as-rows">
                    @forelse($g[1] as $row)
                        @php $t = $row['task']; @endphp
                        @php $due = \App\Http\Controllers\TeamProgressController::dueAt($t); @endphp
                        <div class="as-row">
                            <div>
                                <button type="button" class="as-check" title="Mark complete" data-url="{{ action('TaskController@updateStatus', $t->id) }}" data-edit="{{ action('TaskController@edit', $t->id) }}">{!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}</button>
                                <a class="as-name" href="{{ action('TaskController@edit', $t->id) }}">{{ $t->title }}</a>
                                @if($t->status === 'in_progress')
                                    <span class="as-pill as-tag as-hide-sm">In progress</span>
                                @endif
                            </div>
                            <div class="as-hide-sm">
                                @if($due)
                                    <span class="{{ $due->lt(now()) ? 'as-due-late' : ($due->isToday() ? 'as-due-today' : '') }}">
                                        {{ $due->isToday() ? 'Today' : ($due->isYesterday() ? 'Yesterday' : $due->format('M j')) }}
                                        @if($t->due_time)
                                            {{ $due->format('g:i A') }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <div><span class="as-pill as-p-{{ $t->priority }}">{{ ucfirst($t->priority) }}</span></div>
                            <div class="as-hide-sm"><span class="as-pill as-tag">{{ $storeLabels[$t->store] ?? 'Both' }}</span></div>
                            <div class="as-hide-sm">{!! $ownerCell($row['owner']) !!}</div>
                        </div>
                    @empty
                        <div class="as-empty">{{ $g[2] }}</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <div class="as-table">
        <div class="as-section collapsed">
            <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> Didn't get to, last {{ $days }} days <span class="as-count">{{ count($missedRows) }}</span></div>
            <div class="as-rows">
                @forelse($missedRows as $row)
                    @php $t = $row['task']; @endphp
                    <div class="as-row as-row-miss">
                        <div><span class="as-name">{{ $t->title }}</span>
                            @if($t->repeat_daily)
                                <span class="as-meta"><i class="fa fa-repeat"></i></span>
                            @endif
                        </div>
                        <div class="as-hide-sm"><span class="as-meta">{{ $row['date']->format('D M j') }}</span></div>
                        <div class="as-hide-sm"><span class="as-pill as-tag">{{ $storeLabels[$t->store] ?? 'Both' }}</span></div>
                        <div>{!! $ownerCell($row['owner']) !!}</div>
                    </div>
                @empty
                    <div class="as-empty">Everything got done.</div>
                @endforelse
            </div>
        </div>
    </div>

    <p class="as-note">Tasks with nobody assigned show everyone Sling had on a Cashier shift at that store. If one doesn't get done, it counts only against the closer, the cashier whose shift ended last. Checking one off here marks it complete, same as on the Tasks list.</p>

</div>
</section>

@include('tasks.partials.asana_script')
@endsection
