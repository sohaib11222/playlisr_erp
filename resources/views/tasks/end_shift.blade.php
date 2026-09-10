@extends('layouts.app')
@section('title', 'End Shift')

@section('content')
<section class="content-header">
    <h1>End Shift <small>before you go, check off anything you got to</small></h1>
</section>

<section class="content">
    @if(empty($dueTasks))
        <div class="alert alert-success">
            <strong>You're all caught up.</strong> Nothing assigned to you is due today. Have a good one!
        </div>
    @else
        @include('tasks.partials.due_today_bubble', ['dueTodayTasks' => $dueTasks])
    @endif

    <a href="{{ route('tasks.index') }}" class="btn btn-default"><i class="fa fa-list"></i> View all Tasks &amp; Projects</a>
</section>
@stop
