@extends('layouts.app')
@section('title', 'Add Project')

@section('content')
@include('tasks.partials.asana_styles')
@include('tasks.partials.asana_detail_styles')

<section class="content">
<div class="as-wrap as-task">

    @include('tasks.partials.asana_nav')

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="as-task-card">
        <form method="POST" action="{{ action('ProjectController@store') }}">
            @csrf
            <div class="as-task-top">
                <strong style="font-size:14px;">New project</strong>
                <a href="{{ action('ProjectController@index') }}" class="as-back"><i class="fa fa-times"></i> Close</a>
            </div>
            @include('projects.partials.asana_form', ['project' => null])
            <div class="as-save-bar">
                <button type="submit" class="as-btn" style="border:0;">Create project</button>
                <a href="{{ action('ProjectController@index') }}" class="as-btn-ghost">Cancel</a>
            </div>
        </form>
    </div>

</div>
</section>
@stop
