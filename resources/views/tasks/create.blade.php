@extends('layouts.app')
@section('title', $type === 'daily' ? 'Add Daily Task' : 'Add Weekly Task')

@section('content')
<section class="content-header"><h1>{{ $type === 'daily' ? 'Add Daily Task' : 'Add Weekly Task' }}</h1></section>

<section class="content">
    @include('tasks.partials.tabs')
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('TaskController@store') }}">
                @csrf
                @include('tasks.partials.form_fields', ['task' => null, 'type' => $type])
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('TaskController@index', ['type' => $type]) }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>
@stop
