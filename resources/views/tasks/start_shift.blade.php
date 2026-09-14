@extends('layouts.app')
@section('title', 'Start Shift')

@section('content')
<section class="content-header">
    <h1>You're clocked in <small>here's what's on your plate today</small></h1>
</section>

<section class="content">
    @if(empty($dueTasks))
        <div class="alert alert-success">
            <strong>Nothing due today.</strong> You're all caught up — have a good shift!
        </div>
    @else
        @include('tasks.partials.due_today_bubble', ['dueTodayTasks' => $dueTasks])
    @endif

    <a href="{{ $continueUrl }}" class="btn btn-primary btn-lg"><i class="fa fa-arrow-right"></i> Continue to POS</a>
</section>
@stop
