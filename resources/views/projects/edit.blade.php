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

        @php
            $projectTasks = \Schema::hasColumn('weekly_tasks', 'project_id')
                ? $project->tasks()->with('assignees')->orderByRaw("status = 'complete'")->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")->orderBy('end_date')->get()
                : collect();
            $doneCount = $projectTasks->where('status', 'complete')->count();
            $pct = $projectTasks->count() ? round(100 * $doneCount / $projectTasks->count()) : 0;
        @endphp
        <div style="border-top:1px solid #edeae9;padding:16px 24px 8px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                <h4 style="font-size:14px;font-weight:600;margin:0;">Tasks <span class="as-count">{{ $doneCount }} of {{ $projectTasks->count() }} done</span></h4>
                <div class="as-bar" style="max-width:220px;"><span style="width:{{ $pct }}%"></span></div>
                <div class="as-seg" id="asProjView" style="margin-left:auto;">
                    <a href="#" data-show="incomplete" class="on"><i class="fa fa-circle-o"></i> Incomplete</a>
                    <a href="#" data-show="completed"><i class="fa fa-check-circle-o"></i> Completed</a>
                    <a href="#" data-show="all">All</a>
                </div>
            </div>
            <div class="as-table" id="asProjTasks" style="margin-bottom:10px;">
                <div class="as-empty" id="asProjNone" style="border-top:0;padding-left:16px;display:none;">Nothing to show here.</div>
                @forelse($projectTasks as $t)
                    @if($t->status === 'complete')
                        <div class="as-row done">
                            <div>
                                <span class="as-check done">{!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}</span>
                                <a class="as-name" href="{{ action('TaskController@edit', $t->id) }}">{{ $t->title }}</a>
                            </div>
                            <div class="as-hide-sm"><span class="as-meta">{{ $t->completed_at ? 'Done ' . $t->completed_at->format('M j') : '' }}</span></div>
                            <div><span class="as-pill as-p-{{ $t->priority }}">{{ ucfirst($t->priority) }}</span></div>
                            <div class="as-hide-sm"><span class="as-pill as-tag">{{ $storeLabels[$t->store] ?? 'Both' }}</span></div>
                            <div class="as-hide-sm"></div>
                        </div>
                    @else
                        @include('tasks.partials.asana_row', ['t' => $t, 'due' => \App\Http\Controllers\TeamProgressController::dueAt($t), 'viaShift' => false, 'storeLabels' => $storeLabels])
                    @endif
                @empty
                    <div class="as-empty" style="border-top:0;padding-left:16px;">No tasks yet. Break this project into steps below.</div>
                @endforelse
            </div>
            <form method="POST" action="{{ action('TaskController@store') }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
                @csrf
                <input type="hidden" name="project_id" value="{{ $project->id }}">
                <input type="hidden" name="return_to" value="project">
                <input type="hidden" name="task_type" value="weekly">
                <input type="hidden" name="no_due_date" value="1">
                <input type="hidden" name="start_date" value="{{ now()->toDateString() }}">
                <input type="hidden" name="priority" value="{{ $project->priority ?: 'medium' }}">
                @if($project->store)
                    <input type="hidden" name="store" value="{{ $project->store }}">
                @endif
                <input type="text" name="title" required maxlength="200" placeholder="Add a task to this project..." style="flex:1;min-width:200px;border:1px solid #edeae9;border-radius:6px;padding:6px 10px;font-size:14px;">
                <button type="submit" class="as-btn" style="border:0;"><i class="fa fa-plus"></i> Add task</button>
                <a href="{{ action('TaskController@create', ['project_id' => $project->id]) }}" class="as-meta" style="text-decoration:none;">More options</a>
            </form>
        </div>

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
@include('tasks.partials.asana_script')
<script>
(function () {
    var seg = document.getElementById('asProjView');
    var box = document.getElementById('asProjTasks');
    var none = document.getElementById('asProjNone');
    if (!seg || !box) { return; }
    function apply(mode) {
        var shown = 0;
        box.querySelectorAll('.as-row').forEach(function (r) {
            var done = r.classList.contains('done');
            var vis = mode === 'all' || (mode === 'completed' ? done : !done);
            r.style.display = vis ? '' : 'none';
            if (vis) { shown++; }
        });
        if (none) { none.style.display = (shown === 0 && box.querySelectorAll('.as-row').length) ? '' : 'none'; }
        seg.querySelectorAll('a').forEach(function (a) { a.classList.toggle('on', a.dataset.show === mode); });
    }
    seg.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); apply(a.dataset.show); });
    });
    apply('incomplete');
})();
</script>
@stop
