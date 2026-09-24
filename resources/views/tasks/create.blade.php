@extends('layouts.app')
@section('title', 'Add Task')

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

    @php($task = null)
    @php($currentTaskType = old('task_type', $type ?? 'weekly'))
    @php($selectedAssignees = old('assignees', []))

    <div class="as-task-card">
        <form method="POST" action="{{ action('TaskController@store') }}" id="asTaskForm">
            @csrf
            @if(!empty($projectId))
                <input type="hidden" name="return_to" value="project">
            @endif

            <div class="as-task-top">
                <strong style="font-size:14px;">New task</strong>
                <a href="{{ action('TaskController@index') }}" class="as-back"><i class="fa fa-times"></i> Close</a>
            </div>

            <div class="as-task-body">
                <input type="text" class="as-title-input" name="title" value="{{ old('title') }}" required maxlength="200" aria-label="Task name" placeholder="Task name" autofocus>

                <div class="as-fields">
                    <div class="k">Assignees</div>
                    <div class="v" style="display:block;">
                        {!! Form::select('assignees[]', $assignableUsers, $selectedAssignees, ['id' => 'task_assignees', 'class' => 'form-control select2', 'multiple', 'style' => 'width: 100%;', 'data-placeholder' => 'Unassigned']) !!}
                    </div>

                    <div class="k">Project</div>
                    <div class="v">
                        <select name="project_id" class="form-control">
                            <option value="">No project</option>
                            @foreach($projectOptions ?? [] as $pid => $ptitle)
                                <option value="{{ $pid }}" @if((int) old('project_id', $projectId ?? null) === (int) $pid) selected @endif>{{ $ptitle }}</option>
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
                        <input type="date" id="task_start_date" class="form-control" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" required>
                        <span id="task_end_date_wrap" style="{{ $currentTaskType === 'daily' ? 'display:none;' : '' }}">
                            <span class="text-muted">to</span>
                            <input type="text" id="task_end_date_preview" class="form-control" value="{{ now()->addDays(7)->format('M j, Y') }}" disabled style="width:120px;">
                        </span>
                        <span class="text-muted">by</span>
                        <input type="time" class="form-control" name="due_time" value="{{ old('due_time') }}" title="Optional. Blank means end of day.">
                        <label style="font-weight:400;font-size:13px;margin:0 0 0 6px;"><input type="hidden" name="no_due_date" value="0"><input type="checkbox" name="no_due_date" value="1" @if(old('no_due_date')) checked @endif> No due date</label>
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
                                <option value="{{ $key }}" @if(old('priority', 'medium') === $key) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="k">Store</div>
                    <div class="v">
                        <select name="store" class="form-control">
                            <option value="" @if(!old('store')) selected @endif>Both stores</option>
                            @foreach($storeLabels as $key => $label)
                                <option value="{{ $key }}" @if(old('store') === $key) selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="k">Photo</div>
                    <div class="v">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="requires_photo" value="1" @if(old('requires_photo')) checked @endif>
                                Needs a photo in #taskphotos before it's complete
                            </label>
                        </div>
                    </div>
                </div>


                <div class="as-desc-label">Description</div>
                <textarea name="description" class="as-desc-box" placeholder="What is this task about?">{{ old('description') }}</textarea>
            </div>

            <div class="as-save-bar">
                <button type="submit" class="as-btn" style="border:0;">Create task</button>
                <a href="{{ action('TaskController@index') }}" class="as-btn-ghost">Cancel</a>
            </div>
        </form>

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
@stop
