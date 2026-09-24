@extends('layouts.app')
@section('title', 'Tasks')

@section('content')
@include('tasks.partials.asana_styles')
<style>
.as-all .as-cols, .as-all .as-row { grid-template-columns: minmax(0, 1fr) 132px 150px 96px 104px 120px 64px; }
.as-all .as-row { cursor: default; }
.as-tname { display: flex; flex-direction: column; min-width: 0; }
.as-tname .as-line { display: flex; align-items: center; gap: 8px; min-width: 0; }
.as-tname .as-desc { color: #6d6e6f; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.as-note-btn { border: 0; background: none; color: #9ca6af; font-size: 12px; padding: 0 4px; cursor: pointer; white-space: nowrap; }
.as-note-btn:hover { color: #1e1f21; }
.as-detail { display: none; border-top: 1px solid #edeae9; background: #fafafa; padding: 12px 16px 14px 46px; font-size: 13px; }
.as-detail.open { display: block; }
.as-detail .as-who { color: #6d6e6f; font-size: 12px; margin-bottom: 8px; }
.as-detail .as-n { margin-bottom: 6px; display: flex; gap: 8px; align-items: flex-start; }
.as-detail .as-n .as-avatar { margin-top: 1px; }
.as-detail form { display: flex; gap: 6px; margin-top: 8px; max-width: 520px; }
.as-detail input[type=text] { flex: 1; border: 1px solid #edeae9; border-radius: 6px; padding: 5px 10px; font-size: 13px; }
.as-detail button[type=submit] { border: 0; background: #4573d2; color: #fff; border-radius: 6px; padding: 5px 12px; font-size: 13px; }
.as-check-lbl { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #1e1f21; margin: 0; font-weight: 400; cursor: pointer; }
@media (max-width: 767px) { .as-all .as-row { grid-template-columns: minmax(0, 1fr) auto; } .as-detail { padding-left: 16px; } }
</style>

<section class="content">
<div class="as-wrap">

    <div class="as-head">
        <div>
            <h1 class="as-title">All Tasks</h1>
            <div class="as-sub">Everything on the team's list today and this week.</div>
        </div>
    </div>

    @include('tasks.partials.asana_nav')

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    @php
        $vq = request()->except(['status', 'page']);
        $viewUrls = [
            'incomplete' => action('TaskController@index') . '?' . http_build_query(array_merge($vq, ['status' => 'incomplete'])),
            'completed' => action('TaskController@index') . '?' . http_build_query(array_merge($vq, ['status' => 'complete'])),
            'all' => action('TaskController@index') . '?' . http_build_query(array_merge($vq, ['status' => 'all'])),
        ];
        $viewCurrent = $status === 'incomplete' ? 'incomplete' : ($status === 'complete' ? 'completed' : 'all');
    @endphp
    <div class="as-toolbar">
        <div class="as-tools">
            <a href="{{ action('TaskController@create') }}" class="as-btn"><i class="fa fa-plus"></i> Add task</a>
            @include('tasks.partials.asana_view_toggle')
        </div>
        <form method="GET" action="{{ action('TaskController@index') }}" class="as-tools">
            <input type="hidden" name="status" value="{{ $status ?: 'all' }}">
            @if($canToggleStore)
                <select name="store" class="as-filter" onchange="this.form.submit()">
                    <option value="" @if(!$store) selected @endif>All stores</option>
                    @foreach($storeLabels as $key => $label)
                        <option value="{{ $key }}" @if($store === $key) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            @elseif($store)
                <input type="hidden" name="store" value="{{ $store }}">
                <span class="as-chip">{{ $storeLabels[$store] ?? ucfirst($store) }}</span>
            @endif
            <select name="type" class="as-filter" onchange="this.form.submit()">
                <option value="" @if(!$type) selected @endif>Today + this week</option>
                <option value="daily" @if($type === 'daily') selected @endif>Today</option>
                <option value="weekly" @if($type === 'weekly') selected @endif>This week</option>
            </select>
            <select name="priority" class="as-filter" onchange="this.form.submit()">
                <option value="" @if(!$priority) selected @endif>Any priority</option>
                @foreach($priorityLabels as $key => $label)
                    <option value="{{ $key }}" @if($priority === $key) selected @endif>{{ $label }} priority</option>
                @endforeach
            </select>
            <label class="as-chip {{ $assignedToMe ? 'on' : '' }}">
                <input type="checkbox" name="assigned_to_me" value="1" onchange="this.form.submit()" @if($assignedToMe) checked @endif style="display:none;">
                <i class="fa fa-user"></i> Just mine
            </label>
            @if($canToggleStore)
                <a href="{{ url('/admin/task-store-assignments') }}" class="as-icon-btn" title="Store assignments"><i class="fa fa-cog"></i></a>
            @endif
        </form>
    </div>

    @php
        $groups = [
            'daily' => 'Today',
            'weekly' => 'This week',
        ];
        $byType = $tasks->getCollection()->groupBy('task_type');
    @endphp

    <div class="as-table as-all">
        <div class="as-cols">
            <div>Task name</div><div>Status</div><div>Due date</div><div>Priority</div><div>Store</div><div>Assignees</div><div></div>
        </div>

        @foreach($groups as $key => $title)
            @php
                $rows = $byType->get($key, collect());
            @endphp
            @if(!$type || $type === $key)
            <div class="as-section">
                <div class="as-section-h"><span class="as-caret"><i class="fa fa-caret-down"></i></span> {{ $title }} <span class="as-count">{{ $rows->where('status', '!=', 'complete')->count() }} open{{ $rows->where('status', 'complete')->count() ? ', ' . $rows->where('status', 'complete')->count() . ' done' : '' }}</span></div>
                <div class="as-rows">
                    @forelse($rows as $t)
                        @php
                            $due = \App\Http\Controllers\TeamProgressController::dueAt($t);
                            $isDone = $t->status === 'complete';
                        @endphp
                        <div class="as-row {{ $isDone ? 'done' : '' }}">
                            <div>
                                @if($isDone)
                                    <span class="as-check done" title="Completed{{ $t->completedBy ? ' by ' . $t->completedBy->user_full_name : '' }}">{!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}</span>
                                @else
                                    <button type="button" class="as-check" title="Mark complete" data-url="{{ action('TaskController@updateStatus', $t->id) }}" data-edit="{{ action('TaskController@edit', $t->id) }}">{!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}</button>
                                @endif
                                <div class="as-tname">
                                    <div class="as-line">
                                        <a class="as-name" href="{{ action('TaskController@edit', $t->id) }}">{{ $t->title }}</a>
                                        @if($t->repeat_daily || $t->repeat_weekly || $t->repeat_of)
                                            <span class="as-meta" title="Repeats"><i class="fa fa-repeat"></i></span>
                                        @endif
                                        @if($t->requires_photo)
                                            <span class="as-meta" title="{{ $t->photo_confirmed_at ? 'Photo confirmed' : 'Needs a photo in #taskphotos' }}"><i class="fa fa-camera" style="color:{{ $t->photo_confirmed_at ? '#58a182' : '#c92f54' }}"></i></span>
                                        @endif
                                        @if($t->project_id && $t->project)
                                            <a href="{{ action('ProjectController@edit', $t->project_id) }}" class="as-pill as-tag as-hide-sm" style="text-decoration:none;" title="Project"><i class="fa fa-list-ul"></i> {{ \Illuminate\Support\Str::limit($t->project->title, 24) }}</a>
                                        @endif
                                        <button type="button" class="as-note-btn" data-toggle-detail="d{{ $t->id }}" title="Notes and details"><i class="fa fa-comment-o"></i>@if($t->notes->count()) {{ $t->notes->count() }}@endif</button>
                                    </div>
                                    @if($t->description)
                                        <div class="as-desc" title="{{ $t->description }}">{{ $t->description }}</div>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <form action="{{ action('TaskController@updateStatus', $t->id) }}" method="POST" style="margin:0;">
                                    @csrf
                                    <select name="status" class="as-status as-st-{{ $t->status }}" onchange="this.form.submit()">
                                        <option value="not_started" @if($t->status === 'not_started') selected @endif>Not started</option>
                                        <option value="in_progress" @if($t->status === 'in_progress') selected @endif>In progress</option>
                                        <option value="complete" @if($t->status === 'complete') selected @endif>Complete</option>
                                    </select>
                                </form>
                            </div>
                            <div class="as-hide-sm">
                                @if($t->no_due_date)
                                    <span class="as-meta">No due date</span>
                                @endif
                                @if($due)
                                    <span class="{{ !$isDone && $due->lt(now()) ? 'as-due-late' : ($due->isToday() ? 'as-due-today' : '') }}">
                                        @if($t->task_type === 'weekly')
                                            {{ $t->start_date->format('M j') }} - {{ $t->end_date->format('M j') }}
                                        @else
                                            {{ $due->isToday() ? 'Today' : ($due->isYesterday() ? 'Yesterday' : ($due->isTomorrow() ? 'Tomorrow' : $due->format('M j'))) }}
                                        @endif
                                        @if($t->due_time)
                                            {{ $due->format('g:i A') }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <div class="as-hide-sm"><span class="as-pill as-p-{{ $t->priority }}">{{ $priorityLabels[$t->priority] ?? ucfirst($t->priority) }}</span></div>
                            <div class="as-hide-sm"><span class="as-pill as-tag">{{ $t->store ? ($storeLabels[$t->store] ?? ucfirst($t->store)) : 'Both' }}</span></div>
                            <div class="as-hide-sm">
                                @if($t->assignees->count())
                                    <span class="as-avatars">
                                        @foreach($t->assignees->take(3) as $a)
                                            {!! \App\Http\Controllers\TeamProgressController::avatar($a->id, trim($a->first_name . ' ' . $a->last_name), 'sm') !!}
                                        @endforeach
                                    </span>
                                    @if($t->assignees->count() > 3)
                                        <span class="as-meta" style="margin-left:4px;">+{{ $t->assignees->count() - 3 }}</span>
                                    @endif
                                @else
                                    <span class="as-meta">Unassigned</span>
                                @endif
                            </div>
                            <div class="as-acts">
                                <a href="{{ action('TaskController@edit', $t->id) }}" class="as-icon-btn" title="Edit"><i class="fa fa-pencil"></i></a>
                                @if(\App\Http\Controllers\TeamProgressController::canDelete($t))
                                <form action="{{ action('TaskController@destroy', $t->id) }}" method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Delete this task?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="as-icon-btn del" title="Delete"><i class="fa fa-trash"></i></button>
                                </form>
                                @endif
                            </div>
                        </div>
                        <div class="as-detail" id="d{{ $t->id }}">
                            <div class="as-who">
                                Created by {{ $t->creator->user_full_name ?? 'someone' }}
                                @if($t->startedBy)
                                    &middot; Started by {{ $t->startedBy->user_full_name }}{{ $t->started_at ? ', ' . $t->started_at->format('M j g:i A') : '' }}
                                @endif
                                @if($t->completedBy)
                                    &middot; Completed by {{ $t->completedBy->user_full_name }}{{ $t->completed_at ? ', ' . $t->completed_at->format('M j g:i A') : '' }}
                                @endif
                            </div>
                            @foreach($t->notes as $note)
                                <div class="as-n">
                                    {!! \App\Http\Controllers\TeamProgressController::avatar($note->user_id, $note->author ? trim($note->author->first_name . ' ' . $note->author->last_name) : 'Someone', 'sm') !!}
                                    <div>
                                        <strong>{{ $note->author ? trim($note->author->first_name . ' ' . $note->author->last_name) : 'Someone' }}</strong>
                                        <span class="as-meta">{{ $note->created_at->diffForHumans() }}</span>
                                        <div>{{ $note->note }}</div>
                                    </div>
                                </div>
                            @endforeach
                            <form action="{{ action('TaskController@addNote', $t->id) }}" method="POST">
                                @csrf
                                <input type="text" name="note" placeholder="Add a note..." maxlength="2000" required>
                                <button type="submit">Add</button>
                            </form>
                        </div>
                    @empty
                        <div class="as-empty">No tasks here. Click "Add task" to create one.</div>
                    @endforelse
                </div>
            </div>
            @endif
        @endforeach
    </div>

    <div class="text-center">{{ $tasks->links() }}</div>

</div>
</section>

@include('tasks.partials.asana_script')
<script>
document.querySelectorAll('[data-toggle-detail]').forEach(function (b) {
    b.addEventListener('click', function () {
        var d = document.getElementById(b.getAttribute('data-toggle-detail'));
        if (d) { d.classList.toggle('open'); }
    });
});
</script>
@stop
