@extends('layouts.app')
@section('title', 'Edit Task')

@section('content')
<section class="content-header"><h1>Edit Task</h1></section>

<section class="content">
    @include('tasks.partials.tabs')
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('TaskController@update', $task->id) }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @include('tasks.partials.form_fields', ['task' => $task])
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="not_started" @if($task->status==='not_started') selected @endif>Not started</option>
                        <option value="in_progress" @if($task->status==='in_progress') selected @endif>In progress</option>
                        <option value="complete" @if($task->status==='complete') selected @endif>Complete</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('TaskController@index') }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">Notes</h3></div>
        <div class="box-body">
            <form method="POST" action="{{ action('TaskController@addNote', $task->id) }}" style="margin-bottom:15px;">
                @csrf
                <div class="form-group">
                    <textarea name="note" class="form-control" rows="2" placeholder="Post a progress note — visible to everyone viewing this task." required>{{ old('note') }}</textarea>
                </div>
                <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-comment"></i> Add note</button>
            </form>

            @if($task->notes->isEmpty())
                <p class="text-muted">No notes yet.</p>
            @else
                <ul class="list-unstyled" style="border-top:1px solid #eee;">
                    @foreach($task->notes as $note)
                        <li style="padding:10px 0;border-bottom:1px solid #eee;">
                            <div style="white-space:pre-wrap;">{{ $note->note }}</div>
                            <small class="text-muted">
                                {{ $note->author ? trim($note->author->first_name . ' ' . $note->author->last_name) : 'Unknown' }}
                                &middot; {{ $note->created_at->format('M j, Y g:i A') }}
                            </small>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>
@stop
