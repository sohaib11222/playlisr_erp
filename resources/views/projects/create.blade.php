@extends('layouts.app')
@section('title', 'Add Project')

@section('content')
<section class="content-header"><h1>Add Project</h1></section>

<section class="content">
    @include('tasks.partials.tabs')
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('ProjectController@store') }}">
                @csrf
                @include('projects.partials.form_fields', ['project' => null])
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('ProjectController@index') }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>
@stop
