@extends('layouts.app')
@section('title', $task->title)

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

    @php($currentTaskType = old('task_type', $task->task_type))
    @php($currentStatus = old('status', $task->status))
    @php($selectedAssignees = old('assignees', $task->assignees->pluck('id')->all()))

    <div class="as-task-card">
        <form method="POST" action="{{ action('TaskController@update', $task->id) }}" id="asTaskForm">
            @csrf
            @method('PUT')

            <div class="as-task-top">
                <button type="button" id="asCompleteBtn" class="as-complete-btn {{ $currentStatus === 'complete' ? 'is-done' : '' }}">
                    {!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}
                    <span>{{ $currentStatus === 'complete' ? 'Completed' : 'Mark complete' }}</span>
                </button>
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : action('TaskController@index') }}" class="as-back"><i class="fa fa-times"></i> Close</a>
            </div>

            <div class="as-task-body">
                <input type="text" class="as-title-input" name="title" value="{{ old('title', $task->title) }}" required maxlength="200" aria-label="Task name">

                <div class="as-fields">
                    <div class="k">Assignees</div>
                    <div class="v" style="display:block;">
                        {!! Form::select('assignees[]', $assignableUsers, $selectedAssignees, ['id' => 'task_assignees', 'class' => 'form-control select2', 'multiple', 'style' => 'width: 100%;', 'data-placeholder' => 'Unassigned']) !!}
                    </div>

                    <div class="k">Status</div>
                    <div class="v">
                        <select name="status" id="asStatus" class="as-status as-st-{{ $currentStatus }}">
                            <option value="not_started" @if($currentStatus === 'not_started') selected @endif>Not started</option>
                            <option value="in_progress" @if($currentStatus === 'in_progress') selected @endif>In progress</option>
                            <option value="complete" @if($currentStatus === 'complete') selected @endif>Complete</option>
                        </select>
                        @if($task->startedBy && $task->started_at)
                            <small class="text-muted">Started by {{ $task->startedBy->first_name }}, {{ $task->started_at->format('M j g:i A') }}</small>
                        @endif
                        @if($task->completedBy && $task->completed_at)
                            <small class="text-muted">Completed by {{ $task->completedBy->first_name }}, {{ $task->completed_at->format('M j g:i A') }}</small>
                        @endif
                    </div>

                    <div class="k">Project</div>
                    <div class="v">
                        <select name="project_id" class="form-control">
                            <option value="">No project</option>
                            @foreach($projectOptions ?? [] as $pid => $ptitle)
                                <option value="{{ $pid }}" @if((int) old('project_id', $task->project_id) === (int) $pid) selected @endif>{{ $ptitle }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="k">Due date</div>
                    <div class="v">
                        <select id="task_type" name="task_type" class="form-control">
                            <option value="daily" @if($currentTaskType === 'daily') selected @endif>Today</option>
                            <option value="weekly" @if($currentTaskType === 'weekly') selected @endif>This week</option>
                        </select>
                        <span id="task_start_date_label_text" class="text-muted" style="display:none;">{{ $currentTaskType === 'daily' ? 'Date' : 'Start date' }}</span>
                        <input type="date" id="task_start_date" class="form-control" name="start_date" value="{{ old('start_date', $task->start_date->toDateString()) }}" required>
                        <span id="task_end_date_wrap" style="{{ $currentTaskType === 'daily' ? 'display:none;' : '' }}">
                            <span class="text-muted">to</span>
                            <input type="text" id="task_end_date_preview" class="form-control" value="{{ $task->end_date->format('M j, Y') }}" disabled style="width:120px;">
                        </span>
                        <span class="text-muted">by</span>
                        <input type="time" class="form-control" name="due_time" value="{{ old('due_time', $task->due_time ? substr($task->due_time, 0, 5) : '') }}" title="Optional. Blank means end of day.">
                    </div>

                    <div class="k">Repeat</div>
                    <div class="v" id="task_repeat_wrap" style="display:block;">
            @if(isset($task) && $task->repeat_of !== null)
                <div class="checkbox" style="margin-top:7px;">
                    <label class="text-muted">
                        <i class="fa fa-repeat"></i>
                        Repeats {{ $task->repeat_weekly ? 'weekly' : 'daily' }}
                        @if($task->repeatRoot)
                            (from "{{ $task->repeatRoot->title }}")
                        @endif
                    </label>
                </div>
                <small class="text-muted">Only the original task controls whether the series repeats.</small>
            @else
                
                @php($todayRepeatOn = old('repeat_daily', $task->repeat_daily ?? false) || old('repeat_weekly', $task->repeat_weekly ?? false))
                @php($todayRepeatFreq = old('repeat_weekly', $task->repeat_weekly ?? false) ? 'weekly' : 'daily')
                <div class="checkbox" id="task_repeat_daily_row" style="margin-top:7px;{{ $currentTaskType === 'daily' ? '' : 'display:none;' }}">
                    <label>
                        <input type="checkbox" id="task_repeat_today_enabled" @if($todayRepeatOn) checked @endif @if($currentTaskType !== 'daily') disabled @endif>
                        Repeat
                    </label>
                    <select id="task_repeat_today_freq" class="form-control" style="width:auto;display:inline-block;margin-left:8px;{{ $todayRepeatOn ? '' : 'display:none;' }}" @if($currentTaskType !== 'daily') disabled @endif>
                        <option value="daily" @if($todayRepeatFreq==='daily') selected @endif>Daily</option>
                        <option value="weekly" @if($todayRepeatFreq==='weekly') selected @endif>Weekly</option>
                    </select>
                    <input type="hidden" id="task_repeat_daily" name="repeat_daily" value="{{ $todayRepeatOn && $todayRepeatFreq==='daily' ? '1' : '0' }}" @if($currentTaskType !== 'daily') disabled @endif>
                    <input type="hidden" id="task_repeat_today_weekly" name="repeat_weekly" value="{{ $todayRepeatOn && $todayRepeatFreq==='weekly' ? '1' : '0' }}" @if($currentTaskType !== 'daily') disabled @endif>
                    <br>
                    <small class="text-muted" id="task_repeat_today_hint">
                        {{ $todayRepeatFreq==='weekly' ? 'Spawns a fresh 1-day task every 7 days instead of needing to be re-added. Uncheck to stop — past ones stay as history.' : 'Resets to "not started" every day instead of needing to be re-added or reset by hand. Uncheck to stop resetting.' }}
                    </small>
                </div>
                <div class="checkbox" id="task_repeat_weekly_row" style="margin-top:7px;{{ $currentTaskType === 'weekly' ? '' : 'display:none;' }}">
                    <label>
                        <input type="checkbox" id="task_repeat_weekly" name="repeat_weekly" value="1" @if(old('repeat_weekly', $task->repeat_weekly ?? false)) checked @endif @if($currentTaskType !== 'weekly') disabled @endif>
                        Repeat weekly
                    </label>
                    <br>
                    <small class="text-muted">Auto-creates a fresh copy each week instead of needing to be re-added. Uncheck to stop — past ones stay as history.</small>
                </div>
            @endif
                    </div>

                    <div class="k">Priority</div>
                    <div class="v">
                        <select name="priority" class="form-control">
                            @foreach($priorityLabels as $key => $label)
                                <option value="{{ $key }}" @if(old('priority', $task->priority) === $key) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="k">Store</div>
                    <div class="v">
                        <select name="store" class="form-control">
                            <option value="" @if(empty($task->store)) selected @endif>Both stores</option>
                            @foreach($storeLabels as $key => $label)
                                <option value="{{ $key }}" @if($task->store === $key) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="k">Photo</div>
                    <div class="v">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="requires_photo" value="1" @if(old('requires_photo', $task->requires_photo)) checked @endif>
                                Needs a photo in #taskphotos before it's complete
                            </label>
                        </div>
                    </div>
                </div>

                @if($task->requires_photo)
                    <div class="as-photo">
                        @if($task->photo_confirmed_at)
                            <span style="color:#2f7a57;"><i class="fa fa-check-circle"></i> Photo confirmed by {{ $task->photoConfirmedBy->user_full_name ?? 'someone' }} on {{ $task->photo_confirmed_at->format('M j, g:i A') }}</span>
                        @else
                            <div class="checkbox" style="margin:0;">
                                <label>
                                    <input type="checkbox" name="photo_confirmed" value="1" @if(old('photo_confirmed')) checked @endif>
                                    I posted a photo of the finished work to #taskphotos in Slack
                                </label>
                            </div>
                            <small class="text-muted">Needed before this can be marked complete.</small>
                        @endif
                    </div>
                @endif

                <div class="as-desc-label">Description</div>
                <textarea name="description" class="as-desc-box" placeholder="What is this task about?">{{ old('description', $task->description) }}</textarea>
            </div>

            <div class="as-save-bar">
                <button type="submit" class="as-btn" style="border:0;">Save changes</button>
                <a href="{{ action('TaskController@index') }}" class="as-btn-ghost">Cancel</a>
            </div>
        </form>

        <div class="as-activity">
            <h4>Activity</h4>
            <div class="as-ev">
                <span class="as-dot"><i class="fa fa-plus"></i></span>
                <div>{{ $task->creator->user_full_name ?? 'Someone' }} created this task <span class="as-when">{{ $task->created_at->format('M j, g:i A') }}</span></div>
            </div>
            @foreach($task->notes->sortBy('created_at') as $note)
                <div class="as-ev">
                    {!! \App\Http\Controllers\TeamProgressController::avatar($note->user_id, $note->author ? trim($note->author->first_name . ' ' . $note->author->last_name) : 'Someone', 'sm') !!}
                    <div>
                        <strong>{{ $note->author ? trim($note->author->first_name . ' ' . $note->author->last_name) : 'Someone' }}</strong>
                        <span class="as-when">{{ $note->created_at->format('M j, g:i A') }}</span>
                        <div class="as-body">{{ $note->note }}</div>
                    </div>
                </div>
            @endforeach
            @if($task->completedBy && $task->completed_at)
                <div class="as-ev">
                    <span class="as-dot" style="background:#e6f4ee;color:#2f7a57;"><i class="fa fa-check"></i></span>
                    <div>{{ $task->completedBy->user_full_name }} completed this task <span class="as-when">{{ $task->completed_at->format('M j, g:i A') }}</span></div>
                </div>
            @endif

            <form method="POST" action="{{ action('TaskController@addNote', $task->id) }}" class="as-comment">
                @csrf
                {!! \App\Http\Controllers\TeamProgressController::avatar(auth()->id(), trim(auth()->user()->first_name . ' ' . auth()->user()->last_name), 'sm') !!}
                <textarea name="note" placeholder="Add a comment or progress note..." required></textarea>
                <button type="submit">Comment</button>
            </form>
        </div>
    </div>

</div>
</section>

<script>
(function() {
    function updateTaskTypeUI() {
        var typeEl = document.getElementById('task_type');
        var startDateEl = document.getElementById('task_start_date');
        if (!typeEl) {
            return;
        }
        var isDaily = typeEl.value === 'daily';

        var labelText = document.getElementById('task_start_date_label_text');
        if (labelText) {
            labelText.textContent = isDaily ? 'Date' : 'Start date';
        }

        var endDateWrap = document.getElementById('task_end_date_wrap');
        if (endDateWrap) {
            endDateWrap.style.display = isDaily ? 'none' : '';
        }

        // Only one of the daily/weekly repeat rows is ever the "active"
        // type at a time, but both live in the DOM together — and the
        // daily row now also carries a hidden `repeat_weekly` field (the
        // "Today, repeat Weekly" option) alongside the weekly row's own
        // `repeat_weekly` checkbox. Disabling the inactive row's fields
        // (not just hiding them) keeps only one same-named field posting.
        var dailyRow = document.getElementById('task_repeat_daily_row');
        if (dailyRow) {
            dailyRow.style.display = isDaily ? '' : 'none';
            dailyRow.querySelectorAll('input, select').forEach(function (el) { el.disabled = !isDaily; });
        }

        var weeklyRow = document.getElementById('task_repeat_weekly_row');
        if (weeklyRow) {
            weeklyRow.style.display = isDaily ? 'none' : '';
            weeklyRow.querySelectorAll('input, select').forEach(function (el) { el.disabled = isDaily; });
        }

        if (isDaily) {
            updateRepeatTodayFields();
        }

        var endDatePreview = document.getElementById('task_end_date_preview');
        if (!startDateEl || !startDateEl.value || !endDatePreview) {
            return;
        }
        var d = new Date(startDateEl.value + 'T00:00:00');
        if (!isDaily) {
            d.setDate(d.getDate() + 7);
        }
        var opts = { year: 'numeric', month: 'short', day: 'numeric' };
        endDatePreview.value = d.toLocaleDateString('en-US', opts);
    }

    // "Today" row's Repeat checkbox + Daily/Weekly frequency select drive
    // two hidden inputs (task_repeat_daily / task_repeat_today_weekly)
    // rather than posting themselves directly, since the actual submitted
    // fields need to be "0"/"1" regardless of checkbox state.
    function updateRepeatTodayFields() {
        var enabled = document.getElementById('task_repeat_today_enabled');
        var freq = document.getElementById('task_repeat_today_freq');
        var dailyHidden = document.getElementById('task_repeat_daily');
        var weeklyHidden = document.getElementById('task_repeat_today_weekly');
        var hint = document.getElementById('task_repeat_today_hint');
        if (!enabled || !freq || !dailyHidden || !weeklyHidden) {
            return;
        }

        var on = enabled.checked;
        freq.style.display = on ? 'inline-block' : 'none';

        dailyHidden.value = (on && freq.value === 'daily') ? '1' : '0';
        weeklyHidden.value = (on && freq.value === 'weekly') ? '1' : '0';

        if (hint) {
            hint.textContent = freq.value === 'weekly'
                ? 'Spawns a fresh 1-day task every 7 days instead of needing to be re-added. Uncheck to stop — past ones stay as history.'
                : 'Resets to "not started" every day instead of needing to be re-added or reset by hand. Uncheck to stop resetting.';
        }
    }

    var typeEl = document.getElementById('task_type');
    var startDateEl = document.getElementById('task_start_date');
    if (typeEl) {
        typeEl.addEventListener('change', updateTaskTypeUI);
    }
    if (startDateEl) {
        startDateEl.addEventListener('change', updateTaskTypeUI);
    }

    var repeatTodayEnabled = document.getElementById('task_repeat_today_enabled');
    var repeatTodayFreq = document.getElementById('task_repeat_today_freq');
    if (repeatTodayEnabled) {
        repeatTodayEnabled.addEventListener('change', updateRepeatTodayFields);
    }
    if (repeatTodayFreq) {
        repeatTodayFreq.addEventListener('change', updateRepeatTodayFields);
    }
    updateRepeatTodayFields();
})();
</script>
<script>
(function () {
    var btn = document.getElementById('asCompleteBtn');
    var status = document.getElementById('asStatus');
    var form = document.getElementById('asTaskForm');
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
