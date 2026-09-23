@extends('layouts.app')
@section('title', 'Add Task')

@section('content')
@include('tasks.partials.asana_styles')
<style>
.as-task { max-width: 880px; margin: 0 auto; }
.as-task-card { background: #fff; border: 1px solid #edeae9; border-radius: 10px; }
.as-task-top { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #edeae9; gap: 10px; flex-wrap: wrap; }
.as-complete-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #cfcbcb; background: #fff; color: #1e1f21; border-radius: 6px; padding: 5px 12px; font-size: 13px; cursor: pointer; }
.as-complete-btn svg { width: 12px; height: 12px; stroke: #6d6e6f; }
.as-complete-btn:hover { border-color: #58a182; color: #2f7a57; background: #f2faf6; }
.as-complete-btn:hover svg { stroke: #58a182; }
.as-complete-btn.is-done { background: #e6f4ee; border-color: #58a182; color: #2f7a57; }
.as-complete-btn.is-done svg { stroke: #2f7a57; }
.as-back { color: #6d6e6f !important; font-size: 13px; text-decoration: none !important; }
.as-back:hover { color: #1e1f21 !important; }
.as-task-body { padding: 18px 24px 8px; }
.as-title-input { width: 100%; border: 1px solid transparent; border-radius: 6px; font-size: 24px; font-weight: 600; padding: 4px 8px; margin: 0 0 14px -8px; color: #1e1f21; background: transparent; }
.as-title-input:hover { border-color: #edeae9; }
.as-title-input:focus { border-color: #4573d2; outline: none; }
.as-fields { display: grid; grid-template-columns: 140px minmax(0, 1fr); row-gap: 6px; align-items: center; font-size: 14px; }
.as-fields > .k { color: #6d6e6f; font-size: 13px; padding: 6px 0; align-self: start; padding-top: 12px; }
.as-fields > .v { min-height: 36px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.as-fields .form-control, .as-in { border: 1px solid transparent; border-radius: 6px; box-shadow: none; background: transparent; height: 32px; padding: 4px 8px; font-size: 14px; width: auto; color: #1e1f21; }
.as-fields .form-control:hover, .as-in:hover { border-color: #edeae9; }
.as-fields .form-control:focus, .as-in:focus { border-color: #4573d2; outline: none; box-shadow: none; }
.as-fields .checkbox { margin: 0 !important; }
.as-fields .checkbox label { font-size: 13px; }
.as-fields small.text-muted { font-size: 12px; color: #9ca6af; }
.as-fields .select2-container--default .select2-selection--multiple { border: 1px solid transparent !important; border-radius: 6px; min-height: 32px; }
.as-fields .select2-container--default .select2-selection--multiple:hover { border-color: #edeae9 !important; }
.as-desc-label { color: #6d6e6f; font-size: 13px; margin: 18px 0 6px; }
.as-desc-box { width: 100%; min-height: 90px; border: 1px solid #edeae9; border-radius: 6px; padding: 10px 12px; font-size: 14px; resize: vertical; }
.as-desc-box:focus { border-color: #4573d2; outline: none; }
.as-save-bar { display: flex; gap: 8px; align-items: center; padding: 14px 24px; border-top: 1px solid #edeae9; }
.as-btn-ghost { border: 1px solid #cfcbcb; background: #fff; color: #1e1f21 !important; border-radius: 6px; padding: 6px 12px; font-size: 13px; text-decoration: none !important; }
.as-activity { background: #f9f8f8; border-top: 1px solid #edeae9; border-radius: 0 0 10px 10px; padding: 16px 24px 20px; }
.as-activity h4 { font-size: 14px; font-weight: 600; margin: 0 0 12px; }
.as-ev { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; font-size: 13px; }
.as-ev .as-dot { width: 24px; height: 24px; border-radius: 50%; background: #edeae9; color: #6d6e6f; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; flex: 0 0 auto; }
.as-ev .as-when { color: #9ca6af; font-size: 12px; margin-left: 4px; }
.as-ev .as-body { white-space: pre-wrap; margin-top: 2px; color: #1e1f21; }
.as-comment { display: flex; gap: 10px; align-items: flex-start; margin-top: 8px; }
.as-comment textarea { flex: 1; border: 1px solid #edeae9; border-radius: 8px; padding: 8px 12px; font-size: 14px; min-height: 40px; resize: vertical; background: #fff; }
.as-comment textarea:focus { border-color: #4573d2; outline: none; }
.as-comment button { border: 0; background: #4573d2; color: #fff; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
.as-photo { background: #fff8e6; border: 1px solid #f5e0a8; border-radius: 6px; padding: 8px 12px; font-size: 13px; margin-top: 10px; }
@media (max-width: 767px) {
    .as-fields { grid-template-columns: 100px minmax(0, 1fr); }
    .as-task-body, .as-activity, .as-save-bar { padding-left: 14px; padding-right: 14px; }
}
</style>

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
