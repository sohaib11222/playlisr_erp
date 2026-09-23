@extends('layouts.app')
@section('title', 'Task Accountability')

@php
    $ownerText = function ($owner) use ($names) {
        if (empty($owner['ids'])) {
            return '<span class="label label-danger">No owner</span>';
        }
        $list = e(implode(', ', array_map(function ($id) use ($names) { return $names[$id] ?? ('User #' . $id); }, $owner['ids'])));
        return $owner['via'] === 'sling'
            ? $list . ' <small class="text-muted" title="No one assigned. Owner is whoever Sling had on shift at this store.">(on shift)</small>'
            : $list;
    };
@endphp

@section('content')
<section class="content-header">
    <h1>Task Accountability <small>who owns what, what's late, who's getting it done</small></h1>
</section>

<section class="content">

    @include('tasks.partials.tabs')

    <div class="row">
        <div class="col-sm-4"><div class="small-box bg-red"><div class="inner"><h3>{{ count($overdue) }}</h3><p>Overdue tasks</p></div></div></div>
        <div class="col-sm-4"><div class="small-box bg-yellow"><div class="inner"><h3>{{ count($missedRows) }}</h3><p>Missed in the last {{ $days }} days</p></div></div></div>
        <div class="col-sm-4"><div class="small-box bg-gray"><div class="inner"><h3>{{ $noOwnerCount }}</h3><p>Open tasks with nobody assigned</p></div></div></div>
    </div>

    <p class="text-muted">
        If a task has nobody assigned, its owner is whoever Sling had on a Cashier shift at that store that day, marked "(on shift)".
        This page only reads tasks. It doesn't change them.
    </p>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Scorecard, {{ $windowStart->format('M j') }} to {{ $today->format('M j') }}</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Done</th>
                        <th>Done late</th>
                        <th>Missed (assigned)</th>
                        <th>Missed (on shift)</th>
                        <th>On time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($score as $s)
                    <tr>
                        <td>{{ $s['name'] }}</td>
                        <td>{{ $s['done'] }}</td>
                        <td>{{ $s['late'] ?: '' }}</td>
                        <td>@if($s['missed'])<span class="text-danger"><strong>{{ $s['missed'] }}</strong></span>@endif</td>
                        <td>@if($s['missed_shift'])<span class="text-warning"><strong>{{ $s['missed_shift'] }}</strong></span>@endif</td>
                        <td>
                            @if($s['rate'] === null)
                                -
                            @else
                                <span class="label label-{{ $s['rate'] >= 90 ? 'success' : ($s['rate'] >= 70 ? 'warning' : 'danger') }}">{{ $s['rate'] }}%</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-muted">No task activity in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Overdue</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Task</th><th>Store</th><th>Was due</th><th>Late by</th><th>Status</th><th>Owner</th><th></th></tr></thead>
                <tbody>
                    @forelse($overdue as $row)
                    @php $t = $row['task']; @endphp
                    <tr>
                        <td><strong>{{ $t->title }}</strong></td>
                        <td>{{ $storeLabels[$t->store] ?? 'Both' }}</td>
                        <td>{{ $t->end_date->format('D M j') }}</td>
                        <td><span class="text-danger">{{ $row['days'] }} {{ $row['days'] == 1 ? 'day' : 'days' }}</span></td>
                        <td>@include('tasks.partials.status_label', ['status' => $t->status])</td>
                        <td>{!! $ownerText($row['owner']) !!}</td>
                        <td><a href="{{ action('TaskController@edit', $t->id) }}" class="btn btn-xs btn-default">Open</a></td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-muted">Nothing overdue.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Open right now</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Task</th><th>Store</th><th>Priority</th><th>Due</th><th>Status</th><th>Owner today</th><th></th></tr></thead>
                <tbody>
                    @forelse($openToday as $row)
                    @php $t = $row['task']; @endphp
                    <tr>
                        <td><strong>{{ $t->title }}</strong></td>
                        <td>{{ $storeLabels[$t->store] ?? 'Both' }}</td>
                        <td>{{ ucfirst($t->priority) }}</td>
                        <td>{{ $t->end_date->isSameDay($today) ? 'Today' : $t->end_date->format('D M j') }}</td>
                        <td>@include('tasks.partials.status_label', ['status' => $t->status])</td>
                        <td>{!! $ownerText($row['owner']) !!}</td>
                        <td><a href="{{ action('TaskController@edit', $t->id) }}" class="btn btn-xs btn-default">Open</a></td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-muted">Nothing open.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Missed, last {{ $days }} days</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Day</th><th>Task</th><th>Store</th><th>Owner</th></tr></thead>
                <tbody>
                    @forelse($missedRows as $row)
                    @php $t = $row['task']; @endphp
                    <tr>
                        <td>{{ $row['date']->format('D M j') }}</td>
                        <td>{{ $t->title }}@if($t->repeat_daily) <small class="text-muted">(daily)</small>@endif</td>
                        <td>{{ $storeLabels[$t->store] ?? 'Both' }}</td>
                        <td>{!! $ownerText($row['owner']) !!}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-muted">Nothing missed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@endsection
