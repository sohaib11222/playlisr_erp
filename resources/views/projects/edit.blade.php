@extends('layouts.app')
@section('title', $project->title)

@section('content')
@include('tasks.partials.asana_styles')
@include('tasks.partials.asana_detail_styles')

<section class="content">
<div class="as-wrap as-task">

    @include('tasks.partials.asana_nav')

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="as-task-card">
        <form method="POST" action="{{ action('ProjectController@update', $project->id) }}" id="asProjectForm">
            @csrf
            @method('PUT')
            <div class="as-task-top">
                <button type="button" id="asCompleteBtn" class="as-complete-btn {{ $project->status === 'complete' ? 'is-done' : '' }}">
                    {!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}
                    <span>{{ $project->status === 'complete' ? 'Completed' : 'Mark complete' }}</span>
                </button>
                <a href="{{ action('ProjectController@index') }}" class="as-back"><i class="fa fa-times"></i> Close</a>
            </div>
            @include('projects.partials.asana_form', ['project' => $project])
            <div class="as-save-bar">
                <button type="submit" class="as-btn" style="border:0;">Save changes</button>
                <a href="{{ action('ProjectController@index') }}" class="as-btn-ghost">Cancel</a>
            </div>
        </form>

        <div class="as-activity">
            <h4>Members <span class="as-count">{{ $project->contributors->count() }}</span></h4>
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px;">
                @forelse($project->contributors as $c)
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #edeae9;border-radius:16px;padding:3px 10px 3px 4px;font-size:13px;">
                        {!! \App\Http\Controllers\TeamProgressController::avatar($c->id, trim($c->first_name . ' ' . $c->last_name), 'sm') !!}
                        {{ trim($c->first_name . ' ' . $c->last_name) }}
                        @if((int) $c->id === (int) auth()->id())
                            <form action="{{ action('ProjectController@removeContributor', [$project->id, $c->id]) }}" method="POST" style="display:inline;margin:0;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="as-leave" title="Leave this project">Leave</button>
                            </form>
                        @endif
                    </span>
                @empty
                    <span class="as-meta">No one has joined yet.</span>
                @endforelse
                @if(!$project->contributors->contains('id', auth()->id()))
                    <form action="{{ action('ProjectController@join', $project->id) }}" method="POST" style="display:inline;margin:0;">
                        @csrf
                        <button type="submit" class="as-join" style="margin-left:0;"><i class="fa fa-plus"></i> Join this project</button>
                    </form>
                @endif
            </div>

            <h4>Activity</h4>
            <div class="as-ev">
                <span class="as-dot"><i class="fa fa-plus"></i></span>
                <div>{{ $project->creator->user_full_name ?? 'Someone' }} created this project <span class="as-when">{{ $project->created_at->format('M j, g:i A') }}</span></div>
            </div>
            @if($project->startedBy && $project->started_at)
                <div class="as-ev">
                    <span class="as-dot" style="background:#fdefc6;color:#8a6100;"><i class="fa fa-play"></i></span>
                    <div>{{ $project->startedBy->user_full_name }} started this project <span class="as-when">{{ $project->started_at->format('M j, g:i A') }}</span></div>
                </div>
            @endif
            @if($project->completedBy && $project->completed_at)
                <div class="as-ev">
                    <span class="as-dot" style="background:#e6f4ee;color:#2f7a57;"><i class="fa fa-check"></i></span>
                    <div>{{ $project->completedBy->user_full_name }} completed this project <span class="as-when">{{ $project->completed_at->format('M j, g:i A') }}</span></div>
                </div>
            @endif
        </div>
    </div>

</div>
</section>

<script>
(function () {
    var btn = document.getElementById('asCompleteBtn');
    var status = document.getElementById('asStatus');
    var form = document.getElementById('asProjectForm');
    if (status) {
        status.addEventListener('change', function () { status.className = 'as-status as-st-' + status.value; });
    }
    if (btn && status && form) {
        btn.addEventListener('click', function () {
            status.value = status.value === 'complete' ? 'in_progress' : 'complete';
            form.submit();
        });
    }
})();
</script>
@stop
