@extends('layouts.app')
@section('title', 'Edit Task')

@section('content')
<section class="content-header"><h1>Edit Task</h1></section>

<section class="content">
    @include('tasks.partials.tabs', ['type' => $task->task_type])
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('TaskController@update', $task->id) }}">
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
                <a href="{{ action('TaskController@index', ['type' => $task->task_type]) }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>
@stop
