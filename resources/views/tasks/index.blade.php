@extends('layouts.app')
@section('title', 'Weekly Tasks')

@section('content')
<section class="content-header">
    <h1>Tasks &amp; Projects <small>weekly tasks</small>
        <a href="{{ action('TaskController@create') }}" class="btn btn-primary pull-right"><i class="fa fa-plus"></i> Add Task</a>
    </h1>
</section>

<section class="content">

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    @include('tasks.partials.tabs')

    <div class="box box-solid">
        <div class="box-header with-border" style="display:flex;align-items:center;flex-wrap:wrap;">
            @include('tasks.partials.store_toggle', ['indexAction' => action('TaskController@index'), 'store' => $store, 'storeLabels' => $storeLabels])
            <form method="GET" action="{{ action('TaskController@index') }}" class="form-inline">
                @if($store)<input type="hidden" name="store" value="{{ $store }}">@endif
                <label>Status</label>
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="" @if(!$status) selected @endif>All</option>
                    <option value="not_started" @if($status==='not_started') selected @endif>Not started</option>
                    <option value="in_progress" @if($status==='in_progress') selected @endif>In progress</option>
                    <option value="complete" @if($status==='complete') selected @endif>Complete</option>
                </select>
            </form>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Store</th>
                        <th>Start</th>
                        <th>Ends</th>
                        <th>Status</th>
                        <th>Created by</th>
                        <th>Started by</th>
                        <th>Completed by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tasks as $t)
                    <tr>
                        <td><strong>{{ $t->title }}</strong>@if($t->description)<div class="text-muted"><small>{{ $t->description }}</small></div>@endif</td>
                        <td>{{ $t->store ? ($storeLabels[$t->store] ?? $t->store) : 'Both' }}</td>
                        <td>{{ $t->start_date->format('M j, Y') }}</td>
                        <td>{{ $t->end_date->format('M j, Y') }}</td>
                        <td>
                            @include('tasks.partials.status_dropdown', ['action' => action('TaskController@updateStatus', $t->id), 'status' => $t->status])
                        </td>
                        <td class="text-muted">{{ $t->creator->user_full_name ?? '' }}</td>
                        <td class="text-muted">
                            {{ $t->startedBy->user_full_name ?? '—' }}
                            @if($t->started_at)<div><small>{{ $t->started_at->format('M j, Y') }}</small></div>@endif
                        </td>
                        <td class="text-muted">
                            {{ $t->completedBy->user_full_name ?? '—' }}
                            @if($t->completed_at)<div><small>{{ $t->completed_at->format('M j, Y') }}</small></div>@endif
                        </td>
                        <td class="text-right" style="white-space:nowrap;">
                            <a href="{{ action('TaskController@edit', $t->id) }}" class="btn btn-xs btn-default"><i class="fa fa-edit"></i></a>
                            <form action="{{ action('TaskController@destroy', $t->id) }}" method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this task?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center text-muted">No tasks yet. Click "Add Task" to create one.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="text-center">{{ $tasks->links() }}</div>
        </div>
    </div>
</section>
@stop
