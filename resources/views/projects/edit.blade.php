@extends('layouts.app')
@section('title', 'Edit Project')

@section('content')
<section class="content-header"><h1>Edit Project</h1></section>

<section class="content">
    @include('tasks.partials.tabs')
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('ProjectController@update', $project->id) }}">
                @csrf
                @method('PUT')
                @include('projects.partials.form_fields', ['project' => $project])
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="not_started" @if($project->status==='not_started') selected @endif>Not started</option>
                        <option value="in_progress" @if($project->status==='in_progress') selected @endif>In progress</option>
                        <option value="complete" @if($project->status==='complete') selected @endif>Complete</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('ProjectController@index') }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>
@stop
