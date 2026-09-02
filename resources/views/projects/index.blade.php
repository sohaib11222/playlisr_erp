@extends('layouts.app')
@section('title', 'Projects')

@section('content')
<section class="content-header">
    <h1>Tasks &amp; Projects <small>projects</small>
        <a href="{{ action('ProjectController@create') }}" class="btn btn-primary pull-right"><i class="fa fa-plus"></i> Add Project</a>
    </h1>
</section>

<section class="content">

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    @include('tasks.partials.tabs')

    <div class="box box-solid">
        <div class="box-header with-border" style="display:flex;align-items:center;flex-wrap:wrap;">
            @include('tasks.partials.store_toggle', ['indexAction' => action('ProjectController@index'), 'store' => $store, 'storeLabels' => $storeLabels, 'canToggleStore' => $canToggleStore])
            <form method="GET" action="{{ action('ProjectController@index') }}" class="form-inline">
                @if($store)<input type="hidden" name="store" value="{{ $store }}">@endif
                <label style="margin-right:5px;">Status</label>
                <select name="status" class="form-control" onchange="this.form.submit()" style="margin-right:15px;">
                    <option value="" @if(!$status) selected @endif>All</option>
                    <option value="not_started" @if($status==='not_started') selected @endif>Not started</option>
                    <option value="in_progress" @if($status==='in_progress') selected @endif>In progress</option>
                    <option value="complete" @if($status==='complete') selected @endif>Complete</option>
                </select>
                <label style="margin-right:5px;">Priority</label>
                <select name="priority" class="form-control" onchange="this.form.submit()">
                    <option value="" @if(!$priority) selected @endif>All</option>
                    @foreach($priorityLabels as $key => $label)
                        <option value="{{ $key }}" @if($priority===$key) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="box-body">
            @forelse($projects as $p)
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        {{ $p->title }}
                        <span class="label label-default">{{ $p->store ? ($storeLabels[$p->store] ?? $p->store) : 'Both stores' }}</span>
                        <span class="label label-{{ ['high'=>'danger','medium'=>'warning','low'=>'default'][$p->priority] ?? 'default' }}">{{ $priorityLabels[$p->priority] ?? ucfirst($p->priority) }}</span>
                    </h3>
                    <div class="box-tools">
                        @include('tasks.partials.status_dropdown', ['action' => action('ProjectController@updateStatus', $p->id), 'status' => $p->status])
                        <a href="{{ action('ProjectController@edit', $p->id) }}" class="btn btn-xs btn-default"><i class="fa fa-edit"></i></a>
                        <form action="{{ action('ProjectController@destroy', $p->id) }}" method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this project?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <div class="box-body">
                    @if($p->description)<p>{{ $p->description }}</p>@endif

                    <div class="row">
                        <div class="col-md-4">
                            <strong>Created by</strong><br>
                            <span class="text-muted">{{ $p->creator->user_full_name ?? '' }}</span>
                        </div>
                        <div class="col-md-4">
                            <strong>Started by</strong><br>
                            <span class="text-muted">
                                {{ $p->startedBy->user_full_name ?? '—' }}
                                @if($p->started_at)<br><small>{{ $p->started_at->format('M j, Y') }}</small>@endif
                            </span>
                        </div>
                        <div class="col-md-4">
                            <strong>Completed by</strong><br>
                            <span class="text-muted">
                                {{ $p->completedBy->user_full_name ?? '—' }}
                                @if($p->completed_at)<br><small>{{ $p->completed_at->format('M j, Y') }}</small>@endif
                            </span>
                        </div>
                    </div>

                    <hr>

                    <strong>Contributors</strong>
                    <div>
                        @forelse($p->contributors as $c)
                            <span class="label label-info" style="margin-right:4px;">
                                {{ $c->user_full_name }}
                                @if((int)$c->id === (int)auth()->id())
                                <form action="{{ action('ProjectController@removeContributor', [$p->id, $c->id]) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-link btn-xs" style="color:#fff;padding:0 0 0 4px;" title="Leave this project">&times;</button>
                                </form>
                                @endif
                            </span>
                        @empty
                            <span class="text-muted">No one has joined yet.</span>
                        @endforelse
                    </div>

                    @if(!$p->contributors->contains('id', auth()->id()))
                    <form action="{{ action('ProjectController@join', $p->id) }}" method="POST" style="margin-top:10px;">
                        @csrf
                        <button type="submit" class="btn btn-xs btn-success"><i class="fa fa-plus"></i> Join this project</button>
                    </form>
                    @endif
                </div>
            </div>
            @empty
            <p class="text-center text-muted">No projects yet. Click "Add Project" to create one.</p>
            @endforelse

            <div class="text-center">{{ $projects->links() }}</div>
        </div>
    </div>
</section>
@stop
